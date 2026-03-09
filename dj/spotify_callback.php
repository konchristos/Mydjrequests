<?php
// /dj/spotify_callback.php
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/config/spotify.php';

function spotifyRedirectToReturn(string $status): void
{
    $fallback = '/dj/dashboard.php';
    $returnTo = $_SESSION['spotify_return_to'] ?? $fallback;
    unset($_SESSION['spotify_return_to'], $_SESSION['spotify_oauth_state']);

    $parts = parse_url((string)$returnTo);
    $path = isset($parts['path']) && str_starts_with((string)$parts['path'], '/')
        ? (string)$parts['path']
        : $fallback;
    $query = isset($parts['query']) ? (string)$parts['query'] : '';

    parse_str($query, $qp);
    $qp['spotify_auth'] = $status;
    $qs = http_build_query($qp);

    header('Location: ' . $path . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}

// Require DJ session
if (empty($_SESSION['dj_id'])) {
    exit('DJ session missing.');
}

// User pressed "Cancel" on Spotify consent screen.
if (!empty($_GET['error'])) {
    $oauthError = (string)$_GET['error'];
    spotifyRedirectToReturn($oauthError === 'access_denied' ? 'cancelled' : 'invalid');
}

if (
    empty($_GET['code']) ||
    empty($_GET['state']) ||
    $_GET['state'] !== ($_SESSION['spotify_oauth_state'] ?? null)
) {
    spotifyRedirectToReturn('invalid');
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
    spotifyRedirectToReturn('token_failed');
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
    spotifyRedirectToReturn('profile_failed');
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

spotifyRedirectToReturn('connected');
