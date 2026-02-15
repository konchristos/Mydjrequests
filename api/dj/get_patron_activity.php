<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

header('Content-Type: application/json; charset=utf-8');

$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));
$guestToken = trim((string)($_GET['guest_token'] ?? ''));

if ($eventUuid === '' || $guestToken === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event_uuid or guest_token']);
    exit;
}

$db = db();

$eventStmt = $db->prepare('
    SELECT id
    FROM events
    WHERE uuid = ?
      AND user_id = ?
    LIMIT 1
');
$eventStmt->execute([$eventUuid, (int)($_SESSION['dj_id'] ?? 0)]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$eventId = (int)$event['id'];

$requested = [];
$voted = [];

$reqStmt = $db->prepare('
    SELECT
        MAX(NULLIF(TRIM(song_title), "")) AS song_title,
        MAX(NULLIF(TRIM(artist), "")) AS artist,
        COUNT(*) AS request_count,
        MAX(created_at) AS last_at
    FROM song_requests
    WHERE event_id = ?
      AND guest_token = ?
    GROUP BY COALESCE(NULLIF(spotify_track_id, ""), CONCAT(LOWER(song_title), "::", LOWER(artist)))
    ORDER BY request_count DESC, last_at DESC
    LIMIT 20
');
$reqStmt->execute([$eventId, $guestToken]);
$requested = $reqStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$voteStmt = $db->prepare('
    SELECT
        MAX(NULLIF(TRIM(song_title), "")) AS song_title,
        MAX(NULLIF(TRIM(artist), "")) AS artist,
        COUNT(*) AS vote_count,
        MAX(created_at) AS last_at
    FROM song_votes
    WHERE event_id = ?
      AND guest_token = ?
    GROUP BY track_key
    ORDER BY vote_count DESC, last_at DESC
    LIMIT 20
');
$voteStmt->execute([$eventId, $guestToken]);
$voted = $voteStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
    'ok' => true,
    'requested_tracks' => $requested,
    'voted_tracks' => $voted,
], JSON_UNESCAPED_UNICODE);
