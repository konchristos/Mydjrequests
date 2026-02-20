<?php

function mdjr_get_user_plan_base(PDO $db, int $userId): string
{
    if ($userId <= 0) {
        return 'pro';
    }

    try {
        $stmt = $db->prepare('
            SELECT plan
            FROM subscriptions
            WHERE user_id = :uid
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute(['uid' => $userId]);
        $plan = strtolower((string)($stmt->fetchColumn() ?: ''));
        if ($plan !== '') {
            return $plan;
        }
    } catch (Throwable $e) {
        // Fallback below.
    }

    try {
        $stmt = $db->prepare('SELECT subscription FROM users WHERE id = :uid LIMIT 1');
        $stmt->execute(['uid' => $userId]);
        $fallback = strtolower((string)($stmt->fetchColumn() ?: ''));
        if ($fallback !== '') {
            return $fallback;
        }
    } catch (Throwable $e) {
        // Keep default.
    }

    return 'pro';
}

function mdjr_ensure_plan_simulation_table(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_plan_simulations (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            simulated_plan VARCHAR(20) NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_admin_plan_sim_updated_by (updated_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mdjr_get_admin_plan_simulation(PDO $db, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    try {
        mdjr_ensure_plan_simulation_table($db);
        $stmt = $db->prepare('
            SELECT simulated_plan
            FROM admin_plan_simulations
            WHERE user_id = :uid
            LIMIT 1
        ');
        $stmt->execute(['uid' => $userId]);
        $sim = strtolower((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return null;
    }

    if (!in_array($sim, ['pro', 'premium'], true)) {
        return null;
    }

    return $sim;
}

function mdjr_set_admin_plan_simulation(PDO $db, int $userId, ?string $plan, int $adminUserId): void
{
    mdjr_ensure_plan_simulation_table($db);

    if ($plan === null || $plan === '') {
        $stmt = $db->prepare('DELETE FROM admin_plan_simulations WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        return;
    }

    $plan = strtolower($plan);
    if (!in_array($plan, ['pro', 'premium'], true)) {
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO admin_plan_simulations (user_id, simulated_plan, updated_by)
        VALUES (:uid, :plan, :updated_by)
        ON DUPLICATE KEY UPDATE
            simulated_plan = VALUES(simulated_plan),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        'uid' => $userId,
        'plan' => $plan,
        'updated_by' => $adminUserId > 0 ? $adminUserId : null,
    ]);
}

function mdjr_get_user_plan(PDO $db, int $userId): string
{
    $basePlan = mdjr_get_user_plan_base($db, $userId);
    $sim = mdjr_get_admin_plan_simulation($db, $userId);
    if ($sim !== null) {
        return $sim;
    }

    return $basePlan;
}

function mdjr_user_has_premium(PDO $db, int $userId): bool
{
    return mdjr_get_user_plan($db, $userId) === 'premium';
}

function mdjr_ensure_premium_tables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS premium_event_links (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            slug VARCHAR(120) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            fallback_path VARCHAR(255) NULL,
            before_live_path VARCHAR(255) NULL,
            live_path VARCHAR(255) NULL,
            after_live_path VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_premium_event_links_event (event_id),
            UNIQUE KEY uq_premium_event_links_slug (slug),
            KEY idx_premium_event_links_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS premium_event_qr_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            foreground_color CHAR(7) NOT NULL DEFAULT '#000000',
            background_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
            frame_text VARCHAR(80) NULL,
            logo_path VARCHAR(255) NULL,
            logo_scale_pct TINYINT UNSIGNED NOT NULL DEFAULT 18,
            image_size INT UNSIGNED NOT NULL DEFAULT 480,
            error_correction CHAR(1) NOT NULL DEFAULT 'H',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_premium_event_qr_settings_event (event_id),
            KEY idx_premium_event_qr_settings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS premium_user_qr_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            foreground_color CHAR(7) NOT NULL DEFAULT '#000000',
            background_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
            frame_text VARCHAR(80) NULL,
            logo_path VARCHAR(255) NULL,
            logo_scale_pct TINYINT UNSIGNED NOT NULL DEFAULT 18,
            image_size INT UNSIGNED NOT NULL DEFAULT 480,
            error_correction CHAR(1) NOT NULL DEFAULT 'H',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_premium_user_qr_settings_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS premium_event_link_hits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            slug VARCHAR(120) NOT NULL,
            source VARCHAR(60) NULL,
            event_state VARCHAR(20) NULL,
            redirect_target VARCHAR(255) NULL,
            visitor_hash CHAR(64) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            referer VARCHAR(255) NULL,
            query_string VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_premium_event_link_hits_event (event_id),
            KEY idx_premium_event_link_hits_user (user_id),
            KEY idx_premium_event_link_hits_source (source),
            KEY idx_premium_event_link_hits_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mdjr_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    if ($value === '') {
        $value = 'event';
    }
    return substr($value, 0, 90);
}

function mdjr_generate_unique_event_slug(PDO $db, array $event): string
{
    $title = (string)($event['title'] ?? 'event');
    $uuid = (string)($event['uuid'] ?? '');
    $base = mdjr_slugify($title);
    $suffix = $uuid !== '' ? substr(preg_replace('/[^a-z0-9]/', '', strtolower($uuid)) ?? '', 0, 6) : substr(bin2hex(random_bytes(4)), 0, 6);

    $candidate = $base . '-' . $suffix;
    $n = 0;

    while (true) {
        $slug = $n === 0 ? $candidate : substr($candidate, 0, 110) . '-' . $n;

        $stmt = $db->prepare('SELECT id FROM premium_event_links WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $slug;
        }

        $n++;
        if ($n > 1000) {
            return 'event-' . substr(bin2hex(random_bytes(8)), 0, 10);
        }
    }
}

function mdjr_get_or_create_premium_link(PDO $db, array $event, int $userId): array
{
    $stmt = $db->prepare('SELECT * FROM premium_event_links WHERE event_id = :event_id LIMIT 1');
    $stmt->execute(['event_id' => (int)$event['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    $slug = mdjr_generate_unique_event_slug($db, $event);
    $fallbackPath = '/r/' . urlencode((string)$event['uuid']);

    $insert = $db->prepare('
        INSERT INTO premium_event_links (
            event_id, user_id, slug, is_enabled,
            fallback_path, before_live_path, live_path, after_live_path
        ) VALUES (
            :event_id, :user_id, :slug, 0,
            :fallback_path, :before_live_path, :live_path, :after_live_path
        )
    ');
    $insert->execute([
        'event_id' => (int)$event['id'],
        'user_id' => $userId,
        'slug' => $slug,
        'fallback_path' => $fallbackPath,
        'before_live_path' => $fallbackPath,
        'live_path' => $fallbackPath,
        'after_live_path' => $fallbackPath,
    ]);

    $stmt->execute(['event_id' => (int)$event['id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function mdjr_get_premium_link_by_slug(PDO $db, string $slug): ?array
{
    $stmt = $db->prepare('SELECT * FROM premium_event_links WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mdjr_clean_internal_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    $parts = parse_url($path);
    if ($parts === false) {
        return '';
    }

    if (!empty($parts['scheme']) || !empty($parts['host'])) {
        return '';
    }

    $cleanPath = $parts['path'] ?? '';
    if ($cleanPath === '') {
        return '';
    }
    if ($cleanPath[0] !== '/') {
        $cleanPath = '/' . $cleanPath;
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
    return substr($cleanPath . $query, 0, 255);
}

function mdjr_save_premium_link_settings(PDO $db, int $eventId, int $userId, array $data): void
{
    $stmt = $db->prepare('
        UPDATE premium_event_links
        SET
            is_enabled = :is_enabled,
            fallback_path = :fallback_path,
            before_live_path = :before_live_path,
            live_path = :live_path,
            after_live_path = :after_live_path,
            updated_at = CURRENT_TIMESTAMP
        WHERE event_id = :event_id AND user_id = :user_id
        LIMIT 1
    ');

    $stmt->execute([
        'is_enabled' => !empty($data['is_enabled']) ? 1 : 0,
        'fallback_path' => $data['fallback_path'],
        'before_live_path' => $data['before_live_path'],
        'live_path' => $data['live_path'],
        'after_live_path' => $data['after_live_path'],
        'event_id' => $eventId,
        'user_id' => $userId,
    ]);
}

function mdjr_get_qr_settings(PDO $db, int $eventId): ?array
{
    $stmt = $db->prepare('SELECT * FROM premium_event_qr_settings WHERE event_id = :event_id LIMIT 1');
    $stmt->execute(['event_id' => $eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mdjr_save_qr_settings(PDO $db, int $eventId, int $userId, array $data): void
{
    $stmt = $db->prepare('
        INSERT INTO premium_event_qr_settings (
            event_id, user_id, foreground_color, background_color,
            frame_text, logo_path, logo_scale_pct, image_size, error_correction
        ) VALUES (
            :event_id, :user_id, :foreground_color, :background_color,
            :frame_text, :logo_path, :logo_scale_pct, :image_size, :error_correction
        )
        ON DUPLICATE KEY UPDATE
            foreground_color = VALUES(foreground_color),
            background_color = VALUES(background_color),
            frame_text = VALUES(frame_text),
            logo_path = VALUES(logo_path),
            logo_scale_pct = VALUES(logo_scale_pct),
            image_size = VALUES(image_size),
            error_correction = VALUES(error_correction),
            updated_at = CURRENT_TIMESTAMP
    ');

    $stmt->execute([
        'event_id' => $eventId,
        'user_id' => $userId,
        'foreground_color' => $data['foreground_color'],
        'background_color' => $data['background_color'],
        'frame_text' => $data['frame_text'],
        'logo_path' => $data['logo_path'],
        'logo_scale_pct' => $data['logo_scale_pct'],
        'image_size' => $data['image_size'],
        'error_correction' => $data['error_correction'],
    ]);
}

function mdjr_get_user_qr_settings(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare('SELECT * FROM premium_user_qr_settings WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mdjr_save_user_qr_settings(PDO $db, int $userId, array $data): void
{
    $stmt = $db->prepare('
        INSERT INTO premium_user_qr_settings (
            user_id, foreground_color, background_color,
            frame_text, logo_path, logo_scale_pct, image_size, error_correction
        ) VALUES (
            :user_id, :foreground_color, :background_color,
            :frame_text, :logo_path, :logo_scale_pct, :image_size, :error_correction
        )
        ON DUPLICATE KEY UPDATE
            foreground_color = VALUES(foreground_color),
            background_color = VALUES(background_color),
            frame_text = VALUES(frame_text),
            logo_path = VALUES(logo_path),
            logo_scale_pct = VALUES(logo_scale_pct),
            image_size = VALUES(image_size),
            error_correction = VALUES(error_correction),
            updated_at = CURRENT_TIMESTAMP
    ');

    $stmt->execute([
        'user_id' => $userId,
        'foreground_color' => $data['foreground_color'],
        'background_color' => $data['background_color'],
        'frame_text' => $data['frame_text'],
        'logo_path' => $data['logo_path'],
        'logo_scale_pct' => $data['logo_scale_pct'],
        'image_size' => $data['image_size'],
        'error_correction' => $data['error_correction'],
    ]);
}

function mdjr_detect_upload_mime(string $tmpPath): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmpPath) ?: '';
            finfo_close($finfo);
            return $mime;
        }
    }

    if (function_exists('getimagesize')) {
        $info = @getimagesize($tmpPath);
        if (!empty($info['mime'])) {
            return (string)$info['mime'];
        }
    }

    return '';
}

function mdjr_log_premium_hit(PDO $db, array $payload): void
{
    $stmt = $db->prepare('
        INSERT INTO premium_event_link_hits (
            event_id, user_id, slug, source, event_state, redirect_target,
            visitor_hash, ip_address, user_agent, referer, query_string
        ) VALUES (
            :event_id, :user_id, :slug, :source, :event_state, :redirect_target,
            :visitor_hash, :ip_address, :user_agent, :referer, :query_string
        )
    ');

    $stmt->execute([
        'event_id' => (int)$payload['event_id'],
        'user_id' => (int)$payload['user_id'],
        'slug' => substr((string)($payload['slug'] ?? ''), 0, 120),
        'source' => substr((string)($payload['source'] ?? ''), 0, 60),
        'event_state' => substr((string)($payload['event_state'] ?? ''), 0, 20),
        'redirect_target' => substr((string)($payload['redirect_target'] ?? ''), 0, 255),
        'visitor_hash' => substr((string)($payload['visitor_hash'] ?? ''), 0, 64),
        'ip_address' => substr((string)($payload['ip_address'] ?? ''), 0, 45),
        'user_agent' => substr((string)($payload['user_agent'] ?? ''), 0, 255),
        'referer' => substr((string)($payload['referer'] ?? ''), 0, 255),
        'query_string' => substr((string)($payload['query_string'] ?? ''), 0, 255),
    ]);
}
