<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../app/bootstrap.php';
require_once APP_ROOT . '/app/config/spotify.php'; // 👈 IMPORTANT
require_once APP_ROOT . '/app/lib/spotify_playlist.php';

$trackId = trim($_GET['spotify_track_id'] ?? '');

if (!preg_match('/^[A-Za-z0-9]{22}$/', $trackId)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid or missing spotify_track_id'
    ]);
    exit;
}

/**
 * ✅ App-level token (Client Credentials)
 */
try {
    $token = spotify_get_access_token();
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to obtain Spotify app token',
        'detail' => $e->getMessage()
    ]);
    exit;
}

/**
 * Fetch audio features
 */
$res = spotifyApiJson(
    'GET',
    'https://api.spotify.com/v1/audio-features/' . rawurlencode($trackId),
    $token
);

if (!$res['ok']) {
    echo json_encode([
        'ok' => false,
        'error' => $res['error'],
        'status' => $res['status']
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'audio_features' => $res['data']
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);