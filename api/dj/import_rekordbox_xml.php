<?php
declare(strict_types=1);

ini_set('upload_max_filesize', '500M');
ini_set('post_max_size', '500M');
ini_set('max_execution_time', '600');
set_time_limit(600);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

if (empty($_FILES['library_xml']) || !is_array($_FILES['library_xml'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['library_xml'];
$errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($errorCode !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => uploadErrorMessage($errorCode)]);
    exit;
}

$originalName = (string)($file['name'] ?? '');
$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload validation failed.']);
    exit;
}

$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext !== 'xml') {
    http_response_code(400);
    echo json_encode(['error' => 'Only .xml files are allowed.']);
    exit;
}

$mime = '';
if (function_exists('mime_content_type')) {
    $mime = (string)(mime_content_type($tmpPath) ?: '');
}
if ($mime !== '' && stripos($mime, 'xml') === false && stripos($mime, 'text/plain') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Uploaded file is not valid XML.']);
    exit;
}

$uploadDir = APP_ROOT . '/uploads/dj_libraries/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to initialize upload directory.']);
    exit;
}

$safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
if ($safeBase === '' || $safeBase === '.' || $safeBase === '..') {
    $safeBase = 'rekordbox_library.xml';
}

$djId = (int)($_SESSION['dj_id'] ?? 0);
if ($djId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$targetPath = $uploadDir . $djId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase;
if (!move_uploaded_file($tmpPath, $targetPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store uploaded file.']);
    exit;
}

require_once APP_ROOT . '/library_import/RekordboxXMLImporter.php';

$db = db();

$beforeIdentityCount = tableCount($db, 'track_identities');
$beforeDjTracksCount = countDjTracks($db, $djId);

try {
    $importer = new RekordboxXMLImporter($db, $djId);
    $result = runImporter($importer, $djId, $targetPath);

    $afterIdentityCount = tableCount($db, 'track_identities');
    $afterDjTracksCount = countDjTracks($db, $djId);

    $tracksProcessed = (int)($result['rows_buffered'] ?? $result['total_tracks_seen'] ?? $result['rows_inserted'] ?? 0);
    if ($tracksProcessed < 0) {
        $tracksProcessed = 0;
    }

    $newIdentities = max(0, $afterIdentityCount - $beforeIdentityCount);
    $existingIdentities = max(0, $tracksProcessed - $newIdentities);
    $djTracksAdded = max(0, $afterDjTracksCount - $beforeDjTracksCount);

    echo json_encode([
        'tracks_processed' => $tracksProcessed,
        'new_identities' => $newIdentities,
        'existing_identities' => $existingIdentities,
        'dj_tracks_added' => $djTracksAdded,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
} finally {
    if (is_file($targetPath)) {
        @unlink($targetPath);
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

    $sql = "SELECT COUNT(*) FROM `{$table}`";
    return (int)$db->query($sql)->fetchColumn();
}

function countDjTracks(PDO $db, int $djId): int
{
    $hasDjTracks = (int)$db->query("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'dj_tracks'
    ")->fetchColumn();
    if ($hasDjTracks <= 0) {
        return 0;
    }

    $hasDjId = (int)$db->query("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'dj_tracks'
          AND COLUMN_NAME = 'dj_id'
    ")->fetchColumn() > 0;

    if (!$hasDjId) {
        return (int)$db->query("SELECT COUNT(*) FROM dj_tracks")->fetchColumn();
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM dj_tracks WHERE dj_id = ?");
    $stmt->execute([$djId]);
    return (int)$stmt->fetchColumn();
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
