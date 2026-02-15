<?php
// /dj/export_requests_csv.php

require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$djId    = (int)$_SESSION['dj_id'];
$eventId = (int)($_GET['event_id'] ?? 0);



if (!$eventId) {
    http_response_code(400);
    exit('Missing event_id');
}

$db = db();

/* --------------------------------------------------
   Verify event belongs to logged-in DJ
-------------------------------------------------- */
$stmt = $db->prepare("
    SELECT id, title, event_date
    FROM events
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$eventId, $djId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);


// Build filename from event
$eventName = $event['title'] ?? 'Event';
$eventDate = $event['event_date'] ?? date('Y-m-d');

// Sanitize for filesystem
$eventNameSafe = preg_replace('/[^a-z0-9]+/i', '_', $eventName);
$eventNameSafe = trim($eventNameSafe, '_');

$filename = sprintf(
    '%s_%s.csv',
    $eventNameSafe,
    $eventDate
);


if (!$event) {
    http_response_code(403);
    exit('Unauthorized');
}

/* --------------------------------------------------
   Fetch requests
-------------------------------------------------- */
$stmt = $db->prepare("
SELECT
    COALESCE(NULLIF(spotify_track_name, ''), song_title) AS title,
    COALESCE(NULLIF(spotify_artist_name, ''), artist)   AS artist,
    COALESCE(NULLIF(requester_name, ''), 'Guest')       AS requester,
    status,
    created_at
FROM song_requests
WHERE event_id = ?
ORDER BY created_at ASC
");
$stmt->execute([$eventId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* --------------------------------------------------
   CSV headers
-------------------------------------------------- */


header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

/* --------------------------------------------------
   CSV column headers
-------------------------------------------------- */
fputcsv($output, [
    'Requested At',
    'Title',
    'Artist',
    'Requested By',
    'Status'
]);

/* --------------------------------------------------
   Rows
-------------------------------------------------- */
foreach ($rows as $r) {

    // Normalize status for humans
    switch (strtolower($r['status'])) {
        case 'played':
            $status = 'Played';
            break;
        case 'skipped':
            $status = 'Skipped';
            break;
        default:
            $status = 'Unplayed'; // NEW + ACCEPTED
    }

fputcsv($output, [
    $r['created_at'],   // UTC – correct for CSV
    $r['title'],
    $r['artist'],
    $r['requester'],
    $status
]);
}

fclose($output);
exit;