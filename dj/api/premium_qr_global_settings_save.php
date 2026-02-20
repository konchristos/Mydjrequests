<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

header('Content-Type: application/json');

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

if ($djId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing user context']);
    exit;
}

if (!mdjr_user_has_premium($db, $djId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Premium plan required']);
    exit;
}

$fg = strtoupper(trim((string)($_POST['foreground_color'] ?? '#000000')));
$bg = strtoupper(trim((string)($_POST['background_color'] ?? '#FFFFFF')));
$frameText = trim((string)($_POST['frame_text'] ?? ''));
$logoScalePct = (int)($_POST['logo_scale_pct'] ?? 18);
$imageSize = (int)($_POST['image_size'] ?? 480);
$dotStyle = strtolower(trim((string)($_POST['dot_style'] ?? 'square')));
$eyeOuterStyle = strtolower(trim((string)($_POST['eye_outer_style'] ?? 'square')));
$eyeInnerStyle = strtolower(trim((string)($_POST['eye_inner_style'] ?? 'square')));
$fillMode = strtolower(trim((string)($_POST['fill_mode'] ?? 'solid')));
$gradientStart = strtoupper(trim((string)($_POST['gradient_start'] ?? '#000000')));
$gradientEnd = strtoupper(trim((string)($_POST['gradient_end'] ?? '#FF2FD2')));
$gradientAngle = (int)($_POST['gradient_angle'] ?? 45);
$removeLogo = ((string)($_POST['remove_logo'] ?? '0') === '1');
$resetDefaults = ((string)($_POST['reset_defaults'] ?? '0') === '1');

if (!preg_match('/^#[0-9A-F]{6}$/', $fg)) {
    $fg = '#000000';
}
if (!preg_match('/^#[0-9A-F]{6}$/', $bg)) {
    $bg = '#FFFFFF';
}

$logoScalePct = max(8, min(20, $logoScalePct));
$imageSize = max(220, min(1200, $imageSize));
$frameText = substr($frameText, 0, 80);
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
if (!preg_match('/^#[0-9A-F]{6}$/', $gradientStart)) {
    $gradientStart = '#000000';
}
if (!preg_match('/^#[0-9A-F]{6}$/', $gradientEnd)) {
    $gradientEnd = '#FF2FD2';
}
$gradientAngle = max(0, min(360, $gradientAngle));

try {
    mdjr_ensure_premium_tables($db);

    $existing = mdjr_get_user_qr_settings($db, $djId) ?: [];
    $logoPath = (string)($existing['logo_path'] ?? '');

    if ($resetDefaults) {
        $fg = '#000000';
        $bg = '#FFFFFF';
        $frameText = '';
        $logoScalePct = 18;
        $imageSize = 480;
        $dotStyle = 'square';
        $eyeOuterStyle = 'square';
        $eyeInnerStyle = 'square';
        $fillMode = 'solid';
        $gradientStart = '#000000';
        $gradientEnd = '#FF2FD2';
        $gradientAngle = 45;
        $logoPath = '';
        $removeLogo = true;
    }

    if ($removeLogo) {
        $logoPath = '';
    }

    if (!empty($_FILES['logo']['name'])) {
        $file = $_FILES['logo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Logo upload failed');
        }
        if (($file['size'] ?? 0) > (2 * 1024 * 1024)) {
            throw new RuntimeException('Logo is too large (max 2MB)');
        }

        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        $mime = mdjr_detect_upload_mime((string)$file['tmp_name']);
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Invalid logo format (PNG/JPG/WEBP only)');
        }

        $dir = APP_ROOT . '/uploads/qr_logos/user_' . $djId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to prepare upload folder');
        }

        $name = 'global_' . $djId . '_' . time() . '.' . $allowed[$mime];
        $dest = $dir . '/' . $name;

        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            throw new RuntimeException('Failed to save logo');
        }

        $logoPath = '/uploads/qr_logos/user_' . $djId . '/' . $name;
    }

    mdjr_save_user_qr_settings($db, $djId, [
        'foreground_color' => $fg,
        'background_color' => $bg,
        'frame_text' => $frameText,
        'logo_path' => $logoPath !== '' ? $logoPath : null,
        'logo_scale_pct' => $logoScalePct,
        'image_size' => $imageSize,
        'error_correction' => 'H',
        'dot_style' => $dotStyle,
        'eye_outer_style' => $eyeOuterStyle,
        'eye_inner_style' => $eyeInnerStyle,
        'fill_mode' => $fillMode,
        'gradient_start' => $gradientStart,
        'gradient_end' => $gradientEnd,
        'gradient_angle' => $gradientAngle,
    ]);

    $updated = mdjr_get_user_qr_settings($db, $djId) ?: [];

    echo json_encode([
        'ok' => true,
        'reset_defaults' => $resetDefaults ? 1 : 0,
        'settings' => [
            'foreground_color' => $updated['foreground_color'] ?? $fg,
            'background_color' => $updated['background_color'] ?? $bg,
            'frame_text' => $updated['frame_text'] ?? $frameText,
            'logo_path' => $updated['logo_path'] ?? null,
            'logo_scale_pct' => (int)($updated['logo_scale_pct'] ?? $logoScalePct),
            'image_size' => (int)($updated['image_size'] ?? $imageSize),
            'error_correction' => (string)($updated['error_correction'] ?? 'H'),
            'dot_style' => (string)($updated['dot_style'] ?? $dotStyle),
            'eye_outer_style' => (string)($updated['eye_outer_style'] ?? $eyeOuterStyle),
            'eye_inner_style' => (string)($updated['eye_inner_style'] ?? $eyeInnerStyle),
            'fill_mode' => (string)($updated['fill_mode'] ?? $fillMode),
            'gradient_start' => (string)($updated['gradient_start'] ?? $gradientStart),
            'gradient_end' => (string)($updated['gradient_end'] ?? $gradientEnd),
            'gradient_angle' => (int)($updated['gradient_angle'] ?? $gradientAngle),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Failed to save premium QR settings',
    ]);
}
