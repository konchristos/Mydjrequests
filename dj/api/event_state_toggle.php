<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();
$pdo = db();

header('Content-Type: application/json');

$djId     = (int)$_SESSION['dj_id'];
$eventId  = (int)($_POST['event_id'] ?? 0);
$newState = trim((string)($_POST['state'] ?? ''));

if (!$eventId || $newState === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

// Verify event ownership
$eventModel = new Event();
$event = $eventModel->findById($eventId);

if (!$event || (int)$event['user_id'] !== $djId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

try {
    setEventState($pdo, $eventId, $djId, $newState);

    echo json_encode([
        'ok'         => true,
        'event_id'   => $eventId,
        'new_state'  => $newState
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to update state'
    ]);
}