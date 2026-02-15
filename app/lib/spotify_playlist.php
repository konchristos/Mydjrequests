<?php
//app/lib/spotify_playlist.php
declare(strict_types=1);

require_once APP_ROOT . '/app/config/spotify.php';


/**
 * Sanitize text for Spotify playlist names
 */
function sanitizeSpotifyText(string $text): string
{
    // Trim + normalize whitespace
    $text = trim(preg_replace('/\s+/', ' ', $text));

    // Spotify allows UTF-8, but strip control chars
    $text = preg_replace('/[[:cntrl:]]/', '', $text);

    // Optional: cap length (Spotify max is generous, but be safe)
    return mb_substr($text, 0, 100);
}


/**
 * Spotify Playlist helper
 * Uses DJ OAuth tokens (dj_spotify_accounts)
 * NEVER echoes or prints
 */

function spotifyGetDjAccessToken(int $djId): ?string
{
    $db = db();

    $stmt = $db->prepare("
        SELECT access_token, refresh_token, expires_at
        FROM dj_spotify_accounts
        WHERE dj_id = ?
        LIMIT 1
    ");
    $stmt->execute([$djId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    // âœ… Still valid (with 60s buffer)
    if (strtotime($row['expires_at']) > time() + 60) {
        return $row['access_token'];
    }

    // ðŸ”„ Refresh token
    if (empty($row['refresh_token'])) {
        return null;
    }

    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode(
                SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET
            ),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (empty($data['access_token']) || empty($data['expires_in'])) {
        return null;
    }

    $newToken     = $data['access_token'];
    $newExpiresAt = date('Y-m-d H:i:s', time() + (int)$data['expires_in']);

    // Spotify MAY return a new refresh token (rare, but handle it)
    $newRefresh = $data['refresh_token'] ?? $row['refresh_token'];

    $upd = $db->prepare("
        UPDATE dj_spotify_accounts
        SET access_token = ?, refresh_token = ?, expires_at = ?
        WHERE dj_id = ?
    ");
    $upd->execute([$newToken, $newRefresh, $newExpiresAt, $djId]);

    return $newToken;

}


function spotifyApiJson(string $method, string $url, string $accessToken, ?array $payload = null): array
{
    $ch = curl_init($url);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: MyDJRequests/1.0 (support@mydjrequests.com)',
    ];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'error' => $err];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];

    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'data' => $json];
    }

    return [
        'ok' => false,
        'status' => $status,
        'error' => $json['error']['message'] ?? $raw,
        'data' => $json
    ];
}

/**
 * Create (or fetch existing) Spotify playlist for an event.
 * Stores playlist in event_spotify_playlists (unique per event).
 */
function ensureSpotifyPlaylistForEvent(PDO $db, int $djId, int $eventId, string $playlistName, bool $isPublic = false): array
{
    // existing?
    $stmt = $db->prepare("SELECT * FROM event_spotify_playlists WHERE event_id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['ok' => true, 'playlist' => $row, 'created' => false];
    }

    // need DJ spotify_user_id
    $stmt = $db->prepare("SELECT spotify_user_id FROM dj_spotify_accounts WHERE dj_id = ? LIMIT 1");
    $stmt->execute([$djId]);
    $acct = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acct || empty($acct['spotify_user_id'])) {
        return ['ok' => false, 'error' => 'Spotify not connected for this DJ.'];
    }

    $token = spotifyGetDjAccessToken($djId);
    if (!$token) {
        return ['ok' => false, 'error' => 'Unable to get Spotify access token.'];
    }

    $create = spotifyApiJson('POST', 'https://api.spotify.com/v1/users/' . rawurlencode($acct['spotify_user_id']) . '/playlists', $token, [
        'name'        => $playlistName,
        'public'      => $isPublic,
        'collaborative' => false,
        'description' => '🎧 Generated by MyDJRequests for this event ✨ Patrons submit song requests at https://mydjrequests.com',
    ]);

    if (!$create['ok']) {
        return ['ok' => false, 'error' => 'Spotify create playlist failed: ' . $create['error']];
    }

    $pl = $create['data'];
    $playlistId  = $pl['id'] ?? null;
    $playlistUrl = $pl['external_urls']['spotify'] ?? null;

    if (!$playlistId) {
        return ['ok' => false, 'error' => 'Spotify playlist response missing ID.'];
    }

    $ins = $db->prepare("
        INSERT INTO event_spotify_playlists
          (event_id, dj_id, spotify_playlist_id, spotify_playlist_url, playlist_name, is_public)
        VALUES
          (:event_id, :dj_id, :pid, :url, :name, :pub)
    ");
    $ins->execute([
        ':event_id' => $eventId,
        ':dj_id'    => $djId,
        ':pid'      => $playlistId,
        ':url'      => $playlistUrl,
        ':name'     => $playlistName,
        ':pub'      => $isPublic ? 1 : 0,
    ]);

    $stmt = $db->prepare("SELECT * FROM event_spotify_playlists WHERE event_id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);

    return ['ok' => true, 'playlist' => $saved, 'created' => true];
}

/**
 * Add requested tracks (spotify_track_id) to the event playlist.
 * De-dupes using event_spotify_playlist_tracks unique key.
 */
/**
 * Sync ACTIVE Spotify tracks for an event into its playlist.
 * Uses spotify_tracks.added_to_playlist_at for idempotency.
 */
function syncEventPlaylistFromRequests(PDO $db, int $djId, int $eventId): array
{
    // 1️⃣ Get playlist
    $stmt = $db->prepare("
        SELECT spotify_playlist_id
        FROM event_spotify_playlists
        WHERE event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$eventId]);
    $pl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pl || empty($pl['spotify_playlist_id'])) {
        return ['ok' => false, 'error' => 'No playlist for event'];
    }

    $playlistId = $pl['spotify_playlist_id'];

    // 2️⃣ Get desired tracks (DB is source of truth)
    $stmt = $db->prepare("
        SELECT DISTINCT spotify_track_id
        FROM song_requests
        WHERE event_id = ?
          AND spotify_track_id IS NOT NULL
          AND status IN ('new', 'accepted')
    ");
    $stmt->execute([$eventId]);

    $desired = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'spotify_track_id');

    // 3️⃣ Get Spotify token
    $token = spotifyGetDjAccessToken($djId);
    if (!$token) {
        return ['ok' => false, 'error' => 'Spotify not connected'];
    }

    // 4️⃣ Fetch current playlist tracks from Spotify
    $existing = [];
    $url = "https://api.spotify.com/v1/playlists/" . rawurlencode($playlistId) . "/tracks?limit=100";

    while ($url) {
        $res = spotifyApiJson('GET', $url, $token);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => 'Failed reading playlist'];
        }

        foreach ($res['data']['items'] as $item) {
            if (!empty($item['track']['id'])) {
                $existing[] = $item['track']['id'];
            }
        }

        $url = $res['data']['next'] ?? null;
    }

    // 5️⃣ Diff
    $toAdd    = array_diff($desired, $existing);
    $toRemove = array_diff($existing, $desired);

    // 6️⃣ Remove first (played/skipped)
    if ($toRemove) {
        spotifyApiJson(
            'DELETE',
            "https://api.spotify.com/v1/playlists/" . rawurlencode($playlistId) . "/tracks",
            $token,
            [
                'tracks' => array_map(fn($id) => ['uri' => "spotify:track:$id"], $toRemove)
            ]
        );
    }

    // 7️⃣ Add missing (new/accepted)
    foreach (array_chunk($toAdd, 100) as $chunk) {
        spotifyApiJson(
            'POST',
            "https://api.spotify.com/v1/playlists/" . rawurlencode($playlistId) . "/tracks",
            $token,
            [
                'uris' => array_map(fn($id) => "spotify:track:$id", $chunk)
            ]
        );
    }

    return [
        'ok'      => true,
        'added'   => count($toAdd),
        'removed' => count($toRemove)
    ];
}




