<?php
declare(strict_types=1);

ini_set('upload_max_filesize', '500M');
ini_set('post_max_size', '500M');
ini_set('max_execution_time', '600');
set_time_limit(600);

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

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$db = db();
ensureDjTracksTable($db);
ensureImportJobsTable($db);

$action = trim((string)($_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'start':
            handleChunkStart($djId);
            break;
        case 'chunk':
            handleChunkPart($djId);
            break;
        case 'finish':
            handleChunkFinish($db, $djId);
            break;
        default:
            handleSingleUpload($db, $djId);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
}

function handleChunkStart(int $djId): void
{
    $fileName = trim((string)($_POST['file_name'] ?? ''));
    $fileSize = (int)($_POST['file_size'] ?? 0);
    $totalChunks = (int)($_POST['total_chunks'] ?? 0);

    if ($fileName === '' || $fileSize <= 0 || $totalChunks <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing upload metadata.']);
        return;
    }

    if (!isAllowedLibraryUploadName($fileName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only .xml and .zip files are allowed.']);
        return;
    }

    $safeName = sanitiseLibraryFilename($fileName);
    $uploadId = bin2hex(random_bytes(16));

    $sessionDir = chunkSessionDir($djId, $uploadId);
    ensureDirectory($sessionDir);

    $meta = [
        'dj_id' => $djId,
        'file_name' => $fileName,
        'safe_name' => $safeName,
        'file_size' => $fileSize,
        'total_chunks' => $totalChunks,
        'created_at' => gmdate('c'),
    ];

    file_put_contents($sessionDir . '/meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'ok' => true,
        'upload_id' => $uploadId,
    ]);
}

function handleChunkPart(int $djId): void
{
    $uploadId = trim((string)($_POST['upload_id'] ?? ''));
    $chunkIndex = (int)($_POST['chunk_index'] ?? -1);

    if ($uploadId === '' || $chunkIndex < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing chunk metadata.']);
        return;
    }

    $meta = loadChunkMeta($djId, $uploadId);
    if (!$meta) {
        http_response_code(404);
        echo json_encode(['error' => 'Upload session not found.']);
        return;
    }

    $totalChunks = (int)($meta['total_chunks'] ?? 0);
    if ($chunkIndex >= $totalChunks) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid chunk index.']);
        return;
    }

    if (empty($_FILES['chunk']) || !is_array($_FILES['chunk'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Chunk payload missing.']);
        return;
    }

    $chunk = $_FILES['chunk'];
    $errorCode = (int)($chunk['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => uploadErrorMessage($errorCode)]);
        return;
    }

    $tmpPath = (string)($chunk['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Chunk upload validation failed.']);
        return;
    }

    $partPath = chunkPartPath($djId, $uploadId, $chunkIndex);
    if (!move_uploaded_file($tmpPath, $partPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to store uploaded chunk.']);
        return;
    }

    echo json_encode([
        'ok' => true,
        'chunk_index' => $chunkIndex,
    ]);
}

function handleChunkFinish(PDO $db, int $djId): void
{
    set_time_limit(0);

    $uploadId = trim((string)($_POST['upload_id'] ?? ''));
    if ($uploadId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing upload id.']);
        return;
    }

    $meta = loadChunkMeta($djId, $uploadId);
    if (!$meta) {
        http_response_code(404);
        echo json_encode(['error' => 'Upload session not found.']);
        return;
    }

    $totalChunks = (int)($meta['total_chunks'] ?? 0);
    if ($totalChunks <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid upload metadata.']);
        return;
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        if (!is_file(chunkPartPath($djId, $uploadId, $i))) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing one or more upload chunks.']);
            return;
        }
    }

    $safeName = (string)($meta['safe_name'] ?? 'rekordbox_library.xml');
    $targetUploadPath = buildTargetUploadPath($djId, $safeName);

    $out = fopen($targetUploadPath, 'wb');
    if ($out === false) {
        throw new RuntimeException('Failed to create merged XML file.');
    }

    try {
        for ($i = 0; $i < $totalChunks; $i++) {
            $in = fopen(chunkPartPath($djId, $uploadId, $i), 'rb');
            if ($in === false) {
                throw new RuntimeException('Failed to read chunk #' . $i);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
    } finally {
        fclose($out);
    }

    $declaredBytes = max(0, (int)($meta['file_size'] ?? 0));
    [$sourcePath, $storedBytes] = prepareUploadedLibrarySource($djId, $targetUploadPath, $safeName);
    $jobId = createImportJob($db, $djId, $sourcePath, $uploadId, $declaredBytes, $storedBytes);
    $dispatched = dispatchImportWorker($jobId);

    echo json_encode([
        'ok' => true,
        'job_id' => $jobId,
        'status' => $dispatched ? 'queued' : 'queued_manual',
    ]);
}

function handleSingleUpload(PDO $db, int $djId): void
{
    if (empty($_FILES['library_xml']) || !is_array($_FILES['library_xml'])) {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0) {
            http_response_code(413);
            echo json_encode(['error' => 'Upload rejected before processing. Use chunked upload or increase server limits.']);
            return;
        }
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded.']);
        return;
    }

    $file = $_FILES['library_xml'];
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => uploadErrorMessage($errorCode)]);
        return;
    }

    $originalName = (string)($file['name'] ?? '');
    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload validation failed.']);
        return;
    }

    if (!isAllowedLibraryUploadName($originalName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only .xml and .zip files are allowed.']);
        return;
    }

    $safeName = sanitiseLibraryFilename($originalName);
    $targetUploadPath = buildTargetUploadPath($djId, $safeName);
    if (!move_uploaded_file($tmpPath, $targetUploadPath)) {
        throw new RuntimeException('Failed to store uploaded file.');
    }

    $declaredBytes = max(0, (int)($file['size'] ?? 0));
    [$sourcePath, $storedBytes] = prepareUploadedLibrarySource($djId, $targetUploadPath, $safeName);
    $jobId = createImportJob($db, $djId, $sourcePath, null, $declaredBytes, $storedBytes);
    $dispatched = dispatchImportWorker($jobId);

    echo json_encode([
        'ok' => true,
        'job_id' => $jobId,
        'status' => $dispatched ? 'queued' : 'queued_manual',
    ]);
}

