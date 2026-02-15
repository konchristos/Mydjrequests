<?php
require_once __DIR__ . '/../app/bootstrap_public.php';

header('Content-Type: application/json');

$djUuid = $_GET['dj'] ?? null;
if (!$djUuid) {
    echo json_encode(['live' => false]);
    exit;
}

$db = db();

// Find DJ
$stmt = $db->prepare("SELECT id FROM users WHERE uuid = ? LIMIT 1");
$stmt->execute([$djUuid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['live' => false]);
    exit;
}

// Find live event
$stmt = $db->prepare("
    SELECT id
    FROM events
    WHERE user_id = ?
      AND event_state = 'live'
    LIMIT 1
");
$stmt->execute([(int)$user['id']]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'live'    => (bool)$event,
    'eventId'=> $event['id'] ?? null
]);