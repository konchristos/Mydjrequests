<?php
require_once __DIR__ . '/../app/bootstrap_public.php';

function mdjr_hex_to_rgb_components(string $hex): array
{
    $hex = strtoupper(trim($hex));
    if (!preg_match('/^#[0-9A-F]{6}$/', $hex)) {
        $hex = '#000000';
    }

    return [
        hexdec(substr($hex, 1, 2)),
        hexdec(substr($hex, 3, 2)),
        hexdec(substr($hex, 5, 2)),
    ];
}

function mdjr_load_image_from_path(string $path)
{
    if (!is_file($path)) {
        return null;
    }

    $mime = mdjr_detect_upload_mime($path);
    if ($mime === 'image/png') {
        return @imagecreatefrompng($path);
    }
    if ($mime === 'image/jpeg') {
        return @imagecreatefromjpeg($path);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }

    return null;
}

$db = db();
mdjr_ensure_premium_tables($db);

$uuid = trim((string)($_GET['uuid'] ?? ''));
if ($uuid === '') {
    http_response_code(400);
    exit('Missing event UUID');
}

$eventModel = new Event();
$event = $eventModel->findByUuid($uuid);
if (!$event) {
    http_response_code(404);
    exit('Event not found');
}

$ownerId = (int)$event['user_id'];
if (!mdjr_user_has_premium($db, $ownerId)) {
    header('Location: ' . url('qr_generate.php?uuid=' . rawurlencode($uuid)), true, 302);
    exit;
}

$link = mdjr_get_or_create_premium_link($db, $event, $ownerId);
$settings = mdjr_get_user_qr_settings($db, $ownerId) ?: [];

$slug = (string)($link['slug'] ?? '');
if ($slug === '') {
    header('Location: ' . url('qr_generate.php?uuid=' . rawurlencode($uuid)), true, 302);
    exit;
}

$targetUrl = url('e/' . rawurlencode($slug) . '?src=qr');
$requestedSize = (int)($_GET['size'] ?? 0);
$size = $requestedSize > 0
    ? max(220, min(1200, $requestedSize))
    : max(220, min(1200, (int)($settings['image_size'] ?? 480)));
$fgHex = (string)($settings['foreground_color'] ?? '#000000');
$bgHex = (string)($settings['background_color'] ?? '#FFFFFF');
$frameText = trim((string)($settings['frame_text'] ?? ''));
$logoScalePct = max(8, min(20, (int)($settings['logo_scale_pct'] ?? 18)));

// Optional query overrides for live preview in settings.
$qFg = strtoupper(trim((string)($_GET['fg'] ?? '')));
$qBg = strtoupper(trim((string)($_GET['bg'] ?? '')));
$qFrame = trim((string)($_GET['frame'] ?? ''));
$qLogoScale = (int)($_GET['logo_scale'] ?? 0);
if (preg_match('/^#[0-9A-F]{6}$/', $qFg)) {
    $fgHex = $qFg;
}
if (preg_match('/^#[0-9A-F]{6}$/', $qBg)) {
    $bgHex = $qBg;
}
if ($qFrame !== '') {
    $frameText = substr($qFrame, 0, 80);
}
if ($qLogoScale > 0) {
    $logoScalePct = max(8, min(20, $qLogoScale));
}

$fg = mdjr_hex_to_rgb_components($fgHex);
$bg = mdjr_hex_to_rgb_components($bgHex);
$colorParam = sprintf('%02X%02X%02X', $fg[0], $fg[1], $fg[2]);
$bgParam = sprintf('%02X%02X%02X', $bg[0], $bg[1], $bg[2]);

$apiUrl = 'https://api.qrserver.com/v1/create-qr-code/'
    . '?size=' . $size . 'x' . $size
    . '&ecc=H'
    . '&qzone=2'
    . '&color=' . $colorParam
    . '&bgcolor=' . $bgParam
    . '&data=' . rawurlencode($targetUrl);

$raw = @file_get_contents($apiUrl);
if ($raw === false || $raw === '') {
    header('Location: ' . url('qr_generate.php?uuid=' . rawurlencode($uuid)), true, 302);
    exit;
}

$qr = @imagecreatefromstring($raw);
if (!$qr) {
    header('Location: ' . url('qr_generate.php?uuid=' . rawurlencode($uuid)), true, 302);
    exit;
}

$logoPath = trim((string)($settings['logo_path'] ?? ''));
if ($logoPath !== '') {
    $fullLogoPath = APP_ROOT . '/' . ltrim($logoPath, '/');
    $logo = mdjr_load_image_from_path($fullLogoPath);
    if ($logo) {
        $qrW = imagesx($qr);
        $qrH = imagesy($qr);
        $logoW = imagesx($logo);
        $logoH = imagesy($logo);

        if ($logoW > 0 && $logoH > 0) {
            $maxLogo = (int)floor(min($qrW, $qrH) * ($logoScalePct / 100));
            $ratio = min($maxLogo / $logoW, $maxLogo / $logoH);
            $dstW = max(1, (int)floor($logoW * $ratio));
            $dstH = max(1, (int)floor($logoH * $ratio));

            $dstX = (int)floor(($qrW - $dstW) / 2);
            $dstY = (int)floor(($qrH - $dstH) / 2);

            $pad = 8;
            $white = imagecolorallocate($qr, 255, 255, 255);
            imagefilledrectangle(
                $qr,
                max(0, $dstX - $pad),
                max(0, $dstY - $pad),
                min($qrW, $dstX + $dstW + $pad),
                min($qrH, $dstY + $dstH + $pad),
                $white
            );

            imagealphablending($qr, true);
            imagesavealpha($qr, true);
            imagecopyresampled($qr, $logo, $dstX, $dstY, 0, 0, $dstW, $dstH, $logoW, $logoH);
        }

        imagedestroy($logo);
    }
}

$out = $qr;
if ($frameText !== '') {
    $paddingY = 56;
    $canvasW = imagesx($qr);
    $canvasH = imagesy($qr) + $paddingY;

    $canvas = imagecreatetruecolor($canvasW, $canvasH);
    $bgColor = imagecolorallocate($canvas, $bg[0], $bg[1], $bg[2]);
    imagefill($canvas, 0, 0, $bgColor);

    imagecopy($canvas, $qr, 0, 0, 0, 0, imagesx($qr), imagesy($qr));

    $textColor = imagecolorallocate($canvas, $fg[0], $fg[1], $fg[2]);
    $font = 5;
    $text = strtoupper(substr($frameText, 0, 42));
    $textW = imagefontwidth($font) * strlen($text);
    $textX = (int)max(0, floor(($canvasW - $textW) / 2));
    $textY = imagesy($qr) + 20;
    imagestring($canvas, $font, $textX, $textY, $text, $textColor);

    $out = $canvas;
}

header('Content-Type: image/png');
header('Cache-Control: no-store, max-age=0');
imagepng($out);

if ($out !== $qr) {
    imagedestroy($out);
}
imagedestroy($qr);
