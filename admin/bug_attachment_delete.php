<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

if (!verify_csrf_token()) {
    http_response_code(403);
    exit('Invalid session');
}

$attachmentId = (int)($_POST['attachment_id'] ?? 0);
$bugId = (int)($_POST['bug_id'] ?? 0);

if ($attachmentId <= 0 || $bugId <= 0) {
    redirect('admin/bugs.php');
    exit;
}

$db = db();
$stmt = $db->prepare("
    SELECT file_path
    FROM bug_attachments
    WHERE id = :aid AND bug_id = :bid
    LIMIT 1
");
$stmt->execute(['aid' => $attachmentId, 'bid' => $bugId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    redirect('admin/bug_view.php?id=' . $bugId);
    exit;
}

$del = $db->prepare("DELETE FROM bug_attachments WHERE id = :aid");
$del->execute(['aid' => $attachmentId]);

$filePath = APP_ROOT . $row['file_path'];
if (is_file($filePath)) {
    @unlink($filePath);
}

redirect('admin/bug_view.php?id=' . $bugId);
exit;
