<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('account/');
    exit;
}

if (!verify_csrf_token()) {
    http_response_code(403);
    exit('Invalid session');
}

$userId = (int)$_SESSION['dj_id'];

// Generate 8 recovery codes
$codes = [];
for ($i = 0; $i < 8; $i++) {
    $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8 chars hex
}

$userModel = new User();
$userModel->replaceRecoveryCodes($userId, $codes);

$_SESSION['recovery_codes'] = $codes;

redirect('account/');
exit;
