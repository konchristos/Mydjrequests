<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/bootstrap.php';

if (!function_exists('is_admin') || !is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $db = db();
    djPlaylistPreferencesEnsureSchema($db);
    echo json_encode([
        'ok' => true,
        'message' => 'DJ playlist preference schema ensured.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Schema migration failed.',
        'detail' => $e->getMessage(),
    ]);
}
