<?php
// app/config/config.php

// -----------------------------------------------------------------------------
// Secrets loader
// -----------------------------------------------------------------------------
// Keep real credentials outside public_html.
// Server file path (recommended): /home/mydjrequests/.secrets/mydjrequests.php
if (!defined('MDJR_SECRETS_FILE')) {
    define('MDJR_SECRETS_FILE', '/home/mydjrequests/.secrets/mydjrequests.php');
}

$mdjrSecrets = [];
if (is_file(MDJR_SECRETS_FILE)) {
    $loaded = require MDJR_SECRETS_FILE;
    if (is_array($loaded)) {
        $mdjrSecrets = $loaded;
    }
}

if (!function_exists('mdjr_secret')) {
    function mdjr_secret(string $key, $default = '')
    {
        global $mdjrSecrets;
        if (is_array($mdjrSecrets) && array_key_exists($key, $mdjrSecrets)) {
            return $mdjrSecrets[$key];
        }
        $env = getenv($key);
        return ($env !== false && $env !== '') ? $env : $default;
    }
}

// Toggle debug mode (do NOT leave on in production)
define('APP_DEBUG', (bool)mdjr_secret('APP_DEBUG', false));

// Base URL of your site (no trailing slash)
define('BASE_URL', (string)mdjr_secret('BASE_URL', 'https://mydjrequests.com'));

// Database config (replace with your own)
define('DB_HOST', (string)mdjr_secret('DB_HOST', 'localhost'));
define('DB_NAME', (string)mdjr_secret('DB_NAME', ''));
define('DB_USER', (string)mdjr_secret('DB_USER', ''));
define('DB_PASS', (string)mdjr_secret('DB_PASS', ''));
define('DB_CHARSET', (string)mdjr_secret('DB_CHARSET', 'utf8mb4'));

// Trusted device cookie name
define('MDJR_TRUSTED_COOKIE', 'mdjr_trusted_device');

// Optional: default timezone
date_default_timezone_set((string)mdjr_secret('APP_TIMEZONE', 'Australia/Melbourne'));
