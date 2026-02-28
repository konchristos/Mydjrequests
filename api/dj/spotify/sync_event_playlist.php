<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// Never leak PHP warnings into JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);



require_once __DIR__ . '/../../../app/bootstrap.php';
require_dj_login();

require_once APP_ROOT . '/app/lib/spotify_playlist.php';

$db   = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

if (!$djId) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// ---- Validate input ----
$eventId = (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing event_id']);
    exit;
}

// ---- Verify event ownership (events.user_id) ----
$stmt = $db->prepare("
    SELECT id, title
    FROM events
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$eventId, $djId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Event not found or not owned by this DJ'
    ]);
    exit;
}

// ---- Sync playlist ----
$res = syncEventPlaylistFromRequests($db, $djId, $eventId);

// Always return valid JSON
echo json_encode($res, JSON_UNESCAPED_UNICODE);
exit;
