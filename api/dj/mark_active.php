<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../app/bootstrap.php';

$db = db();

$eventId  = (int)($_POST['event_id'] ?? 0);
$trackKey = trim($_POST['track_key'] ?? '');

if (!$eventId || !$trackKey) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

$sql = "
UPDATE song_requests
SET status = 'accepted', updated_at = NOW()
WHERE event_id = ?
AND (
    spotify_track_id = ?
    OR CONCAT(song_title,'::',artist) = ?
)
";

$stmt = $db->prepare($sql);
$stmt->execute([$eventId, $trackKey, $trackKey]);

echo json_encode([
    'ok' => true,
    'affected' => $stmt->rowCount()
]);