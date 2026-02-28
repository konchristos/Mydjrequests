<?php
/**
 * qr_poster.php — high-resolution printable A4 poster
 */

require_once __DIR__ . '/app/bootstrap_public.php';

error_reporting(0);
ini_set('display_errors', 0);
if (ob_get_length()) {
    ob_clean();
}

function draw_centered($img, int $size, int $y, int $color, ?string $font, string $text): void
{
    if ($text === '') {
        return;
    }
    if (!$font) {
        $x = max(10, (int)((imagesx($img) - (strlen($text) * 8)) / 2));
        imagestring($img, 5, $x, max(0, $y - 20), $text, $color);
        return;
    }
    $bbox = imagettfbbox($size, 0, $font, $text);
    $textWidth = (int)abs(($bbox[2] ?? 0) - ($bbox[0] ?? 0));
    $x = (int)((imagesx($img) - $textWidth) / 2);
    imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
}

function mdjr_wrap_ttf_lines(string $text, string $font, int $fontSize, int $maxWidth, int $maxLines = 3): array
{
    $text = trim((string)(preg_replace('/\s+/', ' ', $text) ?? ''));
    if ($text === '') {
        return [];
    }

    $words = explode(' ', $text);
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = $current === '' ? $word : ($current . ' ' . $word);
        $bbox = imagettfbbox($fontSize, 0, $font, $candidate);
        $width = (int)abs(($bbox[2] ?? 0) - ($bbox[0] ?? 0));
        if ($width <= $maxWidth || $current === '') {
            $current = $candidate;
            continue;
        }
        $lines[] = $current;
        $current = $word;
        if (count($lines) >= ($maxLines - 1)) {
            break;
        }
    }

    if ($current !== '' && count($lines) < $maxLines) {
        $lines[] = $current;
    }

    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
    }
    if (count($lines) === $maxLines && count($words) > 0) {
        $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], '.') . '...';
    }

    return $lines;
}

function draw_centered_wrapped($img, int $fontSize, int $y, int $color, ?string $font, string $text, int $maxWidth, int $lineGap, int $maxLines = 3): int
{
    $text = trim($text);
    if ($text === '') {
        return $y;
    }

    if (!$font) {
        draw_centered($img, 5, $y, $color, null, substr($text, 0, 80));
        return $y + 30;
    }

    $lines = mdjr_wrap_ttf_lines($text, $font, $fontSize, $maxWidth, $maxLines);
    if (!$lines) {
        return $y;
    }

    $lineY = $y;
    foreach ($lines as $line) {
        draw_centered($img, $fontSize, $lineY, $color, $font, $line);
        $lineY += $lineGap;
    }
    return $lineY;
}

function mdjr_load_image_file(string $path)
{
    if (!is_file($path)) {
        return false;
    }
    $mime = '';
    $meta = @getimagesize($path);
    if (is_array($meta) && !empty($meta['mime'])) {
        $mime = strtolower((string)$meta['mime']);
    }
    if ($mime === 'image/png') {
        return @imagecreatefrompng($path);
    }
    if ($mime === 'image/jpeg') {
        return @imagecreatefromjpeg($path);
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }
    return false;
}

$uuid = (string)($_GET['uuid'] ?? '');
if ($uuid === '') {
    exit;
}

$eventModel = new Event();
$event = $eventModel->findByUuid($uuid);
if (!$event) {
    exit;
}

$dj = trim((string)($_GET['dj'] ?? ''));
$title = trim((string)($_GET['title'] ?? ''));
$location = trim((string)($_GET['location'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));

$userModel = new User();
$eventDj = $userModel->findById((int)$event['user_id']);
if ($eventDj) {
    $dj = trim((string)($eventDj['dj_name'] ?: $eventDj['name'] ?: $dj));
}
if ($title === '' && !empty($event['title'])) {
    $title = (string)$event['title'];
}
if ($location === '' && !empty($event['location'])) {
    $location = (string)$event['location'];
}
if ($date === '' && !empty($event['event_date'])) {
    $date = (string)$event['event_date'];
}
if ($dj === '') {
    $dj = 'Your DJ';
}
if ($title === '') {
    $title = 'Event';
}
if ($location === '') {
    $location = 'Location';
}
if ($date === '') {
    $date = 'Date';
}

$formattedDate = $date;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt instanceof DateTime) {
        $formattedDate = $dt->format('j F Y');
    }
}

