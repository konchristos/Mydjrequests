<?php
// app/config/spotify.php

// 1) Put your real Spotify API credentials here
//    from https://developer.spotify.com/dashboard
define('SPOTIFY_CLIENT_ID',     'ef773cfe093c4f9382afde1cee5599e5');
define('SPOTIFY_CLIENT_SECRET', '26cb4899442b4eb3af050842b72a70c9');

// 2) Where to cache the access token
//    Make sure this folder is writeable (or change path if needed)
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 1)); // safety fallback
}
define('SPOTIFY_TOKEN_CACHE', APP_ROOT . '/storage/spotify_token.json');

/**
 * Get an app-level Spotify access token using Client Credentials flow.
 * Caches the token in a JSON file until it expires.
 *
 * @return string access token
 * @throws RuntimeException on failure
 */
function spotify_get_access_token(): string
{
    // Try cache first
    if (file_exists(SPOTIFY_TOKEN_CACHE)) {
        $data = json_decode(file_get_contents(SPOTIFY_TOKEN_CACHE), true);
        if (!empty($data['access_token']) && !empty($data['expires_at'])) {
            if ($data['expires_at'] > time() + 60) {
                return $data['access_token'];
            }
        }
    }

    // Need to request a new token
    $ch = curl_init('https://accounts.spotify.com/api/token');

    $authHeader = base64_encode(
        SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET
    );

    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => http_build_query(['grant_type' => 'client_credentials']),
        CURLOPT_HTTPHEADER      => [
            'Authorization: Basic ' . $authHeader,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 10,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Spotify token request failed: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($status !== 200 || empty($data['access_token']) || empty($data['expires_in'])) {
        throw new RuntimeException('Spotify token error: ' . $response);
    }

    $token      = $data['access_token'];
    $expires_at = time() + (int)$data['expires_in'];

    // Save cache (ignore failures quietly)
    @file_put_contents(
        SPOTIFY_TOKEN_CACHE,
        json_encode([
            'access_token' => $token,
            'expires_at'   => $expires_at,
        ]),
        LOCK_EX
    );

    return $token;
}



function spotify_get_user_access_token(int $djId): string
{
    $db = db();

    $stmt = $db->prepare("
        SELECT *
        FROM dj_spotify_accounts
        WHERE dj_id = ?
    ");
    $stmt->execute([$djId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('DJ has not connected Spotify.');
    }

    if (strtotime($row['expires_at']) > time() + 60) {
        return $row['access_token'];
    }

    // Refresh token
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
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
        throw new RuntimeException('Spotify token refresh failed.');
    }

    $stmt = $db->prepare("
        UPDATE dj_spotify_accounts
        SET access_token = ?, expires_at = ?
        WHERE dj_id = ?
    ");
    $stmt->execute([
        $data['access_token'],
        date('Y-m-d H:i:s', time() + $data['expires_in']),
        $djId,
    ]);

    return $data['access_token'];
}