<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('account/#recovery-codes');
    exit;
}

if (!verify_csrf_token()) {
    http_response_code(403);
    exit('Invalid session');
}

$userId = (int)$_SESSION['dj_id'];

$userModel = new User();
$hasActiveRecoveryCodes = $userModel->hasActiveRecoveryCodes($userId);

if ($hasActiveRecoveryCodes && (string)($_POST['confirm_overwrite'] ?? '') !== '1') {
    $_SESSION['recovery_codes_error'] = 'Please confirm that you want to replace your existing recovery codes.';
    redirect('account/#recovery-codes');
    exit;
}

// Generate 8 recovery codes
$codes = [];
for ($i = 0; $i < 8; $i++) {
    $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8 chars hex
}

$userModel->replaceRecoveryCodes($userId, $codes);

$_SESSION['recovery_codes'] = $codes;
unset($_SESSION['recovery_codes_error']);

redirect('account/#recovery-codes');
exit;