$db = db();
$isPremium = mdjr_user_has_premium($db, (int)$event['user_id']);
$qrSettings = $isPremium ? (mdjr_get_user_qr_settings($db, (int)$event['user_id']) ?: []) : [];
$posterShow = [
    'event_name' => true,
    'location' => true,
    'date' => true,
    'dj_name' => true,
];
$posterOrder = ['dj_name', 'event_name', 'location', 'date'];
$posterBgPath = '';

if ($isPremium && $qrSettings) {
    $posterShow = [
        'event_name' => !isset($qrSettings['poster_show_event_name']) || !empty($qrSettings['poster_show_event_name']),
        'location' => !isset($qrSettings['poster_show_location']) || !empty($qrSettings['poster_show_location']),
        'date' => !isset($qrSettings['poster_show_date']) || !empty($qrSettings['poster_show_date']),
        'dj_name' => !isset($qrSettings['poster_show_dj_name']) || !empty($qrSettings['poster_show_dj_name']),
    ];
    $posterOrder = mdjr_parse_poster_field_order((string)($qrSettings['poster_field_order'] ?? 'dj_name,event_name,location,date'));
    $posterBgPath = trim((string)($qrSettings['poster_bg_path'] ?? ''));

    $eventOverride = mdjr_get_event_poster_override($db, (int)$event['id'], (int)$event['user_id']);
    if ($eventOverride && !empty($eventOverride['use_override'])) {
        $posterShow = [
            'event_name' => !empty($eventOverride['poster_show_event_name']),
            'location' => !empty($eventOverride['poster_show_location']),
            'date' => !empty($eventOverride['poster_show_date']),
            'dj_name' => !empty($eventOverride['poster_show_dj_name']),
        ];
        $posterOrder = mdjr_parse_poster_field_order((string)($eventOverride['poster_field_order'] ?? 'dj_name,event_name,location,date'));
        $overrideBg = trim((string)($eventOverride['poster_bg_path'] ?? ''));
        if ($overrideBg !== '') {
            $posterBgPath = $overrideBg;
        }
    }
}

$qrUrl = $isPremium
    ? url('qr/premium_generate.php?uuid=' . rawurlencode($uuid) . '&output=poster')
    : ('https://api.qrserver.com/v1/create-qr-code/?size=800x800&data=' . rawurlencode('https://mydjrequests.com/r/' . rawurlencode($uuid)));
$qrRaw = @file_get_contents($qrUrl);
if (!$qrRaw) {
    exit;
}
$qrImg = @imagecreatefromstring($qrRaw);
if (!$qrImg) {
    exit;
}

$width = 2480;
$height = 3508;
$poster = imagecreatetruecolor($width, $height);

$white = imagecolorallocate($poster, 255, 255, 255);
$black = imagecolorallocate($poster, 0, 0, 0);
$pink = imagecolorallocate($poster, 255, 47, 210);
$softBlack = imagecolorallocate($poster, 25, 25, 25);
$lightGray = imagecolorallocate($poster, 226, 228, 235);

imagefill($poster, 0, 0, $white);

$bgImg = false;
if ($posterBgPath !== '' && strpos($posterBgPath, '/uploads/') === 0) {
    $bgFile = APP_ROOT . '/' . ltrim($posterBgPath, '/');
    $bgImg = mdjr_load_image_file($bgFile);
}
if ($bgImg) {
    imagecopyresampled(
        $poster,
        $bgImg,
        0,
        0,
        0,
        0,
        $width,
        $height,
        imagesx($bgImg),
        imagesy($bgImg)
    );
}