function spotifyGetPlaylistVisibility(string $playlistId, string $accessToken): ?bool
{
    $res = spotifyApiJson(
        'GET',
        'https://api.spotify.com/v1/playlists/' . rawurlencode($playlistId),
        $accessToken
    );

    if (!$res['ok']) {
        return null; // can't verify (missing scope, not found, etc.)
    }

    // Spotify returns public as true/false (can be null in some edge cases)
    if (!array_key_exists('public', $res['data'])) {
        return null;
    }

    return is_bool($res['data']['public']) ? $res['data']['public'] : null;
}





function renameEventSpotifyPlaylist(PDO $db, int $djId, int $eventId): array
{
    // Fetch event
    $stmt = $db->prepare("
        SELECT title, event_date
        FROM events
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$eventId, $djId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        return ['ok' => false, 'error' => 'event_not_found'];
    }

    // Fetch playlist
    $stmt = $db->prepare("
        SELECT spotify_playlist_id
        FROM event_spotify_playlists
        WHERE event_id = ?
          AND dj_id = ?
        LIMIT 1
    ");
    $stmt->execute([$eventId, $djId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['spotify_playlist_id'])) {
        return ['ok' => true, 'skipped' => 'no_playlist'];
    }

    // Build canonical name (SAME AS CREATE)
    $title = sanitizeSpotifyText((string)($event['title'] ?? 'Event'));

    $date = '';
    if (!empty($event['event_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $event['event_date'])) {
        $date = $event['event_date'];
    }

    $playlistName = $date
        ? "MyDjRequests - {$date} - {$title}"
        : "MyDjRequests - {$title}";

    // Spotify token
    $token = spotifyGetDjAccessToken($djId);
    if (!$token) {
        return ['ok' => true, 'skipped' => 'no_token'];
    }

    // Rename playlist
    spotifyApiJson(
        'PUT',
        'https://api.spotify.com/v1/playlists/' . rawurlencode($row['spotify_playlist_id']),
        $token,
        ['name' => $playlistName]
    );

    // Update local cache
    $upd = $db->prepare("
        UPDATE event_spotify_playlists
        SET playlist_name = ?
        WHERE event_id = ?
          AND dj_id = ?
    ");
    $upd->execute([$playlistName, $eventId, $djId]);

    return ['ok' => true];
}



function spotifyGetServiceAccessToken(): ?string
{
    $ch = curl_init('https://accounts.spotify.com/api/token');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials'
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode(
                SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET
            ),
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($raw, true);

    if (empty($json['access_token'])) {
        return null;
    }

    return $json['access_token'];
}


/**
 * Get Spotify APP access token (Client Credentials)
 * Used for audio features, analysis, popularity, cron jobs
 */
function spotifyGetAppAccessToken(): ?string
{
    static $cachedToken = null;
    static $expiresAt   = 0;

    // Reuse token if still valid
    if ($cachedToken && time() < $expiresAt - 60) {
        return $cachedToken;
    }

    $clientId     = getenv('SPOTIFY_CLIENT_ID');
    $clientSecret = getenv('SPOTIFY_CLIENT_SECRET');

    if (!$clientId || !$clientSecret) {
        return null;
    }

    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials'
        ])
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || !$response) {
        return null;
    }

    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        return null;
    }

    $cachedToken = $data['access_token'];
    $expiresAt   = time() + (int)$data['expires_in'];

    return $cachedToken;
}




