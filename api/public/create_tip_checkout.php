<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once __DIR__ . '/../../app/config/stripe.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Stripe\Stripe;
use Stripe\Checkout\Session;

Stripe::setApiKey(STRIPE_SECRET_KEY);

// --------------------
// Inputs
// --------------------
$eventUuid  = $_POST['event_uuid'] ?? null;
$amount     = (int)($_POST['amount'] ?? 500); // cents
$guestToken = $_COOKIE['mdjr_guest'] ?? null;

// --------------------
// Basic validation
// --------------------
if (!$eventUuid || !$guestToken || $amount < 100) {
    http_response_code(400);
    echo json_encode(['error' => 'INVALID_REQUEST']);
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

// Event must be live
$eventState = strtolower((string)($event['event_state'] ?? ''));

if (!in_array($eventState, ['live', 'in_progress'], true)) {
    http_response_code(403);
    echo json_encode([
        'error' => 'EVENT_NOT_LIVE',
        'state' => $event['event_state']
    ]);
    exit;
}

// --------------------
// Load DJ
// --------------------
$userModel = new User();
$dj = $userModel->findById($event['user_id']);

$stripeAccountId = $dj['stripe_connect_account_id'] ?? null;
$stripeOnboarded = (int)($dj['stripe_connect_onboarded'] ?? 0) === 1;

if (!$stripeAccountId || !$stripeOnboarded) {
    http_response_code(400);
    echo json_encode([
        'error' => 'DJ_NOT_ONBOARDED'
    ]);
    exit;
}

// --------------------
// Create Stripe Checkout
// --------------------
try {

    $baseUrl = 'https://mydjrequests.com';

    $session = Session::create([
        'mode' => 'payment',

        // Checkout Session metadata
        'metadata' => [
            'type'        => 'dj_tip',
            'event_uuid'  => $eventUuid,
            'dj_user_id'  => (string)$dj['id'],
            'guest_token'=> $guestToken,
        ],

        'line_items' => [[
            'price_data' => [
                'currency' => 'aud',
                'unit_amount' => $amount,
                'product_data' => [
                    'name' => 'Tip the DJ 💜',
                    'description' =>
                        'Voluntary tip to support the DJ. '
                      . 'Tips are non-refundable and do not guarantee a song will be played.',
                ],
            ],
            'quantity' => 1,
        ]],

        // PaymentIntent metadata (used by webhook)
        'payment_intent_data' => [
            'description' =>
                'Voluntary, non-refundable DJ tip during a live event',

            'metadata' => [
                'type'        => 'dj_tip',
                'event_uuid'  => $eventUuid,
                'dj_user_id'  => (string)$dj['id'],
                'guest_token'=> $guestToken,
            ],

            'transfer_data' => [
                'destination' => $stripeAccountId,
            ],
        ],

        // Small UX + dispute clarity win
        'custom_text' => [
            'submit' => [
                'message' =>
                    'Tips are optional, non-refundable, and help support the DJ.',
            ],
        ],

        'success_url' => $baseUrl . '/request/thanks.php?event=' . urlencode($eventUuid),
        'cancel_url'  => $baseUrl . '/request/?event=' . urlencode($eventUuid),
    ]);

    echo json_encode(['url' => $session->url]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'STRIPE_CHECKOUT_FAILED',
        'message' => $e->getMessage(),
    ]);
}