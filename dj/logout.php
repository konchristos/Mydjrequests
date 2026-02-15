<?php
require_once __DIR__ . '/../app/bootstrap.php';

$auth = new AuthController();
$auth->logout();

// After logout, you are no longer a logged-in DJ,
// so login.php needs the maintenance key again.
header('Location: ' . mdjr_url('dj/login.php'));
exit;