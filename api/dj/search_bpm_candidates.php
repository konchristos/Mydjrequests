<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}

$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));
$trackKey = trim((string)($_GET['track_key'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

if ($eventUuid === '' || $trackKey === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event or track']);
    exit;
}

$db = db();

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

$baseTitle = trim((string)($req['song_title'] ?? ''));
$baseArtist = trim((string)($req['artist'] ?? ''));
$search = $q !== '' ? $q : trim($baseTitle . ' ' . $baseArtist);
$manualMode = ($q !== '');

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

$matchTitle = $manualMode ? $search : $baseTitle;
$matchArtist = $manualMode ? $search : $baseArtist;

$titleTokens = tokeniseForMatch($matchTitle);
$artistTokens = tokeniseForMatch($matchArtist);
$searchTokens = tokeniseForMatch($search);
$tokens = $manualMode
    ? array_values(array_unique($searchTokens))
    : array_values(array_unique(array_merge($titleTokens, $artistTokens, $searchTokens)));

$rowsById = [];
$rawTitleNeedle = mb_strtolower($matchTitle !== '' ? $matchTitle : $search, 'UTF-8');
$rawArtistNeedle = mb_strtolower($matchArtist, 'UTF-8');
$normTitleNeedle = normaliseForMatch($matchTitle !== '' ? $matchTitle : $search);
$normArtistNeedle = normaliseForMatch($matchArtist);

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
        SELECT id, title, artist, bpm, key_text, year, genre
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
        SELECT id, title, artist, bpm, key_text, year, genre
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
    SELECT id, title, artist, bpm, key_text, year, genre
    FROM bpm_test_tracks
    WHERE " . implode($manualMode ? ' AND ' : ' OR ', $where) . "
    ORDER BY id DESC
    LIMIT 300
";

$st3 = $db->prepare($sql3);
$st3->execute($params);
foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rowsById[(int)$r['id']] = $r;
}

$rows = array_values($rowsById);

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
            SELECT id, title, artist, bpm, key_text, year, genre
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
        SELECT id, title, artist, bpm, key_text, year, genre
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
    $directTitleHit = ($normBaseTitle !== '' && str_contains($normTitle, $normBaseTitle)) ? 1 : 0;
    $directArtistHit = ($normBaseArtist !== '' && str_contains($normArtist, $normBaseArtist)) ? 1 : 0;
    $rawTitleHit = ($rawTitleNeedle !== '' && str_contains(mb_strtolower($title, 'UTF-8'), $rawTitleNeedle)) ? 1 : 0;
    $rawArtistHit = ($rawArtistNeedle !== '' && str_contains(mb_strtolower($artist, 'UTF-8'), $rawArtistNeedle)) ? 1 : 0;
    $exactPairHit = (
        $rawTitleNeedle !== '' &&
        $rawArtistNeedle !== '' &&
        mb_strtolower(trim($title), 'UTF-8') === trim($rawTitleNeedle) &&
        mb_strtolower(trim($artist), 'UTF-8') === trim($rawArtistNeedle)
    ) ? 1 : 0;

    $combined =
        ($titleScore * 0.45) +
        ($artistScore * 0.20) +
        ($tokenScore * 0.15) +
        ($directTitleHit * 10) +
        ($directArtistHit * 5) +
        ($rawTitleHit * 12) +
        ($rawArtistHit * 8) +
        ($exactPairHit * 25);

    if ($manualMode) {
        $tokenMinHits = max(1, min(2, count($tokens)));
        $hasStrongTokenMatch = ($tokenHit >= $tokenMinHits);
        $hasDirectMatch = ($rawTitleHit === 1 || $directTitleHit === 1 || $directArtistHit === 1);
        if (!$hasStrongTokenMatch && !$hasDirectMatch) {
            continue;
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
        'match_score' => round($combined, 2),
        'title_score' => round($titleScore, 2),
        'artist_score' => round($artistScore, 2),
        'token_score' => round($tokenScore, 2),
        'direct_title_hit' => $directTitleHit,
        'direct_artist_hit' => $directArtistHit,
        'raw_title_hit' => $rawTitleHit,
        'raw_artist_hit' => $rawArtistHit,
        'exact_pair_hit' => $exactPairHit,
    ];
}

usort($scored, static function (array $a, array $b): int {
    if ($a['match_score'] === $b['match_score']) {
        return $b['id'] <=> $a['id'];
    }
    return $b['match_score'] <=> $a['match_score'];
});

$scored = array_slice($scored, 0, $manualMode ? 40 : 100);

echo json_encode([
    'ok' => true,
    'request' => [
        'track_key' => $trackKey,
        'spotify_track_id' => (string)($req['spotify_track_id'] ?? ''),
        'song_title' => $baseTitle,
        'artist' => $baseArtist,
    ],
    'rows' => $scored,
], JSON_UNESCAPED_UNICODE);
