<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$db = db();

$eventId = (int)($_GET['event_id'] ?? 0);
if (!$eventId) {
    echo json_encode(['ok' => false]);
    exit;
}

$stmt = $db->prepare("
    SELECT
        id,
        COALESCE(spotify_track_name, song_title) AS title,
        COALESCE(spotify_artist_name, artist)    AS artist,
        status,
        created_at
    FROM song_requests
    WHERE event_id = ?
      AND status IN ('new','accepted')
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt->execute([$eventId]);

echo json_encode([
    'ok' => true,
    'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);