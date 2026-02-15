<?php
// /api/admin/get_users.php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

// ---------------------------
// Auth: must be logged in
// ---------------------------
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// ---------------------------
// Auth: must be admin
// ---------------------------
$db = db();

$stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$isAdmin = (int)$stmt->fetchColumn();

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

// ---------------------------
// Fetch users
// ---------------------------
$sql = "
    SELECT
        id,
        COALESCE(NULLIF(dj_name, ''), name) AS display_name,
        email
    FROM users
    ORDER BY display_name ASC
";

$users = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------
// Response
// ---------------------------
echo json_encode([
    'ok'    => true,
    'count' => count($users),
    'users' => $users
]);