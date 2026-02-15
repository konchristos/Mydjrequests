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

    $configPath = APP_ROOT . '/app/config/subscriptions.php';
    $config = file_exists($configPath) ? require $configPath : [];
    $currentPath = current_path();

    // ✅ Auto-renew free access using subscriptions table
    if (!empty($config['auto_renew_trial'])) {
        $autoDays = (int)($config['auto_renew_days'] ?? 30);
        $autoDays = $autoDays > 0 ? $autoDays : 30;

        $subscriptionModel = new Subscription();
        $subscriptionModel->ensureFreeActive($userId, $autoDays);
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

