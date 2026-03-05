<?php
//dj/verify_2fa_email.php
require_once __DIR__ . '/../app/bootstrap.php';

// Must be mid-login
if (empty($_SESSION['pending_2fa_user'])) {
    redirect('dj/login.php');
    exit;
}

$userId = (int)$_SESSION['pending_2fa_user'];

$userModel = new User();
$user = $userModel->findById($userId);

if (!$user) {
    $_SESSION = [];
    redirect('dj/login.php');
    exit;
}

$error   = '';
$success = false;
$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;

// Pull flash error from redirect (resend cooldown, etc)
if (!empty($_SESSION['2fa_flash_error'])) {
    $error = $_SESSION['2fa_flash_error'];
    unset($_SESSION['2fa_flash_error']);
}


// Handle resend (with cooldown)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {

    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh.';
    } else {

        $now = time();
        $cooldownSeconds = 60;
        $lastSent = $_SESSION['last_2fa_email_sent'] ?? 0;

        if (($now - $lastSent) < $cooldownSeconds) {
            $remaining = $cooldownSeconds - ($now - $lastSent);
            $_SESSION['2fa_flash_error'] =
                "Please wait {$remaining} seconds before resending the code.";
        } else {
            require_once APP_ROOT . '/app/helpers/email_2fa.php';
            send_email_2fa_code($userId, $user);
            $_SESSION['last_2fa_email_sent'] = $now;
        }

        // 🔁 REDIRECT after POST
        redirect('dj/verify_2fa_email.php');
        exit;
    }
}


// Cooldown state for UI
$cooldownSeconds = 60;
$lastSent = $_SESSION['last_2fa_email_sent'] ?? 0;
$remainingCooldown = max(0, $cooldownSeconds - (time() - $lastSent));


// Handle verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {

    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh.';
    } else {

        $code = trim($_POST['code'] ?? '');

        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Please enter the 6-digit code.';
        } else {

            $db = db();
            $stmt = $db->prepare("
                SELECT * FROM user_2fa_codes
                WHERE user_id = ? AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $error = 'This code has expired. Please request a new one.';
            } elseif ($row['attempts'] >= 5) {
                $error = 'Too many attempts. Please log in again.';
            } elseif (!password_verify($code, $row['code_hash'])) {

                $stmt = $db->prepare("
                    UPDATE user_2fa_codes
                    SET attempts = attempts + 1
                    WHERE id = ?
                ");
                $stmt->execute([$row['id']]);

                $error = 'Invalid verification code.';
            } else {
                // ✅ SUCCESS — FINALISE LOGIN (SINGLE SESSION)
                $stmt = $db->prepare("DELETE FROM user_2fa_codes WHERE user_id = ?");
                $stmt->execute([$userId]);
            
                $sessionId = bin2hex(random_bytes(32));
            
                $_SESSION['dj_id']      = (int)$user['id'];
                $_SESSION['dj_email']   = $user['email'];
                $_SESSION['dj_name']    = $user['name'];
                $_SESSION['dj_alias']   = $user['dj_name'] ?? '';
                $_SESSION['session_id'] = $sessionId;
            
                // 🔥 Kill other sessions
                $userModel->updateActiveSession((int)$user['id'], $sessionId);
                
                
                // 🧾 LOGIN AUDIT — untrusted device (2FA completed)
                require_once APP_ROOT . '/app/helpers/login_audit.php';
                mdjr_log_login(
                    (int)$user['id'],
                    false,
                    $sessionId
                );

                // 🔐 Remember trusted device (OPTIONAL)
                if (!empty($_POST['remember_device'])) {
                    require_once APP_ROOT . '/app/helpers/trusted_device.php';
                    remember_device((int)$user['id'], 30);
                }
            
                unset($_SESSION['pending_2fa_user']);
                unset($_SESSION['last_2fa_email_sent']);
            
                redirect('dj/dashboard.php');
                exit;
            }
        }
    }
}

$pageTitle = 'Verify Login';
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
    max-width: 620px !important;
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

.muted-copy {
    color: var(--muted);
    font-size: 14px;
    margin-bottom: 12px;
}

.btn-gradient-outline[disabled] {
    opacity: 0.45;
    cursor: not-allowed;
    box-shadow: none;
    background-image:
        linear-gradient(#0b1320, #0b1320),
        linear-gradient(135deg, #6885a2, #8aa0b8);
    color: #b5c7dc;
}


.remember-device {
    display: flex;
    align-items: center;
    gap: 10px;

    margin-top: 12px;
    margin-bottom: 18px;

    color: #c1d3e7;
    font-size: 14px;

    user-select: none;
}

/* 🔥 override global label styles */
.card label.remember-device {
    display: flex;
}

.remember-device input {
    margin: 0;
    width: 16px;
    height: 16px;
    accent-color: #35b6ff;
}

.remember-device span {
    line-height: 1.2;
}


.code-input {
    width: 220px;
    letter-spacing: 0.3em;
    text-align: center;
    font-size: 18px;

    margin-top: 6px;
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

<h1>Verify your login</h1>

<div class="card">

    <p class="muted-copy">
        We’ve sent a 6-digit verification code to:<br>
        <strong><?php echo htmlspecialchars($user['email']); ?></strong>
    </p>

    <?php if ($error): ?>
        <p class="status-error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post" style="margin-top:14px;">
        <?php echo csrf_field(); ?>

        <label>Verification code</label>
<input
    type="text"
    name="code"
    class="code-input"
    inputmode="numeric"
    pattern="[0-9]{6}"
    maxlength="6"
    placeholder="123456"
    required
    autofocus
>


<label class="remember-device">
    <input type="checkbox" name="remember_device" value="1">
    <span>Remember this device for 30 days</span>
</label>

        <button type="submit">Verify</button>
    </form>


<form method="post" id="resend-form" style="margin-top:12px;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="resend" value="1">

    <button
        type="submit"
        id="resend-btn"
        class="btn-gradient-outline"
        <?php if ($remainingCooldown > 0): ?>disabled<?php endif; ?>
    >
        <?php if ($remainingCooldown > 0): ?>
            Resend available in <span id="cooldown"><?= $remainingCooldown ?></span>s
        <?php else: ?>
            Resend code
        <?php endif; ?>
    </button>
</form>



    <p style="margin-top:14px;font-size:14px;">
        <a href="<?php echo mdjr_url('dj/logout.php'); ?>">
            Cancel & log out
        </a>
    </p>

</div>

<footer class="auth-footer">
    <div class="auth-footer-inner">
        &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved. <a href="/privacy.php" style="color:inherit; text-decoration:underline;">Privacy</a>
    </div>
</footer>


<?php if ($remainingCooldown > 0): ?>
<script>
(function () {
    let remaining = <?= (int)$remainingCooldown ?>;
    const btn = document.getElementById('resend-btn');
    const span = document.getElementById('cooldown');

    if (!btn || !span) return;

    const timer = setInterval(() => {
        remaining--;

        if (remaining <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            btn.textContent = 'Resend code';
        } else {
            span.textContent = remaining;
        }
    }, 1000);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/auth_footer.php'; ?>
