<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once __DIR__ . '/../../app/config/stripe.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Stripe\Stripe;
use Stripe\Checkout\Session;

Stripe::setApiKey(STRIPE_SECRET_KEY);

// -------------------------
// Inputs
// -------------------------
$eventUuid  = $_POST['event_uuid'] ?? null;
$trackKey   = $_POST['track_key']  ?? null;
$guestToken = $_COOKIE['mdjr_guest'] ?? null;
$amount     = 500; // $5 AUD fixed boost

if (!$eventUuid || !$trackKey || !$guestToken) {
    http_response_code(400);
    echo json_encode(['error' => 'INVALID_INPUT']);
    exit;
}

// -------------------------
// Resolve event + DJ
// -------------------------
$db = db();

$stmt = $db->prepare("
    SELECT
        e.id AS event_id,
        e.event_state,
        e.user_id AS dj_user_id,
        u.stripe_connect_account_id
    FROM events e
    JOIN users u ON u.id = e.user_id
    WHERE e.uuid = ?
");
$stmt->execute([$eventUuid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['stripe_connect_account_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'DJ_NOT_READY_FOR_BOOSTS']);
    exit;
}

$eventState = strtolower((string)($row['event_state'] ?? ''));
if ($eventState !== 'live') {
    http_response_code(403);
    echo json_encode(['error' => 'EVENT_NOT_LIVE']);
    exit;
}

$baseUrl = 'https://mydjrequests.com';


// -------------------------
// Resolve track title + artist
// -------------------------
$songTitle = '';
$artist    = '';

$stmt = $db->prepare("
    SELECT
        COALESCE(spotify_track_name, song_title) AS title,
        COALESCE(spotify_artist_name, artist)    AS artist
    FROM song_requests
    WHERE event_id = ?
      AND (
          spotify_track_id = ?
          OR CONCAT(LOWER(song_title), '::', LOWER(artist)) = ?
      )
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([
    (int)$row['event_id'],
    $trackKey,
    $trackKey
]);

if ($track = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $songTitle = trim($track['title'] ?? '');
    $artist    = trim($track['artist'] ?? '');
}




// -------------------------
// Create Stripe Checkout
// -------------------------
try {

    $session = Session::create([
        'mode' => 'payment',

        'line_items' => [[
            'price_data' => [
                'currency' => 'aud',
                'unit_amount' => $amount,
                
'product_data' => [
    'name' => 'Boost: ' .($songTitle ?: 'Track Boost') . ($artist ? " — $artist" : '') . '  🚀',
    'description' =>
        'Boost visibility of this track to the DJ. '
        . 'Voluntary. No guarantee of play.',
],
                
                
            ],
            'quantity' => 1,
        ]],

        // Checkout metadata
'metadata' => [
    'type'        => 'track_boost',
    'guest_token'=> $guestToken,
    'event_id'   => (string)$row['event_id'],
    'dj_user_id' => (string)$row['dj_user_id'],
    'track_key'  => (string)$trackKey,
    'song_title' => $songTitle,
    'artist'     => $artist,
],

        // PaymentIntent metadata (used by webhook)
        'payment_intent_data' => [
'metadata' => [
    'type'        => 'track_boost',
    'guest_token'=> $guestToken,
    'event_id'   => (string)$row['event_id'],
    'dj_user_id' => (string)$row['dj_user_id'],
    'track_key'  => (string)$trackKey,
    'song_title' => $songTitle,
    'artist'     => $artist,
],
            
            'transfer_data' => [
                'destination' => $row['stripe_connect_account_id'],
            ],
        ],

        'success_url' => $baseUrl . '/request/?event=' . urlencode($eventUuid),
        'cancel_url'  => $baseUrl . '/request/?event=' . urlencode($eventUuid),
    ]);

    echo json_encode(['url' => $session->url]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'STRIPE_FAILED',
        'message' => $e->getMessage()
    ]);
}
