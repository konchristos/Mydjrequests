<?php
// app/config/helpers.php

/**
 * Standard URL builder (no key appended)
 * Use this for all logged-in pages or normal routing.
 */
function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}


/**
 * Maintenance-mode URL builder
 * Only used for:
 *   - landing.php
 *   - index.php
 *   - dj/login.php
 *   - dj/register.php
 *
 * Automatically appends maintenance key *only when required*.
 */
function mdjr_url(string $path = ''): string
{
    // If maintenance mode is OFF → behave normally
    if (!defined('MAINTENANCE_MODE') || MAINTENANCE_MODE === false) {
        return url($path);
    }

    // Pages requiring the key during maintenance
    $protected = [
        'index.php',
        'landing.php',
        'dj/login.php',
        'dj/register.php',
    ];

    $filename = basename($path);

    if (in_array($filename, $protected)) {
        return url($path) . '?key=' . urlencode(MAINTENANCE_SECRET);
    }

    return url($path);
}


/**
 * Escape output safely
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function mdjr_default_broadcast_token(): string
{
    return '__MDJR_DEFAULT_BROADCAST__';
}

function mdjr_default_broadcast_template(): string
{
    return "🔊 You’re Live at {{EVENT_NAME}} with {{DJ_NAME}}\n\nUse this page to shape the vibe.\n\n• Home – Event info. Enter your name so the DJ knows who you are when you interact.\n• My Requests – Send in your songs and manage your requests.\n• All Requests – See what the crowd is requesting in real time.\n• Message – Chat directly with the DJ and receive live updates.\n• Contact – Connect and follow the DJ.\n\nDrop your requests and let’s make it a night to remember.";
}

function mdjr_default_platform_live_message(): string
{
    return "You can submit song requests while the event is live.\n\nRequests are suggestions only and may not be played.\nTips/Boosts are voluntary and are non-refundable. Boosts help highlight requests, but do not guarantee playback.\n\nPlease keep requests appropriate for the event and enjoy the music!";
}


/**
 * Standard redirect (no key injection)
 * DJ pages after login should NOT include key.
 */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}


/**
 * DJ login session check
 */
function is_dj_logged_in(): bool
{
    return !empty($_SESSION['dj_id']);
}


/**
 * Require DJ login before accessing DJ tools
 * If not logged in → redirect to login WITH key (if needed).
 */
function current_path(): string
{
    if (PHP_SAPI === 'cli') {
        return '';
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ($uri === '') {
        return '';
    }

    return parse_url($uri, PHP_URL_PATH) ?? '';
}


/**
 * Require DJ login before accessing DJ tools
 * If not logged in → redirect to login WITH key (if needed).
 * Also enforces trial auto-renew + terms acceptance (if enabled).
 */
function require_dj_login(): void
{
    if (!is_dj_logged_in()) {
        header("Location: " . mdjr_url('dj/login.php'));
        exit;
    }

    if (PHP_SAPI === 'cli') {
        return;
    }

    $userId = (int)($_SESSION['dj_id'] ?? 0);
    if ($userId <= 0) {
        session_destroy();
        header('Location: /dj/login.php');
        exit;
    }

    $userModel = new User();
    $user = $userModel->findById($userId);

    // Force email update if recovery code used
    if (!empty($_SESSION['force_email_update'])) {
        $path = current_path();
        if ($path !== '/account/update_email.php' && $path !== '/dj/logout.php') {
            header('Location: /account/update_email.php');
            exit;
        }
    }


    if (!$user) {
        session_destroy();
        header('Location: /dj/login.php');
        exit;
    }

    // Admins should never be blocked by subscription gating.
    if (!empty($user['is_admin'])) {
        return;
    }

    $configPath = APP_ROOT . '/app/config/subscriptions.php';
    $config = file_exists($configPath) ? require $configPath : [];
    $currentPath = current_path();

    $isAlphaOpenAccess = false;
    try {
        $isAlphaOpenAccess = mdjr_is_alpha_open_access(db());
    } catch (Throwable $e) {
        $isAlphaOpenAccess = false;
    }

    // During alpha, keep trial refreshed for frictionless testing.
    if ($isAlphaOpenAccess && !empty($config['auto_renew_trial'])) {
        $autoDays = (int)($config['auto_renew_days'] ?? 30);
        $autoDays = $autoDays > 0 ? $autoDays : 30;

        $subscriptionModel = new Subscription();
        $subscriptionModel->ensureFreeActive($userId, $autoDays);
    }

    // After alpha closes, block non-subscribed users from product pages.
    if (!$isAlphaOpenAccess) {
        $allowedPaths = [
            '/account/index.php',
            '/account/subscription_locked.php',
            '/account/update_email.php',
            '/account/change_password.php',
            '/account/recovery_codes.php',
            '/account/revoke_device.php',
            '/account/revoke_all_devices.php',
            '/dj/logout.php',
            '/dj/terms.php',
        ];

        $isApiPath = (strpos($currentPath, '/api/') === 0) || (strpos($currentPath, '/dj/api/') === 0);
        $hasPlatformAccess = false;
        try {
            $hasPlatformAccess = mdjr_user_has_platform_access(db(), $userId);
        } catch (Throwable $e) {
            $hasPlatformAccess = false;
        }

        if (!$hasPlatformAccess && !$isApiPath && !in_array($currentPath, $allowedPaths, true)) {
            header('Location: /account/subscription_locked.php');
            exit;
        }

        if (!$hasPlatformAccess && $isApiPath) {
            http_response_code(402);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => 'Active trial or subscription required.',
                'code' => 'SUBSCRIPTION_REQUIRED',
            ]);
            exit;
        }
    }

    // ✅ Terms acceptance gate (optional)
    if (!empty($config['require_terms'])) {
        $termsVersion = (string)($config['terms_version'] ?? 'v1');
        $termsPath = '/dj/terms.php';

        if ($currentPath !== $termsPath) {
            $acceptedVersion = (string)($user['terms_accepted_version'] ?? '');

            if ($acceptedVersion !== $termsVersion) {
                $returnTo = $currentPath ?: '/dj/dashboard.php';
                header('Location: /dj/terms.php?return=' . urlencode($returnTo));
                exit;
            }
        }
    }
}
