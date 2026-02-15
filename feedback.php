<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/mail.php';

$pageTitle = 'Feedback';
$error = '';
$success = '';

$isLoggedIn = !empty($_SESSION['dj_id']);
$prefillName = '';
$prefillEmail = '';

if ($isLoggedIn) {
    try {
        $user = (new User())->findById((int)$_SESSION['dj_id']);
        if ($user) {
            $prefillName = trim((string)($user['dj_name'] ?? '')) ?: trim((string)($user['name'] ?? ''));
            $prefillEmail = trim((string)($user['email'] ?? ''));
        }
    } catch (Throwable $e) {
        $prefillName = '';
        $prefillEmail = '';
    }

    if (isset($_GET['submitted']) && $_GET['submitted'] === '1') {
        $success = 'Thank you for your feedback.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));

        if ($name === '' || $email === '' || $message === '') {
            $error = 'Please provide name, email, and feedback.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email.';
        } else {
            $feedbackModel = new Feedback();
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

            if ($isLoggedIn) {
                $name = $prefillName;
                $email = $prefillEmail;

                if ($name === '' || $email === '') {
                    $error = 'Could not resolve your account details. Please re-login and try again.';
                } else {
                    $feedbackModel->create((int)$_SESSION['dj_id'], $name, $email, $message, $ip);

                    $nid = notifications_create('feedback', 'New Feedback', $name . ' submitted feedback', '/admin/feedback.php');
                    notifications_add_admins($nid);

                    header('Location: /dj/feedback.php?submitted=1');
                    exit;
                }
            } else {
                $ipCount = $feedbackModel->countRecentPendingByIp($ip, 60);
                $emailCount = $feedbackModel->countRecentPendingByEmail($email, 60);

                if ($ipCount >= 5) {
                    $error = 'Too many submissions from this IP. Please try again in about an hour.';
                } elseif ($emailCount >= 3) {
                    $error = 'Too many submissions for this email right now. Please try again later.';
                } else {
                    $token = $feedbackModel->createPublicVerification($name, $email, $message, $ip, $userAgent, 24);
                    $verifyUrl = url('feedback_verify.php?token=' . urlencode($token));

                    $subject = 'Verify your feedback submission';
                    $html = "
                        <p>Hi " . e($name) . ",</p>
                        <p>Please confirm your feedback submission by clicking the button below:</p>
                        <p>
                            <a href='" . e($verifyUrl) . "' style='display:inline-block;background:#ff2fd2;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px;font-weight:600;'>
                                Verify Feedback
                            </a>
                        </p>
                        <p>This link expires in 24 hours.</p>
                        <p>If you did not submit feedback, you can ignore this email.</p>
                    ";
                    $text = "Please verify your feedback submission:\n\n{$verifyUrl}\n\nThis link expires in 24 hours.";

                    if (!mdjr_send_mail($email, $subject, $html, $text)) {
                        $error = 'Could not send verification email right now. Please try again.';
                    } else {
                        header('Location: /');
                        exit;
                    }
                }
            }
        }
    }
}

if ($isLoggedIn):
    $pageBodyClass = 'admin-page';
    require __DIR__ . '/dj/layout.php';
?>
<div style="max-width:760px; margin: 0 auto;">
    <div style="background:#111116; border:1px solid #1f1f29; border-radius:14px; padding:24px;">
        <h1 style="margin-top:0;">Feedback</h1>
        <p style="margin:0 0 12px;"><a href="/dj/feedback.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
        <p>Share your thoughts to help improve MYDJREQUESTS.</p>

        <?php if ($error): ?><div style="color:#ff8080;"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div style="color:#7be87f;"><?php echo e($success); ?></div><?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>

            <label for="name" style="display:block; margin:10px 0 6px; color:#cfcfd8;">Name</label>
            <input id="name" name="name" type="text" value="<?php echo e($prefillName); ?>" readonly required style="width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff;">

            <label for="email" style="display:block; margin:10px 0 6px; color:#cfcfd8;">Email</label>
            <input id="email" name="email" type="email" value="<?php echo e($prefillEmail); ?>" readonly required style="width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff;">

            <label for="message" style="display:block; margin:10px 0 6px; color:#cfcfd8;">Feedback</label>
            <textarea id="message" name="message" rows="6" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff;"><?php echo e((string)($_POST['message'] ?? '')); ?></textarea>

            <button type="submit" style="background:#ff2fd2; color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; margin-top:12px;">Send Feedback</button>
        </form>
    </div>
</div>
<?php
    require __DIR__ . '/dj/footer.php';
else:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?></title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon-v2.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <link rel="manifest" href="/site.webmanifest" />

    <style>
        body { margin:0; font-family:'Inter', sans-serif; background:#0d0d0f; color:#e5e5ef; }
        .wrap { max-width: 720px; margin: 40px auto; padding: 0 20px 60px; }
        .card { background:#111116; border:1px solid #1f1f29; border-radius:14px; padding:24px; }
        label { display:block; margin: 10px 0 6px; color:#cfcfd8; }
        input, textarea { width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff; }
        .btn { background:#ff2fd2; color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; margin-top:12px; }
        .error { color:#ff8080; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Feedback</h1>
            <p style="margin:0 0 12px;"><a href="/" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
            <p>Share your thoughts to help improve MYDJREQUESTS.</p>

            <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <label for="name">Name</label>
                <input id="name" name="name" type="text" value="<?php echo e((string)($_POST['name'] ?? '')); ?>" required>

                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="<?php echo e((string)($_POST['email'] ?? '')); ?>" required>

                <label for="message">Feedback</label>
                <textarea id="message" name="message" rows="6" required><?php echo e((string)($_POST['message'] ?? '')); ?></textarea>

                <button class="btn" type="submit">Send Verification Email</button>
            </form>
            <p style="margin-top:10px; color:#aaa; font-size:14px;">
                Public feedback requires email verification before it is submitted.
            </p>
        </div>
    </div>
</body>
</html>
<?php endif; ?>
