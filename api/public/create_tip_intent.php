<?php
// /api/public/create_tip_intent.php
header('Content-Type: application/json');


ini_set('display_errors', 1);
error_reporting(E_ALL);

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
$amount     = (int)($_POST['amount'] ?? 0); // cents
$guestToken = $_COOKIE['mdjr_guest'] ?? null;
$guestName = trim($_POST['guest_name'] ?? '');

// --------------------
// Validation
// --------------------
/*if (!$eventUuid || !$guestToken || $amount < 500) {
    http_response_code(400);
    echo json_encode(['error' => 'INVALID_REQUEST']);
    exit;
}*/


if (!$eventUuid || !$guestToken || $amount < 500) {
    http_response_code(400);
    echo json_encode([
        'error' => 'INVALID_REQUEST',
        'debug' => [
            'event_uuid' => $eventUuid,
            'guest_token_present' => !!$guestToken,
            'amount' => $amount,
        ]
    ]);
    exit;
}

// --------------------
// Load event
// --------------------
$eventModel = new Event();
$event = $eventModel->findByUuid($eventUuid);

if (!$event) {
    http_response_code(404);
    echo json_encode(['error' => 'EVENT_NOT_FOUND']);
    exit;
}

$eventState = strtolower((string)($event['event_state'] ?? ''));
if ($eventState !== 'live') {
    http_response_code(403);
    echo json_encode(['error' => 'EVENT_NOT_LIVE']);
    exit;
}

// --------------------
// Load DJ
// --------------------
$userModel = new User();
$dj = $userModel->findById($event['user_id']);

if (
    empty($dj['stripe_connect_account_id']) ||
    (int)($dj['stripe_connect_onboarded'] ?? 0) !== 1
) {
    http_response_code(400);
    echo json_encode(['error' => 'DJ_NOT_ONBOARDED']);
    exit;
}

// --------------------
// Create PaymentIntent
// --------------------
try {

    // Build metadata safely
    $metadata = [
        'type'         => 'dj_tip',
        'event_uuid'   => $eventUuid,
        'event_id'     => (string)$event['id'],
        'dj_user_id'   => (string)$dj['id'],
        'guest_token' => $guestToken,
        'guest_name' => $guestName ?: null, // ✅ THIS LINE
    ];

    // Patron name = guest name (optional)
    if ($guestName !== '') {
        $metadata['guest_name'] = $guestName;
    }

    $intent = PaymentIntent::create([
        'amount'   => $amount,
        'currency' => 'aud',

        // ✅ Apple Pay / Google Pay / Card
        'automatic_payment_methods' => [
            'enabled' => true,
        ],

        'description' =>
            'Voluntary, non-refundable DJ tip during a live event',

        'metadata' => $metadata,
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
