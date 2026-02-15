<?php
require_once __DIR__ . '/../app/bootstrap_public.php';
require_once __DIR__ . '/../app/config/database.php';

header('Content-Type: application/json');

$uuid = $_GET['event'] ?? '';
if (!$uuid) {
    echo json_encode(['ok' => false, 'msg' => 'Missing event']);
    exit;
}

$db = db(); // <-- FIXED

// Find event ID
$stmt = $db->prepare("SELECT id FROM events WHERE uuid = ?");
$stmt->execute([$uuid]);
$event = $stmt->fetch();

if (!$event) {
    echo json_encode(['ok' => false, 'msg' => 'Event not found']);
    exit;
}

$eventId = (int)$event['id'];

// Fetch mood stats
$stmt = $db->prepare("
    SELECT 
        SUM(mood = 1)  AS positive,
        SUM(mood = -1) AS negative,
        COUNT(*)        AS total
    FROM event_moods
    WHERE event_id = ?
");
$stmt->execute([$eventId]);

$row      = $stmt->fetch();
$positive = (int)$row['positive'];
$negative = (int)$row['negative'];
$total    = (int)$row['total'];
$score    = $total > 0 ? round(($positive / $total) * 100) : null;

// Current guest mood
$guestToken = $_COOKIE['mdjr_guest'] ?? '';
$guestMood  = 0;

if ($guestToken !== '') {
    $stmt = $db->prepare("
        SELECT mood 
        FROM event_moods 
        WHERE event_id = ? AND guest_token = ?
        LIMIT 1
    ");
    $stmt->execute([$eventId, $guestToken]);
    $guestMood = (int)$stmt->fetchColumn();
}

echo json_encode([
    'ok'         => true,
    'positive'   => $positive,
    'negative'   => $negative,
    'total'      => $total,
    'score'      => $score,
    'guest_mood' => $guestMood
]);

exit;