<?php
//api/debug/spotify/fetch_track.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../app/bootstrap.php';
require_once APP_ROOT . '/app/lib/spotify_playlist.php';

/**
 * INPUT
 * Example:
 * /api/debug/spotify/fetch_track.php?spotify_track_id=5CI1FP2Volc9wjz2MBZsGx
 */
$trackId = trim($_GET['spotify_track_id'] ?? '');

if (!preg_match('/^[A-Za-z0-9]{22}$/', $trackId)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid or missing spotify_track_id'
    ]);
    exit;
}

/**
 * Use SERVICE token (client credentials)
 */
$token = spotifyGetServiceAccessToken();
if (!$token) {
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to obtain Spotify service token'
    ]);
    exit;
}

/**
 * Fetch track
 */
$res = spotifyApiJson(
    'GET',
    'https://api.spotify.com/v1/tracks/' . rawurlencode($trackId),
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

/**
 * Return raw Spotify data (no DB writes)
 */
echo json_encode([
    'ok' => true,
    'track' => $res['data']
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;