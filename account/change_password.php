<?php
//accounts/change_password.php
require_once __DIR__ . '/../app/bootstrap.php';

require_dj_login();

header('Content-Type: application/json');

$djId = (int)($_SESSION['dj_id'] ?? 0);

$current = $_POST['current_password'] ?? '';
$new     = $_POST['new_password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

if ($current === '' || $new === '' || $confirm === '') {
    echo json_encode(['ok' => false, 'error' => 'All fields are required']);
    exit;
}

if ($new !== $confirm) {
    echo json_encode(['ok' => false, 'error' => 'New passwords do not match']);
    exit;
}

if (strlen($new) < 8) {
    echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters']);
    exit;
}

// (Recommended if your app uses CSRF globally)
if (function_exists('verify_csrf_token') && !verify_csrf_token()) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session token. Please refresh and try again.']);
    exit;
}

$userModel = new User();
$user = $userModel->findById($djId);

if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'User not found']);
    exit;
}

if (!password_verify($current, $user['password_hash'])) {
    echo json_encode(['ok' => false, 'error' => 'Current password is incorrect']);
    exit;
}

$ok = $userModel->updatePassword($djId, $new);

if (!$ok) {
    echo json_encode(['ok' => false, 'error' => 'Failed to update password']);
    exit;
}


// Send "password changed" email (non-blocking)
require_once __DIR__ . '/../app/mail.php';
require_once __DIR__ . '/../app/helpers/user_display_name.php';

$displayName = mdjr_display_name($user);
$email       = $user['email'];

$subject = 'Your MyDJRequests password was changed';

$html = "
    <p>Hi " . htmlspecialchars($displayName) . ",</p>
    <p>This is a confirmation that your <strong>MyDJRequests</strong> account password was successfully changed.</p>
    <p>If you made this change, no further action is required.</p>
    <p>If you did <strong>not</strong> change your password, please reset it immediately using the <em>Forgot Password</em> option or contact support.</p>
    <p>— MyDJRequests Team</p>
";

$text =
    "Hi {$displayName},\n\n" .
    "This is a confirmation that your MyDJRequests password was changed.\n\n" .
    "If you did not change it, please reset it immediately using Forgot Password or contact support.\n\n" .
    "— MyDJRequests Team";

@mdjr_send_mail($email, $subject, $html, $text);

echo json_encode(['ok' => true]);