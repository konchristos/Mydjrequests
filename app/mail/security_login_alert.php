<?php
//app/mail/security_login_alert.php
function mdjr_send_security_login_alert(array $user, array $meta): void
{
    require_once APP_ROOT . '/app/mail.php';

    $subject = 'New login to your MyDJRequests account';

    $html = "
        <p>Hi {$user['name']},</p>

        <p>We noticed a login to your <strong>MyDJRequests</strong> account from a new device or location.</p>

        <ul>
            <li><strong>Device:</strong> {$meta['device']}</li>
            <li><strong>IP Address:</strong> {$meta['ip']}</li>
            <li><strong>Country:</strong> {$meta['country']}</li>
            <li><strong>Time:</strong> {$meta['time']}</li>
        </ul>

        <p>If this was you, no action is needed.</p>

        <p>If you don’t recognise this login, please reset your password immediately.</p>

        <p style='margin-top:20px;'>
            — MyDJRequests Security
        </p>
    ";

    $text = "
New login detected on your MyDJRequests account.

Device: {$meta['device']}
IP: {$meta['ip']}
Country: {$meta['country']}
Time: {$meta['time']}

If this wasn't you, reset your password immediately.
";

    mdjr_send_mail($user['email'], $subject, $html, $text);
}