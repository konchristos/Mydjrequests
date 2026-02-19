<?php
// /api/public/create_boost_intent.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once __DIR__ . '/../../app/config/stripe.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Stripe\Stripe;
use Stripe\PaymentIntent;

Stripe::setApiKey(STRIPE_SECRET_KEY);

// --------------------
// Inputs
// --------------------
$eventUuid  = $_POST['event_uuid'] ?? null;
$trackKey   = $_POST['track_key']  ?? null;
$guestToken = $_COOKIE['mdjr_guest'] ?? null;
$guestName = trim($_POST['guest_name'] ?? '');

$amount = 500; // fixed $5 AUD boost

if (!$eventUuid || !$trackKey || !$guestToken) {
    http_response_code(400);
    echo json_encode(['error' => 'INVALID_INPUT']);
    exit;
}

// --------------------
// Resolve event + DJ
// --------------------
$db = db();

$stmt = $db->prepare("
    SELECT
        e.id   AS event_id,
        e.uuid AS event_uuid,
        e.event_state,
        e.user_id AS dj_user_id,
        u.stripe_connect_onboarded
    FROM events e
    JOIN users u ON u.id = e.user_id
    WHERE e.uuid = ?
");
$stmt->execute([$eventUuid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'EVENT_NOT_FOUND']);
    exit;
}

$eventState = strtolower((string)($row['event_state'] ?? ''));
if ($eventState !== 'live') {
    http_response_code(403);
    echo json_encode(['error' => 'EVENT_NOT_LIVE']);
    exit;
}

if ((int)$row['stripe_connect_onboarded'] !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'DJ_NOT_ONBOARDED']);
    exit;
}

// --------------------
// Resolve track context (for metadata clarity)
// --------------------
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

$song = $stmt->fetch(PDO::FETCH_ASSOC);
$songTitle = trim($song['title'] ?? '');
$artist    = trim($song['artist'] ?? '');

// --------------------
// Create PaymentIntent
// --------------------
try {

    $intent = PaymentIntent::create([
        'amount'   => $amount,
        'currency' => 'aud',

    // ✅ THIS enables Apple Pay + Google Pay
    'automatic_payment_methods' => [
        'enabled' => true,
    ],

        'description' =>
            'Track boost during live DJ event (no guarantee of play)',

        'metadata' => [
            'type'        => 'track_boost',
            'event_uuid'  => (string)$row['event_uuid'],
            'event_id'    => (string)$row['event_id'],
            'dj_user_id'  => (string)$row['dj_user_id'],
            'guest_token'=> $guestToken,
            'guest_name' => $guestName ?: null, // ✅ THIS LINE
            'track_key'  => (string)$trackKey,
            'song_title' => $songTitle,
            'artist'     => $artist,
        ],
    ]);

    echo json_encode([
        'client_secret' => $intent->client_secret
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'STRIPE_INTENT_FAILED',
        'message' => $e->getMessage(),
    ]);
}
