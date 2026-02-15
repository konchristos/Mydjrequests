<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../BPM/bpm_matching/matching.php';

$db = db();

function settingEnabled(PDO $db, string $key, bool $default = false): bool
{
    $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    if ($val === false) return $default;
    $val = strtolower(trim((string)$val));
    return in_array($val, ['1', 'true', 'yes', 'on'], true);
}

function tableColumns(PDO $db, string $table): array
{
    $stmt = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function pickColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array(strtolower($candidate), $columns, true)) return $candidate;
    }
    return null;
}

function normalizeText(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/\[[^\]]*\]|\([^\)]*\)/', ' ', $s);
    $dropWords = [
        'extended', 'mix', 'remix', 'radio', 'edit', 'version', 'original',
        'club', 'dub', 'rework', 'vip', 'bootleg', 'live', 'feat', 'featuring', 'ft'
    ];
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    $parts = preg_split('/\s+/', trim($s)) ?: [];
    $parts = array_values(array_filter($parts, static function ($w) use ($dropWords) {
        return $w !== '' && !in_array($w, $dropWords, true);
    }));
    return implode(' ', $parts);
}

if (!settingEnabled($db, 'bpm_backfill_enabled', true)) {
    echo "BPM/year backfill disabled by feature flag.\n";
    exit(0);
}

