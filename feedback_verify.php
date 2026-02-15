<?php
require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Verify Feedback';
$message = '';
$ok = false;

$token = trim((string)($_GET['token'] ?? ''));
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

if ($token === '') {
    $message = 'Verification link is invalid.';
} else {
    $feedbackModel = new Feedback();
    $result = $feedbackModel->verifyPublicTokenAndCreateFeedback($token, $ip);

    if (!empty($result['ok'])) {
        $ok = true;
        $name = (string)($result['name'] ?? 'Guest');
        $nid = notifications_create('feedback', 'New Feedback', $name . ' submitted feedback', '/admin/feedback.php');
        notifications_add_admins($nid);
        $message = 'Thanks. Your feedback has been verified and submitted.';
    } else {
        $message = (string)($result['message'] ?? 'Verification failed.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?></title>
    <style>
        body { margin:0; font-family:'Inter', sans-serif; background:#0d0d0f; color:#e5e5ef; }
        .wrap { max-width: 720px; margin: 40px auto; padding: 0 20px 60px; }
        .card { background:#111116; border:1px solid #1f1f29; border-radius:14px; padding:24px; }
        .msg-ok { color:#7be87f; }
        .msg-err { color:#ff8080; }
        a.btn { display:inline-block; margin-top:12px; background:#ff2fd2; color:#fff; text-decoration:none; border:none; padding:10px 14px; border-radius:8px; font-weight:600; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Feedback Verification</h1>
            <p class="<?php echo $ok ? 'msg-ok' : 'msg-err'; ?>"><?php echo e($message); ?></p>
            <a class="btn" href="/feedback.php">Back to Feedback</a>
        </div>
    </div>
</body>
</html>
