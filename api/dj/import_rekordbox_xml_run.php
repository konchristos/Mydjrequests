<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

header('Content-Type: application/json; charset=utf-8');
ignore_user_abort(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$jobId = (int)($_POST['job_id'] ?? 0);
if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job id.']);
    exit;
}

$db = db();
ensureDjTracksTable($db);
ensureImportJobsTable($db);

$job = claimQueuedJobForDj($db, $jobId, $djId);
if (!$job) {
    echo json_encode([
        'ok' => true,
        'message' => 'No queued job found to run.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Job accepted for processing.',
    'job_id' => (int)$job['id'],
]);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

processImportJob($db, $job);

function processImportJob(PDO $db, array $job): void
{
    try {
        set_time_limit(0);
        require_once APP_ROOT . '/library_import/RekordboxXMLImporter.php';

        $djId = (int)$job['dj_id'];
        $filePath = (string)$job['source_file_path'];
        if ($djId <= 0 || $filePath === '' || !is_file($filePath)) {
            throw new RuntimeException('Import source file not found.');
        }

        $beforeIdentityCount = tableCount($db, 'track_identities');
        $beforeDjTracksCount = countDjTracks($db, $djId);

        $importer = new RekordboxXMLImporter($db, $djId);
        $result = runImporter($importer, $djId, $filePath);

        $afterIdentityCount = tableCount($db, 'track_identities');
        $afterDjTracksCount = countDjTracks($db, $djId);

        $tracksProcessed = (int)($result['rows_buffered'] ?? $result['total_tracks_seen'] ?? $result['rows_inserted'] ?? 0);
        if ($tracksProcessed < 0) {
            $tracksProcessed = 0;
        }

        $newIdentities = max(0, $afterIdentityCount - $beforeIdentityCount);
        $existingIdentities = max(0, $tracksProcessed - $newIdentities);
        $djTracksAdded = isset($result['rows_inserted'])
            ? (int)$result['rows_inserted']
            : max(0, $afterDjTracksCount - $beforeDjTracksCount);
        $djTracksUpdated = isset($result['rows_updated']) ? (int)$result['rows_updated'] : 0;

        $done = $db->prepare("
            UPDATE dj_library_import_jobs
            SET status = 'done',
                tracks_processed = :tracks_processed,
                new_identities = :new_identities,
                existing_identities = :existing_identities,
                dj_tracks_added = :dj_tracks_added,
                dj_tracks_updated = :dj_tracks_updated,
                error_message = NULL,
                finished_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $done->execute([
            ':tracks_processed' => $tracksProcessed,
            ':new_identities' => $newIdentities,
            ':existing_identities' => $existingIdentities,
            ':dj_tracks_added' => $djTracksAdded,
            ':dj_tracks_updated' => $djTracksUpdated,
            ':id' => (int)$job['id'],
        ]);

        if (is_file($filePath)) {
            @unlink($filePath);
        }

        $chunkUploadId = trim((string)($job['chunk_upload_id'] ?? ''));
        if ($chunkUploadId !== '') {
            cleanupChunkSession($djId, $chunkUploadId);
        }
    } catch (Throwable $e) {
        $failed = $db->prepare("
            UPDATE dj_library_import_jobs
            SET status = 'failed',
                error_message = :error_message,
                finished_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $failed->execute([
            ':error_message' => mb_substr($e->getMessage(), 0, 900, 'UTF-8'),
            ':id' => (int)$job['id'],
        ]);
    }
}

function claimQueuedJobForDj(PDO $db, int $jobId, int $djId): ?array
{
    $db->beginTransaction();
    try {
        $sel = $db->prepare("
            SELECT *
            FROM dj_library_import_jobs
            WHERE id = :id
              AND dj_id = :dj_id
              AND status = 'queued'
            LIMIT 1
            FOR UPDATE
        ");
        $sel->execute([
            ':id' => $jobId,
            ':dj_id' => $djId,
        ]);

        $job = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            $db->commit();
            return null;
        }

        $upd = $db->prepare("
            UPDATE dj_library_import_jobs
            SET status = 'processing',
                started_at = NOW(),
                updated_at = NOW(),
                error_message = NULL
            WHERE id = :id
            LIMIT 1
        ");
        $upd->execute([':id' => (int)$job['id']]);

        $db->commit();
        return $job;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return null;
    }
}

function runImporter($importer, int $djId, string $filePath): array
{
    try {
        $result = $importer->import($filePath);
    } catch (ArgumentCountError $e) {
        $result = $importer->import($djId, $filePath);
    }
    return is_array($result) ? $result : [];
}

function tableCount(PDO $db, string $table): int
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if (!$exists) {
        return 0;
    }
    return (int)$db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

function countDjTracks(PDO $db, int $djId): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM dj_tracks WHERE dj_id = ?");
    $stmt->execute([$djId]);
    return (int)$stmt->fetchColumn();
}

function baseUploadDir(): string
{
    return APP_ROOT . '/uploads/dj_libraries';
}

function chunkRootDir(): string
{
    return baseUploadDir() . '/chunks';
}

function chunkSessionDir(int $djId, string $uploadId): string
{
    return chunkRootDir() . '/' . $djId . '_' . $uploadId;
}

function cleanupChunkSession(int $djId, string $uploadId): void
{
    $dir = chunkSessionDir($djId, $uploadId);
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $path) {
        $p = $path->getPathname();
        if ($path->isDir()) {
            @rmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

function ensureDjTracksTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_tracks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dj_id BIGINT UNSIGNED NOT NULL,
            track_identity_id BIGINT UNSIGNED NULL,
            normalized_hash CHAR(64) NULL,
            title VARCHAR(255) NOT NULL,
            artist VARCHAR(255) NOT NULL,
            bpm DECIMAL(6,2) NULL,
            musical_key VARCHAR(32) NULL,
            genre VARCHAR(128) NULL,
            location TEXT NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'rekordbox_xml',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dj_tracks_dj_hash (dj_id, normalized_hash),
            KEY idx_dj_tracks_dj_id (dj_id),
            KEY idx_dj_tracks_track_identity_id (track_identity_id),
            KEY idx_dj_tracks_artist_title (artist, title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
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
