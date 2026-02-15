<?php
/**
 * Spotify API helper
 *
 * Depends on:
 *   app/config/spotify.php
 *   app/lib/spotify_oauth.php   (A6)
 *
 * IMPORTANT:
 * - This file must NEVER echo or print
 * - All errors are swallowed and return null
 */

require_once APP_ROOT . '/app/config/spotify.php';


/**
 * Fetch full Spotify metadata for a track:
 * - Track object
 * - Audio features
 *
 * If $djId is provided, uses DJ OAuth token (auto-refresh).
 * Otherwise falls back to app-level token.
 *
 * @param string   $trackId
 * @param int|null $djId
 * @return array|null
 */
function spotifyFetchTrackMetadata(string $trackId, ?int $djId = null): ?array
{
    try {
        $token = $djId
            ? getSpotifyAccessTokenForDj($djId)
            : spotify_get_access_token();
    } catch (Throwable $e) {
        return null;
    }

    if (empty($token)) {
        return null;
    }

    $headers = [
        'Authorization: Bearer ' . $token
    ];

    $track = spotifyApiGet(
        "https://api.spotify.com/v1/tracks/{$trackId}",
        $headers
    );

    if (!is_array($track)) {
        return null;
    }

    $features = spotifyApiGet(
        "https://api.spotify.com/v1/audio-features/{$trackId}",
        $headers
    );

    return [
        'track'    => $track,
        'features' => is_array($features) ? $features : null
    ];
}

/**
 * Fetch Spotify artist metadata
 *
 * @param string   $artistId
 * @param int|null $djId
 * @return array|null
 */
function spotifyFetchArtist(string $artistId, ?int $djId = null): ?array
{
    try {
        $token = $djId
            ? getSpotifyAccessTokenForDj($djId)
            : spotify_get_access_token();
    } catch (Throwable $e) {
        return null;
    }

    if (empty($token)) {
        return null;
    }

    return spotifyApiGet(
        "https://api.spotify.com/v1/artists/{$artistId}",
        ['Authorization: Bearer ' . $token]
    );
}

/**
 * Generic Spotify GET request
 *
 * @param string $url
 * @param array  $headers
 * @return array|null
 */
function spotifyApiGet(string $url, array $headers): ?array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        curl_close($ch);
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        return null;
    }

    $json = json_decode($response, true);
    return is_array($json) ? $json : null;
}