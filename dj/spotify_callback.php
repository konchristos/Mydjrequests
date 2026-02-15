<?php
// /dj/spotify_callback.php
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/config/spotify.php';

// Require DJ session
if (empty($_SESSION['dj_id'])) {
    exit('DJ session missing.');
}

if (
    empty($_GET['code']) ||
    empty($_GET['state']) ||
    $_GET['state'] !== ($_SESSION['spotify_oauth_state'] ?? null)
) {
    exit('Invalid Spotify authorization.');
}

unset($_SESSION['spotify_oauth_state']);

$code = $_GET['code'];

// Exchange code for token
$ch = curl_init('https://accounts.spotify.com/api/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => 'https://mydjrequests.com/dj/spotify_callback.php',
    ]),
    CURLOPT_HTTPHEADER => [
        'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_RETURNTRANSFER => true,
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if (empty($data['access_token'])) {
    exit('Spotify token exchange failed.');
}

// Fetch Spotify user profile
$ch = curl_init('https://api.spotify.com/v1/me');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $data['access_token'],
        'Accept: application/json',
        'User-Agent: MyDJRequests/1.0',
    ],
    CURLOPT_RETURNTRANSFER => true,
]);

$userJson = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($userJson === false) {
    error_log('Spotify /me curl error: ' . curl_error($ch));
}

curl_close($ch);

$user = json_decode($userJson, true);

// 🔍 TEMP DEBUG (remove later)
error_log('Spotify /me HTTP ' . $httpCode . ': ' . $userJson);

if (
    $httpCode !== 200 ||
    empty($user) ||
    !is_array($user) ||
    empty($user['id'])
) {
    error_log('Spotify profile fetch failed');
    error_log('HTTP: ' . $httpCode);
    error_log('Response: ' . $userJson);
    exit('Failed to retrieve Spotify user profile.');
}



$db = db();

$stmt = $db->prepare("
INSERT INTO dj_spotify_accounts (
    dj_id,
    spotify_user_id,
    access_token,
    refresh_token,
    expires_at,
    scope
) VALUES (
    :dj_id,
    :spotify_user_id,
    :access_token,
    :refresh_token,
    :expires_at,
    :scope
)
ON DUPLICATE KEY UPDATE
    access_token  = VALUES(access_token),
    refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
    expires_at    = VALUES(expires_at),
    scope         = VALUES(scope)
");

$stmt->execute([
    ':dj_id'           => (int) $_SESSION['dj_id'],
    ':spotify_user_id' => $user['id'],
    ':access_token'    => $data['access_token'],
    ':refresh_token'   => $data['refresh_token'] ?? null,
    ':expires_at'      => date('Y-m-d H:i:s', time() + (int)$data['expires_in']),
    ':scope'           => $data['scope'] ?? '',
]);

$returnTo = $_SESSION['spotify_return_to'] ?? '/dj/dashboard.php';
unset($_SESSION['spotify_return_to']);
header('Location: ' . $returnTo);
exit;