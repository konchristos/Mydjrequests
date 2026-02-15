<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

if (empty($_SESSION['force_email_update'])) {
    redirect('account/');
    exit;
}

$userModel = new User();
$user = $userModel->findById((int)$_SESSION['dj_id']);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email.';
        } else {
            $userModel->updateEmail((int)$user['id'], $email);

            // Generate verification token and send
            $rawToken = $userModel->createEmailVerificationToken((int)$user['id']);
            require_once APP_ROOT . '/app/mail.php';
            $verifyUrl = mdjr_url('dj/verify_email.php?token=' . urlencode($rawToken));

            $subject = 'Verify your new MyDJRequests email';
            $html = "<p>Please verify your new email address:</p><p><a href='{$verifyUrl}'>Verify Email</a></p>";
            $text = "Verify your new email: {$verifyUrl}";

            @mdjr_send_mail($email, $subject, $html, $text);

            // Force re-verify and logout
            $_SESSION = [];
            session_destroy();
            header('Location: /dj/login.php?reason=verify_new_email');
            exit;
        }
    }
}

$pageTitle = 'Update Email';
require __DIR__ . '/../dj/layout.php';
?>

<div class="content">
    <p style="margin:0 0 8px;"><a href="/dj/logout.php" style="color:#ff2fd2; text-decoration:none;">← Logout</a></p>
    <h1>Update Email</h1>
    <p class="muted">You used a recovery code. Please set a new email and verify it.</p>

    <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>

    <form method="POST" style="max-width:520px;">
        <?php echo csrf_field(); ?>
        <label for="email">New email</label>
        <input id="email" name="email" type="email" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff;">

        <button class="btn-primary" type="submit" style="margin-top:12px;">Save and Verify</button>
    </form>
</div>
