<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/config/stripe.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Stripe\Account;
use Stripe\Stripe;

require_dj_login();
Stripe::setApiKey(STRIPE_SECRET_KEY);

if (empty($_SESSION['dj_id'])) {
    exit('DJ session missing');
}

$djId = (int) $_SESSION['dj_id'];
$db = db();

// Fetch Stripe Connect account ID
$stmt = $db->prepare("
    SELECT stripe_connect_account_id
    FROM users
    WHERE id = ?
");
$stmt->execute([$djId]);
$stripeAccountId = $stmt->fetchColumn();

if ($stripeAccountId) {
    // Retrieve account from Stripe to confirm onboarding
    $account = Account::retrieve($stripeAccountId);

    if (!empty($account->details_submitted)) {
        $update = $db->prepare("
            UPDATE users
            SET stripe_connect_onboarded = 1
            WHERE id = ?
        ");
        $update->execute([$djId]);
    }
}

// Redirect back to dashboard
header('Location: /dj/dashboard.php?stripe=connected');
exit;
