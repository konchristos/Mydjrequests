<?php
/**
 * qr_poster.php — high-resolution printable A4 poster
 */

require_once __DIR__ . '/app/bootstrap_public.php';

error_reporting(0);
ini_set('display_errors', 0);
if (ob_get_length()) ob_clean();

// -------------------------
// Inputs
// -------------------------
$uuid     = $_GET['uuid']     ?? '';
$dj       = trim($_GET['dj']       ?? 'Your DJ');
$title    = trim($_GET['title']    ?? 'Event');
$location = trim($_GET['location'] ?? 'Location');
$date     = trim($_GET['date']     ?? 'Date');

if ($uuid === '') {
    exit;
}

$eventModel = new Event();
$event = $eventModel->findByUuid($uuid);
if (!$event) {
    exit;
}

$userModel = new User();
$eventDj = $userModel->findById((int)$event['user_id']);
if ($eventDj) {
    $dj = trim((string)($eventDj['dj_name'] ?: $eventDj['name'] ?: $dj));
}
if (trim($title) === 'Event' && !empty($event['title'])) {
    $title = (string)$event['title'];
}
if (trim($location) === 'Location' && !empty($event['location'])) {
    $location = (string)$event['location'];
}
if (trim($date) === 'Date' && !empty($event['event_date'])) {
    $date = (string)$event['event_date'];
}

// -------------------------
// Format date nicely (e.g. 2025-12-14 → 14 December 2025)
// -------------------------
$formattedDate = $date;

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt instanceof DateTime) {
        $formattedDate = $dt->format('j F Y');  // e.g. 14 December 2025
    }
}

// -------------------------
// Fetch large QR
// -------------------------
$isPremium = mdjr_user_has_premium(db(), (int)$event['user_id']);
$qrSettings = $isPremium ? (mdjr_get_user_qr_settings(db(), (int)$event['user_id']) ?: []) : [];
$qrUrl = $isPremium
    ? url('qr/premium_generate.php?uuid=' . rawurlencode($uuid) . '&output=poster')
    : ("https://api.qrserver.com/v1/create-qr-code/?size=800x800&data=" . rawurlencode("https://mydjrequests.com/r/" . rawurlencode($uuid)));
$qrRaw = @file_get_contents($qrUrl);
if (!$qrRaw) exit;

$qrImg = @imagecreatefromstring($qrRaw);
if (!$qrImg) exit;

// -------------------------
// A4 canvas
// -------------------------
$width  = 2480;   // 300 DPI
$height = 3508;

$poster = imagecreatetruecolor($width, $height);

// Colors
$white = imagecolorallocate($poster, 255, 255, 255);
$black = imagecolorallocate($poster, 0, 0, 0);
$pink  = imagecolorallocate($poster, 255, 47, 210);

imagefill($poster, 0, 0, $white);

// -------------------------
// Resize QR
// -------------------------
$posterScalePct = $isPremium ? max(30, min(75, (int)($qrSettings['poster_qr_scale_pct'] ?? 48))) : 48;
$qrSize    = (int)round($width * ($posterScalePct / 100));
$qrSize    = max(800, min(1700, $qrSize));
$qrResized = imagecreatetruecolor($qrSize, $qrSize);

$bgWhite = imagecolorallocate($qrResized, 255, 255, 255);
imagefill($qrResized, 0, 0, $bgWhite);

imagecopyresampled(
    $qrResized, $qrImg,
    0, 0, 0, 0,
    $qrSize, $qrSize,
    imagesx($qrImg), imagesy($qrImg)
);

// -------------------------
// Load font
// -------------------------
$font = $_SERVER['DOCUMENT_ROOT'] . "/app/fonts/ArialBold.ttf";
if (!file_exists($font)) {
    $font = null; // fallback to imagestring()
}

// -------------------------
// Helper: draw centered text
// -------------------------
function draw_centered($img, $size, $y, $color, $font, $text) {
    if (!$font) {
        // simple fallback if TTF font is missing
        $x = 100;
        imagestring($img, 5, $x, $y - 20, $text, $color);
        return;
    }
    $bbox = imagettfbbox($size, 0, $font, $text);
    $textWidth = $bbox[2] - $bbox[0];
    $x = (imagesx($img) - $textWidth) / 2;
    imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
}

function mdjr_wrap_ttf_lines(string $text, string $font, int $fontSize, int $maxWidth, int $maxLines = 3): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return [];
    }

    $words = explode(' ', $text);
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = $current === '' ? $word : ($current . ' ' . $word);
        $bbox = imagettfbbox($fontSize, 0, $font, $candidate);
        $width = abs($bbox[2] - $bbox[0]);

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
    } elseif ($current !== '' && count($lines) >= $maxLines) {
        $last = $lines[$maxLines - 1] ?? '';
        $last = rtrim($last, '.');
        if (substr($last, -3) !== '...') {
            $lines[$maxLines - 1] = rtrim($last) . '...';
        }
    }

    return $lines;
}

function draw_centered_wrapped($img, int $fontSize, int $y, $color, $font, string $text, int $maxWidth, int $lineGap, int $maxLines = 3): int
{
    if (!$font) {
        $simple = substr($text, 0, 70);
        draw_centered($img, 5, $y, $color, null, $simple);
        return $y + 34;
    }

    $lines = mdjr_wrap_ttf_lines($text, $font, $fontSize, $maxWidth, $maxLines);
    if (empty($lines)) {
        return $y;
    }

    $lineY = $y;
    foreach ($lines as $line) {
        draw_centered($img, $fontSize, $lineY, $color, $font, $line);
        $lineY += $lineGap;
    }

    return $lineY;
}

// -------------------------
// Header (site name)
// -------------------------
draw_centered($poster, 80, 350, $black, $font, "MyDjRequests.com");

// -------------------------
// Place QR
// -------------------------
$qrX = ($width - $qrSize) / 2;
$qrY = 500;

imagecopy($poster, $qrResized, $qrX, $qrY, 0, 0, $qrSize, $qrSize);

// -------------------------
// "SCAN ME"
// -------------------------
draw_centered($poster, 140, $qrY + $qrSize + 210, $pink,  $font, "SCAN ME");

// -------------------------
// Info stack (wrapped title + clearer order)
// -------------------------
$contentY = $qrY + $qrSize + 380;
$maxTextWidth = (int)round($width * 0.88);

// 1) Event title (wrapped to prevent overflow)
$contentY = draw_centered_wrapped($poster, 60, $contentY, $black, $font, $title, $maxTextWidth, 74, 3);

// 2) DJ name (strong but below title)
$contentY += 70;
draw_centered($poster, 84, $contentY, $black, $font, strtoupper($dj));

// 3) Location
$contentY += 98;
$contentY = draw_centered_wrapped($poster, 52, $contentY, $black, $font, $location, $maxTextWidth, 62, 2);

// 4) Date
$contentY += 20;
draw_centered($poster, 52, $contentY, $black, $font, $formattedDate);

// -------------------------
// Output PNG
// -------------------------
if (ob_get_length()) ob_clean();
header("Content-Type: image/png");
header("Content-Disposition: attachment; filename=\"EventPoster-$uuid.png\"");

imagepng($poster);

// Cleanup
imagedestroy($poster);
imagedestroy($qrResized);
imagedestroy($qrImg);
exit;