$font = $_SERVER['DOCUMENT_ROOT'] . '/app/fonts/ArialBold.ttf';
if (!is_file($font)) {
    $font = null;
}

$posterScalePct = $isPremium ? max(30, min(75, (int)($qrSettings['poster_qr_scale_pct'] ?? 48))) : 48;
$qrSize = (int)round($width * ($posterScalePct / 100));
$qrSize = max(780, min(1860, $qrSize));

$qrResized = imagecreatetruecolor($qrSize, $qrSize);
$qrBgWhite = imagecolorallocate($qrResized, 255, 255, 255);
imagefill($qrResized, 0, 0, $qrBgWhite);
imagecopyresampled($qrResized, $qrImg, 0, 0, 0, 0, $qrSize, $qrSize, imagesx($qrImg), imagesy($qrImg));

$qrX = (int)(($width - $qrSize) / 2);
$qrPlatePad = (int)round($qrSize * 0.06);
$qrPlatePad = max(44, min(120, $qrPlatePad));

$headerLogo = false;
$headerLogoUsedPath = '';
$headerLogoCandidates = [
    APP_ROOT . '/assets/logo/MYDJRequests_Logo-blacktext-a4-2400w.png',
    APP_ROOT . '/assets/logo/MYDJRequests_Logo-blacktext-a4-1600w.png',
    APP_ROOT . '/assets/logo/MYDJRequests_Logo-blacktext.png',
    APP_ROOT . '/assets/logo/MYDJRequests_Logo-white-a4-2400w.png',
    APP_ROOT . '/assets/logo/MYDJRequests_Logo-white-a4-1600w.png',
    APP_ROOT . '/assets/logo/MYDJRequests_Logo-white.png',
];
foreach ($headerLogoCandidates as $logoPath) {
    if (!is_file($logoPath)) {
        continue;
    }
    $headerLogo = mdjr_load_image_file($logoPath);
    if ($headerLogo) {
        $headerLogoUsedPath = $logoPath;
        break;
    }
}
$logoW = 0;
$logoH = 0;
$logoPlatePadX = 36;
$logoPlatePadY = 20;
if ($headerLogo) {
    $maxLogoW = (int)round($width * 0.50);
    $maxLogoH = 180;
    $srcW = max(1, imagesx($headerLogo));
    $srcH = max(1, imagesy($headerLogo));
    $scale = min($maxLogoW / $srcW, $maxLogoH / $srcH);
    $logoW = max(180, (int)round($srcW * $scale));
    $logoH = max(50, (int)round($srcH * $scale));
}
$maxTextWidth = (int)round($width * 0.86);

$fieldValues = [
    'event_name' => trim((string)$title),
    'location' => trim((string)$location),
    'date' => trim((string)$formattedDate),
    'dj_name' => trim((string)$dj),
];
$fieldSizes = [
    'event_name' => [60, 74, 3, 58],
    'location' => [52, 62, 2, 54],
    'date' => [52, 62, 1, 64],
    'dj_name' => [86, 94, 2, 72],
];

$logoBlockH = $headerLogo ? ($logoH + ($logoPlatePadY * 2)) : 120;
$qrBlockH = $qrSize + ($qrPlatePad * 2);
$headerToQrGap = 95;
$scanBaselineGap = 245;
$scanToMetaGap = 230;
$metaBlockH = 0;
foreach ($posterOrder as $fieldKey) {
    if (!isset($fieldValues[$fieldKey]) || empty($posterShow[$fieldKey])) {
        continue;
    }
    $value = trim((string)$fieldValues[$fieldKey]);
    if ($value === '') {
        continue;
    }
    [$fontSize, $lineGap, $maxLines, $afterGap] = $fieldSizes[$fieldKey];
    $lineCount = 1;
    if ($font) {
        $wrapped = mdjr_wrap_ttf_lines($value, $font, $fontSize, $maxTextWidth, $maxLines);
        $lineCount = max(1, count($wrapped));
    }
    $metaBlockH += ($lineCount * $lineGap) + $afterGap;
}

