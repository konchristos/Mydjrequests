<?php
declare(strict_types=1);

header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../app/bootstrap.php';
require_dj_login();
require_once APP_ROOT . '/app/lib/spotify_playlist.php';

$db   = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

$eventId        = (int)($_POST['event_id'] ?? 0);
$spotifyTrackId = trim($_POST['spotify_track_id'] ?? '');

if (!$eventId || !$spotifyTrackId) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

/**
 * Verify event ownership
 */
$stmt = $db->prepare("
    SELECT spotify_playlist_id
    FROM event_spotify_playlists
    WHERE event_id = ?
      AND dj_id = ?
    LIMIT 1
");
$stmt->execute([$eventId, $djId]);
$pl = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pl) {
    echo json_encode(['ok' => false, 'error' => 'Playlist not found']);
    exit;
}

$res = syncEventPlaylistFromRequests($db, $djId, $eventId);
if (!$res['ok']) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Spotify remove failed: ' . ($res['error'] ?? 'Sync failed')
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'added' => $res['added'] ?? 0,
    'removed' => $res['removed'] ?? 0,
]);
