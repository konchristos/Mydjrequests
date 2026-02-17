<?php
require_once __DIR__ . '/../../app/bootstrap_public.php';

header('Content-Type: application/json');

$uuid = $_GET['event_uuid'] ?? '';
if (!$uuid) {
    echo json_encode(['ok' => false]);
    exit;
}

$eventModel = new Event();
$event = $eventModel->findByUuid($uuid);

if (!$event) {
    echo json_encode(['ok' => false]);
    exit;
}

$db = db();

// Resolve notice (platform → event override)
$notice = resolveEventNotice(
    $db,
    (int)$event['id'],
    (int)$event['user_id'],
    (string)$event['event_state']
);

if (!$notice) {
    echo json_encode(['ok' => true, 'notice' => null]);
    exit;
}

// Replace variables
$userModel = new User();
$dj = $userModel->findById((int)$event['user_id']);
$djName = $dj['dj_name'] ?: $dj['name'] ?: '';
$eventName = trim((string)($event['title'] ?? ''));

$body = $notice['body'];
$body = str_replace('{{DJ_NAME}}', $djName, $body);
$body = str_replace('{{EVENT_NAME}}', $eventName, $body);

echo json_encode([
    'ok'     => true,
    'notice' => [
        'title'      => $notice['title'],
        'body'       => $body,
        'type'       => $notice['type'],
        'updated_at' => $notice['updated_at']
    ]
]);
