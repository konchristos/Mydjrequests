<?php
//dj/verify_email.php
require_once __DIR__ . '/../app/bootstrap.php';

$token   = trim($_GET['token'] ?? '');
$message = '';
$success = false;

$userModel = new User();

if ($token !== '') {
    $user = $userModel->findByEmailVerificationToken($token);

    if ($user) {
        $userModel->markEmailVerified((int)$user['id']);
        $success = true;
    } else {
        $message = 'This verification link is no longer valid.';
    }
} else {
    $message = 'Missing verification token.';
}

$pageTitle = 'Verify Email';
require __DIR__ . '/auth_layout.php';
?>


<div style="text-align:center;margin-bottom:20px;">
    <a href="<?php echo mdjr_url('/'); ?>">
        <img
            src="/assets/logo/MYDJRequests_Logo-white.png"
            alt="MyDJRequests"
            style="height:40px;width:auto;opacity:0.95;"
        >
    </a>
</div>



<h1>Email Verification</h1>

<div class="card">

<?php if ($success): ?>

    <p>🎉 <strong>Welcome to the MyDJRequests family!</strong></p>

    <p style="margin-top:10px;">
        Your email has been verified successfully and your account is now active.
    </p>

    <p style="margin-top:10px;color:#bbb;">
        You can now log in, set up your DJ profile, and start taking song requests.
    </p>

    <p style="margin-top:20px;">
        <a href="<?php echo mdjr_url('dj/login.php'); ?>" class="btn-primary">
            Proceed to Login
        </a>
    </p>

<?php else: ?>

    <p style="color:#ff4ae0;">
        <?php echo e($message); ?>
    </p>

    <p style="margin-top:10px;color:#bbb;">
        This can happen if the link was already used or has expired.
    </p>

    <div style="margin-top:20px;">
        <a href="<?php echo mdjr_url('dj/login.php'); ?>" class="btn-primary">
            Go to Login
        </a>
    </div>

    <p style="margin-top:14px;font-size:14px;color:#aaa;">
        Still need to verify?
        <a href="<?php echo mdjr_url('dj/login.php'); ?>">
            You can resend the verification email from the login page.
        </a>
    </p>

<?php endif; ?>

</div>

<?php require __DIR__ . '/auth_footer.php'; ?>