<?php
// app/bootstrap_public.php
// Lightweight bootstrap for PUBLIC guest pages (no maintenance mode)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ---------------------------------------------------------
// SESSION
// ---------------------------------------------------------
if (
    session_status() === PHP_SESSION_NONE
    && empty($_SERVER['HTTP_STRIPE_SIGNATURE'])
) {
    session_start();
}

// ---------------------------------------------------------
// BASE PATH CONSTANT
// ---------------------------------------------------------
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));  // points to /home/.../public_html/app/..
}

// ---------------------------------------------------------
// LOAD CORE APP CONFIG (NO maintenance.php HERE)
// ---------------------------------------------------------
require_once APP_ROOT . '/app/config/config.php';
require_once APP_ROOT . '/app/config/database.php';
require_once APP_ROOT . '/app/config/helpers.php';
require_once APP_ROOT . '/app/config/csrf.php';
require_once APP_ROOT . '/app/helpers/event_state.php';
require_once APP_ROOT . '/app/helpers/premium_features.php';
require_once __DIR__ . '/config/app.php';

// ---------------------------------------------------------
// AUTOLOADER (Models + Controllers)
// ---------------------------------------------------------
spl_autoload_register(function ($class) {
    $modelPath      = APP_ROOT . '/app/models/' . $class . '.php';
    $controllerPath = APP_ROOT . '/app/controllers/' . $class . '.php';

    if (file_exists($modelPath)) {
        require_once $modelPath;
        return;
    }
    if (file_exists($controllerPath)) {
        require_once $controllerPath;
        return;
    }
});
