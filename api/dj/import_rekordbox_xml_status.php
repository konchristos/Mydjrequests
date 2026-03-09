<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
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
$stmt = $db->prepare("
    SELECT
        id,
        status,
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
    dispatchImportWorker((int)$job['id']);
}

echo json_encode([
    'job_id' => (int)$job['id'],
    'status' => (string)$job['status'],
    'tracks_processed' => (int)$job['tracks_processed'],
    'new_identities' => (int)$job['new_identities'],
    'existing_identities' => (int)$job['existing_identities'],
    'dj_tracks_added' => (int)$job['dj_tracks_added'],
    'dj_tracks_updated' => (int)$job['dj_tracks_updated'],
    'error_message' => (string)($job['error_message'] ?? ''),
    'started_at' => (string)($job['started_at'] ?? ''),
    'finished_at' => (string)($job['finished_at'] ?? ''),
    'created_at' => (string)($job['created_at'] ?? ''),
]);

function dispatchImportWorker(int $jobId): void
{
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $worker = APP_ROOT . '/app/workers/rekordbox_import_worker.php';
    if (!is_file($worker) || !function_exists('exec')) {
        return;
    }

    $cmd = escapeshellarg($phpBin)
        . ' ' . escapeshellarg($worker)
        . ' --job-id=' . (int)$jobId
        . ' > /dev/null 2>&1 &';
    @exec($cmd);
}
