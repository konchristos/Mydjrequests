<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

if (!verify_csrf_token()) {
    http_response_code(403);
    exit('Invalid session');
}

$attachmentId = (int)($_POST['attachment_id'] ?? 0);
$bugId = (int)($_POST['bug_id'] ?? 0);

if ($attachmentId <= 0 || $bugId <= 0) {
    redirect('dj/bugs.php');
    exit;
}

$db = db();
$stmt = $db->prepare("
    SELECT a.file_path, b.user_id
    FROM bug_attachments a
    INNER JOIN bug_reports b ON b.id = a.bug_id
    WHERE a.id = :aid AND a.bug_id = :bid
    LIMIT 1
");
$stmt->execute(['aid' => $attachmentId, 'bid' => $bugId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || (int)$row['user_id'] !== (int)$_SESSION['dj_id']) {
    http_response_code(403);
    exit('Access denied');
}

// Delete DB row
$del = $db->prepare("DELETE FROM bug_attachments WHERE id = :aid");
$del->execute(['aid' => $attachmentId]);

// Delete file
$filePath = APP_ROOT . $row['file_path'];
if (is_file($filePath)) {
    @unlink($filePath);
}

redirect('dj/bug_view.php?id=' . $bugId);
exit;
