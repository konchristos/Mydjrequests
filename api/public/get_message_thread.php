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

if ($guestToken === '') {
    echo json_encode([
        'ok' => true,
        'guest_status' => 'active',
        'rows' => []
    ]);
    exit;
}

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

$djRows = [];
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
          AND deleted_at IS NULL
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

$rows = array_merge($guestRows, $djRows, $broadcastRows);
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
