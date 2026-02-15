<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/config.php';

if (!isset($_FILES['xml'])) {
    die('No file uploaded');
}

$file = $_FILES['xml'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    die('Upload failed');
}

// Extension check
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, BPM_ALLOWED_EXT, true)) {
    die('Invalid file type. XML only.');
}

// Size check
if ($file['size'] > BPM_MAX_BYTES) {
    die(
        'File too large (' .
        round($file['size'] / 1024 / 1024, 1) .
        "MB). Max allowed: " . BPM_MAX_MB . "MB."
    );
}

// Ensure upload dir exists
if (!is_dir(BPM_UPLOAD_DIR)) {
    mkdir(BPM_UPLOAD_DIR, 0755, true);
}

// Generate safe filename
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$target = BPM_UPLOAD_DIR . 'rekordbox_' . time() . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    die('Failed to save uploaded file');
}

// Redirect to mapping step
header('Location: map_fields.php?file=' . urlencode(basename($target)));
exit;