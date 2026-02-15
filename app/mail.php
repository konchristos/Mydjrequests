<?php
// app/mail.php
// Lightweight HTML email helper using PHP's built-in mail().
// This is suitable for most cPanel / VPS setups where the server
// is already configured to send mail (Exim/Postfix).
//
// If you later decide to use PHPMailer over SMTP, you can replace
// the internals of mdjr_send_mail() accordingly.

function mdjr_send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $config = require __DIR__ . '/config/mail.php';

    $fromEmail = $config['from_email'] ?? 'no-reply@mydjrequests.com';
    $fromName  = $config['from_name']  ?? 'MyDJRequests';

    // Generate a MIME boundary
    $boundary = md5(uniqid((string)mt_rand(), true));

    // Basic headers
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $fromEmail);
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    // Fallback plain text body if not provided
    if ($textBody === '') {
        $textBody = strip_tags(
            preg_replace('/<br\s*\/?>/i', "\n", $htmlBody)
        );
    }

    // Build the multipart body
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";

    $body .= "--{$boundary}--\r\n";

    // Encode subject as UTF‑8
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}
