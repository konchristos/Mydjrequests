<?php
require_once __DIR__ . '/../../app/bootstrap_public.php';

header('Content-Type: application/json');

// -------------------------
// Inputs
// -------------------------
$eventUuid = $_GET['event_uuid'] ?? null;
$guestToken = $_COOKIE['mdjr_guest'] ?? null;

if (!$eventUuid || !$guestToken) {
    echo json_encode([
        'ok' => false,
        'total_cents' => 0,
        'items' => []
    ]);
    exit;
}

$db = db();

// -------------------------
// Resolve event_id
// -------------------------
$stmt = $db->prepare("SELECT id FROM events WHERE uuid = ?");
$stmt->execute([$eventUuid]);
$eventId = (int)$stmt->fetchColumn();

if (!$eventId) {
    echo json_encode([
        'ok' => false,
        'total_cents' => 0,
        'items' => []
    ]);
    exit;
}

// -------------------------
// Fetch TIP line items
// -------------------------
$stmt = $db->prepare("
    SELECT
        amount_cents,
        currency,
        created_at,
        'tip' AS item_type,
        NULL AS track_title
    FROM event_tips
    WHERE event_id = ?
      AND guest_token = ?
      AND status = 'succeeded'
");
$stmt->execute([$eventId, $guestToken]);
$tips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------
// Fetch BOOST line items
// -------------------------
$stmt = $db->prepare("
    SELECT
        b.amount_cents,
        b.currency,
        b.created_at,
        'boost' AS item_type,
        r.song_title AS track_title
    FROM event_track_boosts b
    LEFT JOIN song_requests r
        ON r.spotify_track_id = b.track_key
       AND r.event_id = b.event_id
    WHERE b.event_id = ?
      AND b.guest_token = ?
      AND b.status = 'succeeded'
");
$stmt->execute([$eventId, $guestToken]);
$boosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------
// Merge + calculate totals
// -------------------------
$items = array_merge($tips, $boosts);

$totalCents = 0;
foreach ($items as $row) {
    $totalCents += (int)$row['amount_cents'];
}

// Sort newest first (PHP 7.0+ compatible)
usort($items, function ($a, $b) {
    $timeA = strtotime($a['created_at']);
    $timeB = strtotime($b['created_at']);

    if ($timeA === $timeB) {
        return 0;
    }

    return ($timeA < $timeB) ? 1 : -1;
});

// -------------------------
// Response
// -------------------------
echo json_encode([
    'ok' => true,
    'total_cents' => $totalCents,
    'currency' => $items[0]['currency'] ?? 'AUD',
    'items' => $items
]);