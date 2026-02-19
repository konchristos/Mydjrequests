<?php
require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once __DIR__ . '/../../app/config/stripe.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Stripe\Webhook;

function ensureWebhookTables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS stripe_webhook_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(255) NOT NULL UNIQUE,
            event_type VARCHAR(120) NOT NULL,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS stripe_disputes (
            dispute_id VARCHAR(255) NOT NULL PRIMARY KEY,
            charge_id VARCHAR(255) NULL,
            payment_intent_id VARCHAR(255) NULL,
            event_id BIGINT UNSIGNED NULL,
            dj_user_id BIGINT UNSIGNED NULL,
            payment_type ENUM('dj_tip','track_boost','unknown') NOT NULL DEFAULT 'unknown',
            currency CHAR(3) NULL,
            disputed_amount_cents INT NOT NULL DEFAULT 0,
            status VARCHAR(64) NULL,
            reason VARCHAR(64) NULL,
            evidence_due_by DATETIME NULL,
            funds_withdrawn TINYINT(1) NOT NULL DEFAULT 0,
            funds_reinstated TINYINT(1) NOT NULL DEFAULT 0,
            closed_at DATETIME NULL,
            last_webhook_event_id VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_dispute_payment_intent (payment_intent_id),
            KEY idx_dispute_event (event_id),
            KEY idx_dispute_dj (dj_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS stripe_dispute_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dispute_id VARCHAR(255) NOT NULL,
            webhook_event_id VARCHAR(255) NOT NULL,
            event_type VARCHAR(120) NOT NULL,
            amount_cents INT NOT NULL DEFAULT 0,
            currency CHAR(3) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dispute_webhook_event (dispute_id, webhook_event_id),
            KEY idx_dispute_events_dispute (dispute_id),
            KEY idx_dispute_events_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function markEventProcessed(PDO $db, string $eventId, string $eventType): bool
{
    $stmt = $db->prepare("
        INSERT IGNORE INTO stripe_webhook_events (event_id, event_type)
        VALUES (?, ?)
    ");
    $stmt->execute([$eventId, $eventType]);
    return $stmt->rowCount() === 1;
}

function resolvePaymentByIntent(PDO $db, string $paymentIntentId): ?array
{
    $tipStmt = $db->prepare("
        SELECT event_id, dj_user_id, amount_cents, currency
        FROM event_tips
        WHERE stripe_payment_intent_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $tipStmt->execute([$paymentIntentId]);
    $tip = $tipStmt->fetch(PDO::FETCH_ASSOC);
    if ($tip) {
        return [
            'payment_type' => 'dj_tip',
            'event_id' => (int)$tip['event_id'],
            'dj_user_id' => (int)$tip['dj_user_id'],
            'amount_cents' => (int)$tip['amount_cents'],
            'currency' => strtoupper((string)$tip['currency']),
        ];
    }

    $boostStmt = $db->prepare("
        SELECT event_id, dj_user_id, amount_cents, currency
        FROM event_track_boosts
        WHERE stripe_payment_intent_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $boostStmt->execute([$paymentIntentId]);
    $boost = $boostStmt->fetch(PDO::FETCH_ASSOC);
    if ($boost) {
        return [
            'payment_type' => 'track_boost',
            'event_id' => (int)$boost['event_id'],
            'dj_user_id' => (int)$boost['dj_user_id'],
            'amount_cents' => (int)$boost['amount_cents'],
            'currency' => strtoupper((string)$boost['currency']),
        ];
    }

    return null;
}

function applyDisputeAdjustment(
    PDO $db,
    string $paymentType,
    int $eventId,
    int $djUserId,
    string $currency,
    int $amountCents,
    int $countDelta,
    int $createdTs
): void {
    $amountDecimal = $amountCents / 100;
    $year = (int)date('Y', $createdTs);
    $month = (int)date('n', $createdTs);

    if ($paymentType === 'dj_tip') {
        $db->prepare("
            INSERT INTO event_tip_stats
            (event_id, dj_user_id, currency, total_tips_count, total_tips_amount, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_tips_count = total_tips_count + VALUES(total_tips_count),
                total_tips_amount = total_tips_amount + VALUES(total_tips_amount),
                updated_at = NOW()
        ")->execute([$eventId, $djUserId, $currency, $countDelta, $amountDecimal]);

        $db->prepare("
            INSERT INTO event_tip_stats_monthly
            (dj_user_id, year, month, currency, total_tips_count, total_tips_amount)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_tips_count = total_tips_count + VALUES(total_tips_count),
                total_tips_amount = total_tips_amount + VALUES(total_tips_amount)
        ")->execute([$djUserId, $year, $month, $currency, $countDelta, $amountDecimal]);
        return;
    }

    if ($paymentType === 'track_boost') {
        $db->prepare("
            INSERT INTO event_track_boost_stats
            (event_id, dj_user_id, currency, total_boosts_count, total_boosts_amount, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_boosts_count = total_boosts_count + VALUES(total_boosts_count),
                total_boosts_amount = total_boosts_amount + VALUES(total_boosts_amount),
                updated_at = NOW()
        ")->execute([$eventId, $djUserId, $currency, $countDelta, $amountDecimal]);

        $db->prepare("
            INSERT INTO event_track_boost_stats_monthly
            (dj_user_id, year, month, currency, total_boosts_count, total_boosts_amount, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                total_boosts_count = total_boosts_count + VALUES(total_boosts_count),
                total_boosts_amount = total_boosts_amount + VALUES(total_boosts_amount),
                updated_at = NOW()
        ")->execute([$djUserId, $year, $month, $currency, $countDelta, $amountDecimal]);
    }
}

function handlePaymentIntentSucceeded(PDO $db, object $event): void
{
    $intent = $event->data->object;
    $metadata = $intent->metadata ?? [];
    $paymentType = $metadata->type ?? null;

    if (!$paymentType) {
        return;
    }

    $djUserId   = (int)($metadata->dj_user_id ?? 0);
    $guestToken = $metadata->guest_token ?? null;
    $amountCents = (int)$intent->amount;
    $currency = strtoupper((string)$intent->currency);
    $createdTs = (int)$intent->created;

    $patronName = null;
    if (!empty($intent->metadata->guest_name)) {
        $patronName = trim((string)$intent->metadata->guest_name);
    } elseif (!empty($intent->charges->data[0]->billing_details->name)) {
        $patronName = (string)$intent->charges->data[0]->billing_details->name;
    }

    if (!$djUserId || $amountCents <= 0) {
        return;
    }

    if ($paymentType === 'dj_tip') {
        $eventUuid = $metadata->event_uuid ?? null;
        if (!$eventUuid) {
            return;
        }

        $stmt = $db->prepare("SELECT id FROM events WHERE uuid = ?");
        $stmt->execute([$eventUuid]);
        $eventId = (int)$stmt->fetchColumn();
        if (!$eventId) {
            return;
        }

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
            applyDisputeAdjustment(
                $db,
                'dj_tip',
                $eventId,
                $djUserId,
                $currency,
                $amountCents,
                1,
                $createdTs
            );
        }
        return;
    }

    if ($paymentType === 'track_boost') {
        $eventId  = (int)($metadata->event_id ?? 0);
        $trackKey = $metadata->track_key ?? null;
        if (!$eventId || !$trackKey) {
            return;
        }

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
            applyDisputeAdjustment(
                $db,
                'track_boost',
                $eventId,
                $djUserId,
                $currency,
                $amountCents,
                1,
                $createdTs
            );
        }
    }
}

function handleDisputeEvent(PDO $db, object $event): void
{
    $dispute = $event->data->object;
    $disputeId = (string)($dispute->id ?? '');
    if ($disputeId === '') {
        return;
    }

    $chargeId = (string)($dispute->charge ?? '');
    $paymentIntentId = (string)($dispute->payment_intent ?? '');
    $status = (string)($dispute->status ?? '');
    $reason = (string)($dispute->reason ?? '');
    $currency = strtoupper((string)($dispute->currency ?? ''));
    $disputedAmountCents = (int)($dispute->amount ?? 0);
    $createdTs = (int)($event->created ?? time());

    $evidenceDueBy = null;
    if (!empty($dispute->evidence_details->due_by)) {
        $dueTs = (int)$dispute->evidence_details->due_by;
        if ($dueTs > 0) {
            $evidenceDueBy = gmdate('Y-m-d H:i:s', $dueTs);
        }
    }

    $payment = null;
    $paymentType = 'unknown';
    $eventId = null;
    $djUserId = null;
    $baseAmountCents = 0;
    $paymentCurrency = $currency;

    if ($paymentIntentId !== '') {
        $payment = resolvePaymentByIntent($db, $paymentIntentId);
    }
    if ($payment) {
        $paymentType = (string)$payment['payment_type'];
        $eventId = (int)$payment['event_id'];
        $djUserId = (int)$payment['dj_user_id'];
        $baseAmountCents = (int)$payment['amount_cents'];
        $paymentCurrency = (string)$payment['currency'];
        if ($paymentCurrency === '' && $currency !== '') {
            $paymentCurrency = $currency;
        }
    }

    $closedAt = null;
    if (in_array($status, ['won', 'lost', 'warning_closed'], true)) {
        $closedAt = gmdate('Y-m-d H:i:s');
    }

    $upsert = $db->prepare("
        INSERT INTO stripe_disputes (
            dispute_id,
            charge_id,
            payment_intent_id,
            event_id,
            dj_user_id,
            payment_type,
            currency,
            disputed_amount_cents,
            status,
            reason,
            evidence_due_by,
            closed_at,
            last_webhook_event_id
        ) VALUES (
            :dispute_id,
            :charge_id,
            :payment_intent_id,
            :event_id,
            :dj_user_id,
            :payment_type,
            :currency,
            :disputed_amount_cents,
            :status,
            :reason,
            :evidence_due_by,
            :closed_at,
            :last_webhook_event_id
        )
        ON DUPLICATE KEY UPDATE
            charge_id = VALUES(charge_id),
            payment_intent_id = VALUES(payment_intent_id),
            event_id = COALESCE(VALUES(event_id), event_id),
            dj_user_id = COALESCE(VALUES(dj_user_id), dj_user_id),
            payment_type = CASE
                WHEN stripe_disputes.payment_type = 'unknown' THEN VALUES(payment_type)
                ELSE stripe_disputes.payment_type
            END,
            currency = COALESCE(VALUES(currency), currency),
            disputed_amount_cents = VALUES(disputed_amount_cents),
            status = VALUES(status),
            reason = VALUES(reason),
            evidence_due_by = VALUES(evidence_due_by),
            closed_at = COALESCE(VALUES(closed_at), closed_at),
            last_webhook_event_id = VALUES(last_webhook_event_id),
            updated_at = CURRENT_TIMESTAMP
    ");
    $upsert->execute([
        ':dispute_id' => $disputeId,
        ':charge_id' => ($chargeId !== '' ? $chargeId : null),
        ':payment_intent_id' => ($paymentIntentId !== '' ? $paymentIntentId : null),
        ':event_id' => $eventId,
        ':dj_user_id' => $djUserId,
        ':payment_type' => $paymentType,
        ':currency' => ($paymentCurrency !== '' ? $paymentCurrency : null),
        ':disputed_amount_cents' => $disputedAmountCents,
        ':status' => ($status !== '' ? $status : null),
        ':reason' => ($reason !== '' ? $reason : null),
        ':evidence_due_by' => $evidenceDueBy,
        ':closed_at' => $closedAt,
        ':last_webhook_event_id' => $event->id,
    ]);

    $disputeEventStmt = $db->prepare("
        INSERT IGNORE INTO stripe_dispute_events (
            dispute_id,
            webhook_event_id,
            event_type,
            amount_cents,
            currency
        ) VALUES (?, ?, ?, ?, ?)
    ");
    $disputeEventStmt->execute([
        $disputeId,
        (string)$event->id,
        (string)$event->type,
        $disputedAmountCents,
        ($paymentCurrency !== '' ? $paymentCurrency : null)
    ]);

    if (!in_array($event->type, ['charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'], true)) {
        return;
    }

    if (!$payment || !$eventId || !$djUserId || !in_array($paymentType, ['dj_tip', 'track_boost'], true)) {
        return;
    }

    $direction = ($event->type === 'charge.dispute.funds_withdrawn') ? -1 : 1;
    $adjustAmountCents = max(0, $disputedAmountCents) * $direction;

    $countDelta = 0;
    if ($baseAmountCents > 0 && $disputedAmountCents >= $baseAmountCents) {
        $countDelta = $direction;
    }

    applyDisputeAdjustment(
        $db,
        $paymentType,
        $eventId,
        $djUserId,
        $paymentCurrency,
        $adjustAmountCents,
        $countDelta,
        $createdTs
    );

    if ($event->type === 'charge.dispute.funds_withdrawn') {
        $db->prepare("
            UPDATE stripe_disputes
            SET funds_withdrawn = 1
            WHERE dispute_id = ?
        ")->execute([$disputeId]);
    } else {
        $db->prepare("
            UPDATE stripe_disputes
            SET funds_reinstated = 1
            WHERE dispute_id = ?
        ")->execute([$disputeId]);
    }
}

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

$db = db();
ensureWebhookTables($db);

if (!markEventProcessed($db, (string)$event->id, (string)$event->type)) {
    http_response_code(200);
    exit;
}

if ($event->type === 'payment_intent.succeeded') {
    handlePaymentIntentSucceeded($db, $event);
    http_response_code(200);
    exit;
}

if (strpos((string)$event->type, 'charge.dispute.') === 0) {
    handleDisputeEvent($db, $event);
    http_response_code(200);
    exit;
}

http_response_code(200);
exit;
