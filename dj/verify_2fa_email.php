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
.btn-gradient-outline[disabled] {
    opacity: 0.45;
    cursor: not-allowed;
    box-shadow: none;
    background-image:
        linear-gradient(#0b0b0f, #0b0b0f),
        linear-gradient(135deg, #555, #777);
    color: #aaa;
}


.remember-device {
    display: flex;
    align-items: center;
    gap: 10px;

    margin-top: 12px;
    margin-bottom: 18px;

    color: #aaa;
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
    accent-color: #ff2fd2;
}

.remember-device span {
    line-height: 1.2;
}


.code-input {
    width: 220px;              /* perfect for 6 digits */
    letter-spacing: 0.3em;     /* optional, feels “auth-code-ish” */
    text-align: center;
    font-size: 18px;

    margin-top: 6px;
}

</style>

<h1>Verify your login</h1>

<div class="card">

    <p class="muted" style="margin-bottom:12px;">
        We’ve sent a 6-digit verification code to:<br>
        <strong><?php echo htmlspecialchars($user['email']); ?></strong>
    </p>

    <?php if ($error): ?>
        <p style="color:#ff4ae0;"><?php echo htmlspecialchars($error); ?></p>
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
