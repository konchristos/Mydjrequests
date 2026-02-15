<?php
// app/helpers/login_audit.php

function mdjr_log_login(
    int $userId,
    bool $trustedDevice,
    string $sessionId
): void {

    $db = db();

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    require_once APP_ROOT . '/app/helpers/ip_geo.php';
    require_once APP_ROOT . '/app/helpers/device_info.php';
    require_once APP_ROOT . '/app/helpers/login_security_alert.php';

    $country = $ip ? mdjr_ip_country_code($ip) : null;
    $device  = mdjr_device_label();

    // Insert audit row (unique per session)
    $stmt = $db->prepare("
        INSERT IGNORE INTO user_login_audit
            (user_id, session_id, ip_address, country_code, device_label, trusted_device)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $sessionId,
        $ip,
        $country,
        $device,
        $trustedDevice ? 1 : 0
    ]);

    // 🔔 Security alert (only if new device or country)
    if (mdjr_should_send_security_alert($userId, $device, $country)) {

        $userStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            require_once APP_ROOT . '/app/mail/security_login_alert.php';

            mdjr_send_security_login_alert($user, [
                'device'  => $device,
                'ip'      => $ip ?? 'Unknown',
                'country' => $country ?? 'Unknown',
                'time'    => gmdate('Y-m-d H:i:s') . ' UTC'
            ]);
        }
    }
}