<?php

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/parse_rekordbox_txt.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request');
}

$file    = $_POST['file'] ?? '';
$mapping = $_POST['mapping'] ?? [];

if (!$file || !is_array($mapping) || empty($mapping)) {
    die('Missing file or mapping');
}

$path = __DIR__ . '/uploads/' . basename($file);
if (!is_file($path)) {
    die('Uploaded file not found');
}

// Re-parse source file (single source of truth)
$data = parseRekordboxTxt($path);
$rows = $data['rows'] ?? [];

$db = db();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Prepare insert
$sql = "
INSERT INTO bpm_test_tracks
(source, title, artist, genre, bpm, bpm_confidence, key_text, year, time_seconds, raw_hash)
VALUES
('rekordbox', :title, :artist, :genre, :bpm, 'very_high', :key_text, :year, :time_seconds, :raw_hash)
ON DUPLICATE KEY UPDATE
  imported_at = CURRENT_TIMESTAMP
";

$stmt = $db->prepare($sql);

// Normalisers
function normYear($v): ?int {
    if (!is_numeric($v)) return null;
    $y = (int)$v;
    return ($y >= 1900 && $y <= 2100) ? $y : null;
}

function normTime(?string $v): ?int {
    if (!$v || !str_contains($v, ':')) return null;
    $p = array_map('intval', explode(':', $v));
    return count($p) === 2 ? ($p[0] * 60 + $p[1]) : null;
}

function makeHash(string $title, string $artist, float $bpm, ?int $time): string {
    return hash('sha256', strtolower(trim("$artist|$title|$bpm|$time")));
}

// Counters
$inserted = 0;
$updated  = 0;
$skipped  = 0;

foreach ($rows as $row) {

    // Apply mapping
    $mapped = [];

    foreach ($mapping as $src => $logical) {
        if ($logical === '') {
            continue;
        }

        $parserKey = normaliseHeader($src);

        if (array_key_exists($parserKey, $row)) {
            $mapped[$logical] = trim((string)$row[$parserKey]);
        }
    }

    // ---- validation ----
    $title  = $mapped['title']  ?? '';
    $artist = $mapped['artist'] ?? '';
    $bpm    = isset($mapped['bpm']) && is_numeric($mapped['bpm'])
        ? round((float)$mapped['bpm'], 2)
        : null;

    if ($title === '' || $artist === '' || $bpm === null) {
        $skipped++;
        continue;
    }

    $year = normYear($mapped['year'] ?? null);
    $time = normTime($mapped['time'] ?? null);

    $hash = makeHash($title, $artist, $bpm, $time);

    $stmt->execute([
        ':title'        => $title,
        ':artist'       => $artist,
        ':genre'        => $mapped['genre'] ?? null,
        ':bpm'          => $bpm,
        ':key_text'     => $mapped['key'] ?? null,
        ':year'         => $year,
        ':time_seconds' => $time,
        ':raw_hash'     => $hash
    ]);

    // MySQL rowCount behaviour:
    // 1 = insert, 2 = duplicate update, 0 = no-op
    $affected = $stmt->rowCount();

    if ($affected === 1) {
        $inserted++;
    } elseif ($affected === 2) {
        $updated++;
    }
}

echo "<h2>Import Complete</h2>";
echo "<p>Total rows: " . count($rows) . "</p>";
echo "<p>Inserted: $inserted</p>";
echo "<p>Duplicates (updated): $updated</p>";
echo "<p>Skipped: $skipped</p>";

echo '<hr>';

echo '<p style="margin-top:20px;">';
echo '<a href="index.php" style="
    display:inline-block;
    padding:10px 16px;
    background:#007bff;
    color:#fff;
    text-decoration:none;
    border-radius:4px;
    font-weight:bold;
">
    Import another playlist
</a>';
echo '</p>';