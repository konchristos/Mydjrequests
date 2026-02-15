<?php
require_once __DIR__ . '/../app/bootstrap.php';

$errors = '';
$sent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf_token()) {
        $errors = 'Invalid CSRF token.';
    } else {

        $email = trim($_POST['email'] ?? '');

        if ($email === '') {
            $errors = 'Please enter your email address.';
        } else {

            $userModel = new User();
            $user      = $userModel->findByEmail($email);
            $token     = $userModel->createResetToken($email);

            // Build display name safely
            require_once __DIR__ . '/../app/helpers/user_display_name.php';

            $displayName = mdjr_display_name($user);

            // Send email ONLY if user exists (but never reveal that fact)
            if ($token !== null) {

                require_once __DIR__ . '/../app/mail.php';

                $resetUrl = mdjr_url(
                    'dj/reset_password.php?token=' . urlencode($token)
                );

                $subject = 'Reset your MyDJRequests password';

                $html = "
                    <p>Hi {$displayName},</p>

                    <p>We received a request to reset the password for your
                    <strong>MyDJRequests</strong> account.</p>

                    <p>This reset link is valid for <strong>15 minutes</strong>.</p>

                    <p>
                        <a href='{$resetUrl}' style='
                            display:inline-block;
                            padding:10px 18px;
                            background:#ff2fd2;
                            color:#ffffff;
                            text-decoration:none;
                            border-radius:6px;
                        '>Reset Password</a>
                    </p>

                    <p>If the button doesn’t work, copy and paste this link:</p>
                    <p><a href='{$resetUrl}'>{$resetUrl}</a></p>

                    <p>If you didn’t request this, you can safely ignore this email.</p>

                    <p>— MyDJRequests Team</p>
                ";

                $text =
                    "Hi {$displayName},\n\n" .
                    "We received a request to reset your MyDJRequests password.\n\n" .
                    "This link expires in 15 minutes:\n" .
                    "{$resetUrl}\n\n" .
                    "If you didn’t request this, you can safely ignore this email.\n\n" .
                    "— MyDJRequests Team";

                mdjr_send_mail($email, $subject, $html, $text);
            }

            $sent = true;
        }
    }
}

$pageTitle = 'Forgot Password';
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

<h1>Forgot Password</h1>

<div class="card">

<?php if ($sent && !$errors): ?>

    <p>
        If an account exists for that email address, a password reset link
        has been sent.
    </p>

    <p style="font-size:14px;color:#aaa;margin-bottom:12px;">
        The reset link expires after <strong>15 minutes</strong> for security.
    </p>

    <p>
        <a href="<?php echo mdjr_url('dj/login.php'); ?>">← Back to Login</a>
        <span style="margin:0 8px; color:#666;">|</span>
        <a href="<?php echo mdjr_url('dj/recovery.php'); ?>">Use a recovery code</a>
    </p>

<?php else: ?>

    <?php if ($errors): ?>
        <p style="color:#ff4ae0;"><?php echo e($errors); ?></p>
    <?php endif; ?>

    <form method="post">
        <?php echo csrf_field(); ?>

        <label>Email address</label>
        <input
            type="email"
            name="email"
            required
            value="<?php echo e($_POST['email'] ?? ''); ?>"
        >

        <button type="submit">Send Reset Link</button>
    </form>

    <p style="margin-top:10px;">
        <a href="<?php echo mdjr_url('dj/login.php'); ?>">← Back to Login</a>
        <span style="margin:0 8px; color:#666;">|</span>
        <a href="<?php echo mdjr_url('dj/recovery.php'); ?>">Use a recovery code</a>
    </p>

<?php endif; ?>

</div>

<?php require __DIR__ . '/auth_footer.php'; ?>