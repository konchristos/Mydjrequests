<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/dj_stale_matches.php';
require_dj_login();

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Premium feature']);
    exit;
}

$overrideKey = trim((string)($_GET['override_key'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$djId = (int)($_SESSION['dj_id'] ?? 0);

if ($overrideKey === '' || $djId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing override key']);
    exit;
}

mdjrEnsureDjGlobalTrackOverridesTable($db);
mdjrEnsureDjTrackAvailabilityColumns($db);

$rowStmt = $db->prepare("
    SELECT g.bpm_track_id, g.dj_track_id, b.title, b.artist
    FROM dj_global_track_overrides g
    LEFT JOIN bpm_test_tracks b
        ON b.id = g.bpm_track_id
    WHERE g.dj_id = ?
      AND g.override_key = ?
    LIMIT 1
");
$rowStmt->execute([$djId, $overrideKey]);
$selected = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$selected) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Saved match not found']);
    exit;
}

$fallback = mdjrOverrideKeySplit($overrideKey);
$baseTitle = trim((string)($selected['title'] ?? '')) ?: (string)$fallback['title'];
$baseArtist = trim((string)($selected['artist'] ?? '')) ?: (string)$fallback['artist'];
$selectedBpmTrackId = (int)($selected['bpm_track_id'] ?? 0);
$selectedDjTrackId = (int)($selected['dj_track_id'] ?? 0);
$search = $q !== '' ? $q : trim($baseArtist . ' - ' . $baseTitle);

function mdjrStaleNormalize(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/\b(remix|edit|version|mix|extended|radio|clean|dirty|revibe|rework|feat|featuring|ft|dj)\b/i', '', $value);
    $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value);
    $value = preg_replace('/\s+/', ' ', trim((string)$value));
    return (string)$value;
}

function mdjrStaleCompact(string $value): string
{
    return preg_replace('/\s+/u', '', mdjrStaleNormalize($value)) ?: '';
}

function mdjrStaleTokens(string $value): array
{
    $parts = preg_split('/\s+/', mdjrStaleNormalize($value)) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '' || mb_strlen($part, 'UTF-8') < 3) {
            continue;
        }
        $out[$part] = true;
    }
    return array_keys($out);
}

function mdjrStaleSplitQuery(string $search, string $baseTitle, string $baseArtist): array
{
    $search = trim($search);
    if ($search === '') {
        return [$baseTitle, $baseArtist];
    }
    if (preg_match('/\s[-–—]\s/u', $search)) {
        $parts = preg_split('/\s[-–—]\s/u', $search, 2);
        $left = trim((string)($parts[0] ?? ''));
        $right = trim((string)($parts[1] ?? ''));
        if ($left !== '' && $right !== '') {
            return [$right, $left];
        }
    }
    return [$baseTitle, $baseArtist];
}

function mdjrStaleSimilarity(string $a, string $b): float
{
    $a = mdjrStaleNormalize($a);
    $b = mdjrStaleNormalize($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 100.0;
    }
    similar_text($a, $b, $pct);
    return (float)$pct;
}

function mdjrStaleLooseTitle(string $value): string
{
    $value = mdjrStaleNormalize($value);
    return preg_replace('/\b([\p{L}]{3,})ing\b/u', '$1in', $value) ?: $value;
}

function mdjrStaleLooseSimilarity(string $a, string $b): float
{
    $a = mdjrStaleLooseTitle($a);
    $b = mdjrStaleLooseTitle($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 100.0;
    }
    similar_text($a, $b, $pct);
    return (float)$pct;
}

function mdjrStaleColumns(PDO $db, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $db->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    $cols = [];
    foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $col) {
        $cols[strtolower((string)$col)] = true;
    }
    $cache[$table] = $cols;
    return $cols;
}

