<?php
// api/spotify_search.php
// Lightweight Spotify track search for the guest request form

require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once APP_ROOT . '/app/config/spotify.php';

header('Content-Type: application/json; charset=utf-8');

// Only GET allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$q = trim($_GET['q'] ?? '');
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1 || $limit > 10) {
    $limit = 5;
}

// Simple validation: require at least 2 characters
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'tracks' => []]);
    exit;
}

try {
    $token = spotify_get_access_token();
} catch (Throwable $e) {
    // Fail gracefully – user can still request manually
    echo json_encode([
        'ok'    => false,
        'error' => 'Spotify unavailable'
    ]);
    exit;
}

// Call Spotify search API
$endpoint = 'https://api.spotify.com/v1/search?' . http_build_query([
    'q'     => $q,
    'type'  => 'track',
    'limit' => $limit,
]);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);

$response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    echo json_encode(['ok' => false, 'error' => 'HTTP error']);
    exit;
}

$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($status !== 200 || !isset($data['tracks']['items'])) {
    echo json_encode(['ok' => false, 'error' => 'Spotify error']);
    exit;
}

$tracks = [];
foreach ($data['tracks']['items'] as $item) {
    $tracks[] = [
        'id'        => $item['id'],
        'title'     => $item['name'],
        'artist'    => isset($item['artists'][0]['name']) ? $item['artists'][0]['name'] : '',
        'album'     => $item['album']['name'] ?? '',
        'albumArt'  => $item['album']['images'][0]['url'] ?? null,
        'preview'   => $item['preview_url'] ?? null,
        'spotifyUrl'=> $item['external_urls']['spotify'] ?? null,
    ];
}

echo json_encode([
    'ok'     => true,
    'tracks' => $tracks,
]);