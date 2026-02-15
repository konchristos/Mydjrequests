<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$eventId = $_POST['event_id'] ?? 0;
$djId = (int)$_SESSION['dj_id'];

if (!$eventId) {
    echo json_encode(['success' => false, 'message' => 'Missing event ID']);
    exit;
}

$eventCtrl = new EventController();
$res = $eventCtrl->deleteForUser($djId, (int)$eventId);

header('Content-Type: application/json');
echo json_encode($res);
exit;