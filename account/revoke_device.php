<?php
//account/revoke_device.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

if (!verify_csrf_token()) {
    redirect('account');
}

$deviceId = (int)($_POST['device_id'] ?? 0);

$userModel = new User();
$userModel->revokeTrustedDevice($_SESSION['dj_id'], $deviceId);

redirect('account');

