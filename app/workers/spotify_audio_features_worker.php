<?php
// app/workers/spotify_audio_features_worker.php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap_public.php';   // ✅ use public bootstrap for db()
require_once APP_ROOT . '/app/config/spotify.php';   // must provide spotify_get_access_token()
//require_once APP_ROOT . '/app/lib/spotify_playlist.php';

header('Content-Type: text/plain; charset=utf-8');

$db = db();

echo "🎧 Spotify Audio Features Worker started at " . date('c') . PHP_EOL;

// 1️⃣ Fetch tracks missing BPM (and not recently refreshed)
$sql = "
SELECT spotify_track_id
FROM spotify_tracks
WHERE spotify_track_id IS NOT NULL
  AND spotify_track_id <> ''
  AND (bpm IS NULL OR bpm = 0)
ORDER BY created_at DESC
LIMIT 50
";

$trackIds = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);

if (!$trackIds) {
    echo "✅ No tracks need enrichment." . PHP_EOL;
    exit;
}

echo "🔍 Found " . count($trackIds) . " tracks needing audio features." . PHP_EOL;

// 2️⃣ Get Spotify access token
try {
    
    
$djId = (int)$db->query("
    SELECT dj_id
    FROM dj_spotify_accounts
    WHERE expires_at > NOW()
    ORDER BY expires_at DESC
    LIMIT 1
")->fetchColumn();

if (!$djId) {
    echo "❌ No DJ Spotify accounts available" . PHP_EOL;
    exit;
}
    
    $token = spotify_get_access_token();

    if (!$token) {
        echo "❌ No Spotify app access token returned" . PHP_EOL;
        exit;
    }


    echo "🔑 Using Spotify token: " . substr($token, 0, 15) . "..." . PHP_EOL;
    
    
    
    // =========================
// 🧪 SANITY TEST (TEMP)
// =========================
$testId = '2bJvI42r8EF3wxjOuDav4r'; // known Spotify track

$testUrl = "https://api.spotify.com/v1/audio-features/{$testId}";

$ch = curl_init($testUrl);
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
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo PHP_EOL;
echo "🧪 SANITY TEST STATUS: {$status}" . PHP_EOL;
echo "🧪 RESPONSE:" . PHP_EOL;
echo $response . PHP_EOL;
echo "🧪 END SANITY TEST" . PHP_EOL;

// STOP HERE — do not continue to batch logic
exit;
    

} catch (Throwable $e) {
    echo "❌ Failed to get Spotify token: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// 3️⃣ Prepare update statement
$update = $db->prepare("
UPDATE spotify_tracks SET
    bpm = :bpm,
    musical_key = :key,
    energy = :energy,
    danceability = :danceability,
    last_refreshed_at = NOW()
WHERE spotify_track_id = :track_id
");

// 4️⃣ Batch call Spotify audio-features (max 100 ids per request)
$updated = 0;

foreach (array_chunk($trackIds, 100) as $chunk) {

    $endpoint = 'https://api.spotify.com/v1/audio-features?ids=' . implode(',', array_map('rawurlencode', $chunk));

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: MyDJRequests/1.0 (support@mydjrequests.com)',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        echo "❌ Curl error: " . curl_error($ch) . PHP_EOL;
        curl_close($ch);
        continue;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        echo "⚠️ Spotify returned status {$status}. Response: {$response}" . PHP_EOL;
        continue;
    }

    $json = json_decode($response, true);
    $features = $json['audio_features'] ?? [];

    if (!is_array($features)) {
        echo "⚠️ Unexpected Spotify payload" . PHP_EOL;
        continue;
    }

    foreach ($features as $feature) {
        if (!is_array($feature) || empty($feature['id'])) {
            continue;
        }

        $update->execute([
            ':bpm'          => isset($feature['tempo']) ? (float)$feature['tempo'] : null,
            ':key'          => $feature['key'] ?? null,
            ':energy'       => $feature['energy'] ?? null,
            ':danceability' => $feature['danceability'] ?? null,
            ':track_id'     => $feature['id'],
        ]);

        echo "✅ Enriched {$feature['id']} (tempo=" . ($feature['tempo'] ?? 'null') . ")" . PHP_EOL;
        $updated++;
    }

    // polite pause
    usleep(200000); // 200ms
}

echo "✅ Updated {$updated} tracks." . PHP_EOL;
echo "🏁 Worker finished at " . date('c') . PHP_EOL;