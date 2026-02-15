<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$djId = (int)$_SESSION['dj_id'];
$db = db();

$templates = $_POST['templates'] ?? [];

$allowed = ['pre_event', 'live', 'post_event'];

foreach ($templates as $type => $data) {
    if (!in_array($type, $allowed, true)) continue;

    $title = trim((string)($data['title'] ?? ''));
    $body  = trim((string)($data['body'] ?? ''));

    if ($body === '') continue;

    $stmt = $db->prepare("
        INSERT INTO dj_notice_templates (dj_id, notice_type, title, body)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            body  = VALUES(body),
            updated_at = NOW()
    ");
    $stmt->execute([$djId, $type, $title, $body]);
}

redirect('dj/message_templates.php?saved=1');