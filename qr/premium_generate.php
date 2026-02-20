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

function mdjr_clamp(float $value, float $min, float $max): float
{
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function mdjr_lerp_color(array $start, array $end, float $t): array
{
    $t = mdjr_clamp($t, 0.0, 1.0);
    return [
        (int)round($start[0] + (($end[0] - $start[0]) * $t)),
        (int)round($start[1] + (($end[1] - $start[1]) * $t)),
        (int)round($start[2] + (($end[2] - $start[2]) * $t)),
    ];
}

function mdjr_alloc_color($img, array $rgb, array &$cache): int
{
    $key = $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2];
    if (!isset($cache[$key])) {
        $cache[$key] = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    }
    return $cache[$key];
}

function mdjr_draw_filled_rounded_rect($img, int $x, int $y, int $w, int $h, int $radius, int $color): void
{
    if ($w <= 0 || $h <= 0) {
        return;
    }

    $radius = max(0, min($radius, (int)floor(min($w, $h) / 2)));
    if ($radius <= 0) {
        imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $color);
        return;
    }

    imagefilledrectangle($img, $x + $radius, $y, $x + $w - $radius - 1, $y + $h - 1, $color);
    imagefilledrectangle($img, $x, $y + $radius, $x + $w - 1, $y + $h - $radius - 1, $color);

    $d = $radius * 2;
    imagefilledellipse($img, $x + $radius, $y + $radius, $d, $d, $color);
    imagefilledellipse($img, $x + $w - $radius - 1, $y + $radius, $d, $d, $color);
    imagefilledellipse($img, $x + $radius, $y + $h - $radius - 1, $d, $d, $color);
    imagefilledellipse($img, $x + $w - $radius - 1, $y + $h - $radius - 1, $d, $d, $color);
}

function mdjr_draw_shape($img, int $x, int $y, int $size, string $style, int $color): void
{
    if ($size <= 0) {
        return;
    }

    if ($style === 'circle') {
        imagefilledellipse($img, $x + (int)floor($size / 2), $y + (int)floor($size / 2), $size, $size, $color);
        return;
    }

    $radius = 0;
    if ($style === 'rounded') {
        $radius = (int)round($size * 0.22);
    } elseif ($style === 'extra-rounded') {
        $radius = (int)round($size * 0.44);
    }

    mdjr_draw_filled_rounded_rect($img, $x, $y, $size, $size, $radius, $color);
}

function mdjr_style_color(
    string $fillMode,
    array $solid,
    array $gradStart,
    array $gradEnd,
    int $row,
    int $col,
    int $moduleCount,
    int $gradientAngle
): array {
    if ($fillMode === 'solid' || $moduleCount <= 1) {
        return $solid;
    }

    $nx = $col / ($moduleCount - 1);
    $ny = $row / ($moduleCount - 1);

    if ($fillMode === 'radial') {
        $dx = $nx - 0.5;
        $dy = $ny - 0.5;
        $dist = sqrt(($dx * $dx) + ($dy * $dy));
        $maxDist = sqrt(0.5);
        $t = $maxDist > 0 ? ($dist / $maxDist) : 0.0;
        return mdjr_lerp_color($gradStart, $gradEnd, $t);
    }

    $rad = deg2rad($gradientAngle % 360);
    $vx = cos($rad);
    $vy = sin($rad);
    $projection = (($nx - 0.5) * $vx) + (($ny - 0.5) * $vy);
    $t = 0.5 + $projection;

    return mdjr_lerp_color($gradStart, $gradEnd, $t);
}

function mdjr_is_dark_pixel($img, int $x, int $y): bool
{
    $color = imagecolorat($img, $x, $y);
    if (imageistruecolor($img)) {
        $alpha = ($color >> 24) & 0x7F;
        // Fully/mostly transparent pixels should be treated as background (light).
        if ($alpha >= 100) {
            return false;
        }
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;
    } else {
        $rgba = imagecolorsforindex($img, $color);
        if (!is_array($rgba)) {
            return false;
        }
        if (((int)($rgba['alpha'] ?? 0)) >= 100) {
            return false;
        }
        $r = (int)($rgba['red'] ?? 255);
        $g = (int)($rgba['green'] ?? 255);
        $b = (int)($rgba['blue'] ?? 255);
    }
    $luma = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
    return $luma < 128;
}

