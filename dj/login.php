<?php
//dj/login.php

require_once __DIR__ . '/../app/bootstrap.php';

$errors    = '';
$errorCode = null;
$retryIn = null;

$logoutReason = $_GET['reason'] ?? '';
$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;


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

<div class="auth-cyber-bg" aria-hidden="true">
    <video muted loop playsinline autoplay preload="auto">
        <source src="/assets/video/cyberpunk_night_city_loop.webm" type="video/webm">
        <source src="/assets/video/cyberpunk_night_city_loop.mp4" type="video/mp4">
    </video>
</div>
<div class="auth-cyber-overlay" aria-hidden="true"></div>


<style>
/* Blue/Cyan auth theme override for login page only */
:root {
    --bg: #060b12;
    --panel: rgba(12, 21, 33, 0.9);
    --line: rgba(149, 181, 216, 0.25);
    --text: #eef5ff;
    --muted: #9db1cb;
    --brand: #35b6ff;
    --brand-strong: #1e9fe8;
    --wrap: 1160px;
    --header-h: 56px;
}

body {
    background: #070f19 !important;
    color: var(--text) !important;
    font-family: "Manrope", system-ui, -apple-system, Segoe UI, sans-serif !important;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.auth-cyber-bg {
    position: fixed;
    inset: 0;
    z-index: -2;
    pointer-events: none;
}

.auth-cyber-bg video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: saturate(1.08) contrast(1.06) brightness(0.58);
}

.auth-cyber-overlay {
    position: fixed;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background:
        radial-gradient(circle at 15% -10%, rgba(53, 182, 255, 0.22), transparent 44%),
        radial-gradient(circle at 85% -12%, rgba(45, 210, 190, 0.16), transparent 46%),
        linear-gradient(180deg, rgba(7, 12, 20, 0.35) 0%, rgba(6, 10, 17, 0.9) 76%);
}

header.auth-topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 100;
    width: 100%;
    margin-left: 0;
    padding: 0;
    border-bottom: 1px solid rgba(149, 181, 216, 0.2);
    background: rgba(6, 12, 20, 0.78);
    backdrop-filter: blur(9px);
}

header.auth-topbar .topbar-inner {
    width: 100%;
    max-width: var(--wrap);
    padding: 0 14px;
    margin: 0 auto;
    min-height: var(--header-h);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

header.auth-topbar .nav-brand img {
    height: 30px;
    width: auto;
    display: block;
    opacity: 0.9;
}

header.auth-topbar .auth-nav {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

header.auth-topbar .auth-nav a {
    color: #c9ddf4 !important;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}

header.auth-topbar .auth-nav a:hover {
    color: var(--brand) !important;
}

.content {
    position: relative;
    z-index: 1;
    max-width: 600px !important;
    margin: 0 auto !important;
    padding-top: calc(var(--header-h) + 24px);
    padding-bottom: 84px;
}

h1 {
    color: var(--brand) !important;
    font-family: "Plus Jakarta Sans", "Manrope", sans-serif !important;
}

.card {
    background: var(--panel) !important;
    border: 1px solid var(--line) !important;
    border-radius: 12px !important;
}

label {
    color: #d2e4f8;
    font-weight: 600;
}

input {
    background: rgba(11, 19, 30, 0.76) !important;
    border: 1px solid var(--line) !important;
    color: var(--text) !important;
}

input:focus {
    border-color: var(--brand) !important;
    box-shadow: 0 0 0 3px rgba(53, 182, 255, 0.18);
}

button {
    background: linear-gradient(145deg, var(--brand), var(--brand-strong)) !important;
    color: #03243a !important;
    border: 1px solid rgba(80, 180, 240, 0.85) !important;
}

button:hover {
    background: linear-gradient(145deg, #48c0ff, #2aa7ee) !important;
}

a {
    color: var(--brand) !important;
}

a:hover {
    color: #79ceff !important;
}

/* Neon gradient resend button */
.btn-gradient-outline {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 14px;
    cursor: pointer;
    color: #e4f2ff;

    background-image:
        linear-gradient(#0b1320, #0b1320),
        linear-gradient(135deg, #35b6ff, #2dd4ff);
    background-origin: border-box;
    background-clip: padding-box, border-box;

    transition: all 0.3s ease;
}

.btn-gradient-outline:hover {
    background-image:
        linear-gradient(135deg, #35b6ff, #2dd4ff),
        linear-gradient(135deg, #35b6ff, #2dd4ff);
    color: #03243a;
    box-shadow:
        0 0 18px rgba(53, 182, 255, 0.55),
        0 0 24px rgba(45, 212, 255, 0.4);
    transform: translateY(-1px);
}

.btn-gradient-outline:active {
    transform: translateY(0);
    box-shadow: 0 0 10px rgba(53, 182, 255, 0.45);
}

.security-toast {
    background: rgba(53, 182, 255, 0.1);
    border: 1px solid rgba(53, 182, 255, 0.35);
    color: #8dd8ff;
    padding: 12px 14px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 14px;
    line-height: 1.4;
    box-shadow: 0 0 12px rgba(53, 182, 255, 0.15);
}

.security-toast .muted {
    color: var(--muted);
    font-size: 13px;
}

.status-success {
    color: #7de8bf;
}

.status-error {
    color: #8dd8ff;
}

.muted-copy {
    margin-top: 8px;
    font-size: 14px;
    color: var(--muted);
}

button[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: none;
}

@media (max-width: 720px) {
    :root {
        --header-h: 52px;
    }

    header.auth-topbar .topbar-inner {
        width: calc(100% - 24px);
        min-height: var(--header-h);
        align-items: center;
    }

    header.auth-topbar .auth-nav {
        gap: 10px;
    }

    header.auth-topbar .auth-nav a {
        font-size: 13px;
    }

    .content {
        padding-top: calc(var(--header-h) + 20px);
    }
}

.auth-footer {
    width: 100%;
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 14px 0 16px;
    border-top: 1px solid rgba(149, 181, 216, 0.22);
    color: #9ab1cb;
    background: rgba(7, 13, 22, 0.72);
    font-size: 13px;
    z-index: 2;
}

.auth-footer-inner {
    width: min(var(--wrap), calc(100% - 28px));
    margin: 0 auto;
    text-align: center;
}

</style>

<header class="auth-topbar">
    <div class="topbar-inner">
        <a href="/" class="nav-brand">
            <img src="/assets/logo/MYDJRequests_Logo-white.png" alt="MyDJRequests">
        </a>
        <nav class="auth-nav">
            <?php if ($loggedIn): ?>
                <a href="/dj/dashboard.php">Dashboard</a>
                <a href="/dj/events.php">My Events</a>
                <a href="/plans.php">Pro vs Premium</a>
                <a href="/about.php">About</a>
                <a href="/contact.php">Contact</a>
                <a href="/dj/terms.php">Terms</a>
                <?php if ($adminUser): ?>
                    <a href="/admin/dashboard.php">Admin</a>
                <?php endif; ?>
                <a href="/dj/logout.php">Logout</a>
            <?php else: ?>
                <a href="/plans.php">Pro vs Premium</a>
                <a href="/about.php">About</a>
                <a href="/contact.php">Contact</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

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
        <p class="status-success">
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

        <p class="status-error">
            <strong>You’re one step away from activating your account.</strong><br>
            Please verify your email address to continue.
        </p>

        <p class="muted-copy">
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

        <p class="status-error">
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

<footer class="auth-footer">
    <div class="auth-footer-inner">
        &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved. <a href="/privacy.php" style="color:inherit; text-decoration:underline;">Privacy</a>
    </div>
</footer>

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
