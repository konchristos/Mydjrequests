<?php
//dj/reset_password.php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/mail.php';
require_once __DIR__ . '/../app/helpers/user_display_name.php';

$userModel = new User();

$token = $_GET['token'] ?? '';
$token = is_string($token) ? trim($token) : '';

$errors   = '';
$success  = false;
$userData = null;

if ($token !== '') {
    $userData = $userModel->findByResetToken($token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $errors = 'Invalid CSRF token.';
    } else {
        $token = trim($_POST['token'] ?? '');
        $userData = $userModel->findByResetToken($token);

        if (!$userData) {
            $errors = 'This reset link is invalid or has expired.';
        } else {
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['password_confirm'] ?? '';

            if ($password === '' || $confirm === '') {
                $errors = 'Please enter and confirm your new password.';
            } elseif ($password !== $confirm) {
                $errors = 'Passwords do not match.';
            } elseif (strlen($password) < 8) {
                $errors = 'Password must be at least 8 characters long.';
            } else {
                
                
                $ok = $userModel->updatePassword((int)$userData['id'], $password);

if ($ok) {
    // Send confirmation email (non-blocking)
    $displayName = mdjr_display_name($userData);
    $email       = $userData['email'];

    $subject = 'Your MyDJRequests password has been reset';

    $html = "
        <p>Hi " . htmlspecialchars($displayName) . ",</p>
        <p>This is a confirmation that your <strong>MyDJRequests</strong> password was successfully reset.</p>
        <p>If you made this change, no further action is required.</p>
        <p>If you did <strong>not</strong> reset your password, please secure your account immediately using the
        <em>Forgot Password</em> option or contact support.</p>
        <p>— MyDJRequests Team</p>
    ";

    $text =
        "Hi {$displayName},\n\n" .
        "This is a confirmation that your MyDJRequests password was reset.\n\n" .
        "If you did not reset it, please secure your account immediately.\n\n" .
        "— MyDJRequests Team";

    @mdjr_send_mail($email, $subject, $html, $text);

    $success = true;
} else {
    $errors = 'Unable to update password. Please try again.';
}
                
                
            }
        }
    }
}

$pageTitle = 'Reset Password';
require __DIR__ . '/auth_layout.php';
?>

<h1>Reset Password</h1>

<div class="card">
    <?php if ($success): ?>
        <p>Your password has been updated successfully.</p>
        <p>
            <a href="<?php echo mdjr_url('dj/login.php'); ?>">← Back to Login</a>
        </p>
    <?php else: ?>
        <?php if (!$userData && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
            <p>This reset link is invalid or has expired.</p>
            <p>
                <a href="<?php echo mdjr_url('dj/forgot_password.php'); ?>">Request a new reset link</a>
            </p>
        <?php else: ?>
            <?php if ($errors): ?>
                <p style="color:#ff4ae0;"><?php echo e($errors); ?></p>
            <?php endif; ?>

            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <label>New Password</label>
                <input type="password" name="password" required>

                <label>Confirm New Password</label>
                <input type="password" name="password_confirm" required>

                <button type="submit">Set New Password</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/auth_footer.php'; ?>
