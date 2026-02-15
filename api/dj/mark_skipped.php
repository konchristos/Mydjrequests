<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$eventId  = (int)($_POST['event_id'] ?? 0);
$trackKey = trim($_POST['track_key'] ?? '');

if (!$eventId || !$trackKey) {
  http_response_code(400);
  exit;
}

$db = db();

$sql = "
  UPDATE song_requests
  SET status = 'skipped'
  WHERE event_id = ?
    AND (
      spotify_track_id = ?
      OR CONCAT(song_title, '::', artist) = ?
    )
";

$stmt = $db->prepare($sql);
$stmt->execute([$eventId, $trackKey, $trackKey]);

echo json_encode(['ok' => true]);