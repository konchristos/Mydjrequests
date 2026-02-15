<?php
declare(strict_types=1);

function renameEventSpotifyPlaylist(PDO $db, int $djId, int $eventId): void
{
    require_once APP_ROOT . '/app/lib/spotify_playlist.php';

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

    if (!$event) return;

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

    if (!$row || empty($row['spotify_playlist_id'])) return;

    // Build canonical name (SAME AS CREATE)
    $title = sanitizeSpotifyText(
        (string)($event['title'] ?? 'Event')
    );

    $date = '';
    if (!empty($event['event_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $event['event_date'])) {
        $date = $event['event_date'];
    }

    $playlistName = $date
        ? "MyDjRequests - {$date} - {$title}"
        : "MyDjRequests - {$title}";

    $token = spotifyGetDjAccessToken($djId);
    if (!$token) return;

    // Rename on Spotify
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
}