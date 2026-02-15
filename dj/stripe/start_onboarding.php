<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/config/stripe.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // ✅ ADD THIS

use Stripe\Stripe;          // ✅ ADD
use Stripe\Account;
use Stripe\AccountLink;

Stripe::setApiKey(STRIPE_SECRET_KEY); // ✅ ADD THIS LINE

require_dj_login();

if (empty($_SESSION['dj_id'])) {
    exit('DJ session missing');
}

$djId = (int) $_SESSION['dj_id'];
$db = db();

// Fetch existing Stripe Connect account (if any)
$stmt = $db->prepare("
    SELECT stripe_connect_account_id
    FROM users
    WHERE id = ?
");
$stmt->execute([$djId]);
$stripeAccountId = $stmt->fetchColumn();

// If no Stripe account yet, create one
if (empty($stripeAccountId)) {

    $account = Account::create([
        'type'    => 'express',
        'country' => 'AU',
        'email'   => $_SESSION['dj_email'] ?? null,
        'capabilities' => [
            'card_payments' => ['requested' => true],
            'transfers'     => ['requested' => true],
        ],
    ]);

    $stripeAccountId = $account->id;

    $update = $db->prepare("
        UPDATE users
        SET stripe_connect_account_id = ?
        WHERE id = ?
    ");
    $update->execute([$stripeAccountId, $djId]);
}

// Create onboarding link
$link = AccountLink::create([
    'account'     => $stripeAccountId,
    'refresh_url' => 'https://mydjrequests.com/dj/stripe/refresh.php',
    'return_url'  => 'https://mydjrequests.com/dj/stripe/return.php',
    'type'        => 'account_onboarding',
]);

header('Location: ' . $link->url);
exit;