function mdjrStaleRatingExpr(PDO $db): string
{
    $cols = mdjrStaleColumns($db, 'dj_tracks');
    foreach (['rating', 'stars', 'star_rating', 'rekordbox_rating', 'rb_rating', 'rating_raw'] as $col) {
        if (isset($cols[$col])) {
            return "
                CASE
                    WHEN d.{$col} IS NULL THEN 0
                    WHEN CAST(d.{$col} AS DECIMAL(10,2)) >= 250 THEN (CAST(d.{$col} AS DECIMAL(10,2)) / 51.0)
                    WHEN CAST(d.{$col} AS DECIMAL(10,2)) > 10 THEN (CAST(d.{$col} AS DECIMAL(10,2)) / 20.0)
                    WHEN CAST(d.{$col} AS DECIMAL(10,2)) > 5 THEN (CAST(d.{$col} AS DECIMAL(10,2)) / 2.0)
                    ELSE CAST(d.{$col} AS DECIMAL(10,2))
                END
            ";
        }
    }
    return "0";
}

function mdjrFolderBadge(string $location): string
{
    $path = trim($location);
    if ($path === '') {
        return '';
    }
    $path = preg_replace('#^file://(localhost)?#i', '', $path);
    $path = str_replace('\\', '/', (string)$path);
    $dir = trim((string)dirname($path), '/.');
    if ($dir === '' || $dir === DIRECTORY_SEPARATOR || $dir === '.') {
        return '';
    }
    return trim((string)basename($dir));
}

$tokens = array_values(array_unique(array_merge(
    mdjrStaleTokens($search),
    mdjrStaleTokens($baseTitle),
    mdjrStaleTokens($baseArtist)
)));

$manualMode = ($q !== '');
$manualPairMode = $manualMode && (bool)preg_match('/\s[-–—]\s/u', $search);
$manualBroadMode = $manualMode && !$manualPairMode;
[$manualTitle, $manualArtist] = $manualMode
    ? mdjrStaleSplitQuery($search, $baseTitle, $baseArtist)
    : [$baseTitle, $baseArtist];
$matchTitle = $manualBroadMode ? $search : $manualTitle;
$matchArtist = $manualBroadMode ? $search : $manualArtist;

$normBaseTitle = mdjrStaleNormalize($matchTitle);
$normBaseArtist = mdjrStaleNormalize($matchArtist);
$rawTitleNeedle = mb_strtolower($matchTitle !== '' ? $matchTitle : $search, 'UTF-8');
$rawArtistNeedle = mb_strtolower($matchArtist, 'UTF-8');
$compactTitleNeedle = mdjrStaleCompact($matchTitle !== '' ? $matchTitle : $search);
$compactArtistNeedle = mdjrStaleCompact($matchArtist);
$titleTokens = mdjrStaleTokens($matchTitle);
$artistTokens = mdjrStaleTokens($matchArtist);

$localNeedles = array_values(array_filter(array_unique(array_merge(
    [$rawTitleNeedle, $rawArtistNeedle, $normBaseTitle, $normBaseArtist],
    array_slice($tokens, 0, 6)
))));
if (empty($localNeedles)) {
    $localNeedles = array_values(array_filter([$search]));
}

$whereLocal = [];
$paramsLocal = [$djId];
foreach ($localNeedles as $needle) {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle, 'UTF-8')) . '%';
    $whereLocal[] = '(LOWER(d.title) LIKE ? OR LOWER(d.artist) LIKE ?)';
    $paramsLocal[] = $like;
    $paramsLocal[] = $like;
}

