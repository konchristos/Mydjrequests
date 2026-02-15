<?php
// api/public/vote_song.php

require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once APP_ROOT . '/app/config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$pdo = db();

// Inputs
$eventUuid  = trim($_POST['event_uuid'] ?? '');
$trackKey   = trim($_POST['track_key'] ?? '');
$songTitle  = trim($_POST['song_title'] ?? '');
$artist     = trim($_POST['artist'] ?? '');
$patronName = trim($_POST['patron_name'] ?? '');
$guestToken = $_COOKIE['mdjr_guest'] ?? null;

// Hard requirements
if (!$eventUuid || !$guestToken || !$trackKey) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
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
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

// Insert vote (1 per guest per track per event)
$stmt = $pdo->prepare("
    INSERT IGNORE INTO song_votes (
        event_id,
        track_key,
        song_title,
        artist,
        guest_token,
        patron_name,
        created_at
    ) VALUES (
        :event_id,
        :track_key,
        :song_title,
        :artist,
        :guest_token,
        :patron_name,
        NOW()
    )
");

$stmt->execute([
    ':event_id'    => $eventId,
    ':track_key'   => $trackKey,
    ':song_title'  => $songTitle,
    ':artist'      => $artist,
    ':guest_token' => $guestToken,
    ':patron_name' => $patronName
]);

$inserted = ($stmt->rowCount() === 1);

// ✅ STEP 3: increment monthly vote stats ONLY if inserted
if ($inserted) {
    $year  = (int)date('Y');
    $month = (int)date('n');

    $stmt = $pdo->prepare("
        INSERT INTO song_vote_stats_monthly
            (dj_id, year, month, total_votes)
        VALUES
            (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            total_votes = total_votes + 1
    ");
    $stmt->execute([$djId, $year, $month]);


// ✅ STEP 4: increment event vote stats ONLY if inserted
$stmt = $pdo->prepare("
    INSERT INTO event_vote_stats
        (event_id, total_votes, updated_at)
    VALUES
        (?, 1, NOW())
    ON DUPLICATE KEY UPDATE
        total_votes = total_votes + 1,
        updated_at = NOW()
");
$stmt->execute([$eventId]);

}

echo json_encode([
    'success'  => true,
    'inserted' => $inserted
]);