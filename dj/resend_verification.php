<?php
require_once __DIR__ . '/../app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dj/login.php');
    exit;
}

if (!verify_csrf_token()) {
    redirect('dj/login.php');
    exit;
}

$email = trim($_POST['email'] ?? '');

if ($email === '') {
    redirect('dj/login.php');
    exit;
}

$userModel = new User();
$user = $userModel->findByEmail($email);

// Only resend if user exists and is not verified
if ($user && (int)$user['is_verified'] !== 1) {

    // Generate new token
    $rawToken = $userModel->createEmailVerificationToken((int)$user['id']);

    // Build verify link
    $verifyUrl = mdjr_url('dj/verify_email.php?token=' . urlencode($rawToken));

    require_once __DIR__ . '/../app/mail.php';

    $subject = 'Verify your MyDJRequests account';

    $html = "
        <p>Hi {$user['name']},</p>
        <p>Please verify your email address to activate your account:</p>
        <p>
            <a href='{$verifyUrl}' style='
                display:inline-block;
                padding:12px 20px;
                background:#ff2fd2;
                color:#ffffff;
                text-decoration:none;
                border-radius:6px;
            '>Verify Email</a>
        </p>
        <p>If the button doesn’t work, copy this link:</p>
        <p>{$verifyUrl}</p>
    ";

    $text = "Verify your account:\n\n{$verifyUrl}";

    mdjr_send_mail($email, $subject, $html, $text);
}

// Redirect back to login with success flag
redirect('dj/login.php?resent=1');