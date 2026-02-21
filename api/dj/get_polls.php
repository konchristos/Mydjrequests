<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));
if ($eventUuid === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event']);
    exit;
}

$db = db();
$stmt = $db->prepare("
    SELECT id
    FROM events
    WHERE uuid = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$eventUuid, (int)($_SESSION['dj_id'] ?? 0)]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
$eventId = (int)$event['id'];

if (mdjr_get_user_plan($db, (int)($_SESSION['dj_id'] ?? 0)) !== 'premium') {
    echo json_encode([
        'ok' => true,
        'rows' => [],
        'locked' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$polls = [];
try {
    $pollStmt = $db->prepare("
        SELECT id, question, status, created_at
        FROM event_polls
        WHERE event_id = :event_id
        ORDER BY created_at DESC, id DESC
        LIMIT 200
    ");
    $pollStmt->execute([':event_id' => $eventId]);
    $pollRows = $pollStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pollRows as $pollRow) {
        $pollId = (int)$pollRow['id'];

        $optStmt = $db->prepare("
            SELECT
                o.id,
                o.option_text,
                o.sort_order,
                COUNT(v.id) AS vote_count
            FROM event_poll_options o
            LEFT JOIN event_poll_votes v
              ON v.option_id = o.id
             AND v.poll_id = o.poll_id
            WHERE o.poll_id = :poll_id
            GROUP BY o.id, o.option_text, o.sort_order
            ORDER BY o.sort_order ASC, o.id ASC
        ");
        $optStmt->execute([':poll_id' => $pollId]);
        $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalVotes = 0;
        foreach ($options as &$opt) {
            $opt['id'] = (int)$opt['id'];
            $opt['vote_count'] = (int)$opt['vote_count'];
            $opt['sort_order'] = (int)$opt['sort_order'];
            $totalVotes += $opt['vote_count'];
        }
        unset($opt);

        $polls[] = [
            'id' => $pollId,
            'question' => (string)$pollRow['question'],
            'status' => (string)$pollRow['status'],
            'created_at' => (string)$pollRow['created_at'],
            'total_votes' => $totalVotes,
            'options' => $options,
        ];
    }
} catch (Throwable $e) {
    $polls = [];
}

echo json_encode([
    'ok' => true,
    'rows' => $polls,
], JSON_UNESCAPED_UNICODE);