$options = getopt('', ['limit::', 'dry-run', 'retry-skips', 'fill::']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 300;
$dryRun = array_key_exists('dry-run', $options);
$retrySkips = array_key_exists('retry-skips', $options);
$threshold = 60.0;

$fillRaw = isset($options['fill']) ? strtolower(trim((string)$options['fill'])) : 'bpm';
$tokens = array_values(array_filter(array_map('trim', explode(',', $fillRaw))));
$fillBpm = in_array('bpm', $tokens, true);
$fillYear = in_array('year', $tokens, true);
if (!$fillBpm && !$fillYear) {
    $fillBpm = true;
}

$spotifyCols = tableColumns($db, 'spotify_tracks');
$bpmCols = tableColumns($db, 'bpm_test_tracks');

$trackArtistCol = pickColumn($spotifyCols, ['artist', 'artist_name', 'artists', 'track_artist']);
$trackTitleCol  = pickColumn($spotifyCols, ['title', 'track_name', 'name', 'song_title', 'song']);
$srcArtistCol   = pickColumn($bpmCols, ['artist', 'artist_name', 'artists']);
$srcTitleCol    = pickColumn($bpmCols, ['title', 'track_name', 'name', 'song_title', 'song']);
$srcBpmCol      = pickColumn($bpmCols, ['bpm']);
$srcYearCol     = pickColumn($bpmCols, ['release_year', 'year']);

if (!$trackArtistCol || !$trackTitleCol) {
    echo "Could not detect artist/title columns in spotify_tracks.\n";
    exit(1);
}
if (!$srcArtistCol || !$srcTitleCol) {
    echo "Could not detect artist/title columns in bpm_test_tracks.\n";
    exit(1);
}
if ($fillBpm && (!$srcBpmCol || !in_array('bpm', $spotifyCols, true))) {
    echo "BPM mode requested but bpm column missing in source/target.\n";
    exit(1);
}
if ($fillYear && (!$srcYearCol || !in_array('release_year', $spotifyCols, true))) {
    echo "Year mode requested but release_year column missing in source/target.\n";
    exit(1);
}

$db->exec("CREATE TABLE IF NOT EXISTS bpm_backfill_skips (
  spotify_track_id BIGINT UNSIGNED PRIMARY KEY,
  best_score DECIMAL(5,2) NOT NULL,
  best_artist VARCHAR(255) NULL,
  best_title VARCHAR(255) NULL,
  reason VARCHAR(64) NOT NULL DEFAULT 'low_confidence',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$modeParts = [];
if ($fillBpm) $modeParts[] = 'bpm';
if ($fillYear) $modeParts[] = 'year';
$modeLabel = implode(',', $modeParts);

echo "BPM/year backfill started at " . date('c') . PHP_EOL;
echo "Mode: " . ($dryRun ? "DRY RUN" : "LIVE") . " | Limit: {$limit} | Threshold: {$threshold}" . PHP_EOL;
echo "Fill: {$modeLabel}" . PHP_EOL;
echo "Retry skips: " . ($retrySkips ? "YES" : "NO") . PHP_EOL . PHP_EOL;

$missingParts = [];
if ($fillBpm) $missingParts[] = "(st.`bpm` IS NULL OR st.`bpm` = 0)";
if ($fillYear) $missingParts[] = "(st.`release_year` IS NULL OR st.`release_year` = 0)";
$wherePrefix = $retrySkips ? '' : 's.spotify_track_id IS NULL AND ';
$sql = "
    SELECT st.`id`, st.`{$trackArtistCol}` AS artist, st.`{$trackTitleCol}` AS title
    FROM `spotify_tracks` st
    LEFT JOIN `bpm_backfill_skips` s ON s.spotify_track_id = st.id
    WHERE {$wherePrefix}(" . implode(' OR ', $missingParts) . ")
    ORDER BY st.`id` DESC
    LIMIT :lim
";
$stmt = $db->prepare($sql);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$candidates) {
    echo "No cache rows need selected backfill.\n";
    exit(0);
}

$srcFields = ["`id`", "`{$srcArtistCol}` AS artist", "`{$srcTitleCol}` AS title"];
if ($fillBpm) $srcFields[] = "`{$srcBpmCol}` AS bpm";
if ($fillYear) $srcFields[] = "`{$srcYearCol}` AS release_year";
$src = $db->query("SELECT " . implode(', ', $srcFields) . " FROM `bpm_test_tracks`")->fetchAll(PDO::FETCH_ASSOC);
if (!$src) {
    echo "No rows in bpm_test_tracks.\n";
    exit(0);
}

$setParts = [];
if ($fillBpm) $setParts[] = "`bpm` = CASE WHEN `bpm` IS NULL OR `bpm` = 0 THEN :bpm ELSE `bpm` END";
if ($fillYear) $setParts[] = "`release_year` = CASE WHEN `release_year` IS NULL OR `release_year` = 0 THEN :ryear ELSE `release_year` END";
$updateSql = "UPDATE `spotify_tracks` SET " . implode(', ', $setParts) . " WHERE `id` = :id";
$updateStmt = $db->prepare($updateSql);

$skipStmt = $db->prepare("INSERT INTO `bpm_backfill_skips` (`spotify_track_id`, `best_score`, `best_artist`, `best_title`, `reason`) VALUES (:id, :score, :artist, :title, 'low_confidence') ON DUPLICATE KEY UPDATE `best_score`=VALUES(`best_score`), `best_artist`=VALUES(`best_artist`), `best_title`=VALUES(`best_title`), `reason`=VALUES(`reason`), `updated_at`=CURRENT_TIMESTAMP");
$clearSkipStmt = $db->prepare("DELETE FROM `bpm_backfill_skips` WHERE `spotify_track_id` = :id");

$matched = 0;
$updated = 0;
$skipped = 0;
$yearNowPlusOne = (int)date('Y') + 1;

foreach ($candidates as $row) {
    $needle = normalizeText(trim(($row['artist'] ?? '') . ' ' . ($row['title'] ?? '')));

    $best = null;
    $bestScore = -1.0;
    foreach ($src as $s) {
        $hay = normalizeText(trim(($s['artist'] ?? '') . ' ' . ($s['title'] ?? '')));
        similar_text($needle, $hay, $pct);
        if ($pct > $bestScore) {
            $bestScore = $pct;
            $best = $s;
        }
    }

    if (!$best || $bestScore < $threshold) {
        $skipped++;
        if (!$dryRun) {
            $skipStmt->execute([
                ':id' => (int)$row['id'],
                ':score' => $bestScore > 0 ? round($bestScore, 2) : 0,
                ':artist' => $best['artist'] ?? null,
                ':title' => $best['title'] ?? null,
            ]);
        }
        continue;
    }

    $params = [':id' => (int)$row['id']];
    $hasPayload = false;

    if ($fillBpm) {
        $bpmRaw = isset($best['bpm']) ? trim((string)$best['bpm']) : '';
        if ($bpmRaw !== '' && is_numeric($bpmRaw) && (float)$bpmRaw > 0) {
            $params[':bpm'] = (float)$bpmRaw;
            $hasPayload = true;
        } else {
            $params[':bpm'] = null;
        }
    }

    if ($fillYear) {
        $yearRaw = isset($best['release_year']) ? trim((string)$best['release_year']) : '';
        if ($yearRaw !== '' && ctype_digit($yearRaw)) {
            $yearInt = (int)$yearRaw;
            if ($yearInt >= 1900 && $yearInt <= $yearNowPlusOne) {
                $params[':ryear'] = $yearInt;
                $hasPayload = true;
            } else {
                $params[':ryear'] = null;
            }
        } else {
            $params[':ryear'] = null;
        }
    }

    if (!$hasPayload) {
        $skipped++;
        if (!$dryRun) {
            $skipStmt->execute([
                ':id' => (int)$row['id'],
                ':score' => round($bestScore, 2),
                ':artist' => $best['artist'] ?? null,
                ':title' => $best['title'] ?? null,
            ]);
        }
        continue;
    }

    $matched++;

    if ($dryRun) {
        $bits = [];
        if ($fillBpm) $bits[] = 'BPM ' . (isset($params[':bpm']) && $params[':bpm'] !== null ? (string)$params[':bpm'] : 'n/a');
        if ($fillYear) $bits[] = 'YEAR ' . (isset($params[':ryear']) && $params[':ryear'] !== null ? (string)$params[':ryear'] : 'n/a');
        echo "[DRY] spotify_tracks#{$row['id']} <= {$best['artist']} - {$best['title']} | " . implode(' | ', $bits) . " ({$bestScore}%)\n";
        continue;
    }

    $updateStmt->execute($params);
    if ($updateStmt->rowCount() > 0) {
        $updated++;
    }
    $clearSkipStmt->execute([':id' => (int)$row['id']]);
}

echo PHP_EOL;
echo "Done.\n";
echo "Candidates: " . count($candidates) . PHP_EOL;
echo "Matched: {$matched}" . PHP_EOL;
echo "Updated: {$updated}" . PHP_EOL;
echo "Skipped: {$skipped}" . PHP_EOL;