function createImportJob(
    PDO $db,
    int $djId,
    string $filePath,
    ?string $uploadId,
    int $uploadBytes,
    int $storedBytes
): int
{
    $stmt = $db->prepare('
        INSERT INTO dj_library_import_jobs (
            dj_id, status, source_type, source_file_path, chunk_upload_id,
            upload_bytes, stored_bytes, stage, stage_message,
            tracks_processed, new_identities, existing_identities, dj_tracks_added, dj_tracks_updated,
            error_message, created_at, updated_at
        ) VALUES (
            :dj_id, :status, :source_type, :source_file_path, :chunk_upload_id,
            :upload_bytes, :stored_bytes, :stage, :stage_message,
            0, 0, 0, 0, 0,
            NULL, NOW(), NOW()
        )
    ');

    $stmt->execute([
        ':dj_id' => $djId,
        ':status' => 'queued',
        ':source_type' => 'rekordbox_xml',
        ':source_file_path' => $filePath,
        ':chunk_upload_id' => $uploadId,
        ':upload_bytes' => ($uploadBytes > 0 ? $uploadBytes : null),
        ':stored_bytes' => ($storedBytes > 0 ? $storedBytes : null),
        ':stage' => 'queued',
        ':stage_message' => 'Queued for processing.',
    ]);

    $jobId = (int)$db->lastInsertId();
    if ($jobId <= 0) {
        throw new RuntimeException('Failed to create import job.');
    }

    return $jobId;
}

function dispatchImportWorker(int $jobId): bool
{
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $worker = APP_ROOT . '/app/workers/rekordbox_import_worker.php';
    if (!is_file($worker)) {
        return false;
    }

    $cmd = escapeshellarg($phpBin)
        . ' ' . escapeshellarg($worker)
        . ' --job-id=' . (int)$jobId
        . ' > /dev/null 2>&1 &';

    if (!function_exists('exec')) {
        return false;
    }

    @exec($cmd);
    return true;
}

function baseUploadDir(): string
{
    $dir = APP_ROOT . '/uploads/dj_libraries';
    ensureDirectory($dir);
    return $dir;
}

function chunkRootDir(): string
{
    $dir = baseUploadDir() . '/chunks';
    ensureDirectory($dir);
    return $dir;
}

function chunkSessionDir(int $djId, string $uploadId): string
{
    return chunkRootDir() . '/' . $djId . '_' . $uploadId;
}

function chunkPartPath(int $djId, string $uploadId, int $chunkIndex): string
{
    return chunkSessionDir($djId, $uploadId) . '/part_' . str_pad((string)$chunkIndex, 6, '0', STR_PAD_LEFT) . '.bin';
}

function loadChunkMeta(int $djId, string $uploadId): ?array
{
    $metaPath = chunkSessionDir($djId, $uploadId) . '/meta.json';
    if (!is_file($metaPath)) {
        return null;
    }

    $json = file_get_contents($metaPath);
    if ($json === false || $json === '') {
        return null;
    }

    $meta = json_decode($json, true);
    if (!is_array($meta)) {
        return null;
    }

    if ((int)($meta['dj_id'] ?? 0) !== $djId) {
        return null;
    }

    return $meta;
}

function buildTargetUploadPath(int $djId, string $safeName): string
{
    return baseUploadDir() . '/' . $djId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
}

function sanitiseLibraryFilename(string $fileName): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($fileName));
    if ($safe === '' || $safe === '.' || $safe === '..') {
        $safe = 'rekordbox_library.xml';
    }
    $ext = strtolower((string)pathinfo($safe, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xml', 'zip'], true)) {
        $safe .= '.xml';
    }
    return $safe;
}

