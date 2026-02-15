<?php
require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json');

$eventId = (int)($_GET['event_id'] ?? 0);
if (!$eventId) {
    echo json_encode(['ok' => false]);
    exit;
}

$db = db();

/*
|--------------------------------------------------------------------------
| Tips
|--------------------------------------------------------------------------
*/
$stmt = $db->prepare("
    SELECT
        amount_cents,
        currency,
        patron_name,
        created_at,
        'tip' AS type,
        NULL AS track_title
    FROM event_tips
    WHERE event_id = ?
      AND status = 'succeeded'
");
$stmt->execute([$eventId]);
$tips = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Boosts
|--------------------------------------------------------------------------
*/
$stmt = $db->prepare("
    SELECT
        b.amount_cents,
        b.currency,
        b.patron_name,
        b.created_at,
        'boost' AS type,
        r.song_title AS track_title
    FROM event_track_boosts b
    LEFT JOIN song_requests r
      ON r.spotify_track_id = b.track_key
     AND r.event_id = b.event_id
    WHERE b.event_id = ?
      AND b.status = 'succeeded'
");
$stmt->execute([$eventId]);
$boosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Merge + totals
|--------------------------------------------------------------------------
*/
$items = array_merge($tips, $boosts);

$totalCents = 0;
foreach ($items as $i) {
    $totalCents += (int)$i['amount_cents'];
}

// newest first
usort($items, fn($a, $b) =>
    strtotime($b['created_at']) <=> strtotime($a['created_at'])
);

echo json_encode([
    'ok' => true,
    'total_cents' => $totalCents,
    'currency' => $items[0]['currency'] ?? 'AUD',
    'items' => $items
]);