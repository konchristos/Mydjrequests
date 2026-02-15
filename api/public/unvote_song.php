<?php
// api/public/unvote_song.php

require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once APP_ROOT . '/app/config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$pdo = db();

// Inputs
$eventUuid  = trim($_POST['event_uuid'] ?? '');
$songTitle  = trim($_POST['song_title'] ?? '');
$artist     = trim($_POST['artist'] ?? '');
$spotifyId  = trim($_POST['spotify_track_id'] ?? '');
$guestToken = $_COOKIE['mdjr_guest'] ?? null;

if (!$eventUuid || !$guestToken) {
    echo json_encode(['success' => false]);
    exit;
}

// Resolve event + DJ
$stmt = $pdo->prepare("
    SELECT id, user_id
    FROM events
    WHERE uuid = ?
");
$stmt->execute([$eventUuid]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

$eventId = (int)($event['id'] ?? 0);
$djId    = (int)($event['user_id'] ?? 0);

if (!$eventId || !$djId) {
    echo json_encode(['success' => false]);
    exit;
}

// Delete vote
$stmt = $pdo->prepare("
    DELETE FROM song_votes
    WHERE event_id = ?
      AND guest_token = ?
      AND (
            (spotify_track_id IS NOT NULL AND spotify_track_id = ?)
         OR (spotify_track_id IS NULL
             AND LOWER(song_title) = LOWER(?)
             AND LOWER(artist) = LOWER(?))
      )
");

$stmt->execute([
    $eventId,
    $guestToken,
    $spotifyId,
    $songTitle,
    $artist
]);


$deleted = ($stmt->rowCount() === 1);

if ($deleted) {
    $year  = (int)date('Y');
    $month = (int)date('n');

    // ✅ Monthly DJ stats
    $stmt = $pdo->prepare("
        UPDATE song_vote_stats_monthly
        SET total_votes = GREATEST(total_votes - 1, 0)
        WHERE dj_id = ?
          AND year = ?
          AND month = ?
    ");
    $stmt->execute([$djId, $year, $month]);

    // ✅ Event-level stats
    $stmt = $pdo->prepare("
        UPDATE event_vote_stats
        SET total_votes = GREATEST(total_votes - 1, 0),
            updated_at = NOW()
        WHERE event_id = ?
    ");
    $stmt->execute([$eventId]);
}


echo json_encode([
    'success' => true,
    'deleted' => $deleted
]);