<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$nid = (int)($_GET['id'] ?? 0);
$return = $_GET['redirect'] ?? '/dj/notifications.php';

if (!is_string($return) || $return === '' || $return[0] !== '/') {
    $return = '/dj/notifications.php';
}

if ($nid > 0) {
    notifications_mark_read((int)$_SESSION['dj_id'], $nid);
}

header('Location: ' . $return);
exit;
