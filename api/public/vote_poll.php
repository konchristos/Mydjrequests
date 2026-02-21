<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap_public.php';

$eventUuid = trim((string)($_POST['event_uuid'] ?? ''));
$pollId = (int)($_POST['poll_id'] ?? 0);
$optionId = (int)($_POST['option_id'] ?? 0);
$patronName = trim((string)($_POST['patron_name'] ?? ''));
$guestToken = (string)($_COOKIE['mdjr_guest'] ?? '');

if ($eventUuid === '' || $pollId <= 0 || $optionId <= 0 || $guestToken === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

$eventModel = new Event();
$event = $eventModel->findByUuid($eventUuid);
if (!$event) {
    echo json_encode(['ok' => false, 'error' => 'Event not found']);
    exit;
}

$db = db();
$eventId = (int)$event['id'];

function mdjr_table_has_column(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

try {
    $validOptStmt = $db->prepare("
        SELECT p.id AS poll_id, p.status, o.id AS option_id
        FROM event_polls p
        JOIN event_poll_options o ON o.poll_id = p.id
        WHERE p.id = :poll_id
          AND p.event_id = :event_id
          AND o.id = :option_id
        LIMIT 1
    ");
    $validOptStmt->execute([
        ':poll_id' => $pollId,
        ':event_id' => $eventId,
        ':option_id' => $optionId,
    ]);
    $valid = $validOptStmt->fetch(PDO::FETCH_ASSOC);
    if (!$valid) {
        echo json_encode(['ok' => false, 'error' => 'Invalid poll option']);
        exit;
    }
    if ((string)($valid['status'] ?? '') !== 'active') {
        echo json_encode(['ok' => false, 'error' => 'Poll is closed']);
        exit;
    }

    $voteStmt = $db->prepare("
        INSERT INTO event_poll_votes (
            poll_id, option_id, event_id, guest_token, created_at, updated_at
        ) VALUES (
            :poll_id, :option_id, :event_id, :guest_token, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )
        ON DUPLICATE KEY UPDATE
            option_id = VALUES(option_id),
            updated_at = UTC_TIMESTAMP()
    ");
    $voteStmt->execute([
        ':poll_id' => $pollId,
        ':option_id' => $optionId,
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
    ]);

    if ($patronName !== '') {
        if (mb_strlen($patronName) > 120) {
            $patronName = mb_substr($patronName, 0, 120);
        }

        if (mdjr_table_has_column($db, 'event_poll_votes', 'patron_name')) {
            try {
                $updVoteNameStmt = $db->prepare("
                    UPDATE event_poll_votes
                    SET patron_name = :patron_name
                    WHERE poll_id = :poll_id
                      AND guest_token = :guest_token
                    LIMIT 1
                ");
                $updVoteNameStmt->execute([
                    ':patron_name' => $patronName,
                    ':poll_id' => $pollId,
                    ':guest_token' => $guestToken,
                ]);
            } catch (Throwable $e) {
                // Non-blocking name persistence.
            }
        }

        if (mdjr_table_has_column($db, 'event_page_views', 'patron_name')) {
            try {
                $updViewNameStmt = $db->prepare("
                    UPDATE event_page_views
                    SET patron_name = :patron_name,
                        last_seen_at = UTC_TIMESTAMP()
                    WHERE event_id = :event_id
                      AND guest_token = :guest_token
                    LIMIT 1
                ");
                $updViewNameStmt->execute([
                    ':patron_name' => $patronName,
                    ':event_id' => $eventId,
                    ':guest_token' => $guestToken,
                ]);
            } catch (Throwable $e) {
                // Non-blocking name persistence.
            }
        }
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Unable to submit vote']);
    exit;
}

echo json_encode([
    'ok' => true,
], JSON_UNESCAPED_UNICODE);
