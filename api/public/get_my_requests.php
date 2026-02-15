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
        song_title,
        artist,
        spotify_track_id,
        spotify_album_art_url,
        created_at,
        status
    FROM song_requests
    WHERE event_id = ?
      AND guest_token = ?
    ORDER BY created_at DESC
    
";

$stmt = $db->prepare($sql);
$stmt->execute([$eventId, $guestToken]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 5. Respond
// --------------------------------------------------
echo json_encode([
    'ok'   => true,
    'rows' => $rows
]);