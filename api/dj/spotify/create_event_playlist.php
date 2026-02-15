<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../app/bootstrap.php';
require_dj_login();

require_once APP_ROOT . '/app/lib/spotify_playlist.php';

$db   = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

$eventId = (int)($_POST['event_id'] ?? 0);
if (!$eventId) {
    echo json_encode(['ok' => false, 'error' => 'Missing event_id']);
    exit;
}




// Verify event belongs to DJ + fetch metadata
$stmt = $db->prepare("
    SELECT id, title, event_date
    FROM events
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$eventId, $djId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo json_encode(['ok' => false, 'error' => 'Event not found or not yours.']);
    exit;
}

// Build playlist name (canonical)
$title = sanitizeSpotifyText(
    (string)($event['title'] ?? 'Event')
);

$date = '';
if (!empty($event['event_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $event['event_date'])) {
    $date = $event['event_date'];
}

$playlistName = $date
    ? "MyDjRequests - {$date} - {$title}"
    : "MyDjRequests - {$title}";

// ✅ Default PRIVATE (DJ must opt-in to public)
$isPublic = !empty($_POST['is_public']) ? true : false;

$res = ensureSpotifyPlaylistForEvent(
    $db,
    $djId,
    $eventId,
    $playlistName,
    $isPublic
);

echo json_encode($res, JSON_UNESCAPED_UNICODE);