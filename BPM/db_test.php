<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$db = db();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<h2>BPM DB Performance Test</h2>";
echo "<pre>";

// 1️⃣ Total count
$start = microtime(true);
$total = $db->query("SELECT COUNT(*) FROM bpm_test_tracks")->fetchColumn();
$time = round((microtime(true) - $start) * 1000, 2);
echo "Total tracks: {$total} ({$time} ms)\n";

// 2️⃣ Count by source
$start = microtime(true);
$stmt = $db->prepare("SELECT COUNT(*) FROM bpm_test_tracks WHERE source = ?");
$stmt->execute(['rekordbox']);
$bySource = $stmt->fetchColumn();
$time = round((microtime(true) - $start) * 1000, 2);
echo "Rekordbox tracks: {$bySource} ({$time} ms)\n";

// 3️⃣ Distinct artists
$start = microtime(true);
$artists = $db->query("SELECT COUNT(DISTINCT artist) FROM bpm_test_tracks")->fetchColumn();
$time = round((microtime(true) - $start) * 1000, 2);
echo "Unique artists: {$artists} ({$time} ms)\n";

// 4️⃣ BPM range sample
$start = microtime(true);
$stmt = $db->query("
    SELECT MIN(bpm) AS min_bpm, MAX(bpm) AS max_bpm
    FROM bpm_test_tracks
");
$range = $stmt->fetch(PDO::FETCH_ASSOC);
$time = round((microtime(true) - $start) * 1000, 2);
echo "BPM range: {$range['min_bpm']} – {$range['max_bpm']} ({$time} ms)\n";

// 5️⃣ Sample lookup (indexed hash)
$start = microtime(true);
$stmt = $db->query("
    SELECT title, artist, bpm
    FROM bpm_test_tracks
    ORDER BY imported_at DESC
    LIMIT 5
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$time = round((microtime(true) - $start) * 1000, 2);
echo "Recent imports ({$time} ms):\n";
print_r($rows);

echo "</pre>";