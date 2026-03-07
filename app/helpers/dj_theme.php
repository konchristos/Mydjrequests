<?php

function mdjr_normalize_hex_color(string $value, string $fallback = '#ff2fd2'): string
{
    $value = trim($value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
        return strtolower($value);
    }
    return strtolower($fallback);
}

function mdjr_hex_to_rgb_triplet(string $hex): array
{
    $hex = ltrim(mdjr_normalize_hex_color($hex), '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function mdjr_adjust_hex_brightness(string $hex, int $delta): string
{
    $rgb = mdjr_hex_to_rgb_triplet($hex);
    foreach ($rgb as $idx => $channel) {
        $rgb[$idx] = max(0, min(255, $channel + $delta));
    }
    return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
}

function mdjr_get_dj_theme_color_setting(PDO $db, int $userId): string
{
    if ($userId <= 0) {
        return '#ff2fd2';
    }

    try {
        $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = :k LIMIT 1");
        $stmt->execute(['k' => 'dj_theme_color_' . $userId]);
        $value = $stmt->fetchColumn();
        return mdjr_normalize_hex_color((string)($value ?: ''), '#ff2fd2');
    } catch (Throwable $e) {
        return '#ff2fd2';
    }
}

function mdjr_save_dj_theme_color_setting(PDO $db, int $userId, string $hexColor): bool
{
    if ($userId <= 0) {
        return false;
    }

    $hexColor = mdjr_normalize_hex_color($hexColor, '#ff2fd2');

    try {
        $stmt = $db->prepare("
            INSERT INTO app_settings (`key`, `value`)
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        return $stmt->execute([
            'k' => 'dj_theme_color_' . $userId,
            'v' => $hexColor,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function mdjr_get_dj_theme_config(PDO $db, int $userId): array
{
    $plan = mdjr_get_user_plan($db, $userId);
    $accent = '#ff2fd2';

    if ($plan === 'premium') {
        $accent = mdjr_get_dj_theme_color_setting($db, $userId);
    }

    $accentStrong = mdjr_adjust_hex_brightness($accent, 18);
    $accentSoft = mdjr_adjust_hex_brightness($accent, 44);
    $accentRgb = mdjr_hex_to_rgb_triplet($accent);

    return [
        'plan' => $plan,
        'accent' => $accent,
        'accent_strong' => $accentStrong,
        'accent_soft' => $accentSoft,
        'accent_rgb' => implode(', ', $accentRgb),
    ];
}
