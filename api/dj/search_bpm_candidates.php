<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Premium feature']);
    exit;
}

$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));
$trackKey = trim((string)($_GET['track_key'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

if ($eventUuid === '' || $trackKey === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event or track']);
    exit;
}

$eventStmt = $db->prepare(
    "SELECT id FROM events WHERE uuid = ? AND user_id = ? LIMIT 1"
);
$eventStmt->execute([$eventUuid, (int)($_SESSION['dj_id'] ?? 0)]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
$eventId = (int)$event['id'];

$reqStmt = $db->prepare(
    "
    SELECT
      COALESCE(MAX(NULLIF(r.spotify_track_name, '')), MAX(r.song_title)) AS song_title,
      COALESCE(MAX(NULLIF(r.spotify_artist_name, '')), MAX(r.artist)) AS artist,
      MAX(NULLIF(r.spotify_track_id, '')) AS spotify_track_id
    FROM song_requests r
    WHERE r.event_id = :event_id
      AND COALESCE(NULLIF(r.spotify_track_id, ''), CONCAT(r.song_title, '::', r.artist)) = :track_key
    LIMIT 1
"
);
$reqStmt->execute([
    ':event_id' => $eventId,
    ':track_key' => $trackKey,
]);
$req = $reqStmt->fetch(PDO::FETCH_ASSOC);
if (!$req) {
    echo json_encode(['ok' => false, 'error' => 'Track not found']);
    exit;
}
$selectedBpmTrackId = 0;
$overrideKey = mdjrOverrideTrackKey(
    trim((string)($req['song_title'] ?? '')),
    trim((string)($req['artist'] ?? ''))
);
if ($overrideKey !== '') {
    try {
        $ovStmt = $db->prepare("
            SELECT bpm_track_id
            FROM dj_event_track_overrides
            WHERE dj_id = ?
              AND event_id = ?
              AND override_key = ?
            LIMIT 1
        ");
        $ovStmt->execute([(int)($_SESSION['dj_id'] ?? 0), $eventId, $overrideKey]);
        $selectedBpmTrackId = (int)($ovStmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $selectedBpmTrackId = 0;
    }
}
if ($selectedBpmTrackId <= 0 && $overrideKey !== '') {
    try {
        $govStmt = $db->prepare("
            SELECT bpm_track_id
            FROM dj_global_track_overrides
            WHERE dj_id = ?
              AND override_key = ?
            LIMIT 1
        ");
        $govStmt->execute([(int)($_SESSION['dj_id'] ?? 0), $overrideKey]);
        $selectedBpmTrackId = (int)($govStmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $selectedBpmTrackId = 0;
    }
}
if ($selectedBpmTrackId <= 0 && $overrideKey !== '') {
    try {
        $legacyStmt = $db->prepare("
            SELECT bpm_track_id
            FROM dj_event_track_overrides
            WHERE dj_id = ?
              AND override_key = ?
              AND bpm_track_id IS NOT NULL
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $legacyStmt->execute([(int)($_SESSION['dj_id'] ?? 0), $overrideKey]);
        $selectedBpmTrackId = (int)($legacyStmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $selectedBpmTrackId = 0;
    }
}
if ($selectedBpmTrackId <= 0) {
    $sid = trim((string)($req['spotify_track_id'] ?? ''));
    if ($sid !== '') {
        try {
            $linkStmt = $db->prepare("
                SELECT bpm_track_id
                FROM track_links
                WHERE spotify_track_id = ?
                LIMIT 1
            ");
            $linkStmt->execute([$sid]);
            $selectedBpmTrackId = (int)($linkStmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $selectedBpmTrackId = 0;
        }
    }
}

$baseTitle = trim((string)($req['song_title'] ?? ''));
$baseArtist = trim((string)($req['artist'] ?? ''));
$search = $q !== '' ? $q : trim($baseTitle . ' ' . $baseArtist);
$manualMode = ($q !== '');
$manualPairMode = $manualMode && (bool)preg_match('/\s[-–—]\s/u', $search);
$manualBroadMode = $manualMode && !$manualPairMode;

if ($search === '') {
    echo json_encode(['ok' => true, 'rows' => []]);
    exit;
}

function normaliseForMatch(string $v): string
{
    $v = mb_strtolower($v, 'UTF-8');
    $v = preg_replace('/\b(remix|edit|version|mix|extended|radio|clean|dirty|revibe|rework|feat|featuring|ft|dj)\b/i', '', $v);
    $v = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $v);
    $v = preg_replace('/\s+/', ' ', trim((string)$v));
    return $v;
}

function tokeniseForMatch(string $v): array
{
    $n = normaliseForMatch($v);
    if ($n === '') return [];

    $stop = [
        'the', 'a', 'an', 'in', 'on', 'at', 'to', 'of', 'for', 'and', 'or',
        'vs', 'with', 'by', 'from', 'original', 'remaster', 'remastered'
    ];

    $parts = preg_split('/\s+/', $n);
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '' || mb_strlen($p, 'UTF-8') < 3) continue;
        if (in_array($p, $stop, true)) continue;
        $out[$p] = true;
    }
    return array_keys($out);
}

function compactForMatch(string $v): string
{
    $n = normaliseForMatch($v);
    if ($n === '') {
        return '';
    }
    return preg_replace('/\s+/u', '', $n) ?: '';
}

function escapeLike(string $v): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
}

function similarityPercent(string $a, string $b): float
{
    $na = normaliseForMatch($a);
    $nb = normaliseForMatch($b);
    if ($na === '' || $nb === '') {
        return 0.0;
    }
    if ($na === $nb) {
        return 100.0;
    }
    similar_text($na, $nb, $pct);
    return (float)$pct;
}

function colloquialiseGerund(string $v): string
{
    // Normalize common colloquial endings:
    // "dancing" -> "dancin" so it aligns with "dancin'" after punctuation stripping.
    $v = normaliseForMatch($v);
    if ($v === '') {
        return '';
    }
    return preg_replace('/\b([\p{L}]{3,})ing\b/u', '$1in', $v) ?: $v;
}

function similarityPercentLooseTitle(string $a, string $b): float
{
    $na = colloquialiseGerund($a);
    $nb = colloquialiseGerund($b);
    if ($na === '' || $nb === '') {
        return 0.0;
    }
    if ($na === $nb) {
        return 100.0;
    }
    similar_text($na, $nb, $pct);
    return (float)$pct;
}

function splitManualQuery(string $search, string $baseTitle, string $baseArtist): array
{
    $search = trim($search);
    if ($search === '') {
        return [$baseTitle, $baseArtist];
    }

    // Preferred typed format is "Artist - Title".
    if (preg_match('/\s[-–—]\s/u', $search)) {
        $parts = preg_split('/\s[-–—]\s/u', $search, 2);
        $left = trim((string)($parts[0] ?? ''));
        $right = trim((string)($parts[1] ?? ''));
        if ($left !== '' && $right !== '') {
            return [$right, $left];
        }
    }

    // No explicit "Artist - Title" pattern.
    // Keep base pair context and use raw search tokens as broad matcher.
    return [$baseTitle, $baseArtist];
}

[$manualTitle, $manualArtist] = $manualMode
    ? splitManualQuery($search, $baseTitle, $baseArtist)
    : [$baseTitle, $baseArtist];

$bpmRatingSelect = mdjrBpmCandidateRatingSelect($db);

$matchTitle = $manualTitle;
$matchArtist = $manualArtist;
if ($manualBroadMode) {
    // For plain-text manual searches, treat typed text as primary matcher
    // instead of anchoring to the original request title/artist pair.
    $matchTitle = $search;
    $matchArtist = $search;
}

$titleTokens = tokeniseForMatch($matchTitle);
$artistTokens = tokeniseForMatch($matchArtist);
$searchTokens = tokeniseForMatch($search);
$tokens = $manualMode
    ? array_values(array_unique(array_merge($titleTokens, $artistTokens, $searchTokens)))
    : array_values(array_unique(array_merge($titleTokens, $artistTokens, $searchTokens)));

$rowsById = [];
$rawTitleNeedle = mb_strtolower($matchTitle !== '' ? $matchTitle : $search, 'UTF-8');
$rawArtistNeedle = mb_strtolower($matchArtist, 'UTF-8');
$normTitleNeedle = normaliseForMatch($matchTitle !== '' ? $matchTitle : $search);
$normArtistNeedle = normaliseForMatch($matchArtist);
$compactTitleNeedle = compactForMatch($matchTitle !== '' ? $matchTitle : $search);
$compactArtistNeedle = compactForMatch($matchArtist);
$looseTitleNeedle = colloquialiseGerund($matchTitle !== '' ? $matchTitle : $search);

// 1) Title-first pass (strong signal)
$titleNeedles = array_values(array_filter(array_unique([
    $rawTitleNeedle,
    $normTitleNeedle,
])));
if ($titleNeedles) {
    $whereTitle = [];
    $paramsTitle = [];
    foreach ($titleNeedles as $needle) {
        $whereTitle[] = 'LOWER(title) LIKE ?';
        $paramsTitle[] = '%' . escapeLike($needle) . '%';
    }

    $sql1 = "
        SELECT id, title, artist, bpm, key_text, year, genre, {$bpmRatingSelect} AS rating_value
        FROM bpm_test_tracks
        WHERE " . implode(' OR ', $whereTitle) . "
        ORDER BY id DESC
        LIMIT 600
    ";
    $st1 = $db->prepare($sql1);
    $st1->execute($paramsTitle);
    foreach ($st1->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rowsById[(int)$r['id']] = $r;
    }
}

// 2) Title + artist pass (skip in manual mode so free text query is not polluted by original track artist)
if (!$manualMode && $baseArtist !== '') {
    $titleNeedles = array_values(array_filter(array_unique([
        mb_strtolower($baseTitle, 'UTF-8'),
        normaliseForMatch($baseTitle),
    ])));
    $artistNeedles = array_values(array_filter(array_unique([
        mb_strtolower($baseArtist, 'UTF-8'),
        normaliseForMatch($baseArtist),
    ])));
    $whereTitle = [];
    $whereArtist = [];
    $params2 = [];

    foreach ($titleNeedles as $needle) {
        $whereTitle[] = 'LOWER(title) LIKE ?';
        $params2[] = '%' . escapeLike($needle) . '%';
    }
    foreach ($artistNeedles as $needle) {
        $whereArtist[] = 'LOWER(artist) LIKE ?';
        $params2[] = '%' . escapeLike($needle) . '%';
    }

    $sql2 = "
        SELECT id, title, artist, bpm, key_text, year, genre, {$bpmRatingSelect} AS rating_value
        FROM bpm_test_tracks
        WHERE (" . implode(' OR ', $whereTitle ?: ['1=1']) . ")
          AND (" . implode(' OR ', $whereArtist ?: ['1=1']) . ")
        ORDER BY id DESC
        LIMIT 600
    ";
    $st2 = $db->prepare($sql2);
    $st2->execute($params2);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rowsById[(int)$r['id']] = $r;
    }
}

// 3) Token fallback
$where = [];
$params = [];
$maxTerms = min(8, count($tokens));
for ($i = 0; $i < $maxTerms; $i++) {
    $where[] = '(title LIKE ? OR artist LIKE ?)';
    $like = '%' . $tokens[$i] . '%';
    $params[] = $like;
    $params[] = $like;
}

if (!$where) {
    $where[] = '(title LIKE ? OR artist LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$sql3 = "
    SELECT id, title, artist, bpm, key_text, year, genre, {$bpmRatingSelect} AS rating_value
    FROM bpm_test_tracks
    WHERE " . implode(' OR ', $where) . "
    ORDER BY id DESC
    LIMIT 500
";

$st3 = $db->prepare($sql3);
$st3->execute($params);
foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rowsById[(int)$r['id']] = $r;
}

$rows = array_values($rowsById);

// 2.5) Manual-mode focused pass:
// enforce artist+title pairing with compact artist compare (Night Force ~= Nightforce).
if ($manualMode && $normTitleNeedle !== '' && $compactArtistNeedle !== '') {
    $sqlFocused = "
        SELECT id, title, artist, bpm, key_text, year, genre, {$bpmRatingSelect} AS rating_value
        FROM bpm_test_tracks
        WHERE LOWER(REPLACE(REPLACE(artist, ' ', ''), '-', '')) LIKE ?
          AND LOWER(title) LIKE ?
        ORDER BY id DESC
        LIMIT 800
    ";
    $stFocused = $db->prepare($sqlFocused);
    $stFocused->execute([
        '%' . escapeLike($compactArtistNeedle) . '%',
        '%' . escapeLike($normTitleNeedle) . '%',
    ]);
    foreach ($stFocused->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rowsById[(int)$r['id']] = $r;
    }
    $rows = array_values($rowsById);
}

// 2.6) Manual-mode artist fallback:
// If title-shape differences (e.g. Dancing vs Dancin') miss SQL LIKE retrieval,
// pull artist-matched rows and let scoring decide.
if ($manualMode && count($rows) < 40 && $compactArtistNeedle !== '') {
    $sqlArtistOnly = "
        SELECT id, title, artist, bpm, key_text, year, genre, {$bpmRatingSelect} AS rating_value
        FROM bpm_test_tracks
        WHERE LOWER(REPLACE(REPLACE(artist, ' ', ''), '-', '')) LIKE ?
        ORDER BY id DESC
        LIMIT 1500
    ";
    $stArtistOnly = $db->prepare($sqlArtistOnly);
    $stArtistOnly->execute([
        '%' . escapeLike($compactArtistNeedle) . '%',
    ]);
    foreach ($stArtistOnly->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rowsById[(int)$r['id']] = $r;
    }
    $rows = array_values($rowsById);
}

// 4) Broad fallback by raw title/artist when earlier passes are sparse.
if (!$manualMode && count($rows) < 24) {
    $where4 = [];
    $params4 = [];
    if ($rawTitleNeedle !== '') {
        $where4[] = 'LOWER(title) LIKE ?';
        $params4[] = '%' . escapeLike($rawTitleNeedle) . '%';
    }
    if ($rawArtistNeedle !== '') {
        $where4[] = 'LOWER(artist) LIKE ?';
        $params4[] = '%' . escapeLike($rawArtistNeedle) . '%';
    }
    if ($normTitleNeedle !== '') {
        $where4[] = 'LOWER(title) LIKE ?';
        $params4[] = '%' . escapeLike($normTitleNeedle) . '%';
    }
    if ($normArtistNeedle !== '') {
        $where4[] = 'LOWER(artist) LIKE ?';
        $params4[] = '%' . escapeLike($normArtistNeedle) . '%';
    }

    if ($where4) {
        $sql4 = "
            SELECT id, title, artist, bpm, key_text, year, genre, {$bpmRatingSelect} AS rating_value
            FROM bpm_test_tracks
            WHERE " . implode(' OR ', $where4) . "
            ORDER BY id DESC
            LIMIT 2000
        ";
        $st4 = $db->prepare($sql4);
        $st4->execute($params4);
        foreach ($st4->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rowsById[(int)$r['id']] = $r;
        }
        $rows = array_values($rowsById);
    }
}

if (!$manualMode && count($rows) < 12) {
    $fallbackStmt = $db->query("
        SELECT id, title, artist, bpm, key_text, year, genre, {$bpmRatingSelect} AS rating_value
        FROM bpm_test_tracks
        ORDER BY id DESC
        LIMIT 5000
    ");
    foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rowsById[(int)$r['id']] = $r;
    }
    $rows = array_values($rowsById);
}

$normBaseTitle = normaliseForMatch($matchTitle);
$normBaseArtist = normaliseForMatch($matchArtist);

$scored = [];
foreach ($rows as $row) {
    $title = (string)($row['title'] ?? '');
    $artist = (string)($row['artist'] ?? '');

    $titleScore = similarityPercent($matchTitle, $title);
    $titleScoreLoose = similarityPercentLooseTitle($matchTitle, $title);
    $effectiveTitleScore = max($titleScore, $titleScoreLoose);
    $artistScore = similarityPercent($matchArtist, $artist);

    $rowTokens = tokeniseForMatch($title . ' ' . $artist);
    $tokenHit = 0;
    foreach ($tokens as $tk) {
        if (in_array($tk, $rowTokens, true)) {
            $tokenHit++;
        }
    }
    $tokenScore = $tokens ? (($tokenHit / count($tokens)) * 100.0) : 0.0;

    $normTitle = normaliseForMatch($title);
    $normArtist = normaliseForMatch($artist);
    $looseNormTitle = colloquialiseGerund($title);
    $compactTitle = compactForMatch($title);
    $compactArtist = compactForMatch($artist);
    $directTitleHit = ($normBaseTitle !== '' && str_contains($normTitle, $normBaseTitle)) ? 1 : 0;
    $directArtistHit = ($normBaseArtist !== '' && str_contains($normArtist, $normBaseArtist)) ? 1 : 0;
    $compactArtistHit = ($compactArtistNeedle !== '' && $compactArtist !== '' && str_contains($compactArtist, $compactArtistNeedle)) ? 1 : 0;
    $compactTitleHit = ($compactTitleNeedle !== '' && $compactTitle !== '' && str_contains($compactTitle, $compactTitleNeedle)) ? 1 : 0;
    $looseTitleHit = ($looseTitleNeedle !== '' && $looseNormTitle !== '' && str_contains($looseNormTitle, $looseTitleNeedle)) ? 1 : 0;
    $rawTitleHit = ($rawTitleNeedle !== '' && str_contains(mb_strtolower($title, 'UTF-8'), $rawTitleNeedle)) ? 1 : 0;
    $rawArtistHit = ($rawArtistNeedle !== '' && str_contains(mb_strtolower($artist, 'UTF-8'), $rawArtistNeedle)) ? 1 : 0;
    $exactPairHit = (
        $rawTitleNeedle !== '' &&
        $rawArtistNeedle !== '' &&
        mb_strtolower(trim($title), 'UTF-8') === trim($rawTitleNeedle) &&
        mb_strtolower(trim($artist), 'UTF-8') === trim($rawArtistNeedle)
    ) ? 1 : 0;

    $combined =
        ($effectiveTitleScore * 0.45) +
        ($artistScore * 0.20) +
        ($tokenScore * 0.15) +
        ($directTitleHit * 10) +
        ($directArtistHit * 5) +
        ($compactTitleHit * 10) +
        ($compactArtistHit * 10) +
        ($looseTitleHit * 12) +
        ($rawTitleHit * 12) +
        ($rawArtistHit * 8) +
        ($exactPairHit * 25);

    if ($manualMode) {
        $titleTokenMinHits = max(1, min(2, count($titleTokens)));
        $titleTokenHits = 0;
        $rowTitleTokens = tokeniseForMatch($title);
        foreach ($titleTokens as $tk) {
            if (in_array($tk, $rowTitleTokens, true)) {
                $titleTokenHits++;
            }
        }
        $hasStrongTokenMatch = ($titleTokenHits >= $titleTokenMinHits);
        $artistTokenMinHits = max(1, min(2, count($artistTokens)));
        $artistTokenHits = 0;
        $rowArtistTokens = tokeniseForMatch($artist);
        foreach ($artistTokens as $tk) {
            if (in_array($tk, $rowArtistTokens, true)) {
                $artistTokenHits++;
            }
        }
        $hasStrongArtistTokenMatch = ($artistTokenHits >= $artistTokenMinHits);
        // Pair mode avoids artist-only drift; broad mode allows either side.
        $hasDirectMatch = $manualPairMode
            ? ($rawTitleHit === 1 || $directTitleHit === 1 || $exactPairHit === 1 || $looseTitleHit === 1)
            : ($rawTitleHit === 1 || $directTitleHit === 1 || $exactPairHit === 1 || $looseTitleHit === 1 || $rawArtistHit === 1 || $directArtistHit === 1 || $compactArtistHit === 1);
        $hasHighTitleSimilarity = ($effectiveTitleScore >= 64.0);
        if (!$hasStrongTokenMatch && !$hasStrongArtistTokenMatch && !$hasDirectMatch && !$hasHighTitleSimilarity) {
            continue;
        }
        if ($combined < 45.0 && $effectiveTitleScore < 55.0 && !$directTitleHit && !$exactPairHit && !$looseTitleHit && !$hasHighTitleSimilarity && !($manualBroadMode && $hasStrongArtistTokenMatch)) {
            continue;
        }
        if ($manualPairMode && $matchArtist !== '') {
            $artistMatched = ($rawArtistHit === 1 || $directArtistHit === 1 || $compactArtistHit === 1 || $artistScore >= 55.0);
            if (!$artistMatched && $effectiveTitleScore < 92.0) {
                continue;
            }
        }
    }

    // In default mode (no typed query), strongly anchor to original artist.
    // This prevents generic title-token collisions (e.g. "got", "find", "man")
    // from flooding results with unrelated artists.
    if (!$manualMode && $baseArtist !== '') {
        $artistMatched = ($rawArtistHit === 1 || $directArtistHit === 1 || $artistScore >= 70.0);
        $veryHighTitleOnly = ($titleScore >= 92.0);
        if (!$artistMatched && !$veryHighTitleOnly) {
            continue;
        }
    }

    $scored[] = [
        'id' => (int)$row['id'],
        'title' => $title,
        'artist' => $artist,
        'bpm' => isset($row['bpm']) && is_numeric($row['bpm']) ? (float)$row['bpm'] : null,
        'key_text' => trim((string)($row['key_text'] ?? '')),
        'year' => isset($row['year']) && is_numeric($row['year']) ? (int)$row['year'] : null,
        'genre' => trim((string)($row['genre'] ?? '')),
        'rating_value' => isset($row['rating_value']) && is_numeric($row['rating_value']) ? (float)$row['rating_value'] : 0.0,
        'match_score' => round($combined, 2),
        'title_score' => round($effectiveTitleScore, 2),
        'artist_score' => round($artistScore, 2),
        'token_score' => round($tokenScore, 2),
        'direct_title_hit' => $directTitleHit,
        'direct_artist_hit' => $directArtistHit,
        'raw_title_hit' => $rawTitleHit,
        'raw_artist_hit' => $rawArtistHit,
        'exact_pair_hit' => $exactPairHit,
    ];
}

// Always include currently selected/manual-matched track in modal results,
// even when query filters are strict, so DJs can see current linked version.
if ($selectedBpmTrackId > 0) {
    $hasSelected = false;
    foreach ($scored as $sr) {
        if ((int)($sr['id'] ?? 0) === $selectedBpmTrackId) {
            $hasSelected = true;
            break;
        }
    }
    if (!$hasSelected) {
        try {
            $selStmt = $db->prepare("
                SELECT id, title, artist, bpm, key_text, year, genre, {$bpmRatingSelect} AS rating_value
                FROM bpm_test_tracks
                WHERE id = ?
                LIMIT 1
            ");
            $selStmt->execute([$selectedBpmTrackId]);
            $sel = $selStmt->fetch(PDO::FETCH_ASSOC);
            if ($sel) {
                $selTitle = (string)($sel['title'] ?? '');
                $selArtist = (string)($sel['artist'] ?? '');
                $titleScore = similarityPercent($matchTitle, $selTitle);
                $titleScoreLoose = similarityPercentLooseTitle($matchTitle, $selTitle);
                $effectiveTitleScore = max($titleScore, $titleScoreLoose);
                $artistScore = similarityPercent($matchArtist, $selArtist);
                $rowTokens = tokeniseForMatch($selTitle . ' ' . $selArtist);
                $tokenHit = 0;
                foreach ($tokens as $tk) {
                    if (in_array($tk, $rowTokens, true)) {
                        $tokenHit++;
                    }
                }
                $tokenScore = $tokens ? (($tokenHit / count($tokens)) * 100.0) : 0.0;
                $scored[] = [
                    'id' => (int)$sel['id'],
                    'title' => $selTitle,
                    'artist' => $selArtist,
                    'bpm' => isset($sel['bpm']) && is_numeric($sel['bpm']) ? (float)$sel['bpm'] : null,
                    'key_text' => trim((string)($sel['key_text'] ?? '')),
                    'year' => isset($sel['year']) && is_numeric($sel['year']) ? (int)$sel['year'] : null,
                    'genre' => trim((string)($sel['genre'] ?? '')),
                    'rating_value' => isset($sel['rating_value']) && is_numeric($sel['rating_value']) ? (float)$sel['rating_value'] : 0.0,
                    'match_score' => round(max(140.0, ($effectiveTitleScore * 0.45) + ($artistScore * 0.20) + ($tokenScore * 0.15)), 2),
                    'title_score' => round($effectiveTitleScore, 2),
                    'artist_score' => round($artistScore, 2),
                    'token_score' => round($tokenScore, 2),
                    'direct_title_hit' => 1,
                    'direct_artist_hit' => 1,
                    'raw_title_hit' => 1,
                    'raw_artist_hit' => 1,
                    'exact_pair_hit' => 0,
                ];
            }
        } catch (Throwable $e) {
            // non-blocking
        }
    }
}

// Final guard: in manual mode, ensure results respect the typed search query.
if ($manualMode && trim($search) !== '') {
    $manualQueryNorm = normaliseForMatch($search);
    $manualQueryCompact = compactForMatch($search);
    $manualQueryTokens = tokeniseForMatch($search);
    $scored = array_values(array_filter($scored, static function (array $row) use ($manualQueryNorm, $manualQueryCompact, $manualQueryTokens): bool {
        $title = (string)($row['title'] ?? '');
        $artist = (string)($row['artist'] ?? '');
        $titleNorm = normaliseForMatch($title);
        $artistNorm = normaliseForMatch($artist);
        $titleCompact = compactForMatch($title);
        $artistCompact = compactForMatch($artist);

        $phraseHit = false;
        if ($manualQueryNorm !== '') {
            $phraseHit = str_contains($titleNorm, $manualQueryNorm)
                || str_contains($artistNorm, $manualQueryNorm)
                || str_contains(trim($artistNorm . ' ' . $titleNorm), $manualQueryNorm);
        }
        if ($phraseHit) {
            return true;
        }

        if ($manualQueryCompact !== '') {
            if (
                ($titleCompact !== '' && str_contains($titleCompact, $manualQueryCompact)) ||
                ($artistCompact !== '' && str_contains($artistCompact, $manualQueryCompact)) ||
                (($artistCompact !== '' && $titleCompact !== '') && str_contains($artistCompact . $titleCompact, $manualQueryCompact))
            ) {
                return true;
            }
        }

        if (!empty($manualQueryTokens)) {
            $rowTokens = tokeniseForMatch($title . ' ' . $artist);
            $hits = 0;
            foreach ($manualQueryTokens as $tk) {
                if (in_array($tk, $rowTokens, true)) {
                    $hits++;
                }
            }
            $minHits = max(1, min(2, count($manualQueryTokens)));
            if ($hits >= $minHits) {
                return true;
            }
        }

        // Keep very high-confidence rows as last resort.
        return ((float)($row['match_score'] ?? 0.0)) >= 95.0;
    }));
}

usort($scored, static function (array $a, array $b): int {
    if ($a['match_score'] === $b['match_score']) {
        return $b['id'] <=> $a['id'];
    }
    return $b['match_score'] <=> $a['match_score'];
});

$scored = array_slice($scored, 0, $manualMode ? 80 : 100);

$djId = (int)($_SESSION['dj_id'] ?? 0);
$ownedMetaByHash = [];
if ($djId > 0 && !empty($scored)) {
    $candidateHashes = [];
    foreach ($scored as $row) {
        $h = candidateTrackHash((string)($row['title'] ?? ''), (string)($row['artist'] ?? ''));
        if ($h !== '') {
            $candidateHashes[$h] = true;
        }
    }

    if (!empty($candidateHashes)) {
        $hashes = array_keys($candidateHashes);
        $in = implode(',', array_fill(0, count($hashes), '?'));
        $djRatingExpr = mdjrDjTrackRatingExpr($db);
        $ownStmt = $db->prepare("
            SELECT
                d.normalized_hash,
                d.id,
                d.location,
                {$djRatingExpr} AS rating_value,
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
                    GROUP_CONCAT(
                        DISTINCT dp.name
                        ORDER BY dp.name ASC SEPARATOR '||'
                    ),
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
              AND d.normalized_hash IN ($in)
            GROUP BY d.normalized_hash, d.id
        ");
        $ownStmt->execute(array_merge([$djId], $hashes));
        foreach ($ownStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $h = trim((string)($r['normalized_hash'] ?? ''));
            if ($h !== '') {
                $candidate = [
                    'dj_track_id' => (int)($r['id'] ?? 0),
                    'is_preferred' => !empty($r['is_preferred']) ? 1 : 0,
                    'rating_value' => isset($r['rating_value']) && is_numeric($r['rating_value']) ? (float)$r['rating_value'] : 0.0,
                    'playlist_badge' => mdjrPlaylistBadge(
                        (string)($r['preferred_playlist_name'] ?? ''),
                        (string)($r['any_playlist_name'] ?? ''),
                        (string)($r['location'] ?? '')
                    ),
                ];
                if (!isset($ownedMetaByHash[$h]) || mdjrOwnedCandidateBetter($candidate, $ownedMetaByHash[$h])) {
                    $ownedMetaByHash[$h] = $candidate;
                }
            }
        }
    }
}

$ownedRows = [];
$globalRows = [];
foreach ($scored as $row) {
    $hash = candidateTrackHash((string)($row['title'] ?? ''), (string)($row['artist'] ?? ''));
    $ownedMeta = ($hash !== '' && isset($ownedMetaByHash[$hash])) ? $ownedMetaByHash[$hash] : null;
    $isOwned = is_array($ownedMeta) ? 1 : 0;
    $preferred = ($isOwned === 1 && !empty($ownedMeta['is_preferred'])) ? 1 : 0;
    $row['candidate_hash'] = $hash;
    $row['is_owned'] = $isOwned;
    $row['is_preferred'] = $preferred;
    $row['playlist_badge'] = ($isOwned === 1 && is_array($ownedMeta) && !empty($ownedMeta['playlist_badge']))
        ? (string)$ownedMeta['playlist_badge']
        : '';
    if ($isOwned === 1 && isset($ownedMeta['rating_value'])) {
        $current = isset($row['rating_value']) && is_numeric($row['rating_value']) ? (float)$row['rating_value'] : 0.0;
        $ownedVal = (float)$ownedMeta['rating_value'];
        $row['rating_value'] = max($current, $ownedVal);
    }
    $row['is_title_relevant'] = (
        !empty($row['exact_pair_hit']) ||
        !empty($row['raw_title_hit']) ||
        !empty($row['direct_title_hit']) ||
        (isset($row['title_score']) && is_numeric($row['title_score']) && (float)$row['title_score'] >= 58.0) ||
        (isset($row['token_score']) && is_numeric($row['token_score']) && (float)$row['token_score'] >= 58.0)
    ) ? 1 : 0;
    $row['is_five_star'] = (isset($row['rating_value']) && is_numeric($row['rating_value']) && (float)$row['rating_value'] >= 5.0) ? 1 : 0;
    $row['is_selected'] = ($selectedBpmTrackId > 0 && (int)($row['id'] ?? 0) === $selectedBpmTrackId) ? 1 : 0;
    if ($isOwned === 1) {
        $ownedRows[] = $row;
    } else {
        $globalRows[] = $row;
    }
}

// Prevent one owned library track from appearing as many "owned" metadata variants.
// Keep only the best-scored candidate per normalized artist/title hash.
if (!empty($ownedRows)) {
    $ownedByHash = [];
    foreach ($ownedRows as $row) {
        $h = (string)($row['candidate_hash'] ?? '');
        if ($h === '') {
            $h = '__nohash__' . (string)($row['id'] ?? '');
        }
        if (!isset($ownedByHash[$h])) {
            $ownedByHash[$h] = $row;
            continue;
        }
        $curr = $ownedByHash[$h];
        $currScore = (float)($curr['match_score'] ?? 0);
        $nextScore = (float)($row['match_score'] ?? 0);
        if ($nextScore > $currScore) {
            $ownedByHash[$h] = $row;
            continue;
        }
        if ($nextScore === $currScore && (int)($row['id'] ?? 0) > (int)($curr['id'] ?? 0)) {
            $ownedByHash[$h] = $row;
        }
    }
    $ownedRows = array_values($ownedByHash);
}

usort($ownedRows, static function (array $a, array $b): int {
    $aSelected = !empty($a['is_selected']) ? 1 : 0;
    $bSelected = !empty($b['is_selected']) ? 1 : 0;
    if ($aSelected !== $bSelected) {
        return $bSelected <=> $aSelected;
    }
    $aRelevant = !empty($a['is_title_relevant']) ? 1 : 0;
    $bRelevant = !empty($b['is_title_relevant']) ? 1 : 0;
    if ($aRelevant !== $bRelevant) {
        return $bRelevant <=> $aRelevant;
    }
    $aPreferred = !empty($a['is_preferred']) ? 1 : 0;
    $bPreferred = !empty($b['is_preferred']) ? 1 : 0;
    if ($aPreferred !== $bPreferred) {
        return $bPreferred <=> $aPreferred;
    }
    $aRating = isset($a['rating_value']) && is_numeric($a['rating_value']) ? (float)$a['rating_value'] : 0.0;
    $bRating = isset($b['rating_value']) && is_numeric($b['rating_value']) ? (float)$b['rating_value'] : 0.0;
    if ($aRating !== $bRating) {
        return $bRating <=> $aRating;
    }
    $aScore = isset($a['match_score']) && is_numeric($a['match_score']) ? (float)$a['match_score'] : 0.0;
    $bScore = isset($b['match_score']) && is_numeric($b['match_score']) ? (float)$b['match_score'] : 0.0;
    if ($aScore !== $bScore) {
        return $bScore <=> $aScore;
    }
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

usort($globalRows, static function (array $a, array $b): int {
    $aSelected = !empty($a['is_selected']) ? 1 : 0;
    $bSelected = !empty($b['is_selected']) ? 1 : 0;
    if ($aSelected !== $bSelected) {
        return $bSelected <=> $aSelected;
    }
    $aRelevant = !empty($a['is_title_relevant']) ? 1 : 0;
    $bRelevant = !empty($b['is_title_relevant']) ? 1 : 0;
    if ($aRelevant !== $bRelevant) {
        return $bRelevant <=> $aRelevant;
    }
    $aRating = isset($a['rating_value']) && is_numeric($a['rating_value']) ? (float)$a['rating_value'] : 0.0;
    $bRating = isset($b['rating_value']) && is_numeric($b['rating_value']) ? (float)$b['rating_value'] : 0.0;
    if ($aRating !== $bRating) {
        return $bRating <=> $aRating;
    }
    $aScore = isset($a['match_score']) && is_numeric($a['match_score']) ? (float)$a['match_score'] : 0.0;
    $bScore = isset($b['match_score']) && is_numeric($b['match_score']) ? (float)$b['match_score'] : 0.0;
    if ($aScore !== $bScore) {
        return $bScore <=> $aScore;
    }
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

$scope = 'library';
$scopeMessage = 'Showing matches from your DJ library.';
$finalRows = $ownedRows;
if (empty($finalRows)) {
    $scope = 'global';
    $scopeMessage = 'No matches found in your DJ library. Showing global metadata candidates (not owned).';
    $finalRows = $globalRows;
}

echo json_encode([
    'ok' => true,
    'request' => [
        'track_key' => $trackKey,
        'spotify_track_id' => (string)($req['spotify_track_id'] ?? ''),
        'song_title' => $baseTitle,
        'artist' => $baseArtist,
    ],
    'scope' => $scope,
    'scope_message' => $scopeMessage,
    'library_candidate_count' => count($ownedRows),
    'global_candidate_count' => count($globalRows),
    'selected_bpm_track_id' => $selectedBpmTrackId,
    'rows' => $finalRows,
], JSON_UNESCAPED_UNICODE);

function candidateTrackHash(string $title, string $artist): string
{
    $title = mb_strtolower($title, 'UTF-8');
    $artist = mb_strtolower($artist, 'UTF-8');
    $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title);
    $artist = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $artist);
    $title = preg_replace('/\s+/u', ' ', trim((string)$title));
    $artist = preg_replace('/\s+/u', ' ', trim((string)$artist));
    if ($title === '' && $artist === '') {
        return '';
    }
    return hash('sha256', $artist . '|' . $title);
}

function mdjrBpmCandidateRatingSelect(PDO $db): string
{
    static $expr = null;
    if (is_string($expr)) {
        return $expr;
    }
    $cols = mdjrTableColumns($db, 'bpm_test_tracks');
    foreach (['rating', 'stars', 'star_rating', 'rekordbox_rating', 'rb_rating', 'rating_raw'] as $col) {
        if (isset($cols[$col])) {
            $expr = mdjrNormalizedRatingExpr($col);
            return $expr;
        }
    }
    $expr = "0";
    return $expr;
}

function mdjrDjTrackRatingExpr(PDO $db): string
{
    static $expr = null;
    if (is_string($expr)) {
        return $expr;
    }
    $cols = mdjrTableColumns($db, 'dj_tracks');
    foreach (['rating', 'stars', 'star_rating', 'rekordbox_rating', 'rb_rating', 'rating_raw'] as $col) {
        if (isset($cols[$col])) {
            $expr = mdjrNormalizedRatingExpr('d.' . $col);
            return $expr;
        }
    }
    $expr = "0";
    return $expr;
}

function mdjrTableColumns(PDO $db, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $db->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        $cols = [];
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $col) {
            $key = strtolower((string)$col);
            if ($key !== '') {
                $cols[$key] = true;
            }
        }
        $cache[$table] = $cols;
        return $cols;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

function mdjrNormalizedRatingExpr(string $columnSql): string
{
    // Normalize many rating formats to a 0..5 scale:
    // - numeric 0..5
    // - numeric 0..10
    // - numeric 0..100
    // - Rekordbox style 0..255
    // - star glyph strings like "★★★★★"
    return "
        CASE
            WHEN {$columnSql} IS NULL THEN 0
            WHEN CAST({$columnSql} AS CHAR) LIKE '%★%' THEN
                (CHAR_LENGTH(CAST({$columnSql} AS CHAR)) - CHAR_LENGTH(REPLACE(CAST({$columnSql} AS CHAR), '★', '')))
            WHEN CAST({$columnSql} AS DECIMAL(10,2)) >= 250 THEN
                (CAST({$columnSql} AS DECIMAL(10,2)) / 51.0)
            WHEN CAST({$columnSql} AS DECIMAL(10,2)) > 10 THEN
                (CAST({$columnSql} AS DECIMAL(10,2)) / 20.0)
            WHEN CAST({$columnSql} AS DECIMAL(10,2)) > 5 THEN
                (CAST({$columnSql} AS DECIMAL(10,2)) / 2.0)
            ELSE
                CAST({$columnSql} AS DECIMAL(10,2))
        END
    ";
}

function mdjrOwnedCandidateBetter(array $next, array $current): bool
{
    $nextPreferred = !empty($next['is_preferred']) ? 1 : 0;
    $currPreferred = !empty($current['is_preferred']) ? 1 : 0;
    if ($nextPreferred !== $currPreferred) {
        return $nextPreferred > $currPreferred;
    }
    $nextRating = isset($next['rating_value']) && is_numeric($next['rating_value']) ? (float)$next['rating_value'] : 0.0;
    $currRating = isset($current['rating_value']) && is_numeric($current['rating_value']) ? (float)$current['rating_value'] : 0.0;
    if ($nextRating !== $currRating) {
        return $nextRating > $currRating;
    }
    return ((int)($next['dj_track_id'] ?? PHP_INT_MAX)) < ((int)($current['dj_track_id'] ?? PHP_INT_MAX));
}

function mdjrFolderFromLocation(string $location): string
{
    $path = trim($location);
    if ($path === '') {
        return '';
    }
    $path = preg_replace('#^file://(localhost)?#i', '', $path);
    if (!is_string($path)) {
        return '';
    }
    $path = str_replace('\\', '/', $path);
    $path = trim($path);
    if ($path === '' || stripos($path, 'spotify:track:') === 0) {
        return '';
    }
    $dir = trim((string)dirname($path), '/.');
    if ($dir === '' || $dir === DIRECTORY_SEPARATOR || $dir === '.') {
        return '';
    }
    $folder = basename($dir);
    return is_string($folder) ? trim($folder) : '';
}

function mdjrPlaylistBadge(string $preferredPlaylistName, string $anyPlaylistName, string $location): string
{
    $preferredPlaylistName = trim($preferredPlaylistName);
    if ($preferredPlaylistName !== '') {
        return $preferredPlaylistName;
    }
    $anyPlaylistName = trim($anyPlaylistName);
    if ($anyPlaylistName !== '') {
        return $anyPlaylistName;
    }
    return mdjrFolderFromLocation($location);
}

function mdjrOverrideTrackKey(string $title, string $artist): string
{
    $a = mdjrCoreArtist($artist);
    $t = mdjrCoreTitle($title);
    if ($a === '' || $t === '') {
        return '';
    }
    return $a . '|' . $t;
}

function mdjrCoreArtist(string $artist): string
{
    $artist = mb_strtolower($artist, 'UTF-8');
    $artist = preg_replace('/\\b(feat|ft|featuring|x|vs)\\b/u', ' ', $artist);
    $artist = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $artist);
    $artist = preg_replace('/\\s+/u', ' ', trim((string)$artist));
    return (string)$artist;
}

function mdjrCoreTitle(string $title): string
{
    $title = mb_strtolower($title, 'UTF-8');
    $title = preg_replace('/\\(.*?\\)|\\[.*?\\]/u', ' ', $title);
    if (preg_match('/\\s[-–—]\\s/u', $title)) {
        $parts = preg_split('/\\s[-–—]\\s/u', $title);
        if (is_array($parts) && !empty($parts[0])) {
            $title = (string)$parts[0];
        }
    }
    $title = preg_replace('/\\b(feat|ft|featuring|remix|mix|edit|version|remaster(?:ed)?|radio|extended|club|original|live|explicit|clean|mono|stereo|instrumental|karaoke|rework|dub)\\b/u', ' ', $title);
    $title = preg_replace('/\\b(19|20)\\d{2}\\b/u', ' ', $title);
    $title = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $title);
    $title = preg_replace('/\\s+/u', ' ', trim((string)$title));
    return (string)$title;
}
