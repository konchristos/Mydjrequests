<?php
//dj/login.php

require_once __DIR__ . '/../app/bootstrap.php';

$errors    = '';
$errorCode = null;
$retryIn = null;

$logoutReason = $_GET['reason'] ?? '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf_token()) {
        $errors = 'Invalid CSRF token.';
    } else {
        $auth = new AuthController();
        $res  = $auth->login($_POST);

        if (!empty($res['requires_2fa'])) {
    redirect('dj/verify_2fa_email.php');
} elseif ($res['success']) {
    redirect('dj/dashboard.php');
} 
        
        else {
            $errors    = $res['message'] ?? 'Login failed.';
            $errorCode = $res['code'] ?? null;
            $retryIn   = $res['retry_in'] ?? null;

        }
    }
}
?>

<?php
$pageTitle = "DJ Login";
require __DIR__ . '/auth_layout.php';

?>


<div style="text-align:center;margin-bottom:20px;">
    <a href="<?php echo mdjr_url('/'); ?>">
        <img
            src="/assets/logo/MYDJRequests_Logo-white.png"
            alt="MyDJRequests"
            style="height:36px;width:auto;opacity:0.9;"
        >
    </a>
</div>

<style>
/* Neon gradient resend button */
.btn-gradient-outline {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 14px;
    cursor: pointer;
    color: #ffffff;

    background-image:
        linear-gradient(#0b0b0f, #0b0b0f),
        linear-gradient(135deg, #ff2fd2, #2dd4ff);
    background-origin: border-box;
    background-clip: padding-box, border-box;

    transition: all 0.3s ease;
}

.btn-gradient-outline:hover {
    background-image:
        linear-gradient(135deg, #ff2fd2, #2dd4ff),
        linear-gradient(135deg, #ff2fd2, #2dd4ff);
    color: #0b0b0f;
    box-shadow:
        0 0 18px rgba(255, 47, 210, 0.6),
        0 0 24px rgba(45, 212, 255, 0.45);
    transform: translateY(-1px);
}

.btn-gradient-outline:active {
    transform: translateY(0);
    box-shadow: 0 0 10px rgba(255, 47, 210, 0.5);
}

.security-toast {
    background: rgba(255, 47, 210, 0.08);
    border: 1px solid rgba(255, 47, 210, 0.35);
    color: #ff2fd2;
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 14px;
    line-height: 1.4;
    box-shadow: 0 0 12px rgba(255, 47, 210, 0.15);
}

.security-toast .muted {
    color: #aaa;
    font-size: 13px;
}

button[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: none;
}

</style>

<h1>DJ Login</h1>

<div class="card">

    <?php if ($logoutReason === 'logged_out_elsewhere'): ?>
        <div class="security-toast">
            <strong>🔐 Security notice</strong><br>
            You were logged out because your account was accessed on another device.
            <br>
            <span class="muted">
                For security reasons, only one active session is allowed at a time.
            </span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['resent'])): ?>
        <p style="color:#6ee7b7;">
            ✅ We’ve sent a new verification email.  
            Please check your inbox (and spam folder just in case).
        </p>
    <?php endif; ?>

<?php if ($errors): ?>

    <?php if ($errorCode === 'ACCOUNT_LOCKED' && $retryIn !== null): ?>

        <div class="security-toast">
            <strong>🔒 Account temporarily locked</strong><br>
            Too many failed login attempts.

            <div class="muted" style="margin-top:6px;">
                Try again in
                <strong><span id="lockout-timer"></span></strong>
            </div>
        </div>

    <?php elseif ($errorCode === 'EMAIL_NOT_VERIFIED'): ?>

        <p style="color:#ff4ae0;">
            <strong>You’re one step away from activating your account.</strong><br>
            Please verify your email address to continue.
        </p>

        <p style="margin-top:8px;font-size:14px;color:#aaa;">
            Check your inbox for the verification email sent during registration,
            or resend it below.
        </p>

        <form
            method="post"
            action="<?= mdjr_url('dj/resend_verification.php'); ?>"
            style="margin-top:10px;"
        >
            <?= csrf_field(); ?>
            <input
                type="hidden"
                name="email"
                value="<?= e($_POST['email'] ?? ''); ?>"
            >

            <button type="submit" class="btn-gradient-outline">
                Resend verification email
            </button>
        </form>

    <?php else: ?>

        <p style="color:#ff4ae0;">
            <?= e($errors); ?>
        </p>

    <?php endif; ?>

<?php endif; ?>

    <form method="post" style="margin-top:14px;">
        <?php echo csrf_field(); ?>

        <label>Email:</label>
        <input
            type="email"
            name="email"
            required
            value="<?php echo e($_POST['email'] ?? ''); ?>"
        >

        <label>Password:</label>
        <input type="password" name="password" required>

        <button
            type="submit"
            id="login-btn"
            <?php if ($errorCode === 'ACCOUNT_LOCKED'): ?>disabled<?php endif; ?>
        >
            Login
        </button>
    </form>

    <p style="margin-top:10px;">
        <a href="<?php echo mdjr_url('dj/forgot_password.php'); ?>">
            Forgot your password?
        </a>
    </p>


    <p>
        Don’t have an account?
        <a href="<?php echo mdjr_url('dj/register.php'); ?>">Register</a>
    </p>

</div>

</div> <!-- end .card -->

<?php if ($errorCode === 'ACCOUNT_LOCKED' && $retryIn !== null): ?>
<script>
(function () {
    let remaining = <?= (int)$retryIn ?>;
    const timerEl = document.getElementById('lockout-timer');
    const btn = document.getElementById('login-btn');

    if (!timerEl) return;

    const interval = setInterval(() => {
        remaining--;

        if (remaining <= 0) {
            clearInterval(interval);
            timerEl.textContent = 'now';
            if (btn) btn.disabled = false;
        } else {
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            timerEl.textContent =
                mins > 0
                    ? `${mins}m ${secs}s`
                    : `${secs}s`;
        }
    }, 1000);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/auth_footer.php'; ?>
