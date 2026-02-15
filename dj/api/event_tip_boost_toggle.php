<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

header('Content-Type: application/json');

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);
$eventId = (int)($_POST['event_id'] ?? 0);
$enabled = (string)($_POST['enabled'] ?? '');

if ($djId <= 0 || $eventId <= 0 || ($enabled !== '0' && $enabled !== '1')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid parameters']);
    exit;
}

$eventModel = new Event();
$event = $eventModel->findById($eventId);
if (!$event || (int)$event['user_id'] !== $djId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

try {
    $check = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'events'
          AND COLUMN_NAME = 'tips_boost_enabled'
    ");
    $check->execute();
    if ((int)$check->fetchColumn() === 0) {
        $db->exec("ALTER TABLE events ADD COLUMN tips_boost_enabled TINYINT(1) NULL DEFAULT NULL");
    }

    $stmt = $db->prepare("
        UPDATE events
        SET tips_boost_enabled = :enabled
        WHERE id = :event_id AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([
        ':enabled' => (int)$enabled,
        ':event_id' => $eventId,
        ':user_id' => $djId,
    ]);

    echo json_encode([
        'ok' => true,
        'event_id' => $eventId,
        'tips_boost_enabled' => (int)$enabled,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to update tips/boost setting']);
}
