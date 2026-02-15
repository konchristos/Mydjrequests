<?php
// /api/dj/get_mood_votes.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap.php';

$eventId = (int)($_GET['event_id'] ?? 0);

if (!$eventId) {
    echo json_encode(['ok' => false, 'error' => 'Missing event_id']);
    exit;
}

$db = db();

/*
  One row per guest (enforced by event_id + guest_token uniqueness)
*/
$sql = "
  SELECT
    guest_token,
    patron_name,
    mood,
    updated_at
  FROM event_moods
  WHERE event_id = ?
  ORDER BY updated_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute([$eventId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$positive = [];
$negative = [];

foreach ($rows as $row) {

    $entry = [
        'guest_token' => $row['guest_token'],
        'patron_name' => $row['patron_name'] ?: 'Guest',
        'updated_at'  => $row['updated_at']
    ];

    if ((int)$row['mood'] === 1) {
        $positive[] = $entry;
    } else {
        $negative[] = $entry;
    }
}

echo json_encode([
    'ok'       => true,
    'positive' => $positive,
    'negative' => $negative
]);