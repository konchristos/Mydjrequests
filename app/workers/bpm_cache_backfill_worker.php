<?php
// app/workers/bpm_cache_backfill_worker.php
// Backfills spotify_tracks cache from bpm_test_tracks using fuzzy matching.

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap_internal.php';
require_once APP_ROOT . '/BPM/bpm_matching/matching.php';

$db = db();

$options = getopt('', ['limit::', 'dry-run']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 300;
$dryRun = array_key_exists('dry-run', $options);

function hasColumn(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function pickGenreColumn(PDO $db): ?string
{
    foreach (['genre', 'genres', 'genre_text'] as $candidate) {
        if (hasColumn($db, 'spotify_tracks', $candidate)) {
            return $candidate;
        }
    }
    return null;
}

echo "BPM cache backfill started at " . date('c') . PHP_EOL;

echo "Mode: " . ($dryRun ? 'DRY RUN' : 'WRITE') . " | Limit: {$limit}" . PHP_EOL;

$hasBpm = hasColumn($db, 'spotify_tracks', 'bpm');
$hasKey = hasColumn($db, 'spotify_tracks', 'musical_key');
$hasYear = hasColumn($db, 'spotify_tracks', 'release_year');
$genreColumn = pickGenreColumn($db);

if (!$hasBpm && !$hasKey && !$hasYear && !$genreColumn) {
    echo "No target cache columns found on spotify_tracks. Exiting." . PHP_EOL;
    exit;
}

$where = [];
if ($hasBpm) {
    $where[] = '(st.bpm IS NULL OR st.bpm = 0)';
}
if ($hasKey) {
    $where[] = '(st.musical_key IS NULL OR st.musical_key = "")';
}
if ($hasYear) {
    $where[] = '(st.release_year IS NULL OR st.release_year = 0)';
}
if ($genreColumn) {
    $where[] = '(st.`' . $genreColumn . '` IS NULL OR st.`' . $genreColumn . '` = "")';
}

$sql = "
    SELECT
      st.spotify_track_id,
      st.track_name,
      st.artist_name,
      st.duration_ms,
      st.bpm,
      st.release_year,
      st.release_date,
      st.last_refreshed_at
    FROM spotify_tracks st
    WHERE st.spotify_track_id IS NOT NULL
      AND st.spotify_track_id <> ''
      AND (" . implode(' OR ', $where) . ")
    ORDER BY st.last_refreshed_at ASC, st.created_at ASC
    LIMIT :lim
";

$tracksStmt = $db->prepare($sql);
$tracksStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$tracksStmt->execute();
$tracks = $tracksStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$tracks) {
    echo "No cache rows need enrichment." . PHP_EOL;
    exit;
}

echo "Tracks to process: " . count($tracks) . PHP_EOL;

$candStmt = $db->prepare(
    "SELECT id, title, artist, bpm, key_text, year, genre, time_seconds
     FROM bpm_test_tracks
     WHERE bpm IS NOT NULL
       AND artist LIKE ?
     ORDER BY id DESC
     LIMIT 250"
);

$linkUpsert = $db->prepare(
    "INSERT INTO track_links
      (spotify_track_id, bpm_track_id, confidence_score, confidence_level, match_meta)
     VALUES
      (:spotify_id, :bpm_track_id, :score, :level, :meta)
     ON DUPLICATE KEY UPDATE
      bpm_track_id = VALUES(bpm_track_id),
      confidence_score = VALUES(confidence_score),
      confidence_level = VALUES(confidence_level),
      match_meta = VALUES(match_meta)"
);

$updated = 0;
$matched = 0;
$noMatch = 0;
$errors = 0;

$artistCandidatesCache = [];

foreach ($tracks as $t) {
    $spotifyId = (string)($t['spotify_track_id'] ?? '');
    $title = trim((string)($t['track_name'] ?? ''));
    $artist = trim((string)($t['artist_name'] ?? ''));

    if ($spotifyId === '' || $title === '' || $artist === '') {
        $noMatch++;
        continue;
    }

    $artistKey = mb_strtolower($artist);
    if (!isset($artistCandidatesCache[$artistKey])) {
        $candStmt->execute(['%' . $artist . '%']);
        $artistCandidatesCache[$artistKey] = $candStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $candidates = $artistCandidatesCache[$artistKey];
    if (!$candidates) {
        $noMatch++;
        continue;
    }

    $spotifyTrack = [
        'title' => $title,
        'artist' => $artist,
        'duration_seconds' => !empty($t['duration_ms']) ? (int)round(((int)$t['duration_ms']) / 1000) : null,
        'bpm' => isset($t['bpm']) && is_numeric($t['bpm']) ? (float)$t['bpm'] : null,
        'year' => isset($t['release_year']) && is_numeric($t['release_year']) ? (int)$t['release_year'] : null,
    ];

    $match = matchSpotifyToBpm($spotifyTrack, $candidates);
    if (!$match) {
        $noMatch++;
        continue;
    }

    $matchRow = null;
    foreach ($candidates as $c) {
        if ((int)$c['id'] === (int)$match['bpm_track_id']) {
            $matchRow = $c;
            break;
        }
    }

    if (!$matchRow) {
        $noMatch++;
        continue;
    }

    $matched++;

    $set = [];
    $params = [':sid' => $spotifyId];

    if ($hasBpm && isset($matchRow['bpm']) && is_numeric($matchRow['bpm'])) {
        $set[] = 'bpm = CASE WHEN (bpm IS NULL OR bpm = 0) THEN :bpm ELSE bpm END';
        $params[':bpm'] = (float)$matchRow['bpm'];
    }

    if ($hasKey && !empty($matchRow['key_text'])) {
        $set[] = 'musical_key = CASE WHEN (musical_key IS NULL OR musical_key = "") THEN :mkey ELSE musical_key END';
        $params[':mkey'] = trim((string)$matchRow['key_text']);
    }

    if ($hasYear && !empty($matchRow['year']) && is_numeric($matchRow['year'])) {
        $set[] = 'release_year = CASE WHEN (release_year IS NULL OR release_year = 0) THEN :ryear ELSE release_year END';
        $params[':ryear'] = (int)$matchRow['year'];
    }

    if ($genreColumn && !empty($matchRow['genre'])) {
        $set[] = '`' . $genreColumn . '` = CASE WHEN (`' . $genreColumn . '` IS NULL OR `' . $genreColumn . '` = "") THEN :genre ELSE `' . $genreColumn . '` END';
        $params[':genre'] = trim((string)$matchRow['genre']);
    }

    if (!$set) {
        continue;
    }

    try {
        if (!$dryRun) {
            $db->beginTransaction();

            $sqlUpdate = 'UPDATE spotify_tracks SET ' . implode(', ', $set) . ', last_refreshed_at = NOW() WHERE spotify_track_id = :sid';
            $upd = $db->prepare($sqlUpdate);
            $upd->execute($params);

            $linkUpsert->execute([
                ':spotify_id' => $spotifyId,
                ':bpm_track_id' => (int)$match['bpm_track_id'],
                ':score' => (int)$match['score'],
                ':level' => (string)$match['confidence'],
                ':meta' => json_encode($match['meta'], JSON_UNESCAPED_UNICODE),
            ]);

            $db->commit();
        }

        $updated++;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $errors++;
    }
}

echo "Matched: {$matched}" . PHP_EOL;
echo "Updated: {$updated}" . PHP_EOL;
echo "No match: {$noMatch}" . PHP_EOL;
echo "Errors: {$errors}" . PHP_EOL;
echo "Finished at " . date('c') . PHP_EOL;
