<?php
// app/helpers/email_2fa.php

function send_email_2fa_code(int $userId, array $user): void
{
    // Generate 6-digit code
    $code = random_int(100000, 999999);

    // Hash it before storing
    $hash = password_hash((string)$code, PASSWORD_DEFAULT);

    // Get DB connection
    $db = db();

    // Remove any previous codes for this user
    $stmt = $db->prepare("DELETE FROM user_2fa_codes WHERE user_id = ?");
    $stmt->execute([$userId]);

    // Store new code (10-minute expiry)
    $stmt = $db->prepare("
        INSERT INTO user_2fa_codes (user_id, code_hash, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
    ");
    $stmt->execute([$userId, $hash]);

    // Send email
    require_once APP_ROOT . '/app/mail.php';
    require_once APP_ROOT . '/app/helpers/user_display_name.php';

    $name = mdjr_display_name($user);

    $subject = 'Your MyDJRequests login code';

    $html = "
        <p>Hi {$name},</p>
        <p>Your login verification code is:</p>
        <h2 style='letter-spacing:3px;'>{$code}</h2>
        <p>This code expires in <strong>10 minutes</strong>.</p>
        <p>If you didn’t try to log in, please secure your account immediately.</p>
        <p>— MyDJRequests Team</p>
    ";

  
    $text =
        "Hi {$name},\n\n" .
        "Your MyDJRequests login code is: {$code}\n\n" .
        "This code expires in 10 minutes.\n\n" .
        "— MyDJRequests Team";

    mdjr_send_mail($user['email'], $subject, $html, $text);
}