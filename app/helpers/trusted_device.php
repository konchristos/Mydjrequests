<?php
// app/helpers/trusted_device.php

require_once APP_ROOT . '/app/config/config.php';
require_once APP_ROOT . '/app/helpers/device_info.php';
/**
 * Generate a random trusted-device token (stored in cookie)
 */
function mdjr_trusted_token_generate(): string
{
    return bin2hex(random_bytes(32)); // 64 hex chars
}

/**
 * Hash token for DB storage
 */
function mdjr_trusted_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Generate a readable device label from User-Agent
 * Examples:
 *  - "Mac · Chrome"
 *  - "Windows · Edge"
 *  - "iPhone · Safari"
 *  - "Android · Chrome"
 */

/**
 * Check if current browser is trusted
 */
function is_trusted_device(int $userId): bool
{
    if (empty($_COOKIE[MDJR_TRUSTED_COOKIE])) {
        return false;
    }

    $token = $_COOKIE[MDJR_TRUSTED_COOKIE];

    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    $hash = mdjr_trusted_token_hash($token);

    $db = db();

    // Check device
    $stmt = $db->prepare("
        SELECT id
        FROM user_trusted_devices
        WHERE user_id = ?
          AND device_hash = ?
          AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$userId, $hash]);

    $deviceId = $stmt->fetchColumn();

    if (!$deviceId) {
        return false;
    }

    //  THIS IS THE IMPORTANT PART
    $db->prepare("
        UPDATE user_trusted_devices
        SET last_used_at = NOW()
        WHERE id = ?
    ")->execute([$deviceId]);
    

    return true;
}

/**
 * Remember this browser as trusted
 */
function remember_device(int $userId, int $days = 30): void
{
    $token  = mdjr_trusted_token_generate();
    $hash   = mdjr_trusted_token_hash($token);
    $expiry = (new DateTime("+{$days} days"))->format('Y-m-d H:i:s');

    // Device info
    require_once APP_ROOT . '/app/helpers/device_info.php';
    $label = mdjr_device_label();

    // IP + country
    
    require_once APP_ROOT . '/app/helpers/ip_geo.php';

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $country = $ip ? mdjr_ip_country_code($ip) : null;
    
    error_log("Trusted device geo: IP={$ip} country={$country}");

    $db = db();
    $stmt = $db->prepare("
        INSERT INTO user_trusted_devices
            (user_id, device_hash, device_label, ip_address, country_code, expires_at, last_used_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            device_label  = VALUES(device_label),
            ip_address    = VALUES(ip_address),
            country_code  = VALUES(country_code),
            expires_at    = VALUES(expires_at),
            last_used_at  = NOW()
    ");
    $stmt->execute([$userId, $hash, $label, $ip, $country, $expiry]);

    setcookie(MDJR_TRUSTED_COOKIE, $token, [
        'expires'  => time() + ($days * 86400),
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Forget this device
 */
function forget_device(): void
{
    setcookie(MDJR_TRUSTED_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

