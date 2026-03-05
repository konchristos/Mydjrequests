<?php
require_once __DIR__ . '/../app/bootstrap.php';

$errors = '';
$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $errors = 'Invalid CSRF token.';
    } else {
        $auth = new AuthController();
        $res  = $auth->login($_POST);

        if (!empty($res['recovery'])) {
            redirect('account/update_email.php');
        } elseif ($res['success']) {
            redirect('dj/dashboard.php');
        } else {
            $errors = $res['message'] ?? 'Recovery failed.';
        }
    }
}

$pageTitle = 'Account Recovery';
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
    border-bottom: 1px solid rgba(149, 181, 216, 0.2);
    background: rgba(6, 12, 20, 0.78);
    backdrop-filter: blur(9px);
}

header.auth-topbar .topbar-inner {
    width: min(var(--wrap), calc(100% - 28px));
    margin: 0 auto;
    min-height: var(--header-h);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 8px 0;
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
    gap: 16px;
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

a {
    color: var(--brand) !important;
}

a:hover {
    color: #79ceff !important;
}

.status-error {
    color: #8dd8ff;
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

@media (max-width: 720px) {
    :root {
        --header-h: 52px;
    }

    header.auth-topbar .auth-nav a {
        font-size: 13px;
    }
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
                <a href="<?php echo mdjr_url('dj/login.php'); ?>">DJ Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<h1>Account Recovery</h1>

<div class="card">
    <?php if ($errors): ?>
        <p class="status-error"><?= e($errors); ?></p>
    <?php endif; ?>

    <form method="post" style="margin-top:14px;">
        <?php echo csrf_field(); ?>

        <label>Email:</label>
        <input type="email" name="email" required>

        <label>Recovery code:</label>
        <input type="text" name="recovery_code" required>

        <button type="submit">Recover account</button>
    </form>

    <p style="margin-top:10px;">
        <a href="<?php echo mdjr_url('dj/login.php'); ?>">← Back to Login</a>
    </p>
</div>

<footer class="auth-footer">
    <div class="auth-footer-inner">
        &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved. <a href="/privacy.php" style="color:inherit; text-decoration:underline;">Privacy</a>
    </div>
</footer>

<?php require __DIR__ . '/auth_footer.php'; ?>
