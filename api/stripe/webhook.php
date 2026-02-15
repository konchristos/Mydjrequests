<?php
require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once __DIR__ . '/../../app/config/stripe.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Stripe\Webhook;

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = Webhook::constructEvent(
        $payload,
        $sigHeader,
        STRIPE_WEBHOOK_SECRET
    );
} catch (Throwable $e) {
    http_response_code(400);
    exit;
}

file_put_contents(
    __DIR__ . '/webhook.log',
    date('c') . " {$event->type}\n",
    FILE_APPEND
);

// Only handle successful PaymentIntents
if ($event->type !== 'payment_intent.succeeded') {
    http_response_code(200);
    exit;
}

$intent = $event->data->object;

$metadata = $intent->metadata ?? [];
$paymentType = $metadata->type ?? null;

if (!$paymentType) {
    http_response_code(200);
    exit;
}

$db = db();

// Common fields
$djUserId   = (int)($metadata->dj_user_id ?? 0);
$guestToken = $metadata->guest_token ?? null;
$amountCents = (int)$intent->amount;
$currency = strtoupper($intent->currency);
$createdTs = (int)$intent->created;

// Patron name (prefer app guest name, fallback to Stripe)
$patronName = null;

if (!empty($intent->metadata->guest_name)) {
    $patronName = trim($intent->metadata->guest_name);
} elseif (!empty($intent->charges->data[0]->billing_details->name)) {
    $patronName = $intent->charges->data[0]->billing_details->name;
}

if (!$djUserId || $amountCents <= 0) {
    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| DJ TIP
|--------------------------------------------------------------------------
*/
if ($paymentType === 'dj_tip') {

    $eventUuid = $metadata->event_uuid ?? null;
    if (!$eventUuid) exit;

    $stmt = $db->prepare("SELECT id FROM events WHERE uuid = ?");
    $stmt->execute([$eventUuid]);
    $eventId = (int)$stmt->fetchColumn();

    if (!$eventId) exit;

    $stmt = $db->prepare("
        INSERT IGNORE INTO event_tips (
            event_id,
            dj_user_id,
            guest_token,
            amount_cents,
            currency,
            patron_name,
            status,
            stripe_event_id,
            stripe_payment_intent_id,
            stripe_checkout_session_id,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'succeeded', ?, ?, NULL, NOW())
    ");

    $stmt->execute([
        $eventId,
        $djUserId,
        $guestToken,
        $amountCents,
        $currency,
        $patronName,
        $event->id,
        $intent->id
    ]);

    if ($stmt->rowCount() === 1) {
        $year  = (int)date('Y', $createdTs);
        $month = (int)date('n', $createdTs);
        $amountDecimal = $amountCents / 100;

        $db->prepare("
            INSERT INTO event_tip_stats_monthly
            (dj_user_id, year, month, currency, total_tips_count, total_tips_amount)
            VALUES (?, ?, ?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE
                total_tips_count = total_tips_count + 1,
                total_tips_amount = total_tips_amount + VALUES(total_tips_amount)
        ")->execute([
            $djUserId, $year, $month, $currency, $amountDecimal
        ]);

        $db->prepare("
            INSERT INTO event_tip_stats
            (event_id, dj_user_id, currency, total_tips_count, total_tips_amount, updated_at)
            VALUES (?, ?, ?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_tips_count = total_tips_count + 1,
                total_tips_amount = total_tips_amount + VALUES(total_tips_amount),
                updated_at = NOW()
        ")->execute([
            $eventId, $djUserId, $currency, $amountDecimal
        ]);
    }

    http_response_code(200);
    exit;
}

/*
|--------------------------------------------------------------------------
| TRACK BOOST
|--------------------------------------------------------------------------
*/
if ($paymentType === 'track_boost') {

    $eventId  = (int)($metadata->event_id ?? 0);
    $trackKey = $metadata->track_key ?? null;

    if (!$eventId || !$trackKey) exit;

    $stmt = $db->prepare("
        INSERT IGNORE INTO event_track_boosts (
            event_id,
            track_key,
            dj_user_id,
            guest_token,
            amount_cents,
            currency,
            patron_name,
            stripe_payment_intent_id,
            stripe_checkout_session_id,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'succeeded', NOW())
    ");

    $stmt->execute([
        $eventId,
        $trackKey,
        $djUserId,
        $guestToken,
        $amountCents,
        $currency,
        $patronName,
        $intent->id
    ]);

    if ($stmt->rowCount() === 1) {
        $year  = (int)date('Y', $createdTs);
        $month = (int)date('n', $createdTs);
        $amountDecimal = $amountCents / 100;

        $db->prepare("
            INSERT INTO event_track_boost_stats_monthly
            (dj_user_id, year, month, currency, total_boosts_count, total_boosts_amount, updated_at)
            VALUES (?, ?, ?, ?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_boosts_count = total_boosts_count + 1,
                total_boosts_amount = total_boosts_amount + VALUES(total_boosts_amount),
                updated_at = NOW()
        ")->execute([
            $djUserId, $year, $month, $currency, $amountDecimal
        ]);

        $db->prepare("
            INSERT INTO event_track_boost_stats
            (event_id, dj_user_id, currency, total_boosts_count, total_boosts_amount, updated_at)
            VALUES (?, ?, ?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_boosts_count = total_boosts_count + 1,
                total_boosts_amount = total_boosts_amount + VALUES(total_boosts_amount),
                updated_at = NOW()
        ")->execute([
            $eventId, $djUserId, $currency, $amountDecimal
        ]);
    }

    http_response_code(200);
    exit;
}

http_response_code(200);
exit;