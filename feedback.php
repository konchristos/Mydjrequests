<?php
require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Feedback';

$error = '';
$success = '';

$backTo = $_SERVER['HTTP_REFERER'] ?? '/';
if (!is_string($backTo) || $backTo === '' || strpos($backTo, $_SERVER['HTTP_HOST'] ?? '') === false) {
    // Fallback to homepage if referrer is missing or external
    $backTo = '/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($name === '' || $email === '' || $message === '') {
            $error = 'Please provide name, email, and feedback.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email.';
        } else {
            $userId = !empty($_SESSION['dj_id']) ? (int)$_SESSION['dj_id'] : null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $feedbackModel = new Feedback();
            $feedbackModel->create($userId, $name, $email, $message, $ip);

            $nid = notifications_create('feedback', 'New Feedback', $name . ' submitted feedback', '/admin/feedback.php');
            notifications_add_admins($nid);

            $success = 'Thank you for your feedback.';
        }
    }
}
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
        .success { color:#7be87f; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Feedback</h1>
            <p style="margin:0 0 12px;"><a href="<?php echo e($backTo); ?>" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
            <p>Share your thoughts to help improve MYDJREQUESTS.</p>

            <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="success"><?php echo e($success); ?></div><?php endif; ?>

            <form method="POST">
                <?php echo csrf_field(); ?>
                <label for="name">Name</label>
                <input id="name" name="name" type="text" required>

                <label for="email">Email</label>
                <input id="email" name="email" type="email" required>

                <label for="message">Feedback</label>
                <textarea id="message" name="message" rows="6" required></textarea>

                <button class="btn" type="submit">Send Feedback</button>
            </form>
        </div>
    </div>
</body>
</html>
