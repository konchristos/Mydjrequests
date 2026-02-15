<?php
declare(strict_types=1);

// ⚠️ DEV-ONLY FILE — DELETE AFTER TESTING

$allowedIps = [
    '127.0.0.1',
    $_SERVER['SERVER_ADDR'] ?? null,
    '49.190.89.230', // ← replace with YOUR real IP
];

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    echo "Forbidden (IP: {$clientIp})\n";
    exit;
}

// 🔥 Run the worker
require_once __DIR__ . '/../app/workers/spotify_audio_features_worker.php';