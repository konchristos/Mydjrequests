<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap_public.php';

$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));
$guestToken = (string)($_COOKIE['mdjr_guest'] ?? '');

if ($eventUuid === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event']);
    exit;
}

$eventModel = new Event();
$event = $eventModel->findByUuid($eventUuid);
if (!$event) {
    echo json_encode(['ok' => false, 'error' => 'Event not found']);
    exit;
}

$eventId = (int)$event['id'];

$db = db();

$guestStatus = 'active';
try {
    $stmt = $db->prepare("
        SELECT status
        FROM message_guest_states
        WHERE event_id = :event_id
          AND guest_token = :guest_token
        LIMIT 1
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['status'])) {
        $guestStatus = (string)$row['status'];
    }
} catch (Throwable $e) {
    $guestStatus = 'active';
}

$guestRows = [];
if ($guestToken !== '') {
    try {
        $messagesStmt = $db->prepare("
            SELECT
              id,
              message AS body,
              created_at,
              'guest' AS sender
            FROM messages
            WHERE event_id = :event_id
              AND guest_token = :guest_token
            ORDER BY created_at DESC
            LIMIT 200
        ");
        $messagesStmt->execute([
            ':event_id' => $eventId,
            ':guest_token' => $guestToken
        ]);
        $guestRows = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $guestRows = [];
    }
}

$djRows = [];
if ($guestToken !== '') {
    try {
        $repliesStmt = $db->prepare("
            SELECT
              id,
              message AS body,
              created_at,
              'dj' AS sender
            FROM message_replies
            WHERE event_id = :event_id
              AND guest_token = :guest_token
            ORDER BY created_at DESC
            LIMIT 200
        ");
        $repliesStmt->execute([
            ':event_id' => $eventId,
            ':guest_token' => $guestToken
        ]);
        $djRows = $repliesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $djRows = [];
    }
}

$broadcastRows = [];
try {
    $broadcastStmt = $db->prepare("
        SELECT
          id,
          message AS body,
          created_at,
          'broadcast' AS sender
        FROM event_broadcast_messages
        WHERE event_id = :event_id
        ORDER BY created_at DESC
        LIMIT 200
    "
    );
    $broadcastStmt->execute([':event_id' => $eventId]);
    $broadcastRows = $broadcastStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Backwards-compatible when broadcast table is not installed yet.
    $broadcastRows = [];
}

// One-time backfill: seed initial broadcast from DJ default when none exists.
if (empty($broadcastRows)) {
    try {
        $defaultStmt = $db->prepare("
            SELECT default_broadcast_message
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ");
        $defaultStmt->execute([':user_id' => (int)$event['user_id']]);
        $seedBody = trim((string)($defaultStmt->fetchColumn() ?: ''));
        if ($seedBody === mdjr_default_broadcast_token()) {
            $seedBody = mdjr_default_broadcast_template();
        }

        if ($seedBody !== '') {
            $seedInsert = $db->prepare("
                INSERT INTO event_broadcast_messages (
                    event_id,
                    dj_id,
                    message,
                    created_at,
                    updated_at
                ) VALUES (
                    :event_id,
                    :dj_id,
                    :message,
                    NOW(),
                    NOW()
                )
            ");
            $seedInsert->execute([
                ':event_id' => $eventId,
                ':dj_id' => (int)$event['user_id'],
                ':message' => $seedBody
            ]);

            $broadcastStmt = $db->prepare("
                SELECT
                  id,
                  message AS body,
                  created_at,
                  'broadcast' AS sender
                FROM event_broadcast_messages
                WHERE event_id = :event_id
                ORDER BY created_at DESC
                LIMIT 200
            ");
            $broadcastStmt->execute([':event_id' => $eventId]);
            $broadcastRows = $broadcastStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        // Keep endpoint resilient if broadcasts table/schema is unavailable.
    }
}

$rows = array_merge($guestRows, $djRows, $broadcastRows);

$djName = '';
try {
    $djStmt = $db->prepare("
        SELECT dj_name, name
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $djStmt->execute([':id' => (int)$event['user_id']]);
    $djRow = $djStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $djName = (string)($djRow['dj_name'] ?? '');
    if ($djName === '') {
        $djName = (string)($djRow['name'] ?? '');
    }
} catch (Throwable $e) {
    $djName = '';
}
$eventName = trim((string)($event['title'] ?? ''));
foreach ($rows as &$row) {
    if (($row['sender'] ?? '') !== 'broadcast') {
        continue;
    }
    $body = (string)($row['body'] ?? '');
    $body = str_replace('{{DJ_NAME}}', $djName, $body);
    $body = str_replace('{{EVENT_NAME}}', $eventName, $body);
    $row['body'] = $body;
}
unset($row);

usort($rows, static function (array $a, array $b): int {
    $cmp = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

echo json_encode([
    'ok' => true,
    'guest_status' => $guestStatus,
    'rows' => $rows
], JSON_UNESCAPED_UNICODE);
