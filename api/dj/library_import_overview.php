<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/dj_stale_matches.php';
require_dj_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);
if ($djId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

ensureDjLibraryStatsTable($db);
mdjrEnsureDjTrackAvailabilityColumns($db);

$trackCount = 0;
$lastImportedAt = null;
$lastImportSource = 'rekordbox_xml';
$staleCount = 0;

try {
    $statsStmt = $db->prepare("\n        SELECT track_count, last_imported_at, source\n        FROM dj_library_stats\n        WHERE dj_id = ?\n        LIMIT 1\n    ");
    $statsStmt->execute([$djId]);
    $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($statsRow) {
        $trackCount = max(0, (int)($statsRow['track_count'] ?? 0));
        $lastImportedAt = $statsRow['last_imported_at'] ?? null;
        $lastImportSource = trim((string)($statsRow['source'] ?? 'rekordbox_xml'));
    } else {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM dj_tracks WHERE dj_id = ? AND COALESCE(is_available, 1) = 1");
        $countStmt->execute([$djId]);
        $trackCount = max(0, (int)$countStmt->fetchColumn());
    }
} catch (Throwable $e) {
    $trackCount = 0;
    $lastImportedAt = null;
    $lastImportSource = 'rekordbox_xml';
}

try {
    $staleCount = count(mdjrLoadStaleGlobalMatches($db, $djId));
} catch (Throwable $e) {
    $staleCount = 0;
}

$lastImportedIsoUtc = '';
$lastImportedDisplay = 'Never';
if (!empty($lastImportedAt)) {
    try {
        $dtUtc = new DateTimeImmutable((string)$lastImportedAt, new DateTimeZone('UTC'));
        $lastImportedIsoUtc = $dtUtc->format(DateTime::ATOM);
        $lastImportedDisplay = $dtUtc->format('j M Y, g:i a');
    } catch (Throwable $e) {
        $lastImportedDisplay = (string)$lastImportedAt;
    }
}

echo json_encode([
    'track_count' => $trackCount,
    'last_imported_at' => $lastImportedAt,
    'last_imported_iso_utc' => $lastImportedIsoUtc,
    'last_imported_display' => $lastImportedDisplay,
    'last_import_source' => ($lastImportSource !== '' ? $lastImportSource : 'rekordbox_xml'),
    'stale_count' => $staleCount,
]);

function ensureDjLibraryStatsTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_library_stats (
            dj_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            track_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_imported_at DATETIME NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'rekordbox_xml',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
