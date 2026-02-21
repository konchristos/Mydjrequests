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

try {
    $q = $db->prepare("
        SELECT id, message, created_at
        FROM event_broadcast_messages
        WHERE event_id = :event_id
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ");
    $q->execute([':event_id' => $eventId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}

echo json_encode([
    'ok' => true,
    'rows' => $rows,
], JSON_UNESCAPED_UNICODE);