$contentTotalH = $logoBlockH + $headerToQrGap + $qrBlockH + $scanBaselineGap + $scanToMetaGap + $metaBlockH;
$footerReserve = 220;
$stackTopY = (int)round(($height - $footerReserve - $contentTotalH) / 2);
$stackTopY = max(60, min(420, $stackTopY));

$logoPlateTopY = $stackTopY;
$logoY = $logoPlateTopY + $logoPlatePadY;
$logoX = (int)(($width - $logoW) / 2);

$qrBlockTopY = $logoPlateTopY + $logoBlockH + $headerToQrGap;
$qrY = $qrBlockTopY + $qrPlatePad;

imagefilledrectangle(
    $poster,
    $qrX - $qrPlatePad,
    $qrY - $qrPlatePad,
    $qrX + $qrSize + $qrPlatePad,
    $qrY + $qrSize + $qrPlatePad,
    $white
);
imagerectangle(
    $poster,
    $qrX - $qrPlatePad,
    $qrY - $qrPlatePad,
    $qrX + $qrSize + $qrPlatePad,
    $qrY + $qrSize + $qrPlatePad,
    $lightGray
);

if ($headerLogo) {
    $isBlackTextLogo = stripos(basename($headerLogoUsedPath), 'blacktext') !== false;
    $plateColor = $isBlackTextLogo ? $white : $softBlack;
    $plateBorder = $isBlackTextLogo ? $lightGray : $softBlack;
    imagefilledrectangle(
        $poster,
        $logoX - $logoPlatePadX,
        $logoY - $logoPlatePadY,
        $logoX + $logoW + $logoPlatePadX,
        $logoY + $logoH + $logoPlatePadY,
        $plateColor
    );
    imagerectangle(
        $poster,
        $logoX - $logoPlatePadX,
        $logoY - $logoPlatePadY,
        $logoX + $logoW + $logoPlatePadX,
        $logoY + $logoH + $logoPlatePadY,
        $plateBorder
    );
    imagecopyresampled($poster, $headerLogo, $logoX, $logoY, 0, 0, $logoW, $logoH, imagesx($headerLogo), imagesy($headerLogo));
} else {
    draw_centered($poster, 82, $stackTopY + 110, $softBlack, $font, 'MyDjRequests.com');
}

imagecopy($poster, $qrResized, $qrX, $qrY, 0, 0, $qrSize, $qrSize);
$scanBaselineY = $qrY + $qrSize + $scanBaselineGap;
draw_centered($poster, 148, $scanBaselineY, $pink, $font, 'SCAN ME');

$contentY = $scanBaselineY + $scanToMetaGap;
foreach ($posterOrder as $fieldKey) {
    if (!isset($fieldValues[$fieldKey])) {
        continue;
    }
    if (empty($posterShow[$fieldKey])) {
        continue;
    }
    $value = $fieldValues[$fieldKey];
    if ($value === '') {
        continue;
    }
    if ($fieldKey === 'dj_name') {
        $value = strtoupper($value);
    }
    [$fontSize, $lineGap, $maxLines, $afterGap] = $fieldSizes[$fieldKey];
    $contentY = draw_centered_wrapped($poster, $fontSize, $contentY, $black, $font, $value, $maxTextWidth, $lineGap, $maxLines);
    $contentY += $afterGap;
}

draw_centered($poster, 48, $height - 108, $softBlack, $font, 'MyDjRequests.com');

if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="EventPoster-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uuid) . '.png"');
imagepng($poster);

if ($bgImg) {
    imagedestroy($bgImg);
}
if ($headerLogo) {
    imagedestroy($headerLogo);
}
imagedestroy($poster);
imagedestroy($qrResized);
imagedestroy($qrImg);
exit;
