<?php
// dj/terms.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$userId = (int)($_SESSION['dj_id'] ?? 0);
$userModel = new User();
$user = $userModel->findById($userId);

$config = file_exists(APP_ROOT . '/app/config/subscriptions.php')
    ? require APP_ROOT . '/app/config/subscriptions.php'
    : [];

$termsVersion = (string)($config['terms_version'] ?? 'v1');
$acceptedVersion = (string)($user['terms_accepted_version'] ?? '');
$hasAccepted = ($acceptedVersion === $termsVersion);
$termsTitle = 'Terms and Conditions';

$error = '';

// Safe return path
$returnTo = $_GET['return'] ?? '/dj/dashboard.php';
if (!is_string($returnTo) || $returnTo === '' || $returnTo[0] !== '/') {
    $returnTo = '/dj/dashboard.php';
}

// Privacy Policy URL (public page)
$privacyUrl = '/privacy.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($hasAccepted) {
        redirect(ltrim($returnTo, '/'));
        exit;
    }

    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $userModel->acceptTerms($userId, $termsVersion);
        redirect(ltrim($returnTo, '/'));
        exit;
    }
}

$pageTitle = $termsTitle;
require __DIR__ . '/layout.php';
?>

<style>
.terms-wrap {
    max-width: 760px;
    margin: 0 auto;
}

.terms-card {
    background: #111116;
    border: 1px solid #1f1f29;
    border-radius: 12px;
    padding: 20px;
}

.terms-card h1 {
    margin-top: 0;
    margin-bottom: 8px;
}

.terms-card h2 {
    margin-top: 18px;
    margin-bottom: 8px;
}

.terms-card .meta {
    color: #aaa;
    font-size: 13px;
    margin-bottom: 16px;
}

.terms-card p,
.terms-card li {
    color: #d7d7df;
    line-height: 1.6;
}

.terms-actions {
    margin-top: 18px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.terms-actions input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #ff2fd2;
}

