<?php
// /api/public/notify_signup.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap_public.php';

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid email']);
    exit;
}

$db = db();

try {
    $stmt = $db->prepare("
        INSERT INTO notify_signups (email, ip_address)
        VALUES (?, ?)
    ");
    $stmt->execute([
        strtolower($email),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    // Duplicate email is fine — treat as success
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
}