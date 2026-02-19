<?php
// /api/public/get_my_requests.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap_public.php';

// --------------------------------------------------
// 1. Validate input
// --------------------------------------------------
$eventUuid = $_GET['event_uuid'] ?? '';
if (!$eventUuid) {
    echo json_encode(['ok' => false, 'error' => 'Missing event_uuid']);
    exit;
}

// --------------------------------------------------
// 2. Resolve event_uuid → event_id
// --------------------------------------------------
$eventModel = new Event();
$event = $eventModel->findByUuid($eventUuid);

if (!$event) {
    echo json_encode(['ok' => false, 'error' => 'Event not found']);
    exit;
}

$eventId = (int)$event['id'];

// --------------------------------------------------
// 3. Identify guest (COOKIE — single source of truth)
// --------------------------------------------------
$guestToken = $_COOKIE['mdjr_guest'] ?? null;

if (!$guestToken) {
    // No identity yet → no personal requests
    echo json_encode([
        'ok'   => true,
        'rows' => []
    ]);
    exit;
}

// --------------------------------------------------
// 4. Query requests for THIS guest
// --------------------------------------------------
$db = db();

$sql = "
    SELECT
        COALESCE(
            NULLIF(sr.spotify_track_id, ''),
            CONCAT(LOWER(sr.song_title), '::', LOWER(sr.artist))
        ) AS track_key,
        sr.song_title,
        sr.artist,
        sr.spotify_track_id,
        sr.spotify_album_art_url,
        sr.created_at,
        sr.status,
        EXISTS(
            SELECT 1
            FROM event_track_boosts sb
            WHERE sb.event_id = sr.event_id
              AND sb.guest_token = ?
              AND sb.track_key = COALESCE(
                    NULLIF(sr.spotify_track_id, ''),
                    CONCAT(LOWER(sr.song_title), '::', LOWER(sr.artist))
                  )
              AND sb.status = 'succeeded'
        ) AS has_boosted
    FROM song_requests sr
    WHERE sr.event_id = ?
      AND sr.guest_token = ?
    ORDER BY sr.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute([$guestToken, $eventId, $guestToken]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 5. Respond
// --------------------------------------------------
echo json_encode([
    'ok'   => true,
    'rows' => $rows
]);