.btn-primary {
    background: #ff2fd2;
    color: #fff;
    border: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.error {
    color: #ff8080;
    margin: 10px 0 0;
}

a.terms-link {
    color: #ff7be6;
    text-decoration: none;
}
a.terms-link:hover {
    text-decoration: underline;
}
</style>

<div class="terms-wrap">
    <div class="terms-card">
        <h1><?php echo e($termsTitle); ?></h1>
        <div class="meta">Version: <?php echo e($termsVersion); ?></div>



<p>Welcome to MYDJREQUESTS. By creating an account or using the Service, you agree to these Terms.</p>
<p class="meta"><strong>Last updated:</strong> February 8, 2026</p>

<h2>1. About These Terms</h2>
<p>
    These Terms govern your access to and use of the MYDJREQUESTS platform (“Service”),
    operated by MYDJREQUESTS (ABN 22 842 315 565) (“we”, “us”, “our”).
    If you do not agree, do not use the Service.
</p>

<h2>2. Definitions</h2>
<ul>
    <li><strong>Service</strong> means the MYDJREQUESTS website, applications, and related services.</li>
    <li><strong>DJ</strong> means a registered user who creates or manages events.</li>
    <li><strong>Patron / Guest</strong> means any person submitting a request, message, vote, or payment.</li>
    <li><strong>Event</strong> means a DJ-created live or virtual event.</li>
    <li><strong>Content</strong> means all material submitted through the Service.</li>
    <li><strong>Tips / Boosts</strong> mean voluntary payments (when enabled).</li>
</ul>

<h2>3. Availability and Territory</h2>
<p>
    The Service is available globally. You are responsible for ensuring your use complies
    with applicable laws in your jurisdiction.
</p>

<h2>4. Eligibility</h2>
<p>
    You must be at least 13 years old to use the Service. If under 18, a parent or guardian
    must consent and is responsible for your use.
</p>

<h2>5. Accounts and Security</h2>
<p>
    You are responsible for safeguarding your account credentials and all activity
    conducted under your account.
</p>

<h2>6. The Service</h2>
<p>
    The Service allows DJs to create profiles, manage events, and receive requests or
    messages. Features may change at any time.
</p>

<h2>7. Free Access and Paid Features</h2>
<p>
    Access to certain features of the Service is currently provided at no cost.
    We may introduce paid features, platform fees, subscriptions, or usage-based
    charges at any time, including fees deducted from tips, boosts, or other
    transactions, with prior notice.
</p>
<p>
    Where paid features or fees apply, we will disclose the applicable pricing
    or fee structure before it applies.
</p> 
   
<p>   
    Continued use of the affected paid features after such disclosure constitutes acceptance
of the applicable fees. If you do not agree, you must stop using those paid features.
</p>

<h2>8. Acceptable Use</h2>
<p>You must not:</p>
<ul>
    <li>Use the Service unlawfully or abusively;</li>
    <li>Submit defamatory, misleading, or infringing content;</li>
    <li>Attempt unauthorised access;</li>
    <li>Interfere with system integrity.</li>
</ul>

<h2>9. Content Responsibility</h2>
<p>
    You are responsible for all Content you submit. We may remove Content that breaches
    these Terms or the law.
</p>

<h2>10. Requests and Messaging</h2>
<p>
    Requests are not guarantees. DJs are not required to play any song or fulfil any request.
</p>

<h2>11. Payments, Tips, Boosts & Chargebacks (When Enabled)</h2>


<p>
    Paid features are not yet active. When enabled, payments will be processed via third-party
    providers such as Stripe.
</p>

<p>
    <strong>Platform fees.</strong> When paid features are enabled, we may charge
    a platform fee, including a percentage-based fee deducted from tips, boosts,
    or other transactions processed through the Service. Any applicable platform
    fees will be disclosed before they apply.
</p>

<p>
    Applicable fees will be displayed or made available to DJs through the Service
    before a transaction is processed.
</p>

<p>
    Tips and boosts are voluntary, non-refundable by default, and do not guarantee any outcome,
    except where a refund is required by law.
</p>

<p>
    <strong>Chargebacks and disputes.</strong> Payment disputes and chargebacks are handled
    under the rules of the payment provider and card networks. We may share transaction and
    usage data to respond to disputes.
</p>

<p>
    <strong>DJ responsibility.</strong> Where a chargeback relates to a DJ’s event, content,
    conduct, or representations, the DJ is responsible to us for the transaction and for any
    chargeback amount, reversal, or dispute fee incurred by us, to the extent permitted by law.
</p>

<p>
    You authorise us to <strong>set off</strong> or recover such amounts from sums otherwise
    payable to you, or to require reimbursement where no such amounts are available.
    We may suspend paid features while disputes are pending.
</p>

<p>
    Nothing in this section excludes, restricts, or modifies any rights you may have
under the Australian Consumer Law that cannot be excluded, restricted, or modified.
</p>

<h2>12. Intellectual Property</h2>
<p>
    We own the Service and grant you a limited licence to use it. You grant us a licence to
    host and display your Content for Service operation.
</p>

<h2>13. No Agency</h2>
<p>
    DJs are independent operators. No employment, agency, or partnership exists.
</p>

<h2>14. Privacy</h2>
<p>
    Use of the Service is subject to our
    <a class="terms-link" href="<?php echo e($privacyUrl); ?>">Privacy Policy</a>.
</p>

<h2>15. Termination</h2>
<p>
    We may suspend or terminate access for breaches or risk to the Service.
</p>

<h2>16. Disclaimers</h2>
<p>
    The Service is provided “as is” and “as available”.
</p>

<h2>17. Software Bugs and Maintenance</h2>
<p>
    The Service may contain bugs or defects. We do not guarantee fixes within any timeframe.
    Maintenance may cause temporary unavailability. Live event issues may not be resolvable
    in real time.
</p>

<h2>18. Live Events, Connectivity & Force Majeure</h2>
<p>
    Service availability depends on internet connectivity, venue Wi-Fi, mobile networks,
    devices, and third-party services.
</p>
<p>
    We are not responsible for failures caused by events beyond our reasonable control,
    including network outages, third-party failures (including Stripe), or acts of God.
</p>

<h2>19. Limitation of Liability</h2>
<p>
    To the maximum extent permitted by law, we are not liable for indirect or consequential loss.
    Where liability cannot be excluded, it is limited to resupplying the Service.
</p>

<h2>20. Indemnity</h2>
<p>
    You indemnify us against claims arising from your breach or misuse.
</p>

<h2>21. Changes</h2>
<p>
    We may update these Terms. Continued use constitutes acceptance.
</p>

<h2>22. Governing Law</h2>
<p>
    These Terms are governed by the laws of Victoria, Australia.
</p>

<h2>23. Contact</h2>
<p>
    Contact: <a class="terms-link" href="mailto:info@mydjrequests.com">info@mydjrequests.com</a>
</p>



<hr>

<h2>Stripe Connect Addendum (Future Paid Features)</h2>

<p>
    This Addendum applies if and when MYDJREQUESTS enables Stripe Connect or similar
    payment-splitting services.
</p>

<p>
    DJs acknowledge that MYDJREQUESTS acts as the platform operator and that Stripe
    processes payments on our behalf. Stripe may debit our platform account for
    chargebacks, disputes, refunds, or fees.
</p>

<p>
    DJs authorise us to recover such amounts from DJ balances, apply rolling reserves,
    delay payouts, or require reimbursement where necessary to manage payment risk,
    to the extent permitted by law.
</p>

<p>
    DJs must comply with Stripe’s applicable terms and onboarding requirements when
    paid features are enabled.
</p>

<p>
    This Addendum does not limit rights under the Australian Consumer Law.
</p>



        <?php if ($error): ?>
            <div class="error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if (!$hasAccepted): ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="terms-actions">
                    <input type="checkbox" id="agree" name="agree" value="1" required>
                    <label for="agree">I agree to the Terms and Conditions</label>
                </div>

                <div class="terms-actions" style="margin-top:14px;">
                    <button type="submit" class="btn-primary">Accept and Continue</button>
                </div>
            </form>
        <?php else: ?>
            <p class="meta">By continuing to use the Service, you confirm your acceptance of these Terms.</p>
        <?php endif; ?>
    </div>
</div>