<?php
//account/revoke_all_devices.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

if (!verify_csrf_token()) {
    redirect('account');
}

$userModel = new User();
$userModel->revokeAllTrustedDevices($_SESSION['dj_id']);

redirect('account');
