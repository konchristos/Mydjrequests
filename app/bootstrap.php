<?php
// ---------------------------------------------------------
// BASE PATH CONSTANT — MUST BE FIRST
// ---------------------------------------------------------
define('APP_ROOT', dirname(__DIR__));

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$isCli = (PHP_SAPI === 'cli');




// ---------------------------------------------------------
// SESSION
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔐 2FA STATE FLAGS
$isPending2FA = !empty($_SESSION['pending_2fa_user']);
$isLoggedInDJ = !empty($_SESSION['dj_id']);


// ---------------------------------------------------------
// LOAD MAINTENANCE CONFIG
// ---------------------------------------------------------
require_once __DIR__ . '/config/maintenance.php';


// ---------------------------------------------------------
// MAINTENANCE MODE — SIMPLE, RELIABLE, CORRECT
// ---------------------------------------------------------

// 1) Store bypass in session if key is provided
if (isset($_GET['key']) && $_GET['key'] === MAINTENANCE_SECRET) {
    $_SESSION['maintenance_bypass'] = true;
}

$hasBypass = !empty($_SESSION['maintenance_bypass']);

// Normalize path (no query string)
$currentPath = null;

if (!$isCli && isset($_SERVER['REQUEST_URI'])) {
    $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
}


// ---------------------------------------------------------
// AUTH ROUTES — ALWAYS ALLOWED (password reset, verification)
// ---------------------------------------------------------
$authBypassPaths = [
    '/dj/forgot_password.php',
    '/dj/reset_password.php',
    '/dj/verify_email.php',
    '/dj/resend_verification.php',
    '/dj/login.php',
    '/dj/recovery.php',
    '/dj/verify_2fa_email.php',
    '/dj/logout.php',
    '/feedback.php',

    '/api/auth/forgot_password.php',
    '/api/auth/reset_password.php',
    '/api/auth/verify_email.php',
];

// Pages allowed WITHOUT login, but require key
$publicPagesRequiringKey = [
    '/',
    '/index.php',
    '/landing.php',
    '/dj/register.php'
];

if (MAINTENANCE_MODE && !$isCli) {

    // 1️⃣ Logged-in DJs always bypass maintenance
    if ($isLoggedInDJ) {
        // allowed
    }

    // 2️⃣ Auth-related pages must ALWAYS be accessible
    elseif (in_array($currentPath, $authBypassPaths, true)) {
        // allowed
    }

    // 3️⃣ Pre-login public pages REQUIRE key
    elseif (in_array($currentPath, $publicPagesRequiringKey, true)) {
        if (!$hasBypass) {
            include __DIR__ . '/../public_coming_soon.php';
            exit;
        }
    }

    // 4️⃣ Everything else is blocked
    else {
        include __DIR__ . '/../public_coming_soon.php';
        exit;
    }
}


// ---------------------------------------------------------
// 🔐 HARD 2FA ENFORCEMENT (NO BYPASS)
// ---------------------------------------------------------

$twoFaAllowedPaths = [
    '/dj/verify_2fa_email.php',
    '/dj/logout.php',
];

if (!$isCli && $isPending2FA && !$isLoggedInDJ) {
    if (!in_array($currentPath, $twoFaAllowedPaths, true)) {
        header('Location: /dj/verify_2fa_email.php');
        exit;
    }
}




// ---------------------------------------------------------
// LOAD MAIN CONFIG
// ---------------------------------------------------------
require_once APP_ROOT . '/app/config/config.php';
require_once APP_ROOT . '/app/config/database.php';
require_once APP_ROOT . '/app/config/helpers.php';
require_once APP_ROOT . '/app/config/csrf.php';
require_once APP_ROOT . '/app/helpers/trusted_device.php';
require_once APP_ROOT . '/app/helpers/admin.php';
require_once APP_ROOT . '/app/helpers/event_state.php';
require_once APP_ROOT . '/app/helpers/notifications.php';


// ---------------------------------------------------------
// AUTOLOADER (Controllers + Models)
// ---------------------------------------------------------
spl_autoload_register(function ($class) {
    $modelPath = APP_ROOT . '/app/models/' . $class . '.php';
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


// ---------------------------------------------------------
// 🔥 SINGLE SESSION ENFORCEMENT (GLOBAL)

// ---------------------------------------------------------
// USER TIMEZONE (per-user setting)
// ---------------------------------------------------------
if (!$isCli && !empty($_SESSION['dj_id'])) {
    try {
        $userModelTz = new User();
        $u = $userModelTz->findById((int)$_SESSION['dj_id']);
        if (!empty($u['timezone'])) {
            $_SESSION['tz'] = $u['timezone'];
        }
    } catch (Exception $e) {
        // ignore
    }
}

// ---------------------------------------------------------
if (!$isCli && !empty($_SESSION['dj_id']) && !empty($_SESSION['session_id'])) {

    // Let the autoloader load User + BaseModel
    $userModel = new User();
    $user = $userModel->findById((int)$_SESSION['dj_id']);

    if (
        !$user ||
        empty($user['active_session_id']) ||
        $_SESSION['session_id'] !== $user['active_session_id']
    ) {
        session_destroy();
        header('Location: /dj/login.php?reason=logged_out_elsewhere');
        exit;
    }
}


