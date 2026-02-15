<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../app/bootstrap.php';
require_dj_login();

require_once APP_ROOT . '/app/lib/spotify_playlist.php';

$db    = db();
$djId  = (int)($_SESSION['dj_id'] ?? 0);
$eventId = (int)($_POST['event_id'] ?? 0);

if ($eventId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing event_id']);
    exit;
}

/**
 * 1️⃣ Verify event ownership
 */
$stmt = $db->prepare("
    SELECT id
    FROM events
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$eventId, $djId]);

if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Event not found or not yours']);
    exit;
}

/**
 * 2️⃣ Fetch playlist for this event
 */
$stmt = $db->prepare("
    SELECT spotify_playlist_id
    FROM event_spotify_playlists
    WHERE event_id = ?
    LIMIT 1
");
$stmt->execute([$eventId]);
$playlist = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$playlist || empty($playlist['spotify_playlist_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Spotify playlist not found for event']);
    exit;
}

$playlistId = $playlist['spotify_playlist_id'];

/**
 * 3️⃣ Get Spotify access token
 */
$token = spotifyGetDjAccessToken($djId);
if (!$token) {
    echo json_encode(['ok' => false, 'error' => 'Unable to get Spotify access token']);
    exit;
}

/**
 * 4️⃣ Remove ALL tracks from the Spotify playlist
 */
$res = spotifyApiJson(
    'PUT',
    'https://api.spotify.com/v1/playlists/' . rawurlencode($playlistId) . '/tracks',
    $token,
    ['uris' => []] // Spotify trick: empty array = clear playlist
);

if (!$res['ok']) {
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to clear Spotify playlist: ' . $res['error']
    ]);
    exit;
}

/**
 * 5️⃣ Clear DB tracking for this event
 * (THIS is the critical fix)
 */
$stmt = $db->prepare("
    DELETE FROM event_spotify_playlist_tracks
    WHERE event_id = ?
");
$stmt->execute([$eventId]);

/**
 * 6️⃣ Re-sync from ACTIVE requests only
 */
$stmt = $db->prepare("
    SELECT DISTINCT spotify_track_id
    FROM song_requests
    WHERE event_id = ?
      AND spotify_track_id IS NOT NULL
      AND spotify_track_id <> ''
      AND status NOT IN ('played','skipped')
");
$stmt->execute([$eventId]);
$tracks = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$tracks) {
    echo json_encode(['ok' => true, 'rebuilt' => true, 'added' => 0]);
    exit;
}

$uris = array_map(
    fn($id) => 'spotify:track:' . $id,
    $tracks
);

// Add back in batches of 100
foreach (array_chunk($uris, 100) as $chunk) {
    spotifyApiJson(
        'POST',
        'https://api.spotify.com/v1/playlists/' . rawurlencode($playlistId) . '/tracks',
        $token,
        ['uris' => $chunk]
    );
}

echo json_encode([
    'ok' => true,
    'rebuilt' => true,
    'added' => count($tracks)
]);
exit;