$ratingExpr = mdjrStaleRatingExpr($db);
$localStmt = $db->prepare("
    SELECT
        d.id AS dj_track_id,
        d.title,
        d.artist,
        d.bpm,
        d.musical_key AS key_text,
        d.release_year AS year,
        d.genre,
        d.location,
        {$ratingExpr} AS rating_value,
        MAX(CASE WHEN dpp.playlist_id IS NULL THEN 0 ELSE 1 END) AS is_preferred,
        SUBSTRING_INDEX(
            GROUP_CONCAT(
                DISTINCT CASE WHEN dpp.playlist_id IS NOT NULL THEN dp.name ELSE NULL END
                ORDER BY dp.name ASC SEPARATOR '||'
            ),
            '||',
            1
        ) AS preferred_playlist_name,
        SUBSTRING_INDEX(
            GROUP_CONCAT(DISTINCT dp.name ORDER BY dp.name ASC SEPARATOR '||'),
            '||',
            1
        ) AS any_playlist_name
    FROM dj_tracks d
    LEFT JOIN dj_playlist_tracks dpt
        ON dpt.dj_track_id = d.id
    LEFT JOIN dj_playlists dp
        ON dp.id = dpt.playlist_id
       AND dp.dj_id = d.dj_id
    LEFT JOIN dj_preferred_playlists dpp
        ON dpp.dj_id = d.dj_id
       AND dpp.playlist_id = dpt.playlist_id
    WHERE d.dj_id = ?
      AND COALESCE(d.is_available, 1) = 1
      AND (" . implode(' OR ', $whereLocal) . ")
    GROUP BY d.id
    LIMIT 500
");
$localStmt->execute($paramsLocal);

$rows = [];
foreach (($localStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $localRow) {
    $title = (string)($localRow['title'] ?? '');
    $artist = (string)($localRow['artist'] ?? '');
    $titleScore = max(mdjrStaleSimilarity($matchTitle, $title), mdjrStaleLooseSimilarity($matchTitle, $title));
    $artistScore = mdjrStaleSimilarity($matchArtist, $artist);
    $queryScore = max(mdjrStaleSimilarity($search, $title . ' ' . $artist), mdjrStaleSimilarity($search, $artist . ' ' . $title));
    $rowTitleNorm = mdjrStaleNormalize($title);
    $rowArtistNorm = mdjrStaleNormalize($artist);
    $rowTitleCompact = mdjrStaleCompact($title);
    $rowArtistCompact = mdjrStaleCompact($artist);
    $directTitleHit = ($normBaseTitle !== '' && str_contains($rowTitleNorm, $normBaseTitle)) ? 1 : 0;
    $directArtistHit = ($normBaseArtist !== '' && str_contains($rowArtistNorm, $normBaseArtist)) ? 1 : 0;
    $compactTitleHit = ($compactTitleNeedle !== '' && $rowTitleCompact !== '' && str_contains($rowTitleCompact, $compactTitleNeedle)) ? 1 : 0;
    $compactArtistHit = ($compactArtistNeedle !== '' && $rowArtistCompact !== '' && str_contains($rowArtistCompact, $compactArtistNeedle)) ? 1 : 0;
    $rawTitleHit = ($rawTitleNeedle !== '' && str_contains(mb_strtolower($title, 'UTF-8'), $rawTitleNeedle)) ? 1 : 0;
    $rawArtistHit = ($rawArtistNeedle !== '' && str_contains(mb_strtolower($artist, 'UTF-8'), $rawArtistNeedle)) ? 1 : 0;
    $score =
        ($titleScore * 0.45) +
        ($artistScore * 0.20) +
        ($queryScore * 0.15) +
        ($directTitleHit * 10) +
        ($directArtistHit * 5) +
        ($compactTitleHit * 10) +
        ($compactArtistHit * 10) +
        ($rawTitleHit * 12) +
        ($rawArtistHit * 8) +
        18.0;

    if ($manualMode) {
        $rowTitleTokens = mdjrStaleTokens($title);
        $rowArtistTokens = mdjrStaleTokens($artist);
        $titleTokenHits = 0;
        foreach ($titleTokens as $tk) {
            if (in_array($tk, $rowTitleTokens, true)) {
                $titleTokenHits++;
            }
        }
        $artistTokenHits = 0;
        foreach ($artistTokens as $tk) {
            if (in_array($tk, $rowArtistTokens, true)) {
                $artistTokenHits++;
            }
        }
        $hasStrongTitle = $titleTokenHits >= max(1, min(2, count($titleTokens)));
        $hasStrongArtist = $artistTokenHits >= max(1, min(2, count($artistTokens)));
        $hasDirect = ($rawTitleHit === 1 || $directTitleHit === 1 || $compactTitleHit === 1 || $titleScore >= 64.0);
        if (!$hasStrongTitle && !$hasStrongArtist && !$hasDirect) {
            continue;
        }
        if ($manualPairMode && $matchArtist !== '') {
            $artistMatched = ($rawArtistHit === 1 || $directArtistHit === 1 || $compactArtistHit === 1 || $artistScore >= 55.0);
            if (!$artistMatched && $titleScore < 92.0) {
                continue;
            }
        }
    } elseif ($baseArtist !== '') {
        $artistMatched = ($rawArtistHit === 1 || $directArtistHit === 1 || $artistScore >= 70.0);
        if (!$artistMatched && $titleScore < 92.0) {
            continue;
        }
    }

    $rating = isset($localRow['rating_value']) && is_numeric($localRow['rating_value']) ? (float)$localRow['rating_value'] : 0.0;
    $rows[] = [
        'id' => -1 * (int)($localRow['dj_track_id'] ?? 0),
        'dj_track_id' => (int)($localRow['dj_track_id'] ?? 0),
        'title' => $title,
        'artist' => $artist,
        'bpm_text' => isset($localRow['bpm']) && is_numeric($localRow['bpm']) ? (rtrim(rtrim(number_format((float)$localRow['bpm'], 2, '.', ''), '0'), '.') . ' BPM') : '',
        'key_text' => trim((string)($localRow['key_text'] ?? '')),
        'year_text' => isset($localRow['year']) && is_numeric($localRow['year']) ? (string)(int)$localRow['year'] : '',
        'genre' => trim((string)($localRow['genre'] ?? '')),
        'match_score' => round($score, 2),
        'is_preferred' => !empty($localRow['is_preferred']) ? 1 : 0,
        'playlist_badge' => trim((string)($localRow['preferred_playlist_name'] ?: $localRow['any_playlist_name'] ?? '')),
        'rating_label' => ($rating > 0 ? ((string)round($rating)) . '★' : ''),
        'is_selected' => ($selectedDjTrackId > 0 && (int)($localRow['dj_track_id'] ?? 0) === $selectedDjTrackId) ? 1 : 0,
        'rating_value' => $rating,
        'is_owned' => 1,
        'local_only' => 1,
        'can_apply' => 1,
    ];
}

usort($rows, static function (array $a, array $b): int {
    $aSel = (int)($a['is_selected'] ?? 0);
    $bSel = (int)($b['is_selected'] ?? 0);
    if ($aSel !== $bSel) {
        return $bSel <=> $aSel;
    }
    $aPref = (int)($a['is_preferred'] ?? 0);
    $bPref = (int)($b['is_preferred'] ?? 0);
    if ($aPref !== $bPref) {
        return $bPref <=> $aPref;
    }
    $aRating = (float)($a['rating_value'] ?? 0);
    $bRating = (float)($b['rating_value'] ?? 0);
    if (abs($aRating - $bRating) > 0.001) {
        return $bRating <=> $aRating;
    }
    $aScore = (float)($a['match_score'] ?? 0);
    $bScore = (float)($b['match_score'] ?? 0);
    if (abs($aScore - $bScore) > 0.001) {
        return $bScore <=> $aScore;
    }
    return ((int)($a['dj_track_id'] ?? 0)) <=> ((int)($b['dj_track_id'] ?? 0));
});

echo json_encode([
    'ok' => true,
    'scope' => 'library',
    'scope_message' => 'Showing currently available candidates from your DJ library.',
    'selected_bpm_track_id' => $selectedBpmTrackId,
    'selected_dj_track_id' => $selectedDjTrackId,
    'rows' => array_slice($rows, 0, 80),
], JSON_UNESCAPED_UNICODE);
exit;

$where = [];
$params = [$djId];
$maxTerms = min(10, max(1, count($tokens)));
for ($i = 0; $i < $maxTerms; $i++) {
    $token = $tokens[$i] ?? '';
    if ($token === '') {
        continue;
    }
    $where[] = '(b.title LIKE ? OR b.artist LIKE ?)';
    $params[] = '%' . $token . '%';
    $params[] = '%' . $token . '%';
}
if (empty($where)) {
    $where[] = '(b.title LIKE ? OR b.artist LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$availableMetaByHash = [];
$ratingExpr = mdjrStaleRatingExpr($db);
$availableStmt = $db->prepare("
    SELECT
        d.normalized_hash,
        d.id,
        {$ratingExpr} AS rating_value,
        MAX(CASE WHEN dpp.playlist_id IS NULL THEN 0 ELSE 1 END) AS is_preferred,
        SUBSTRING_INDEX(
            GROUP_CONCAT(DISTINCT dp.name ORDER BY dp.name ASC SEPARATOR '||'),
            '||',
            1
        ) AS playlist_badge,
        SUBSTRING_INDEX(
            GROUP_CONCAT(DISTINCT d.location ORDER BY d.id DESC SEPARATOR '||'),
            '||',
            1
        ) AS sample_location
    FROM dj_tracks d
    LEFT JOIN dj_playlist_tracks dpt
        ON dpt.dj_track_id = d.id
    LEFT JOIN dj_playlists dp
        ON dp.id = dpt.playlist_id
       AND dp.dj_id = d.dj_id
    LEFT JOIN dj_preferred_playlists dpp
        ON dpp.dj_id = d.dj_id
       AND dpp.playlist_id = dpt.playlist_id
    WHERE d.dj_id = ?
      AND COALESCE(d.is_available, 1) = 1
    GROUP BY d.normalized_hash, d.id
");
$availableStmt->execute([$djId]);
foreach (($availableStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $hash = trim((string)($row['normalized_hash'] ?? ''));
    if ($hash === '') {
        continue;
    }
    $candidate = [
        'rating_value' => isset($row['rating_value']) && is_numeric($row['rating_value']) ? (float)$row['rating_value'] : 0.0,
        'is_preferred' => !empty($row['is_preferred']) ? 1 : 0,
        'playlist_badge' => trim((string)($row['playlist_badge'] ?? '')),
        'folder_badge' => mdjrFolderBadge((string)($row['sample_location'] ?? '')),
        'dj_track_id' => (int)($row['id'] ?? 0),
    ];
    if (!isset($availableMetaByHash[$hash])) {
        $availableMetaByHash[$hash] = $candidate;
        continue;
    }
    $current = $availableMetaByHash[$hash];
    if (
        $candidate['is_preferred'] > $current['is_preferred'] ||
        ($candidate['is_preferred'] === $current['is_preferred'] && $candidate['rating_value'] > $current['rating_value']) ||
        ($candidate['is_preferred'] === $current['is_preferred'] && abs($candidate['rating_value'] - $current['rating_value']) < 0.001 && $candidate['dj_track_id'] < $current['dj_track_id'])
    ) {
        $availableMetaByHash[$hash] = $candidate;
    }
}

$rowsById = [];
$stmt = $db->prepare("
    SELECT id, title, artist, bpm, key_text, year, genre
    FROM bpm_test_tracks b
    WHERE " . implode(' OR ', $where) . "
    ORDER BY b.id DESC
    LIMIT 800
");
$stmt->execute(array_slice($params, 1));
foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $rowsById[(int)($row['id'] ?? 0)] = $row;
}

if ($manualMode && $compactArtistNeedle !== '') {
    $focusedStmt = $db->prepare("
        SELECT id, title, artist, bpm, key_text, year, genre
        FROM bpm_test_tracks
        WHERE LOWER(REPLACE(REPLACE(artist, ' ', ''), '-', '')) LIKE ?
        ORDER BY id DESC
        LIMIT 1500
    ");
    $focusedStmt->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $compactArtistNeedle) . '%']);
    foreach (($focusedStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $rowsById[(int)($row['id'] ?? 0)] = $row;
    }
}

$rows = array_values($rowsById);

$scored = [];
foreach ($rows as $row) {
    $title = (string)($row['title'] ?? '');
    $artist = (string)($row['artist'] ?? '');
    $hash = mdjrCandidateTrackHashForStale($title, $artist);
    if ($hash === '' || !isset($availableMetaByHash[$hash])) {
        continue;
    }
    $ownedMeta = $availableMetaByHash[$hash];
    $titleScore = max(mdjrStaleSimilarity($matchTitle, $title), mdjrStaleLooseSimilarity($matchTitle, $title));
    $artistScore = mdjrStaleSimilarity($matchArtist, $artist);
    $queryScore = max(mdjrStaleSimilarity($search, $title . ' ' . $artist), mdjrStaleSimilarity($search, $artist . ' ' . $title));
    $rowTitleNorm = mdjrStaleNormalize($title);
    $rowArtistNorm = mdjrStaleNormalize($artist);
    $rowTitleCompact = mdjrStaleCompact($title);
    $rowArtistCompact = mdjrStaleCompact($artist);
    $directTitleHit = ($normBaseTitle !== '' && str_contains($rowTitleNorm, $normBaseTitle)) ? 1 : 0;
    $directArtistHit = ($normBaseArtist !== '' && str_contains($rowArtistNorm, $normBaseArtist)) ? 1 : 0;
    $compactTitleHit = ($compactTitleNeedle !== '' && $rowTitleCompact !== '' && str_contains($rowTitleCompact, $compactTitleNeedle)) ? 1 : 0;
    $compactArtistHit = ($compactArtistNeedle !== '' && $rowArtistCompact !== '' && str_contains($rowArtistCompact, $compactArtistNeedle)) ? 1 : 0;
    $rawTitleHit = ($rawTitleNeedle !== '' && str_contains(mb_strtolower($title, 'UTF-8'), $rawTitleNeedle)) ? 1 : 0;
    $rawArtistHit = ($rawArtistNeedle !== '' && str_contains(mb_strtolower($artist, 'UTF-8'), $rawArtistNeedle)) ? 1 : 0;
    $score =
        ($titleScore * 0.45) +
        ($artistScore * 0.20) +
        ($queryScore * 0.15) +
        ($directTitleHit * 10) +
        ($directArtistHit * 5) +
        ($compactTitleHit * 10) +
        ($compactArtistHit * 10) +
        ($rawTitleHit * 12) +
        ($rawArtistHit * 8);

    if ($manualMode) {
        $rowTitleTokens = mdjrStaleTokens($title);
        $rowArtistTokens = mdjrStaleTokens($artist);
        $titleTokenHits = 0;
        foreach ($titleTokens as $tk) {
            if (in_array($tk, $rowTitleTokens, true)) {
                $titleTokenHits++;
            }
        }
        $artistTokenHits = 0;
        foreach ($artistTokens as $tk) {
            if (in_array($tk, $rowArtistTokens, true)) {
                $artistTokenHits++;
            }
        }
        $hasStrongTitle = $titleTokenHits >= max(1, min(2, count($titleTokens)));
        $hasStrongArtist = $artistTokenHits >= max(1, min(2, count($artistTokens)));
        $hasDirect = ($rawTitleHit === 1 || $directTitleHit === 1 || $compactTitleHit === 1 || $titleScore >= 64.0);
        if (!$hasStrongTitle && !$hasStrongArtist && !$hasDirect) {
            continue;
        }
        if ($manualPairMode && $matchArtist !== '') {
            $artistMatched = ($rawArtistHit === 1 || $directArtistHit === 1 || $compactArtistHit === 1 || $artistScore >= 55.0);
            if (!$artistMatched && $titleScore < 92.0) {
                continue;
            }
        }
    } elseif ($baseArtist !== '') {
        $artistMatched = ($rawArtistHit === 1 || $directArtistHit === 1 || $artistScore >= 70.0);
        if (!$artistMatched && $titleScore < 92.0) {
            continue;
        }
    }

    $rating = (float)($ownedMeta['rating_value'] ?? 0.0);
    $scored[] = [
        'id' => (int)($row['id'] ?? 0),
        'title' => $title,
        'artist' => $artist,
        'bpm_text' => isset($row['bpm']) && is_numeric($row['bpm']) ? (rtrim(rtrim(number_format((float)$row['bpm'], 2, '.', ''), '0'), '.') . ' BPM') : '',
        'key_text' => trim((string)($row['key_text'] ?? '')),
        'year_text' => isset($row['year']) && is_numeric($row['year']) ? (string)(int)$row['year'] : '',
        'genre' => trim((string)($row['genre'] ?? '')),
        'match_score' => round($score, 2),
        'is_preferred' => !empty($ownedMeta['is_preferred']) ? 1 : 0,
        'playlist_badge' => trim((string)($ownedMeta['playlist_badge'] ?: $ownedMeta['folder_badge'] ?? '')),
        'rating_label' => ($rating > 0 ? ((string)round($rating)) . '★' : ''),
        'is_selected' => ($selectedBpmTrackId > 0 && (int)($row['id'] ?? 0) === $selectedBpmTrackId) ? 1 : 0,
        'rating_value' => $rating,
        'is_owned' => 1,
        'local_only' => 0,
        'can_apply' => 1,
        'dj_track_id' => (int)($ownedMeta['dj_track_id'] ?? 0),
    ];
}

// Add direct local-library candidates that do not have a bpm_test_tracks row.
$localNeedles = array_values(array_filter(array_unique(array_merge(
    [$rawTitleNeedle, $rawArtistNeedle, $normBaseTitle, $normBaseArtist],
    array_slice($tokens, 0, 6)
))));
if (!empty($localNeedles)) {
    $whereLocal = [];
    $paramsLocal = [$djId];
    foreach ($localNeedles as $needle) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($needle, 'UTF-8')) . '%';
        $whereLocal[] = '(LOWER(d.title) LIKE ? OR LOWER(d.artist) LIKE ?)';
        $paramsLocal[] = $like;
        $paramsLocal[] = $like;
    }
    $localRatingExpr = mdjrDjTrackRatingExprForStale($db);
    $localStmt = $db->prepare("
        SELECT
            d.id AS dj_track_id,
            d.title,
            d.artist,
            d.bpm,
            d.musical_key AS key_text,
            d.release_year AS year,
            d.genre,
            d.location,
            {$localRatingExpr} AS rating_value,
            MAX(CASE WHEN dpp.playlist_id IS NULL THEN 0 ELSE 1 END) AS is_preferred,
            SUBSTRING_INDEX(
                GROUP_CONCAT(
                    DISTINCT CASE WHEN dpp.playlist_id IS NOT NULL THEN dp.name ELSE NULL END
                    ORDER BY dp.name ASC SEPARATOR '||'
                ),
                '||',
                1
            ) AS preferred_playlist_name,
            SUBSTRING_INDEX(
                GROUP_CONCAT(DISTINCT dp.name ORDER BY dp.name ASC SEPARATOR '||'),
                '||',
                1
            ) AS any_playlist_name
        FROM dj_tracks d
        LEFT JOIN dj_playlist_tracks dpt
            ON dpt.dj_track_id = d.id
        LEFT JOIN dj_playlists dp
            ON dp.id = dpt.playlist_id
           AND dp.dj_id = d.dj_id
        LEFT JOIN dj_preferred_playlists dpp
            ON dpp.dj_id = d.dj_id
           AND dpp.playlist_id = dpt.playlist_id
        WHERE d.dj_id = ?
          AND COALESCE(d.is_available, 1) = 1
          AND (" . implode(' OR ', $whereLocal) . ")
        GROUP BY d.id
        LIMIT 500
    ");
    $localStmt->execute($paramsLocal);

    $existingDjTrackIds = [];
    foreach ($scored as $row) {
        $existingDjTrackIds[(int)($row['dj_track_id'] ?? 0)] = true;
    }

    foreach (($localStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $localRow) {
        $djTrackId = (int)($localRow['dj_track_id'] ?? 0);
        if ($djTrackId <= 0 || isset($existingDjTrackIds[$djTrackId])) {
            continue;
        }
        $title = (string)($localRow['title'] ?? '');
        $artist = (string)($localRow['artist'] ?? '');
        $titleScore = max(mdjrStaleSimilarity($matchTitle, $title), mdjrStaleLooseSimilarity($matchTitle, $title));
        $artistScore = mdjrStaleSimilarity($matchArtist, $artist);
        $queryScore = max(mdjrStaleSimilarity($search, $title . ' ' . $artist), mdjrStaleSimilarity($search, $artist . ' ' . $title));
        $score = ($titleScore * 0.45) + ($artistScore * 0.20) + ($queryScore * 0.15) + 18.0;
        $playlistBadge = trim((string)($localRow['preferred_playlist_name'] ?: $localRow['any_playlist_name'] ?? ''));
        $rating = isset($localRow['rating_value']) && is_numeric($localRow['rating_value']) ? (float)$localRow['rating_value'] : 0.0;
        $scored[] = [
            'id' => -1 * $djTrackId,
            'dj_track_id' => $djTrackId,
            'title' => $title,
            'artist' => $artist,
            'bpm_text' => isset($localRow['bpm']) && is_numeric($localRow['bpm']) ? (rtrim(rtrim(number_format((float)$localRow['bpm'], 2, '.', ''), '0'), '.') . ' BPM') : '',
            'key_text' => trim((string)($localRow['key_text'] ?? '')),
            'year_text' => isset($localRow['year']) && is_numeric($localRow['year']) ? (string)(int)$localRow['year'] : '',
            'genre' => trim((string)($localRow['genre'] ?? '')),
            'match_score' => round($score, 2),
            'is_preferred' => !empty($localRow['is_preferred']) ? 1 : 0,
            'playlist_badge' => $playlistBadge,
            'rating_label' => ($rating > 0 ? ((string)round($rating)) . '★' : ''),
            'is_selected' => ($selectedBpmTrackId <= 0 && $selectedDjTrackId > 0 && $djTrackId === $selectedDjTrackId) ? 1 : 0,
            'rating_value' => $rating,
            'is_owned' => 1,
            'local_only' => 1,
            'can_apply' => 1,
        ];
        $existingDjTrackIds[$djTrackId] = true;
    }
}

usort($scored, static function (array $a, array $b): int {
    $aSel = (int)($a['is_selected'] ?? 0);
    $bSel = (int)($b['is_selected'] ?? 0);
    if ($aSel !== $bSel) {
        return $bSel <=> $aSel;
    }
    $aPref = (int)($a['is_preferred'] ?? 0);
    $bPref = (int)($b['is_preferred'] ?? 0);
    if ($aPref !== $bPref) {
        return $bPref <=> $aPref;
    }
    $aRating = (float)($a['rating_value'] ?? 0);
    $bRating = (float)($b['rating_value'] ?? 0);
    if (abs($aRating - $bRating) > 0.001) {
        return $bRating <=> $aRating;
    }
    $aScore = (float)($a['match_score'] ?? 0);
    $bScore = (float)($b['match_score'] ?? 0);
    if (abs($aScore - $bScore) > 0.001) {
        return $bScore <=> $aScore;
    }
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

echo json_encode([
    'ok' => true,
    'scope' => 'library',
    'scope_message' => 'Showing currently available candidates from your DJ library.',
    'selected_bpm_track_id' => $selectedBpmTrackId,
    'selected_dj_track_id' => $selectedDjTrackId,
    'rows' => array_slice($scored, 0, 80),
], JSON_UNESCAPED_UNICODE);
