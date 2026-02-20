<?php
// /public/qr/live.php

require_once __DIR__ . '/../app/bootstrap_public.php';
require_once APP_ROOT . '/app/config/database.php';

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=5');
header('Pragma: no-cache');

$db = db();

/**
 * STEP 1: Resolve DJ (UUID preferred)
 */
$djUuid = $_GET['dj'] ?? null;
if (!$djUuid) {
    renderPlaceholder();
    exit;
}

// Adjust if your DJs use numeric IDs instead
$stmt = $db->prepare("
    SELECT id, dj_name, name
    FROM users
    WHERE uuid = ?
    LIMIT 1
");
$stmt->execute([$djUuid]);
$dj = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dj) {
    renderPlaceholder();
    exit;
}

$djId      = (int)$dj['id'];
$djDisplay = $dj['dj_name'] ?: $dj['name'] ?: 'DJ';
$isPremium = mdjr_user_has_premium($db, $djId);

/**
 * STEP 2: Find LIVE event for this DJ
 */
$stmt = $db->prepare("

    SELECT uuid
    FROM events
    WHERE user_id = ?
      AND event_state = 'live'
    ORDER BY
      COALESCE(state_changed_at, created_at) DESC,
      id DESC
    LIMIT 1

");
$stmt->execute([$djId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    renderPlaceholder();
    exit;
}

/**
 * STEP 3: Proxy existing QR generator
 */
$qrUrl = url(
    ($isPremium
        ? ('qr/premium_generate.php?uuid=' . urlencode($event['uuid']) . '&output=obs')
        : ('qr_generate.php?uuid=' . urlencode($event['uuid']) . '&dj=' . urlencode($djDisplay)))
    . '&_=' . time()
);

// Fetch and stream the image
$img = @file_get_contents($qrUrl);
if ($img === false) {
    renderPlaceholder();
    exit;
}

echo $img;
exit;


/**
 * SIMPLE PLACEHOLDER
 * (safe, no dependencies)
 */
function renderPlaceholder()
{
    $img = imagecreatetruecolor(600, 600);

    $bg  = imagecolorallocate($img, 12, 12, 17);
    $txt = imagecolorallocate($img, 255, 47, 210);

    imagefill($img, 0, 0, $bg);
    imagestring($img, 5, 170, 290, 'NO LIVE EVENT', $txt);

    imagepng($img);
    imagedestroy($img);
}
