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
$qrUrl = $isPremium
    ? url('qr/premium_generate.php?uuid=' . rawurlencode($uuid) . '&size=800')
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
$qrSize    = 1200;
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
draw_centered($poster, 70, $qrY + $qrSize + 150, $pink,  $font, "SCAN ME");

// -------------------------
// Event Title, Location, *Formatted* Date
//   (Option A: these come BEFORE the DJ name)
// -------------------------
$baseY = $qrY + $qrSize + 300;  // just below "SCAN ME"

draw_centered($poster, 60, $baseY,         $black, $font, $title);
draw_centered($poster, 55, $baseY + 120,   $black, $font, $location);
draw_centered($poster, 55, $baseY + 240,   $black, $font, $formattedDate);

// -------------------------
// DJ NAME (last line at the bottom of the stack)
// -------------------------
$djY = $baseY + 240 + 150;  // a bit below the date
draw_centered($poster, 90, $djY, $black, $font, strtoupper($dj));

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