function mdjr_estimate_qr_modules_for_payload(string $payload): int
{
    // Byte-mode capacities for ECC H by version 1..40.
    $caps = [
        7, 14, 24, 34, 44, 58, 64, 84, 98, 119,
        137, 155, 177, 194, 220, 250, 280, 310, 338, 382,
        403, 439, 461, 511, 535, 593, 625, 658, 698, 742,
        790, 842, 898, 958, 983, 1051, 1093, 1139, 1219, 1273,
    ];

    $len = strlen($payload);
    $version = 40;
    for ($i = 0; $i < count($caps); $i++) {
        if ($len <= $caps[$i]) {
            $version = $i + 1;
            break;
        }
    }

    return 21 + (($version - 1) * 4);
}

function mdjr_sample_grid_uniform($img, int $modules, int $quietModules = 0): array
{
    $w = imagesx($img);
    $h = imagesy($img);
    $totalUnits = $modules + max(0, $quietModules * 2);
    if ($totalUnits <= 0) {
        $totalUnits = $modules;
    }
    $stepX = $w / $totalUnits;
    $stepY = $h / $totalUnits;
    $grid = [];

    for ($row = 0; $row < $modules; $row++) {
        $line = [];
        for ($col = 0; $col < $modules; $col++) {
            $sx = (int)round(($quietModules + $col + 0.5) * $stepX);
            $sy = (int)round(($quietModules + $row + 0.5) * $stepY);
            $sx = max(0, min($w - 1, $sx));
            $sy = max(0, min($h - 1, $sy));
            $line[] = mdjr_is_dark_pixel($img, $sx, $sy);
        }
        $grid[] = $line;
    }

    return $grid;
}

function mdjr_score_qr_grid(array $grid, int $modules, int $preferredModules): float
{
    if ($modules < 21 || count($grid) !== $modules) {
        return -10.0;
    }

    $finderOrigins = [
        [0, 0],
        [$modules - 7, 0],
        [0, $modules - 7],
    ];

    $match = 0;
    $total = 0;
    foreach ($finderOrigins as $origin) {
        $ox = $origin[0];
        $oy = $origin[1];

        for ($lr = 0; $lr < 7; $lr++) {
            for ($lc = 0; $lc < 7; $lc++) {
                $expectedDark = (
                    $lc === 0 || $lc === 6 || $lr === 0 || $lr === 6
                    || ($lc >= 2 && $lc <= 4 && $lr >= 2 && $lr <= 4)
                );
                $isDark = !empty($grid[$oy + $lr][$ox + $lc]);
                if ($isDark === $expectedDark) {
                    $match++;
                }
                $total++;
            }
        }
    }
    $finderScore = $total > 0 ? ($match / $total) : 0.0;

    $timingMatch = 0;
    $timingTotal = 0;
    for ($c = 8; $c <= $modules - 9; $c++) {
        $expectedDark = (($c % 2) === 0);
        if (!empty($grid[6][$c]) === $expectedDark) {
            $timingMatch++;
        }
        $timingTotal++;
    }
    for ($r = 8; $r <= $modules - 9; $r++) {
        $expectedDark = (($r % 2) === 0);
        if (!empty($grid[$r][6]) === $expectedDark) {
            $timingMatch++;
        }
        $timingTotal++;
    }
    $timingScore = $timingTotal > 0 ? ($timingMatch / $timingTotal) : 0.0;

    $dark = 0;
    $cells = 0;
    for ($r = 0; $r < $modules; $r++) {
        for ($c = 0; $c < $modules; $c++) {
            if (!empty($grid[$r][$c])) {
                $dark++;
            }
            $cells++;
        }
    }
    $density = $cells > 0 ? ($dark / $cells) : 0.5;
    $densityPenalty = 0.0;
    if ($density < 0.2) {
        $densityPenalty = (0.2 - $density) * 2.0;
    } elseif ($density > 0.62) {
        $densityPenalty = ($density - 0.62) * 2.0;
    }

    $prefPenalty = $preferredModules >= 21 ? (abs($modules - $preferredModules) / 80.0) : 0.0;

    return ($finderScore * 0.7) + ($timingScore * 0.3) - $densityPenalty - $prefPenalty;
}

