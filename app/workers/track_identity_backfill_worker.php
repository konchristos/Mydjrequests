<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$db = db();
trackIdentityEnsureSchema($db);

$options = getopt('', ['limit::', 'dry-run']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 500;
$dryRun = array_key_exists('dry-run', $options);

echo "Track identity backfill started at " . date('c') . PHP_EOL;
echo "Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE') . " | Limit: {$limit}" . PHP_EOL . PHP_EOL;

$updatedSpotify = 0;
$updatedRequests = 0;
$scannedSpotify = 0;
$scannedRequests = 0;

$spotifyStmt = $db->prepare("
    SELECT id, spotify_track_id, track_name, artist_name
    FROM spotify_tracks
    WHERE track_identity_id IS NULL
    ORDER BY id ASC
    LIMIT :lim
");
$spotifyStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$spotifyStmt->execute();
$spotifyRows = $spotifyStmt->fetchAll(PDO::FETCH_ASSOC);

$spotifyUpd = $db->prepare("
    UPDATE spotify_tracks
    SET track_identity_id = :tid
    WHERE id = :id
      AND track_identity_id IS NULL
");

foreach ($spotifyRows as $row) {
    $scannedSpotify++;
    $tid = trackIdentityResolveForRequest(
        $db,
        (string)($row['spotify_track_id'] ?? ''),
        (string)($row['track_name'] ?? ''),
        (string)($row['artist_name'] ?? '')
    );
    if (!$tid) {
        continue;
    }

    if ($dryRun) {
        continue;
    }

    $spotifyUpd->execute([
        ':tid' => $tid,
        ':id' => (int)$row['id'],
    ]);
    if ($spotifyUpd->rowCount() > 0) {
        $updatedSpotify++;
    }
}

$requestStmt = $db->prepare("
    SELECT id, spotify_track_id, song_title, artist
    FROM song_requests
    WHERE track_identity_id IS NULL
    ORDER BY id ASC
    LIMIT :lim
");
$requestStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$requestStmt->execute();
$requestRows = $requestStmt->fetchAll(PDO::FETCH_ASSOC);

$requestUpd = $db->prepare("
    UPDATE song_requests
    SET track_identity_id = :tid
    WHERE id = :id
      AND track_identity_id IS NULL
");

foreach ($requestRows as $row) {
    $scannedRequests++;
    $tid = trackIdentityResolveForRequest(
        $db,
        (string)($row['spotify_track_id'] ?? ''),
        (string)($row['song_title'] ?? ''),
        (string)($row['artist'] ?? '')
    );
    if (!$tid) {
        continue;
    }

    if ($dryRun) {
        continue;
    }

    $requestUpd->execute([
        ':tid' => $tid,
        ':id' => (int)$row['id'],
    ]);
    if ($requestUpd->rowCount() > 0) {
        $updatedRequests++;
    }
}

echo "Spotify rows scanned: {$scannedSpotify}" . PHP_EOL;
echo "Spotify rows updated: {$updatedSpotify}" . PHP_EOL;
echo "Song request rows scanned: {$scannedRequests}" . PHP_EOL;
echo "Song request rows updated: {$updatedRequests}" . PHP_EOL;
echo "Finished at " . date('c') . PHP_EOL;
