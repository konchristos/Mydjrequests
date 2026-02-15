<?php
// api/dj/spotify/add_track.php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../../app/bootstrap.php';
require_dj_login();

require_once APP_ROOT . '/app/lib/spotify_playlist.php';

$db   = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

$eventId        = (int)($_POST['event_id'] ?? 0);
$spotifyTrackId = trim((string)($_POST['spotify_track_id'] ?? ''));

if (!$eventId || $spotifyTrackId === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

/**
 * Only allow valid Spotify IDs
 */
if (!preg_match('/^[A-Za-z0-9]{22}$/', $spotifyTrackId)) {
    echo json_encode(['ok' => true, 'skipped' => 'non-spotify']);
    exit;
}

/**
 * Fetch playlist
 */
$stmt = $db->prepare("
    SELECT spotify_playlist_id
    FROM event_spotify_playlists
    WHERE event_id = ?
      AND dj_id = ?
    LIMIT 1
");
$stmt->execute([$eventId, $djId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['spotify_playlist_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Playlist not found']);
    exit;
}

$playlistId = $row['spotify_playlist_id'];

/**
 * Spotify token
 */
$token = spotifyGetDjAccessToken($djId);
if (!$token) {
    echo json_encode(['ok' => false, 'error' => 'Spotify not connected']);
    exit;
}

/**
 * Re-add track
 */
$res = spotifyApiJson(
    'POST',
    'https://api.spotify.com/v1/playlists/' . rawurlencode($playlistId) . '/tracks',
    $token,
    [
        'uris' => [
            'spotify:track:' . $spotifyTrackId
        ]
    ]
);

if (!$res['ok']) {
    echo json_encode(['ok' => false, 'error' => $res['error']]);
    exit;
}

/**
 * 🔑 Reset sync state so future logic is consistent
 */
$upd = $db->prepare("
    UPDATE spotify_tracks
    SET added_to_playlist_at = NOW()
    WHERE spotify_track_id = ?
");
$upd->execute([$spotifyTrackId]);

echo json_encode(['ok' => true]);