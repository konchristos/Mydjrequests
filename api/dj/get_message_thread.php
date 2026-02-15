<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));
$guestToken = trim((string)($_GET['guest_token'] ?? ''));

if ($eventUuid === '' || $guestToken === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event or guest']);
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
$stmt->execute([$eventUuid, (int)$_SESSION['dj_id']]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
$eventId = (int)$event['id'];

$guestRows = [];
try {
    $q = $db->prepare("
      SELECT id, message AS body, created_at, 'guest' AS sender
      FROM messages
      WHERE event_id = :event_id
        AND guest_token = :guest_token
      ORDER BY created_at DESC
      LIMIT 200
    ");
    $q->execute([
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
    ]);
    $guestRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $guestRows = [];
}

$djRows = [];
try {
    $q = $db->prepare("
      SELECT id, message AS body, created_at, 'dj' AS sender
      FROM message_replies
      WHERE event_id = :event_id
        AND guest_token = :guest_token
      ORDER BY created_at DESC
      LIMIT 200
    ");
    $q->execute([
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
    ]);
    $djRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $djRows = [];
}

$broadcastRows = [];
try {
    $q = $db->prepare("
      SELECT id, message AS body, created_at, 'broadcast' AS sender
      FROM event_broadcast_messages
      WHERE event_id = :event_id
        AND deleted_at IS NULL
      ORDER BY created_at DESC
      LIMIT 200
    "
    );
    $q->execute([':event_id' => $eventId]);
    $broadcastRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $broadcastRows = [];
}

$rows = array_merge($guestRows, $djRows, $broadcastRows);
usort($rows, static function (array $a, array $b): int {
    $cmp = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
    if ($cmp !== 0) return $cmp;
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
