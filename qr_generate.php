<?php
// qr_generate.php — generates a QR code via external API

$uuid = $_GET['uuid'] ?? '';
$dj   = $_GET['dj'] ?? '';

if ($uuid === '') {
    http_response_code(400);
    echo 'Missing uuid parameter';
    exit;
}

// Correct new URL format using rewrite rule
$targetUrl = 'https://mydjrequests.com/r/' . rawurlencode($uuid);

// Build external QR image URL (PNG, 220x220)
$qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($targetUrl);

// Redirect straight to QR PNG
header('Location: ' . $qrImageUrl);
exit;