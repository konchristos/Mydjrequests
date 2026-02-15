<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$eventUuid = trim((string)($_POST['event_uuid'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($eventUuid === '' || $message === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

if (mb_strlen($message) > 1000) {
    echo json_encode(['ok' => false, 'error' => 'Message too long']);
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
    $insert = $db->prepare("
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
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        )
    ");
    $insert->execute([
        ':event_id' => $eventId,
        ':dj_id' => (int)($_SESSION['dj_id'] ?? 0),
        ':message' => $message,
    ]);

    echo json_encode([
        'ok' => true,
        'id' => (int)$db->lastInsertId(),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Database upgrade required: missing event_broadcast_messages table'
    ]);
}
