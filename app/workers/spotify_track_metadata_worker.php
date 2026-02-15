<?php
// app/workers/spotify_track_metadata_worker.php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap_internal.php';
require_once APP_ROOT . '/app/lib/spotify_playlist.php';

$db = db();

echo "🎼 Spotify Track Metadata Worker started at " . date('c') . PHP_EOL;

// Fetch tracks missing metadata
$stmt = $db->prepare("
    SELECT spotify_track_id
    FROM spotify_tracks
    WHERE (duration_ms IS NULL OR release_date IS NULL)
      AND spotify_track_id IS NOT NULL
    ORDER BY created_at ASC
    LIMIT 20
");
$stmt->execute();

$trackIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$trackIds) {
    echo "✅ No tracks need metadata enrichment." . PHP_EOL;
    exit;
}

$token = spotifyGetAppAccessToken();
if (!$token) {
    echo "❌ Failed to get Spotify app token." . PHP_EOL;
    exit(1);
}

$update = $db->prepare("
    UPDATE spotify_tracks SET
        duration_ms = :duration,
        release_date = :release_date,
        last_refreshed_at = NOW()
    WHERE spotify_track_id = :track_id
");

$updated = 0;

foreach ($trackIds as $trackId) {

    $endpoint = 'https://api.spotify.com/v1/audio-features/' . rawurlencode($trackId);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: MyDJRequests/1.0 (support@mydjrequests.com)',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        echo "❌ Curl error for {$trackId}: " . curl_error($ch) . PHP_EOL;
        curl_close($ch);
        continue;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        echo "⚠️ Spotify returned {$status} for {$trackId}: {$response}" . PHP_EOL;
        continue;
    }

    $feature = json_decode($response, true);

    if (empty($feature['id'])) {
        echo "⚠️ No features for {$trackId}" . PHP_EOL;
        continue;
    }

    $update->execute([
        ':bpm'          => isset($feature['tempo']) ? (float)$feature['tempo'] : null,
        ':key'          => $feature['key'] ?? null,
        ':energy'       => $feature['energy'] ?? null,
        ':danceability' => $feature['danceability'] ?? null,
        ':track_id'     => $trackId,
    ]);

    echo "✅ Enriched {$trackId} (tempo=" . ($feature['tempo'] ?? 'null') . ")" . PHP_EOL;
    $updated++;

    usleep(150000); // 150ms polite delay
}

echo "🏁 Metadata worker finished at " . date('c') . PHP_EOL;