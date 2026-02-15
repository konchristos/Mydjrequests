<?php
// /api/public/get_event_requests.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap_public.php';

$guestToken = $_COOKIE['mdjr_guest'] ?? '';

$eventUuid = $_GET['event_uuid'] ?? '';
if (!$eventUuid) {
    echo json_encode(['ok' => false, 'error' => 'Missing event_uuid']);
    exit;
}

// Resolve event
$eventModel = new Event();
$event = $eventModel->findByUuid($eventUuid);
if (!$event) {
    echo json_encode(['ok' => false, 'error' => 'Event not found']);
    exit;
}

$eventId = (int)$event['id'];
$db = db();

$guestToken = $_COOKIE['mdjr_guest'] ?? '';

$sql = "
SELECT
    COALESCE(
        NULLIF(sr.spotify_track_id, ''),
        CONCAT(LOWER(sr.song_title), '::', LOWER(sr.artist))
    ) AS track_key,

    sr.song_title,
    sr.artist,

    MAX(sr.spotify_album_art_url) AS album_art,

    COUNT(DISTINCT sr.id) AS request_count,
    COUNT(DISTINCT sv_all.guest_token) AS vote_count,

    (
        COUNT(DISTINCT sr.id)
      + COUNT(DISTINCT sv_all.guest_token)
    ) AS popularity_count,

    MAX(sr.created_at) AS last_requested_at,

MAX(CASE WHEN sr.guest_token = :guest_token_mine THEN 1 ELSE 0 END) AS is_mine,
MAX(CASE WHEN sv_guest.id IS NOT NULL THEN 1 ELSE 0 END) AS has_voted,
MAX(CASE WHEN sb_guest.id IS NOT NULL THEN 1 ELSE 0 END) AS has_boosted,
MAX(CASE WHEN sr.status = 'played' THEN 1 ELSE 0 END) AS is_played

FROM song_requests sr

LEFT JOIN song_votes sv_guest
  ON sv_guest.event_id = sr.event_id
 AND sv_guest.guest_token = :guest_token_vote
 AND sv_guest.track_key = COALESCE(
        NULLIF(sr.spotify_track_id, ''),
        CONCAT(LOWER(sr.song_title), '::', LOWER(sr.artist))
     )

LEFT JOIN song_votes sv_all
  ON sv_all.event_id = sr.event_id
 AND sv_all.track_key = COALESCE(
        NULLIF(sr.spotify_track_id, ''),
        CONCAT(LOWER(sr.song_title), '::', LOWER(sr.artist))
     )
     
     
LEFT JOIN event_track_boosts sb_guest
  ON sb_guest.event_id = sr.event_id
 AND sb_guest.guest_token = :guest_token_boost
 AND sb_guest.track_key = COALESCE(
        NULLIF(sr.spotify_track_id, ''),
        CONCAT(LOWER(sr.song_title), '::', LOWER(sr.artist))
     )
 AND sb_guest.status = 'succeeded'

WHERE sr.event_id = :event_id

GROUP BY
    track_key,
    sr.song_title,
    sr.artist

ORDER BY popularity_count DESC, last_requested_at DESC";


$stmt = $db->prepare($sql);

$stmt->execute([
    ':guest_token_mine'  => $guestToken,
    ':guest_token_vote'  => $guestToken,
    ':guest_token_boost' => $guestToken,
    ':event_id'          => $eventId
]);

echo json_encode([
    'ok'   => true,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);