function mdjr_extract_qr_matrix_uniform($img, int $preferredModules): ?array
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 0 || $h <= 0) {
        return null;
    }

    $candidateModules = [];
    if ($preferredModules >= 21 && (($preferredModules - 21) % 4) === 0) {
        for ($delta = -16; $delta <= 16; $delta += 4) {
            $m = $preferredModules + $delta;
            if ($m < 21 || $m > 177 || (($m - 21) % 4) !== 0) {
                continue;
            }
            $candidateModules[] = $m;
        }
    }
    if (empty($candidateModules)) {
        for ($v = 1; $v <= 40; $v++) {
            $candidateModules[] = 21 + (($v - 1) * 4);
        }
    }

    $candidateModules = array_values(array_unique($candidateModules));
    $best = null;
    $bestScore = -100.0;

    $quietCandidates = [0, 1, 2, 4];
    foreach ($candidateModules as $modules) {
        foreach ($quietCandidates as $quiet) {
            $grid = mdjr_sample_grid_uniform($img, $modules, $quiet);
            $score = mdjr_score_qr_grid($grid, $modules, $preferredModules);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'modules' => $modules,
                    'quiet' => $quiet,
                    'grid' => $grid,
                ];
            }
        }
    }

    if (!$best) {
        return null;
    }

    return $best;
}

function mdjr_finder_origins(int $modules): array
{
    return [
        [0, 0],
        [$modules - 7, 0],
        [0, $modules - 7],
    ];
}

