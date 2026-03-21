<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/rekordbox_import_security.php';
require_dj_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$djId = (int)($_SESSION['dj_id'] ?? 0);
if ($djId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$db = db();
ensureImportJobsTable($db);

$stmt = $db->prepare("
    SELECT
        id,
        status,
        stage,
        stage_message,
        upload_bytes,
        stored_bytes,
        tracks_processed,
        dj_tracks_added,
        dj_tracks_updated,
        error_message,
        created_at,
        started_at,
        finished_at,
        tracks_started_at,
        tracks_finished_at,
        playlists_started_at,
        playlists_finished_at,
        finalizing_started_at,
        finalizing_finished_at
    FROM dj_library_import_jobs
    WHERE dj_id = ?
    ORDER BY id DESC
    LIMIT 12
");
$stmt->execute([$djId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$nowTs = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
$items = [];
foreach ($rows as $row) {
    $status = trim((string)($row['status'] ?? 'queued'));
    $stage = trim((string)($row['stage'] ?? ''));
    $elapsedSeconds = computeElapsedSeconds(
        (string)($row['created_at'] ?? ''),
        (string)($row['started_at'] ?? ''),
        (string)($row['finished_at'] ?? ''),
        $nowTs
    );
    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'status' => $status,
        'stage' => $stage !== '' ? $stage : 'queued',
        'stage_message' => trim((string)($row['stage_message'] ?? '')),
        'created_at' => (string)($row['created_at'] ?? ''),
        'created_at_iso' => formatUtcIso((string)($row['created_at'] ?? '')),
        'created_at_display' => formatLocalDisplay((string)($row['created_at'] ?? '')),
        'elapsed_seconds' => $elapsedSeconds,
        'elapsed_display' => formatElapsedSeconds($elapsedSeconds),
        'upload_bytes' => isset($row['upload_bytes']) ? (int)$row['upload_bytes'] : null,
        'upload_display' => formatBytesHuman(isset($row['upload_bytes']) ? (int)$row['upload_bytes'] : null),
        'stored_bytes' => isset($row['stored_bytes']) ? (int)$row['stored_bytes'] : null,
        'stored_display' => formatBytesHuman(isset($row['stored_bytes']) ? (int)$row['stored_bytes'] : null),
        'tracks_processed' => (int)($row['tracks_processed'] ?? 0),
        'dj_tracks_added' => (int)($row['dj_tracks_added'] ?? 0),
        'dj_tracks_updated' => (int)($row['dj_tracks_updated'] ?? 0),
        'error_message' => trim((string)($row['error_message'] ?? '')),
        'error_display' => formatErrorDisplay((string)($row['error_message'] ?? '')),
        'tracks_seconds' => computeStageSeconds(
            (string)($row['tracks_started_at'] ?? ''),
            (string)($row['tracks_finished_at'] ?? ''),
            $status === 'processing' && $stage === 'processing_tracks' ? $nowTs : null
        ),
        'playlists_seconds' => computeStageSeconds(
            (string)($row['playlists_started_at'] ?? ''),
            (string)($row['playlists_finished_at'] ?? ''),
            $status === 'processing' && $stage === 'processing_playlists' ? $nowTs : null
        ),
        'finalizing_seconds' => computeStageSeconds(
            (string)($row['finalizing_started_at'] ?? ''),
            (string)($row['finalizing_finished_at'] ?? ''),
            $status === 'processing' && $stage === 'finalizing' ? $nowTs : null
        ),
    ];
}

echo json_encode([
    'items' => $items,
]);

function formatUtcIso(string $value): string
{
    try {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        return (new DateTimeImmutable($trimmed, new DateTimeZone('UTC')))->format(DateTime::ATOM);
    } catch (Throwable $e) {
        return '';
    }
}

function formatLocalDisplay(string $value): string
{
    $ts = parseUtcTimestamp($value);
    if ($ts === false || $ts <= 0) {
        return '—';
    }
    return date('d M Y, H:i:s', $ts);
}

function computeElapsedSeconds(string $createdAt, string $startedAt, string $finishedAt, int $nowTs): int
{
    $createdTs = parseUtcTimestamp($createdAt);
    $startTs = parseUtcTimestamp($startedAt);
    $endTs = parseUtcTimestamp($finishedAt);
    if ($startTs !== false && $startTs > 0) {
        return max(0, (($endTs !== false && $endTs > 0) ? $endTs : $nowTs) - $startTs);
    }
    if ($createdTs !== false && $createdTs > 0) {
        return max(0, $nowTs - $createdTs);
    }
    return 0;
}

function computeStageSeconds(string $startValue, string $endValue, ?int $fallbackEndTs = null): ?int
{
    $startTs = parseUtcTimestamp($startValue);
    if ($startTs === false || $startTs <= 0) {
        return null;
    }
    $endTs = parseUtcTimestamp($endValue);
    if ($endTs === false || $endTs <= 0) {
        $endTs = $fallbackEndTs;
    }
    if ($endTs === null || $endTs <= 0) {
        return null;
    }
    return max(0, $endTs - $startTs);
}

function formatElapsedSeconds(int $seconds): string
{
    $n = max(0, $seconds);
    $h = (int)floor($n / 3600);
    $m = (int)floor(($n % 3600) / 60);
    $s = (int)($n % 60);
    if ($h > 0) {
        return sprintf('%dh %02dm %02ds', $h, $m, $s);
    }
    if ($m > 0) {
        return sprintf('%dm %02ds', $m, $s);
    }
    return sprintf('%ds', $s);
}

function formatBytesHuman(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '—';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return $unit === 0 ? sprintf('%d %s', (int)$size, $units[$unit]) : sprintf('%.2f %s', $size, $units[$unit]);
}

function formatErrorDisplay(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '—';
    }
    return mb_strimwidth($trimmed, 0, 80, '…', 'UTF-8');
}

function parseUtcTimestamp(string $value)
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    try {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$dt) {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return false;
    }
}

function ensureImportJobsTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_library_import_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dj_id BIGINT UNSIGNED NOT NULL,
            status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
            source_type VARCHAR(32) NOT NULL DEFAULT 'rekordbox_xml',
            source_file_path VARCHAR(1024) NOT NULL,
            chunk_upload_id VARCHAR(64) NULL,
            upload_bytes BIGINT UNSIGNED NULL,
            stored_bytes BIGINT UNSIGNED NULL,
            source_sha256 CHAR(64) NULL,
            stage VARCHAR(64) NOT NULL DEFAULT 'queued',
            stage_message VARCHAR(255) NULL,
            tracks_processed INT UNSIGNED NOT NULL DEFAULT 0,
            new_identities INT UNSIGNED NOT NULL DEFAULT 0,
            existing_identities INT UNSIGNED NOT NULL DEFAULT 0,
            dj_tracks_added INT UNSIGNED NOT NULL DEFAULT 0,
            dj_tracks_updated INT UNSIGNED NOT NULL DEFAULT 0,
            error_message VARCHAR(1000) NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_dj_library_import_jobs_dj_status (dj_id, status, id),
            KEY idx_dj_library_import_jobs_status (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ensureImportJobsColumn($db, 'dj_tracks_updated', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensureImportJobsColumn($db, 'upload_bytes', 'BIGINT UNSIGNED NULL');
    ensureImportJobsColumn($db, 'stored_bytes', 'BIGINT UNSIGNED NULL');
    ensureImportJobsColumn($db, 'source_sha256', 'CHAR(64) NULL');
    ensureImportJobsColumn($db, 'stage', "VARCHAR(64) NOT NULL DEFAULT 'queued'");
    ensureImportJobsColumn($db, 'stage_message', 'VARCHAR(255) NULL');
    ensureImportJobsColumn($db, 'tracks_started_at', 'DATETIME NULL');
    ensureImportJobsColumn($db, 'tracks_finished_at', 'DATETIME NULL');
    ensureImportJobsColumn($db, 'playlists_started_at', 'DATETIME NULL');
    ensureImportJobsColumn($db, 'playlists_finished_at', 'DATETIME NULL');
    ensureImportJobsColumn($db, 'finalizing_started_at', 'DATETIME NULL');
    ensureImportJobsColumn($db, 'finalizing_finished_at', 'DATETIME NULL');
}

function ensureImportJobsColumn(PDO $db, string $column, string $ddl): void
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'dj_library_import_jobs'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$column]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if ($exists) {
        return;
    }
    $db->exec("ALTER TABLE dj_library_import_jobs ADD COLUMN `{$column}` {$ddl}");
}
