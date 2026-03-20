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

$jobId = (int)($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid job id.']);
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
        new_identities,
        existing_identities,
        dj_tracks_added,
        dj_tracks_updated,
        error_message,
        started_at,
        finished_at,
        created_at
    FROM dj_library_import_jobs
    WHERE id = :id
      AND dj_id = :dj_id
    LIMIT 1
");
$stmt->execute([
    ':id' => $jobId,
    ':dj_id' => $djId,
]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => 'Import job not found.']);
    exit;
}

if (($job['status'] ?? '') === 'queued') {
    dispatchImportWorker($db, (int)$job['id']);
}

$elapsedSeconds = 0;
$createdTs = parseUtcTimestamp((string)($job['created_at'] ?? ''));
$startTs = parseUtcTimestamp((string)($job['started_at'] ?? ''));
$endTs = parseUtcTimestamp((string)($job['finished_at'] ?? ''));
$nowTs = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
if ($startTs !== false && $startTs > 0) {
    $elapsedSeconds = max(0, (($endTs !== false && $endTs > 0) ? $endTs : $nowTs) - $startTs);
} elseif ($createdTs !== false && $createdTs > 0) {
    $elapsedSeconds = max(0, $nowTs - $createdTs);
}

echo json_encode([
    'job_id' => (int)$job['id'],
    'status' => (string)$job['status'],
    'stage' => (string)($job['stage'] ?? ''),
    'stage_message' => (string)($job['stage_message'] ?? ''),
    'upload_bytes' => isset($job['upload_bytes']) ? (int)$job['upload_bytes'] : 0,
    'stored_bytes' => isset($job['stored_bytes']) ? (int)$job['stored_bytes'] : 0,
    'tracks_processed' => (int)$job['tracks_processed'],
    'new_identities' => (int)$job['new_identities'],
    'existing_identities' => (int)$job['existing_identities'],
    'dj_tracks_added' => (int)$job['dj_tracks_added'],
    'dj_tracks_updated' => (int)$job['dj_tracks_updated'],
    'error_message' => (string)($job['error_message'] ?? ''),
    'started_at' => (string)($job['started_at'] ?? ''),
    'finished_at' => (string)($job['finished_at'] ?? ''),
    'created_at' => (string)($job['created_at'] ?? ''),
    'elapsed_seconds' => $elapsedSeconds,
]);

function dispatchImportWorker(PDO $db, int $jobId): void
{
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $worker = APP_ROOT . '/app/workers/rekordbox_import_worker.php';
    if (!is_file($worker) || !function_exists('exec')) {
        return;
    }
    if (!mdjr_rekordbox_can_dispatch_worker($db)) {
        mdjr_rekordbox_log_event('worker_deferred', 'Status poll skipped worker dispatch due to global concurrency cap.', [
            'job_id' => $jobId,
            'processing_jobs' => mdjr_rekordbox_count_processing_jobs($db),
            'max_concurrent' => mdjr_rekordbox_global_processing_limit(),
        ]);
        return;
    }

    $cmd = escapeshellarg($phpBin)
        . ' ' . escapeshellarg($worker)
        . ' --job-id=' . (int)$jobId
        . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

/**
 * Parses DB timestamps as UTC to avoid timezone skew in elapsed calculations.
 */
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