function mdjr_finder_ring_info(int $row, int $col, int $modules): ?array
{
    foreach (mdjr_finder_origins($modules) as $origin) {
        $ox = $origin[0];
        $oy = $origin[1];
        if ($col < $ox || $col > ($ox + 6) || $row < $oy || $row > ($oy + 6)) {
            continue;
        }

        $localCol = $col - $ox;
        $localRow = $row - $oy;

        if ($localCol === 0 || $localCol === 6 || $localRow === 0 || $localRow === 6) {
            return ['type' => 'outer'];
        }

        if ($localCol >= 2 && $localCol <= 4 && $localRow >= 2 && $localRow <= 4) {
            return ['type' => 'inner'];
        }

        return ['type' => 'ring'];
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
$isPreview = ((string)($_GET['preview'] ?? '') === '1');
if ($isPreview) {
    $previewUrl = trim((string)($_GET['preview_url'] ?? ''));
    if ($previewUrl !== '') {
        $parts = parse_url($previewUrl);
        if (is_array($parts)) {
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            if (in_array($scheme, ['http', 'https'], true) && $host !== '') {
                $targetUrl = $previewUrl;
            }
        }
    }
}
$requestedSize = (int)($_GET['size'] ?? 0);
$outputMode = strtolower(trim((string)($_GET['output'] ?? '')));
$outputSize = 0;
if ($outputMode === 'obs') {
    $outputSize = (int)($settings['obs_image_size'] ?? 600);
} elseif ($outputMode === 'poster') {
    $outputSize = (int)($settings['poster_image_size'] ?? 900);
} elseif ($outputMode === 'mobile') {
    $outputSize = (int)($settings['mobile_image_size'] ?? 480);
}
$size = $requestedSize > 0
    ? max(220, min(1800, $requestedSize))
    : max(220, min(1800, $outputSize > 0 ? $outputSize : (int)($settings['image_size'] ?? 480)));

$fgHex = strtoupper((string)($settings['foreground_color'] ?? '#000000'));
$bgHex = strtoupper((string)($settings['background_color'] ?? '#FFFFFF'));
$frameText = trim((string)($settings['frame_text'] ?? ''));
$logoScalePct = max(8, min(20, (int)($settings['logo_scale_pct'] ?? 18)));
$dotStyle = strtolower((string)($settings['dot_style'] ?? 'square'));
$eyeOuterStyle = strtolower((string)($settings['eye_outer_style'] ?? 'square'));
$eyeInnerStyle = strtolower((string)($settings['eye_inner_style'] ?? 'square'));
$fillMode = strtolower((string)($settings['fill_mode'] ?? 'solid'));
$gradientStartHex = strtoupper((string)($settings['gradient_start'] ?? '#000000'));
$gradientEndHex = strtoupper((string)($settings['gradient_end'] ?? '#FF2FD2'));
$gradientAngle = (int)($settings['gradient_angle'] ?? 45);

$allowedStyles = ['square', 'rounded', 'circle', 'extra-rounded'];
if (!in_array($dotStyle, $allowedStyles, true)) {
    $dotStyle = 'square';
}
if (!in_array($eyeOuterStyle, $allowedStyles, true)) {
    $eyeOuterStyle = 'square';
}
if (!in_array($eyeInnerStyle, $allowedStyles, true)) {
    $eyeInnerStyle = 'square';
}
if (!in_array($fillMode, ['solid', 'linear', 'radial'], true)) {
    $fillMode = 'solid';
}

if (preg_match('/^#[0-9A-F]{6}$/', strtoupper(trim((string)($_GET['fg'] ?? ''))))) {
    $fgHex = strtoupper(trim((string)$_GET['fg']));
}
if (preg_match('/^#[0-9A-F]{6}$/', strtoupper(trim((string)($_GET['bg'] ?? ''))))) {
    $bgHex = strtoupper(trim((string)$_GET['bg']));
}
if (array_key_exists('frame', $_GET)) {
    $frameText = substr(trim((string)($_GET['frame'] ?? '')), 0, 80);
}
$qLogoScale = (int)($_GET['logo_scale'] ?? 0);
if ($qLogoScale > 0) {
    $logoScalePct = max(8, min(20, $qLogoScale));
}

$qDot = strtolower(trim((string)($_GET['dot'] ?? '')));
$qEyeOuter = strtolower(trim((string)($_GET['eyeo'] ?? '')));
$qEyeInner = strtolower(trim((string)($_GET['eyei'] ?? '')));
$qFill = strtolower(trim((string)($_GET['fill'] ?? '')));
$qGradientStart = strtoupper(trim((string)($_GET['gs'] ?? '')));
$qGradientEnd = strtoupper(trim((string)($_GET['ge'] ?? '')));
$qGradientAngle = (int)($_GET['ga'] ?? $gradientAngle);

if (in_array($qDot, $allowedStyles, true)) {
    $dotStyle = $qDot;
}
if (in_array($qEyeOuter, $allowedStyles, true)) {
    $eyeOuterStyle = $qEyeOuter;
}
if (in_array($qEyeInner, $allowedStyles, true)) {
    $eyeInnerStyle = $qEyeInner;
}
if (in_array($qFill, ['solid', 'linear', 'radial'], true)) {
    $fillMode = $qFill;
}
if (preg_match('/^#[0-9A-F]{6}$/', $qGradientStart)) {
    $gradientStartHex = $qGradientStart;
}
if (preg_match('/^#[0-9A-F]{6}$/', $qGradientEnd)) {
    $gradientEndHex = $qGradientEnd;
}
$gradientAngle = max(0, min(360, $qGradientAngle));

$preferredModules = mdjr_estimate_qr_modules_for_payload($targetUrl);
$samplePx = 14;
$matrixSize = max(420, min(2400, $preferredModules * $samplePx));
$apiUrl = 'https://api.qrserver.com/v1/create-qr-code/'
    . '?size=' . $matrixSize . 'x' . $matrixSize
    . '&ecc=H'
    . '&qzone=0'
    . '&color=000000'
    . '&bgcolor=FFFFFF'
    . '&data=' . rawurlencode($targetUrl);

$raw = @file_get_contents($apiUrl);
if ($raw === false || $raw === '') {
    header('Location: ' . url('qr_generate.php?uuid=' . rawurlencode($uuid)), true, 302);
    exit;
}

$source = @imagecreatefromstring($raw);
if (!$source) {
    header('Location: ' . url('qr_generate.php?uuid=' . rawurlencode($uuid)), true, 302);
    exit;
}

$matrix = mdjr_extract_qr_matrix_uniform($source, $preferredModules);
imagedestroy($source);
if (!$matrix || empty($matrix['grid']) || (int)($matrix['modules'] ?? 0) < 21) {
    header('Location: ' . url('qr_generate.php?uuid=' . rawurlencode($uuid)), true, 302);
    exit;
}

$modules = (int)$matrix['modules'];
$grid = $matrix['grid'];
$quietZone = 1;
$totalUnits = $modules + ($quietZone * 2);
$modulePx = (int)floor($size / $totalUnits);
if ($modulePx < 1) {
    $modulePx = 1;
}

$drawSize = $modulePx * $totalUnits;
$offsetX = (int)floor(($size - $drawSize) / 2);
$offsetY = (int)floor(($size - $drawSize) / 2);
$codeX = $offsetX + ($quietZone * $modulePx);
$codeY = $offsetY + ($quietZone * $modulePx);

$canvas = imagecreatetruecolor($size, $size);
imagesavealpha($canvas, true);
$colorCache = [];
$fg = mdjr_hex_to_rgb_components($fgHex);
$bg = mdjr_hex_to_rgb_components($bgHex);
$gradStart = mdjr_hex_to_rgb_components($gradientStartHex);
$gradEnd = mdjr_hex_to_rgb_components($gradientEndHex);

$bgColor = mdjr_alloc_color($canvas, $bg, $colorCache);
imagefill($canvas, 0, 0, $bgColor);

for ($row = 0; $row < $modules; $row++) {
    for ($col = 0; $col < $modules; $col++) {
        if (empty($grid[$row][$col])) {
            continue;
        }

        $finder = mdjr_finder_ring_info($row, $col, $modules);
        if ($finder && ($finder['type'] === 'outer' || $finder['type'] === 'inner')) {
            continue;
        }

        $rgb = mdjr_style_color($fillMode, $fg, $gradStart, $gradEnd, $row, $col, $modules, $gradientAngle);
        $color = mdjr_alloc_color($canvas, $rgb, $colorCache);

        $x = $codeX + ($col * $modulePx);
        $y = $codeY + ($row * $modulePx);
        mdjr_draw_shape($canvas, $x, $y, $modulePx, $dotStyle, $color);
    }
}

foreach (mdjr_finder_origins($modules) as $origin) {
    $col0 = $origin[0];
    $row0 = $origin[1];

    $eyeRgb = mdjr_style_color($fillMode, $fg, $gradStart, $gradEnd, $row0 + 3, $col0 + 3, $modules, $gradientAngle);
    $eyeColor = mdjr_alloc_color($canvas, $eyeRgb, $colorCache);

    $x = $codeX + ($col0 * $modulePx);
    $y = $codeY + ($row0 * $modulePx);
    mdjr_draw_shape($canvas, $x, $y, $modulePx * 7, $eyeOuterStyle, $eyeColor);
    mdjr_draw_shape($canvas, $x + $modulePx, $y + $modulePx, $modulePx * 5, $eyeOuterStyle, $bgColor);
    mdjr_draw_shape($canvas, $x + ($modulePx * 2), $y + ($modulePx * 2), $modulePx * 3, $eyeInnerStyle, $eyeColor);
}

$logoPath = trim((string)($settings['logo_path'] ?? ''));
if ($logoPath !== '') {
    $fullLogoPath = APP_ROOT . '/' . ltrim($logoPath, '/');
    $logo = mdjr_load_image_from_path($fullLogoPath);
    if ($logo) {
        $qrW = imagesx($canvas);
        $qrH = imagesy($canvas);
        $logoW = imagesx($logo);
        $logoH = imagesy($logo);

        if ($logoW > 0 && $logoH > 0) {
            $maxLogo = (int)floor(min($qrW, $qrH) * ($logoScalePct / 100));
            $ratio = min($maxLogo / $logoW, $maxLogo / $logoH);
            $dstW = max(1, (int)floor($logoW * $ratio));
            $dstH = max(1, (int)floor($logoH * $ratio));

            $dstX = (int)floor(($qrW - $dstW) / 2);
            $dstY = (int)floor(($qrH - $dstH) / 2);

            $pad = max(4, (int)round($modulePx * 0.6));
            imagefilledrectangle(
                $canvas,
                max(0, $dstX - $pad),
                max(0, $dstY - $pad),
                min($qrW, $dstX + $dstW + $pad),
                min($qrH, $dstY + $dstH + $pad),
                $bgColor
            );

            imagealphablending($canvas, true);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $logo, $dstX, $dstY, 0, 0, $dstW, $dstH, $logoW, $logoH);
        }

        imagedestroy($logo);
    }
}

$out = $canvas;
if ($frameText !== '') {
    $paddingY = 56;
    $canvasW = imagesx($canvas);
    $canvasH = imagesy($canvas) + $paddingY;

    $framed = imagecreatetruecolor($canvasW, $canvasH);
    $frameBg = imagecolorallocate($framed, $bg[0], $bg[1], $bg[2]);
    imagefill($framed, 0, 0, $frameBg);

    imagecopy($framed, $canvas, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas));

    $textColor = imagecolorallocate($framed, $fg[0], $fg[1], $fg[2]);
    $font = 5;
    $text = strtoupper(substr($frameText, 0, 42));
    $textW = imagefontwidth($font) * strlen($text);
    $textX = (int)max(0, floor(($canvasW - $textW) / 2));
    $textY = imagesy($canvas) + 20;
    imagestring($framed, $font, $textX, $textY, $text, $textColor);

    $out = $framed;
}

header('Content-Type: image/png');
header('Cache-Control: no-store, max-age=0');
imagepng($out);

if ($out !== $canvas) {
    imagedestroy($out);
}
imagedestroy($canvas);