function isAllowedLibraryUploadName(string $fileName): bool
{
    $ext = strtolower((string)pathinfo(trim($fileName), PATHINFO_EXTENSION));
    return in_array($ext, ['xml', 'zip'], true);
}

/**
 * @return array{0:string,1:int}
 */
function prepareUploadedLibrarySource(int $djId, string $uploadedPath, string $originalSafeName): array
{
    $ext = strtolower((string)pathinfo($originalSafeName, PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        $storedBytes = is_file($uploadedPath) ? max(0, (int)@filesize($uploadedPath)) : 0;
        return [$uploadedPath, $storedBytes];
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP uploads are not supported on this server.');
    }

    $zip = new ZipArchive();
    if ($zip->open($uploadedPath) !== true) {
        throw new RuntimeException('Failed to open ZIP archive.');
    }

    $xmlEntries = [];
    $maxExtractedBytes = 2 * 1024 * 1024 * 1024; // 2 GB
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = (string)($stat['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'xml') {
                continue;
            }
            $xmlEntries[] = $stat;
        }

        if (count($xmlEntries) !== 1) {
            throw new RuntimeException('ZIP must contain exactly one XML file.');
        }

        $entry = $xmlEntries[0];
        $entryName = (string)($entry['name'] ?? '');
        $entrySize = max(0, (int)($entry['size'] ?? 0));
        if ($entrySize <= 0) {
            throw new RuntimeException('ZIP XML entry is empty.');
        }
        if ($entrySize > $maxExtractedBytes) {
            throw new RuntimeException('Extracted XML exceeds the 2GB safety limit.');
        }

        $xmlSafeName = sanitiseLibraryFilename((string)basename($entryName));
        if (strtolower((string)pathinfo($xmlSafeName, PATHINFO_EXTENSION)) !== 'xml') {
            $xmlSafeName .= '.xml';
        }
        $targetXmlPath = buildTargetUploadPath($djId, $xmlSafeName);

        $in = $zip->getStream($entryName);
        if ($in === false) {
            throw new RuntimeException('Failed to read XML from ZIP archive.');
        }

        $out = fopen($targetXmlPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Failed to create extracted XML file.');
        }

        try {
            $copied = stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        if (!is_int($copied) || $copied <= 0) {
            @unlink($targetXmlPath);
            throw new RuntimeException('Failed to extract XML from ZIP archive.');
        }
    } finally {
        $zip->close();
        if (is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
    }

    $storedBytes = is_file($targetXmlPath) ? max(0, (int)@filesize($targetXmlPath)) : 0;
    if ($storedBytes <= 0) {
        throw new RuntimeException('Extracted XML file is missing.');
    }

    return [$targetXmlPath, $storedBytes];
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
}

function uploadErrorMessage(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Uploaded file exceeds allowed size limit.';
        case UPLOAD_ERR_PARTIAL:
            return 'File upload was interrupted. Please retry.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server is missing a temporary upload directory.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server failed to write uploaded file.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload blocked by server extension.';
        default:
            return 'Upload failed due to an unknown error.';
    }
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
            release_year INT NULL,
            genre VARCHAR(128) NULL,
            location TEXT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_import_job_id BIGINT UNSIGNED NULL,
            last_seen_at DATETIME NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'rekordbox_xml',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dj_tracks_dj_hash (dj_id, normalized_hash),
            KEY idx_dj_tracks_dj_id (dj_id),
            KEY idx_dj_tracks_track_identity_id (track_identity_id),
            KEY idx_dj_tracks_artist_title (artist, title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    ensureDjTracksColumn($db, 'is_available', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensureDjTracksColumn($db, 'last_seen_import_job_id', 'BIGINT UNSIGNED NULL');
    ensureDjTracksColumn($db, 'last_seen_at', 'DATETIME NULL');
    ensureDjTracksColumn($db, 'release_year', 'INT NULL');
}

function ensureDjTracksColumn(PDO $db, string $column, string $ddl): void
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'dj_tracks'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$column]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }
    $db->exec("ALTER TABLE dj_tracks ADD COLUMN `{$column}` {$ddl}");
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
