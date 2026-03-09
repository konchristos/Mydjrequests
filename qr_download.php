<?php
require_once __DIR__ . '/app/bootstrap_public.php';
require_once __DIR__ . '/app/helpers/dj_theme.php';
$db = db();

/* -------------------------------------------------
   Helpers
------------------------------------------------- */

function drawRoundedRect($img, $x1, $y1, $x2, $y2, $r, $color) {
    imagearc($img, $x1+$r, $y1+$r, $r*2, $r*2, 180, 270, $color);
    imagearc($img, $x2-$r, $y1+$r, $r*2, $r*2, 270, 360, $color);
    imagearc($img, $x2-$r, $y2-$r, $r*2, $r*2,   0,  90, $color);
    imagearc($img, $x1+$r, $y2-$r, $r*2, $r*2,  90, 180, $color);

    imageline($img, $x1+$r, $y1, $x2-$r, $y1, $color);
    imageline($img, $x1+$r, $y2, $x2-$r, $y2, $color);
    imageline($img, $x1, $y1+$r, $x1, $y2-$r, $color);
    imageline($img, $x2, $y1+$r, $x2, $y2-$r, $color);
}

function centerTextX($imgWidth, $text, $fontSize, $font = null) {
    if ($font) {
        $box = imagettfbbox($fontSize, 0, $font, $text);
        $textWidth = abs($box[2] - $box[0]);
    } else {
        $textWidth = imagefontwidth(5) * strlen($text);
    }
    return (int)(($imgWidth - $textWidth) / 2);
}

/* -------------------------------------------------
   Input
------------------------------------------------- */

$uuid = $_GET['uuid'] ?? '';
if (!$uuid) {
    http_response_code(400);
    exit('Missing event UUID');
}

/* -------------------------------------------------
   Load data
------------------------------------------------- */

$eventModel = new Event();
$event = $eventModel->findByUuid($uuid);
if (!$event) {
    http_response_code(404);
    exit('Event not found');
}

$userModel = new User();
$dj = $userModel->findById((int)$event['user_id']);
$djName = strtoupper($dj['dj_name'] ?: $dj['name'] ?: 'DJ');

/* -------------------------------------------------
   Load QR image
------------------------------------------------- */

$isPremium = mdjr_user_has_premium($db, (int)$event['user_id']);
$qrUrl  = $isPremium
    ? url('qr/premium_generate.php?uuid=' . urlencode($uuid) . '&output=mobile')
    : url('qr_generate.php?uuid=' . urlencode($uuid));
$qrData = @file_get_contents($qrUrl);
if (!$qrData) {
    http_response_code(500);
    exit('Failed to load QR image');
}
$qrImg = imagecreatefromstring($qrData);

/* -------------------------------------------------
   Canvas (SKINNY overlay)
------------------------------------------------- */

$W = 380;   // ⬅ tighter width
$H = 480;

$img = imagecreatetruecolor($W, $H);
imageantialias($img, true);
imagesavealpha($img, true);

$transparent = imagecolorallocatealpha($img, 0, 0, 0, 0);
imagefill($img, 0, 0, $transparent);

/* -------------------------------------------------
   Colours
------------------------------------------------- */

$accentRgb = [255, 47, 210];
if ($isPremium) {
    $theme = mdjr_get_dj_theme_config($db, (int)$event['user_id']);
    $accentRgb = mdjr_hex_to_rgb_triplet((string)($theme['accent'] ?? '#ff2fd2'));
}

$neonAccent = imagecolorallocate($img, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
$white      = imagecolorallocate($img, 255, 255, 255);
$glowAccent = imagecolorallocatealpha($img, $accentRgb[0], $accentRgb[1], $accentRgb[2], 90);

/* -------------------------------------------------
   Background gradient
------------------------------------------------- */

for ($y = 0; $y < $H; $y++) {
    $ratio = $y / $H;
    $r = 8  + (20 - 8)  * $ratio;
    $g = 8  + (20 - 8)  * $ratio;
    $b = 14 + (30 - 14) * $ratio;
    $line = imagecolorallocate($img, (int)$r, (int)$g, (int)$b);
    imageline($img, 0, $y, $W, $y, $line);
}

/* -------------------------------------------------
   Thick rounded neon border
------------------------------------------------- */

for ($i = 0; $i < 3; $i++) {
    drawRoundedRect(
        $img,
        6 + $i,
        6 + $i,
        $W - 6 - $i,
        $H - 6 - $i,
        16,
        $neonAccent
    );
}

/* -------------------------------------------------
   Font
------------------------------------------------- */

$font = __DIR__ . '/assets/fonts/Poppins-SemiBold.ttf';
$useTTF = file_exists($font);

/* -------------------------------------------------
   Header
------------------------------------------------- */

$headerText = 'MyDjRequests.com';
$headerY = 44;
$x = centerTextX($W, $headerText, 18, $useTTF ? $font : null);

if ($useTTF) {
    imagettftext($img, 18, 0, $x, $headerY, $white, $font, $headerText);
} else {
    imagestring($img, 5, $x, $headerY - 18, $headerText, $white);
}

/* -------------------------------------------------
   QR (dominant)
------------------------------------------------- */

$qrSize = 300;
$qrX = (int)(($W - $qrSize) / 2);
$qrY = 70;

$qrFrame = imagecolorallocate($img, 255, 255, 255);
imagefilledrectangle(
    $img,
    $qrX - 8,
    $qrY - 8,
    $qrX + $qrSize + 8,
    $qrY + $qrSize + 8,
    $qrFrame
);

imagecopyresampled(
    $img,
    $qrImg,
    $qrX,
    $qrY,
    0, 0,
    $qrSize,
    $qrSize,
    imagesx($qrImg),
    imagesy($qrImg)
);

/* -------------------------------------------------
   SCAN ME (glow)
------------------------------------------------- */

$scanText = 'SCAN ME';
$scanMeY  = $qrY + $qrSize + 36;
$x = centerTextX($W, $scanText, 22, $useTTF ? $font : null);

if ($useTTF) {
    imagettftext($img, 22, 0, $x+1, $scanMeY+1, $glowAccent, $font, $scanText);
    imagettftext($img, 22, 0, $x-1, $scanMeY-1, $glowAccent, $font, $scanText);
    imagettftext($img, 22, 0, $x,   $scanMeY,   $neonAccent, $font, $scanText);
} else {
    imagestring($img, 5, $x, $scanMeY - 14, $scanText, $neonAccent);
}

/* -------------------------------------------------
   DJ Name
------------------------------------------------- */

$djNameY = $scanMeY + 32;
$x = centerTextX($W, $djName, 22, $useTTF ? $font : null);

if ($useTTF) {
    imagettftext($img, 22, 0, $x, $djNameY, $white, $font, $djName);
} else {
    imagestring($img, 5, $x, $djNameY - 14, $djName, $white);
}

/* -------------------------------------------------
   Output
------------------------------------------------- */

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="mydjrequests-qr-overlay.png"');
header('Cache-Control: no-store');

imagepng($img);
imagedestroy($img);
