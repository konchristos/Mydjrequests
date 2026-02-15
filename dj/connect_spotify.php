<?php
//  /dj/connect_spotify.php
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/config/spotify.php';

if (empty($_SESSION['dj_id'])) {
    header('Location: /dj/login.php');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['spotify_oauth_state'] = $state;

// remember where we came from so callback can return there
$_SESSION['spotify_return_to'] = $_SERVER['HTTP_REFERER'] ?? '/dj/dashboard.php';

$upgrade = isset($_GET['upgrade']) && $_GET['upgrade'] === '1';

$params = [
    'response_type' => 'code',
    'client_id'     => SPOTIFY_CLIENT_ID,
    'scope'         => implode(' ', [
        'user-read-private',
        'playlist-modify-public',
        'playlist-modify-private',
        'user-read-email',
    ]),
    'redirect_uri'  => 'https://mydjrequests.com/dj/spotify_callback.php',
    'state'         => $state,

    // 🔐 Force full re-auth
    'show_dialog' => 'true',   // re-consent + scope refresh
    'prompt'      => 'login',  // 👈 force account chooser
];

// ✅ IMPORTANT: force Spotify to show consent screen when upgrading scopes
if ($upgrade) {
    $params['show_dialog'] = 'true';
}

header('Location: https://accounts.spotify.com/authorize?' . http_build_query($params));
exit;