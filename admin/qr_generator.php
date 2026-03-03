<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'QR Generator';
$pageBodyClass = 'admin-page';

$db = db();
$error = '';
$success = '';

function adminEnsureSettingsTable(PDO $db): void
{
    $db->exec("\n        CREATE TABLE IF NOT EXISTS app_settings (\n          `key` VARCHAR(100) PRIMARY KEY,\n          `value` VARCHAR(255) NOT NULL,\n          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n        )\n    ");
}

function adminGetSetting(PDO $db, string $key, string $default): string
{
    $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : (string)$val;
}

function adminSetSetting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare("\n        INSERT INTO app_settings (`key`, `value`)\n        VALUES (?, ?)\n        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)\n    ");
    $stmt->execute([$key, $value]);
}

function normalizeHexColor(string $value, string $fallback): string
{
    $value = strtoupper(trim($value));
    if ($value === '') {
        return $fallback;
    }
    if ($value[0] !== '#') {
        $value = '#' . $value;
    }
    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
}

function normalizeStyleChoice(string $value, string $fallback = 'square'): string
{
    $value = strtolower(trim($value));
    $allowed = ['square', 'rounded', 'circle', 'extra-rounded'];
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function decodePresetPayload(string $json): ?array
{
    if ($json === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function buildAdminQrPngUrl(string $url, int $size, string $fgHex, string $bgHex): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $fg = ltrim($fgHex, '#');
    $bg = ltrim($bgHex, '#');
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&ecc=H&qzone=1'
        . '&color=' . rawurlencode($fg)
        . '&bgcolor=' . rawurlencode($bg)
        . '&data=' . rawurlencode($url);
}

function adminQRSafeImageExtension(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }
    return 'png';
}

function adminQRDetectMimeType(string $path): string
{
    if ($path === '' || !is_file($path)) {
        return '';
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return strtolower(trim($mime));
        }
    }

    if (class_exists('finfo')) {
        $finfo = @new finfo(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @$finfo->file($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower(trim($mime));
            }
        }
    }

    if (function_exists('exif_imagetype')) {
        $imgType = @exif_imagetype($path);
        if ($imgType !== false) {
            $map = [
                IMAGETYPE_PNG => 'image/png',
                IMAGETYPE_JPEG => 'image/jpeg',
                IMAGETYPE_WEBP => 'image/webp',
            ];
            if (isset($map[$imgType])) {
                return $map[$imgType];
            }
        }
    }

    if (function_exists('getimagesize')) {
        $info = @getimagesize($path);
        if (is_array($info) && !empty($info['mime'])) {
            return strtolower(trim((string)$info['mime']));
        }
    }

    return '';
}

adminEnsureSettingsTable($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_custom_qr_slot') {
            $slot = max(1, min(5, (int)($_POST['slot'] ?? 1)));
            $url = trim((string)($_POST['custom_qr_url'] ?? ''));

            if ($url === '') {
                $error = 'Custom QR URL cannot be empty.';
            } elseif (!preg_match('#^https?://#i', $url)) {
                $error = 'URL must start with http:// or https://';
            } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
                $error = 'Enter a valid URL.';
            } else {
                adminSetSetting($db, 'admin_custom_qr_url_' . $slot, $url);
                adminSetSetting($db, 'admin_custom_qr_active_slot', (string)$slot);
                $success = "Saved custom QR URL to slot {$slot}.";
            }
        }

        if ($action === 'use_custom_qr_slot') {
            $slot = max(1, min(5, (int)($_POST['slot'] ?? 1)));
            $slotUrl = trim(adminGetSetting($db, 'admin_custom_qr_url_' . $slot, ''));
            if ($slotUrl === '') {
                $error = "Slot {$slot} is empty.";
            } else {
                adminSetSetting($db, 'admin_custom_qr_active_slot', (string)$slot);
                $success = "Loaded slot {$slot}.";
            }
        }

        if ($action === 'delete_custom_qr_slot') {
            $slot = max(1, min(5, (int)($_POST['slot'] ?? 1)));
            adminSetSetting($db, 'admin_custom_qr_url_' . $slot, '');
            $success = "Cleared slot {$slot}.";

            $activeSlot = max(1, min(5, (int)adminGetSetting($db, 'admin_custom_qr_active_slot', '1')));
            if ($activeSlot === $slot) {
                $fallback = 1;
                for ($i = 1; $i <= 5; $i++) {
                    $v = trim(adminGetSetting($db, 'admin_custom_qr_url_' . $i, ''));
                    if ($v !== '') {
                        $fallback = $i;
                        break;
                    }
                }
                adminSetSetting($db, 'admin_custom_qr_active_slot', (string)$fallback);
            }
        }

        if ($action === 'save_custom_qr_style') {
            $size = max(220, min(1400, (int)($_POST['custom_qr_size'] ?? 420)));
            $fgHex = normalizeHexColor((string)($_POST['custom_qr_fg'] ?? '#000000'), '#000000');
            $bgHex = normalizeHexColor((string)($_POST['custom_qr_bg'] ?? '#FFFFFF'), '#FFFFFF');
            $dotStyle = normalizeStyleChoice((string)($_POST['custom_qr_dot_style'] ?? 'square'));
            $eyeOuterStyle = normalizeStyleChoice((string)($_POST['custom_qr_eye_outer_style'] ?? 'square'));
            $eyeInnerStyle = normalizeStyleChoice((string)($_POST['custom_qr_eye_inner_style'] ?? 'square'));
            $fillMode = strtolower(trim((string)($_POST['custom_qr_fill_mode'] ?? 'solid')));
            if (!in_array($fillMode, ['solid', 'linear', 'radial'], true)) {
                $fillMode = 'solid';
            }
            $gradientStart = normalizeHexColor((string)($_POST['custom_qr_gradient_start'] ?? '#000000'), '#000000');
            $gradientEnd = normalizeHexColor((string)($_POST['custom_qr_gradient_end'] ?? '#FF2FD2'), '#FF2FD2');
            $gradientAngle = max(0, min(360, (int)($_POST['custom_qr_gradient_angle'] ?? 45)));

            adminSetSetting($db, 'admin_custom_qr_size', (string)$size);
            adminSetSetting($db, 'admin_custom_qr_fg', $fgHex);
            adminSetSetting($db, 'admin_custom_qr_bg', $bgHex);
            adminSetSetting($db, 'admin_custom_qr_dot_style', $dotStyle);
            adminSetSetting($db, 'admin_custom_qr_eye_outer_style', $eyeOuterStyle);
            adminSetSetting($db, 'admin_custom_qr_eye_inner_style', $eyeInnerStyle);
            adminSetSetting($db, 'admin_custom_qr_fill_mode', $fillMode);
            adminSetSetting($db, 'admin_custom_qr_gradient_start', $gradientStart);
            adminSetSetting($db, 'admin_custom_qr_gradient_end', $gradientEnd);
            adminSetSetting($db, 'admin_custom_qr_gradient_angle', (string)$gradientAngle);

            $success = 'QR style saved.';
        }

        if ($action === 'save_custom_qr_preset_slot') {
            $slot = max(1, min(3, (int)($_POST['preset_slot'] ?? ($_POST['slot'] ?? 1))));
            $payload = [
                'size' => max(220, min(1400, (int)($_POST['custom_qr_size'] ?? 420))),
                'fg' => normalizeHexColor((string)($_POST['custom_qr_fg'] ?? '#000000'), '#000000'),
                'bg' => normalizeHexColor((string)($_POST['custom_qr_bg'] ?? '#FFFFFF'), '#FFFFFF'),
                'dot_style' => normalizeStyleChoice((string)($_POST['custom_qr_dot_style'] ?? 'square')),
                'eye_outer_style' => normalizeStyleChoice((string)($_POST['custom_qr_eye_outer_style'] ?? 'square')),
                'eye_inner_style' => normalizeStyleChoice((string)($_POST['custom_qr_eye_inner_style'] ?? 'square')),
                'fill_mode' => in_array(strtolower(trim((string)($_POST['custom_qr_fill_mode'] ?? 'solid'))), ['solid', 'linear', 'radial'], true)
                    ? strtolower(trim((string)($_POST['custom_qr_fill_mode'] ?? 'solid')))
                    : 'solid',
                'gradient_start' => normalizeHexColor((string)($_POST['custom_qr_gradient_start'] ?? '#000000'), '#000000'),
                'gradient_end' => normalizeHexColor((string)($_POST['custom_qr_gradient_end'] ?? '#FF2FD2'), '#FF2FD2'),
                'gradient_angle' => max(0, min(360, (int)($_POST['custom_qr_gradient_angle'] ?? 45))),
            ];
            adminSetSetting($db, 'admin_custom_qr_preset_slot_' . $slot, json_encode($payload, JSON_UNESCAPED_SLASHES));
            $success = "Preset saved to Slot {$slot}.";
        }

        if ($action === 'load_custom_qr_preset_slot') {
            $slot = max(1, min(3, (int)($_POST['preset_slot'] ?? ($_POST['slot'] ?? 1))));
            $payload = decodePresetPayload(adminGetSetting($db, 'admin_custom_qr_preset_slot_' . $slot, ''));
            if (!$payload) {
                $error = "Preset Slot {$slot} is empty.";
            } else {
                adminSetSetting($db, 'admin_custom_qr_size', (string)max(220, min(1400, (int)($payload['size'] ?? 420))));
                adminSetSetting($db, 'admin_custom_qr_fg', normalizeHexColor((string)($payload['fg'] ?? '#000000'), '#000000'));
                adminSetSetting($db, 'admin_custom_qr_bg', normalizeHexColor((string)($payload['bg'] ?? '#FFFFFF'), '#FFFFFF'));
                adminSetSetting($db, 'admin_custom_qr_dot_style', normalizeStyleChoice((string)($payload['dot_style'] ?? 'square')));
                adminSetSetting($db, 'admin_custom_qr_eye_outer_style', normalizeStyleChoice((string)($payload['eye_outer_style'] ?? 'square')));
                adminSetSetting($db, 'admin_custom_qr_eye_inner_style', normalizeStyleChoice((string)($payload['eye_inner_style'] ?? 'square')));
                adminSetSetting($db, 'admin_custom_qr_fill_mode', in_array((string)($payload['fill_mode'] ?? 'solid'), ['solid', 'linear', 'radial'], true) ? (string)$payload['fill_mode'] : 'solid');
                adminSetSetting($db, 'admin_custom_qr_gradient_start', normalizeHexColor((string)($payload['gradient_start'] ?? '#000000'), '#000000'));
                adminSetSetting($db, 'admin_custom_qr_gradient_end', normalizeHexColor((string)($payload['gradient_end'] ?? '#FF2FD2'), '#FF2FD2'));
                adminSetSetting($db, 'admin_custom_qr_gradient_angle', (string)max(0, min(360, (int)($payload['gradient_angle'] ?? 45))));
                $success = "Preset loaded from Slot {$slot}.";
            }
        }

        if ($action === 'upload_custom_qr_logo') {
            $file = $_FILES['custom_qr_logo'] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Select a logo image to upload.';
            } else {
                $tmpPath = (string)($file['tmp_name'] ?? '');
                $size = (int)($file['size'] ?? 0);
                $mime = adminQRDetectMimeType($tmpPath);
                $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
                if ($size <= 0 || $size > (3 * 1024 * 1024)) {
                    $error = 'Logo must be up to 3MB.';
                } elseif (!in_array($mime, $allowedMimes, true)) {
                    $error = 'Logo must be PNG, JPG, or WEBP.';
                } else {
                    $uploadDir = APP_ROOT . '/uploads/admin_qr_logos';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }
                    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                        $error = 'Upload folder is not writable.';
                    } else {
                        $ext = adminQRSafeImageExtension((string)($file['name'] ?? 'logo.png'));
                        $filename = 'admin_qr_logo_' . date('Ymd_His') . '_' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $ext;
                        $destPath = $uploadDir . '/' . $filename;
                        if (!@move_uploaded_file($tmpPath, $destPath)) {
                            $error = 'Could not save uploaded logo.';
                        } else {
                            $oldLogo = trim(adminGetSetting($db, 'admin_custom_qr_logo_path', ''));
                            adminSetSetting($db, 'admin_custom_qr_logo_path', '/uploads/admin_qr_logos/' . $filename);
                            if ($oldLogo !== '' && strpos($oldLogo, '/uploads/admin_qr_logos/') === 0) {
                                $oldAbs = APP_ROOT . $oldLogo;
                                if (is_file($oldAbs)) {
                                    @unlink($oldAbs);
                                }
                            }
                            $success = 'Logo uploaded.';
                        }
                    }
                }
            }
        }

        if ($action === 'remove_custom_qr_logo') {
            $oldLogo = trim(adminGetSetting($db, 'admin_custom_qr_logo_path', ''));
            adminSetSetting($db, 'admin_custom_qr_logo_path', '');
            if ($oldLogo !== '' && strpos($oldLogo, '/uploads/admin_qr_logos/') === 0) {
                $oldAbs = APP_ROOT . $oldLogo;
                if (is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }
            $success = 'Logo removed.';
        }
    }
}

$customQrSlots = [];
for ($i = 1; $i <= 5; $i++) {
    $customQrSlots[$i] = trim(adminGetSetting($db, 'admin_custom_qr_url_' . $i, ''));
}
$customQrActiveSlot = max(1, min(5, (int)adminGetSetting($db, 'admin_custom_qr_active_slot', '1')));
$customQrActiveUrl = (string)($customQrSlots[$customQrActiveSlot] ?? '');
$customQrSize = max(220, min(1400, (int)adminGetSetting($db, 'admin_custom_qr_size', '420')));
$customQrFg = normalizeHexColor(adminGetSetting($db, 'admin_custom_qr_fg', '#000000'), '#000000');
$customQrBg = normalizeHexColor(adminGetSetting($db, 'admin_custom_qr_bg', '#FFFFFF'), '#FFFFFF');
$customQrDotStyle = normalizeStyleChoice(adminGetSetting($db, 'admin_custom_qr_dot_style', 'square'));
$customQrEyeOuterStyle = normalizeStyleChoice(adminGetSetting($db, 'admin_custom_qr_eye_outer_style', 'square'));
$customQrEyeInnerStyle = normalizeStyleChoice(adminGetSetting($db, 'admin_custom_qr_eye_inner_style', 'square'));
$customQrFillMode = strtolower(trim(adminGetSetting($db, 'admin_custom_qr_fill_mode', 'solid')));
if (!in_array($customQrFillMode, ['solid', 'linear', 'radial'], true)) {
    $customQrFillMode = 'solid';
}
$customQrGradientStart = normalizeHexColor(adminGetSetting($db, 'admin_custom_qr_gradient_start', '#000000'), '#000000');
$customQrGradientEnd = normalizeHexColor(adminGetSetting($db, 'admin_custom_qr_gradient_end', '#FF2FD2'), '#FF2FD2');
$customQrGradientAngle = max(0, min(360, (int)adminGetSetting($db, 'admin_custom_qr_gradient_angle', '45')));
$customQrLogoPath = trim(adminGetSetting($db, 'admin_custom_qr_logo_path', ''));
$customQrLogoUrl = '';
if ($customQrLogoPath !== '' && strpos($customQrLogoPath, '/uploads/admin_qr_logos/') === 0) {
    $absLogo = APP_ROOT . $customQrLogoPath;
    if (is_file($absLogo)) {
        $customQrLogoUrl = url(ltrim($customQrLogoPath, '/'));
    }
}
$customQrPngUrl = buildAdminQrPngUrl($customQrActiveUrl, $customQrSize, $customQrFg, $customQrBg);

include APP_ROOT . '/dj/layout.php';
?>

<style>
.admin-section { margin-top: 20px; }
.admin-section h2 { margin: 0 0 12px; font-size: 22px; }
.admin-section-copy { color:#b8b8c8; margin: 0 0 12px; font-size:14px; }
.error { color:#ff8080; margin: 8px 0 12px; }
.success { color:#7be87f; margin: 8px 0 12px; }
.admin-toggle-row { display:flex; gap:10px; margin-top:10px; flex-wrap:wrap; align-items:center; }
.admin-toggle-btn { background:#ff2fd2; color:#fff; border:none; border-radius:8px; padding:10px 12px; cursor:pointer; font-weight:700; }
.admin-toggle-btn.secondary { background:#25253a; }
.admin-qr-wrap { display:grid; gap:14px; grid-template-columns: minmax(280px, 1fr) 300px; align-items:start; }
.admin-qr-input { width:100%; background:#0e0e14; color:#fff; border:1px solid #2a2a3f; border-radius:8px; padding:10px 12px; }
.admin-qr-color { width:100%; height:42px; background:#0e0e14; border:1px solid #2a2a3f; border-radius:8px; padding:4px; }
.admin-qr-preview { width:280px; height:280px; border-radius:10px; border:1px solid #2a2a3f; background:#111; display:flex; align-items:center; justify-content:center; overflow:hidden; margin-left:auto; }
.admin-qr-preview img { width:100%; height:100%; object-fit:contain; background:#fff; }
.admin-slot-grid { display:grid; gap:8px; margin-top:10px; }
.admin-slot-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.admin-slot-label { min-width:58px; font-weight:700; color:#b8b8c8; }
.admin-slot-url { color:#9da4b8; font-size:12px; max-width:360px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.admin-mini-btn { background:#25253a; color:#fff; border:none; border-radius:7px; padding:7px 9px; cursor:pointer; font-size:12px; font-weight:700; }
.admin-mini-btn.danger { background:#4a1f2a; }
.admin-style-grid { display:grid; gap:10px; grid-template-columns:repeat(3,minmax(140px,1fr)); }
.admin-presets { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
.admin-note { color:#aab0be; font-size:12px; margin-top:8px; }
.admin-tab-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.admin-tab-btn { background:#2a2a3a; color:#fff; border:none; border-radius:12px; padding:9px 14px; font-weight:700; cursor:pointer; }
.admin-tab-btn.active { background:#ff2fd2; color:#fff; }
.admin-tab-pane { display:none; }
.admin-tab-pane.active { display:block; }
@media (max-width: 980px) {
    .admin-qr-wrap { grid-template-columns: 1fr; }
    .admin-qr-preview { margin-left:0; }
    .admin-style-grid { grid-template-columns: 1fr; }
}
</style>

<div class="admin-wrap">
    <h1>QR Generator</h1>
    <p class="admin-section-copy"><a href="/admin/dashboard.php" style="color:#9dd5ff;">&larr; Back to Dashboard</a></p>

    <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?php echo e($success); ?></div><?php endif; ?>

    <div class="admin-section">
        <h2>Custom URL QR Builder</h2>
        <p class="admin-section-copy">Generate QR from any URL, save up to 5 links, and control style colors/size.</p>

        <div class="admin-card" style="cursor:default;">
            <div class="admin-qr-wrap">
                <div>
                    <div class="admin-tab-row" id="qrTabs">
                        <button type="button" class="admin-tab-btn active" data-tab="style">Style</button>
                        <button type="button" class="admin-tab-btn" data-tab="colour">Colour</button>
                        <button type="button" class="admin-tab-btn" data-tab="brand">Brand</button>
                    </div>

                    <form method="POST" id="saveQrSlotForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_custom_qr_slot">
                        <div class="admin-toggle-row">
                            <label for="customQrUrlInput" style="min-width:70px;">URL</label>
                            <input class="admin-qr-input" id="customQrUrlInput" type="url" name="custom_qr_url" placeholder="https://example.com/your-link" value="<?php echo e($customQrActiveUrl); ?>" required>
                        </div>
                        <div class="admin-toggle-row">
                            <label for="customQrSlot" style="min-width:70px;">Save Slot</label>
                            <select id="customQrSlot" name="slot" class="admin-qr-input" style="max-width:140px;">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $customQrActiveSlot === $i ? 'selected' : ''; ?>>Slot <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                            <button type="submit" class="admin-toggle-btn">Save URL</button>
                        </div>
                    </form>

                    <form method="POST" id="saveQrStyleForm" style="margin-top:12px;" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>

                        <div class="admin-tab-pane active" data-pane="style">
                            <div class="admin-style-grid">
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Image Size (px)</span>
                                    <input id="customQrSizeInput" class="admin-qr-input" type="number" min="220" max="1400" name="custom_qr_size" value="<?php echo (int)$customQrSize; ?>">
                                </label>
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Dot Style</span>
                                    <select id="customQrDotStyleInput" class="admin-qr-input" name="custom_qr_dot_style">
                                        <option value="square" <?php echo $customQrDotStyle === 'square' ? 'selected' : ''; ?>>Square</option>
                                        <option value="rounded" <?php echo $customQrDotStyle === 'rounded' ? 'selected' : ''; ?>>Rounded</option>
                                        <option value="circle" <?php echo $customQrDotStyle === 'circle' ? 'selected' : ''; ?>>Circle</option>
                                        <option value="extra-rounded" <?php echo $customQrDotStyle === 'extra-rounded' ? 'selected' : ''; ?>>Extra Rounded</option>
                                    </select>
                                </label>
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Eye Outer Style</span>
                                    <select id="customQrEyeOuterStyleInput" class="admin-qr-input" name="custom_qr_eye_outer_style">
                                        <option value="square" <?php echo $customQrEyeOuterStyle === 'square' ? 'selected' : ''; ?>>Square</option>
                                        <option value="rounded" <?php echo $customQrEyeOuterStyle === 'rounded' ? 'selected' : ''; ?>>Rounded</option>
                                        <option value="circle" <?php echo $customQrEyeOuterStyle === 'circle' ? 'selected' : ''; ?>>Circle</option>
                                        <option value="extra-rounded" <?php echo $customQrEyeOuterStyle === 'extra-rounded' ? 'selected' : ''; ?>>Extra Rounded</option>
                                    </select>
                                </label>
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Eye Inner Style</span>
                                    <select id="customQrEyeInnerStyleInput" class="admin-qr-input" name="custom_qr_eye_inner_style">
                                        <option value="square" <?php echo $customQrEyeInnerStyle === 'square' ? 'selected' : ''; ?>>Square</option>
                                        <option value="rounded" <?php echo $customQrEyeInnerStyle === 'rounded' ? 'selected' : ''; ?>>Rounded</option>
                                        <option value="circle" <?php echo $customQrEyeInnerStyle === 'circle' ? 'selected' : ''; ?>>Circle</option>
                                        <option value="extra-rounded" <?php echo $customQrEyeInnerStyle === 'extra-rounded' ? 'selected' : ''; ?>>Extra Rounded</option>
                                    </select>
                                </label>
                            </div>
                            <div class="admin-presets">
                                <button type="button" class="admin-mini-btn" data-fg="#000000" data-bg="#FFFFFF" data-dot="square" data-eyeo="square" data-eyei="square">Minimal</button>
                                <button type="button" class="admin-mini-btn" data-fg="#FF2FD2" data-bg="#111111" data-dot="rounded" data-eyeo="rounded" data-eyei="rounded">Neon</button>
                                <button type="button" class="admin-mini-btn" data-fg="#FFFFFF" data-bg="#1A1A2E" data-dot="circle" data-eyeo="rounded" data-eyei="circle">Monochrome</button>
                                <button type="button" class="admin-mini-btn" data-fg="#0C1028" data-bg="#8CEBFF" data-dot="extra-rounded" data-eyeo="rounded" data-eyei="rounded">Festival</button>
                            </div>
                            <div class="admin-toggle-row">
                                <label for="qrPresetSlotInput" style="min-width:115px;">Saved Presets</label>
                                <select id="qrPresetSlotInput" class="admin-qr-input" name="preset_slot" style="max-width:140px;">
                                    <option value="1">Slot 1</option>
                                    <option value="2">Slot 2</option>
                                    <option value="3">Slot 3</option>
                                </select>
                                <button type="submit" class="admin-toggle-btn secondary" name="action" value="save_custom_qr_preset_slot">Save Slot</button>
                                <button type="submit" class="admin-toggle-btn secondary" name="action" value="load_custom_qr_preset_slot">Load Slot</button>
                            </div>
                            <div class="admin-note">Presets store style/settings values only. Generated QR images are not stored.</div>
                        </div>

                        <div class="admin-tab-pane" data-pane="colour">
                            <div class="admin-style-grid">
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Fill Mode</span>
                                    <select id="customQrFillModeInput" class="admin-qr-input" name="custom_qr_fill_mode">
                                        <option value="solid" <?php echo $customQrFillMode === 'solid' ? 'selected' : ''; ?>>Solid</option>
                                        <option value="linear" <?php echo $customQrFillMode === 'linear' ? 'selected' : ''; ?>>Linear Gradient</option>
                                        <option value="radial" <?php echo $customQrFillMode === 'radial' ? 'selected' : ''; ?>>Radial Gradient</option>
                                    </select>
                                </label>
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Foreground</span>
                                    <input id="customQrFgInput" class="admin-qr-color" type="color" name="custom_qr_fg" value="<?php echo e($customQrFg); ?>">
                                </label>
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Background</span>
                                    <input id="customQrBgInput" class="admin-qr-color" type="color" name="custom_qr_bg" value="<?php echo e($customQrBg); ?>">
                                </label>
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Gradient Start</span>
                                    <input id="customQrGradientStartInput" class="admin-qr-color" type="color" name="custom_qr_gradient_start" value="<?php echo e($customQrGradientStart); ?>">
                                </label>
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Gradient End</span>
                                    <input id="customQrGradientEndInput" class="admin-qr-color" type="color" name="custom_qr_gradient_end" value="<?php echo e($customQrGradientEnd); ?>">
                                </label>
                                <label>
                                    <span style="display:block;margin-bottom:6px;color:#b8b8c8;font-size:12px;">Gradient Angle (Linear)</span>
                                    <input id="customQrGradientAngleInput" class="admin-qr-input" type="number" min="0" max="360" name="custom_qr_gradient_angle" value="<?php echo (int)$customQrGradientAngle; ?>">
                                </label>
                            </div>
                        </div>

                        <div class="admin-tab-pane" data-pane="brand">
                            <div class="admin-toggle-row" style="margin-top:0;">
                                <label for="customQrLogoInput" style="min-width:70px;">Logo</label>
                                <input id="customQrLogoInput" class="admin-qr-input" type="file" name="custom_qr_logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                                <button type="submit" class="admin-toggle-btn secondary" name="action" value="upload_custom_qr_logo">Upload Logo</button>
                                <button type="submit" class="admin-toggle-btn secondary" name="action" value="remove_custom_qr_logo" <?php echo $customQrLogoUrl === '' ? 'disabled' : ''; ?>>Remove Logo</button>
                            </div>
                            <div class="admin-note">Recommended square logo, transparent PNG, up to 3MB.</div>
                            <?php if ($customQrLogoUrl !== ''): ?>
                                <div class="admin-toggle-row" style="margin-top:8px;">
                                    <span style="color:#b8b8c8;font-size:12px;">Current:</span>
                                    <img src="<?php echo e($customQrLogoUrl); ?>" alt="Current QR logo" style="width:40px;height:40px;object-fit:contain;background:#fff;border-radius:6px;border:1px solid #2a2a3f;">
                                </div>
                            <?php endif; ?>
                            <div class="admin-toggle-row" style="margin-top:0;">
                                <label for="customQrPngUrlInput" style="min-width:70px;">QR PNG</label>
                                <input class="admin-qr-input" id="customQrPngUrlInput" type="text" readonly value="<?php echo e($customQrPngUrl); ?>">
                                <button type="button" class="admin-toggle-btn secondary" id="copyCustomQrPngBtn">Copy</button>
                                <a href="<?php echo e($customQrPngUrl !== '' ? $customQrPngUrl : '#'); ?>" id="downloadCustomQrBtn" class="admin-toggle-btn secondary" download="mydjrequests-qr.png">Download PNG</a>
                                <button type="button" class="admin-toggle-btn secondary" id="downloadCustomQrSvgBtn">Download SVG</button>
                                <button type="button" class="admin-toggle-btn secondary" id="copyCustomQrEmbedBtn">Copy Embed</button>
                            </div>
                        </div>

                        <div class="admin-toggle-row">
                            <button type="submit" class="admin-toggle-btn" name="action" value="save_custom_qr_style">Save Style</button>
                            <span class="admin-note">Style options are saved and reused.</span>
                        </div>
                    </form>

                    <div class="admin-slot-grid">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php $slotUrl = (string)($customQrSlots[$i] ?? ''); ?>
                            <div class="admin-slot-row">
                                <span class="admin-slot-label">Slot <?php echo $i; ?></span>
                                <span class="admin-slot-url"><?php echo $slotUrl !== '' ? e($slotUrl) : 'Empty'; ?></span>
                                <?php if ($slotUrl !== ''): ?>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="use_custom_qr_slot">
                                        <input type="hidden" name="slot" value="<?php echo $i; ?>">
                                        <button type="submit" class="admin-mini-btn">Use</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Clear Slot <?php echo $i; ?>?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_custom_qr_slot">
                                        <input type="hidden" name="slot" value="<?php echo $i; ?>">
                                        <button type="submit" class="admin-mini-btn danger">Clear</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div>
                    <div class="admin-qr-preview" id="customQrPreview">
                        <?php if ($customQrPngUrl !== ''): ?>
                            <img src="<?php echo e($customQrPngUrl); ?>" alt="Custom QR preview">
                        <?php else: ?>
                            <span style="color:#8d93a8;font-size:12px;">No URL yet</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
(function () {
    const initialLogoUrl = <?php echo json_encode($customQrLogoUrl, JSON_UNESCAPED_SLASHES); ?>;
    const tabButtons = Array.from(document.querySelectorAll('#qrTabs .admin-tab-btn'));
    const tabPanes = Array.from(document.querySelectorAll('.admin-tab-pane'));
    const urlInput = document.getElementById('customQrUrlInput');
    const sizeInput = document.getElementById('customQrSizeInput');
    const fgInput = document.getElementById('customQrFgInput');
    const bgInput = document.getElementById('customQrBgInput');
    const fillModeInput = document.getElementById('customQrFillModeInput');
    const gradientStartInput = document.getElementById('customQrGradientStartInput');
    const gradientEndInput = document.getElementById('customQrGradientEndInput');
    const gradientAngleInput = document.getElementById('customQrGradientAngleInput');
    const dotInput = document.getElementById('customQrDotStyleInput');
    const eyeOuterInput = document.getElementById('customQrEyeOuterStyleInput');
    const eyeInnerInput = document.getElementById('customQrEyeInnerStyleInput');
    const pngInput = document.getElementById('customQrPngUrlInput');
    const preview = document.getElementById('customQrPreview');
    const copyBtn = document.getElementById('copyCustomQrPngBtn');
    const openBtn = document.getElementById('downloadCustomQrBtn');
    const downloadSvgBtn = document.getElementById('downloadCustomQrSvgBtn');
    const copyEmbedBtn = document.getElementById('copyCustomQrEmbedBtn');
    let styledPngDataUrl = '';
    let styledSvgContent = '';
    let lastRenderSize = 420;

    function normalizeHex(hex, fallback) {
        const clean = (hex || '').trim().toUpperCase();
        if (!/^#[0-9A-F]{6}$/.test(clean)) return fallback;
        return clean;
    }

    function buildPngUrl(url, size, fgHex, bgHex) {
        const clean = (url || '').trim();
        if (!/^https?:\/\//i.test(clean)) return '';

        const px = clampPx(size);
        lastRenderSize = px;

        const fg = normalizeHex(fgHex, '#000000').slice(1);
        const bg = normalizeHex(bgHex, '#FFFFFF').slice(1);

        return 'https://api.qrserver.com/v1/create-qr-code/?size=' + px + 'x' + px
            + '&ecc=H&qzone=1'
            + '&color=' + encodeURIComponent(fg)
            + '&bgcolor=' + encodeURIComponent(bg)
            + '&data=' + encodeURIComponent(clean);
    }

    function clampPx(size) {
        let px = parseInt(size || '420', 10);
        if (Number.isNaN(px)) px = 420;
        return Math.max(220, Math.min(1400, px));
    }

    function escapeXml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function addRoundedRectPath(ctx, x, y, w, h, r) {
        const radius = Math.max(0, Math.min(r, Math.min(w, h) / 2));
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.lineTo(x + w - radius, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
        ctx.lineTo(x + w, y + h - radius);
        ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
        ctx.lineTo(x + radius, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
        ctx.lineTo(x, y + radius);
        ctx.quadraticCurveTo(x, y, x + radius, y);
        ctx.closePath();
    }

    function drawShape(ctx, x, y, size, style) {
        const s = (style || 'square').toLowerCase();
        if (s === 'circle') {
            ctx.beginPath();
            ctx.arc(x + (size / 2), y + (size / 2), size * 0.5, 0, Math.PI * 2);
            ctx.fill();
            return;
        }
        if (s === 'rounded') {
            addRoundedRectPath(ctx, x, y, size, size, size * 0.22);
            ctx.fill();
            return;
        }
        if (s === 'extra-rounded') {
            addRoundedRectPath(ctx, x, y, size, size, size * 0.38);
            ctx.fill();
            return;
        }
        ctx.fillRect(x, y, size, size);
    }

    function isFinderCell(row, col, modules) {
        const inTopLeft = row >= 0 && row <= 6 && col >= 0 && col <= 6;
        const inTopRight = row >= 0 && row <= 6 && col >= (modules - 7) && col <= (modules - 1);
        const inBottomLeft = row >= (modules - 7) && row <= (modules - 1) && col >= 0 && col <= 6;
        return inTopLeft || inTopRight || inBottomLeft;
    }

    function svgShape(style, x, y, size, fill) {
        const s = (style || 'square').toLowerCase();
        if (s === 'circle') {
            const r = size * 0.5;
            return '<circle cx="' + (x + r) + '" cy="' + (y + r) + '" r="' + r + '" fill="' + fill + '"/>';
        }
        if (s === 'rounded') {
            const rx = size * 0.22;
            return '<rect x="' + x + '" y="' + y + '" width="' + size + '" height="' + size + '" rx="' + rx + '" ry="' + rx + '" fill="' + fill + '"/>';
        }
        if (s === 'extra-rounded') {
            const rx = size * 0.38;
            return '<rect x="' + x + '" y="' + y + '" width="' + size + '" height="' + size + '" rx="' + rx + '" ry="' + rx + '" fill="' + fill + '"/>';
        }
        return '<rect x="' + x + '" y="' + y + '" width="' + size + '" height="' + size + '" fill="' + fill + '"/>';
    }

    function buildStyledSvg(data, containerPx, modules, modulePx, codeX, codeY, dotStyle, eyeOuterStyle, eyeInnerStyle, mode, fg, bg, gs, ge, ga, logoUrl) {
        if (typeof window.qrcode !== 'function') return '';
        const qr = window.qrcode(0, 'H');
        qr.addData(data);
        qr.make();

        let defs = '';
        let fillRef = fg;
        if (mode === 'linear') {
            defs += '<linearGradient id="gMain" x1="0%" y1="0%" x2="100%" y2="100%" gradientTransform="rotate(' + ga + ' ' + (containerPx / 2) + ' ' + (containerPx / 2) + ')">';
            defs += '<stop offset="0%" stop-color="' + gs + '"/><stop offset="100%" stop-color="' + ge + '"/></linearGradient>';
            fillRef = 'url(#gMain)';
        } else if (mode === 'radial') {
            defs += '<radialGradient id="gMain" cx="50%" cy="50%" r="72%">';
            defs += '<stop offset="0%" stop-color="' + gs + '"/><stop offset="100%" stop-color="' + ge + '"/></radialGradient>';
            fillRef = 'url(#gMain)';
        }

        let body = '';
        for (let r = 0; r < modules; r++) {
            for (let c = 0; c < modules; c++) {
                if (!qr.isDark(r, c)) continue;
                if (isFinderCell(r, c, modules)) continue;
                const x = codeX + (c * modulePx);
                const y = codeY + (r * modulePx);
                body += svgShape(dotStyle, x, y, modulePx, fillRef);
            }
        }

        const finders = [
            [0, 0],
            [modules - 7, 0],
            [0, modules - 7]
        ];
        for (const [col0, row0] of finders) {
            const x = codeX + (col0 * modulePx);
            const y = codeY + (row0 * modulePx);
            body += svgShape(eyeOuterStyle, x, y, modulePx * 7, fillRef);
            body += svgShape(eyeOuterStyle, x + modulePx, y + modulePx, modulePx * 5, bg);
            body += svgShape(eyeInnerStyle, x + (modulePx * 2), y + (modulePx * 2), modulePx * 3, fillRef);
        }

        if (logoUrl) {
            const maxLogo = Math.floor(containerPx * 0.18);
            const x = Math.floor((containerPx - maxLogo) / 2);
            const y = Math.floor((containerPx - maxLogo) / 2);
            body += '<rect x="' + (x - 6) + '" y="' + (y - 6) + '" width="' + (maxLogo + 12) + '" height="' + (maxLogo + 12) + '" rx="8" ry="8" fill="#FFFFFF"/>';
            body += '<image x="' + x + '" y="' + y + '" width="' + maxLogo + '" height="' + maxLogo + '" preserveAspectRatio="xMidYMid meet" href="' + escapeXml(logoUrl) + '"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + containerPx + '" height="' + containerPx + '" viewBox="0 0 ' + containerPx + ' ' + containerPx + '">' +
            (defs ? '<defs>' + defs + '</defs>' : '') +
            '<rect x="0" y="0" width="' + containerPx + '" height="' + containerPx + '" fill="' + bg + '"/>' +
            body +
            '</svg>';
    }

    function drawLogoOnCanvas(canvas, logoUrl, done) {
        if (!logoUrl) {
            done();
            return;
        }
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            done();
            return;
        }
        const img = new Image();
        img.onload = function () {
            const cw = canvas.width;
            const ch = canvas.height;
            const maxLogo = Math.floor(Math.min(cw, ch) * 0.18);
            const ratio = Math.min(maxLogo / img.width, maxLogo / img.height);
            const w = Math.max(1, Math.floor(img.width * ratio));
            const h = Math.max(1, Math.floor(img.height * ratio));
            const x = Math.floor((cw - w) / 2);
            const y = Math.floor((ch - h) / 2);
            const pad = 6;
            ctx.fillStyle = '#FFFFFF';
            addRoundedRectPath(ctx, x - pad, y - pad, w + (pad * 2), h + (pad * 2), 8);
            ctx.fill();
            ctx.drawImage(img, x, y, w, h);
            done();
        };
        img.onerror = function () { done(); };
        img.src = logoUrl;
    }

    function renderStyledPreview(url, size, fgHex, bgHex, dotStyle, eyeOuterStyle, eyeInnerStyle, fillMode, gradientStart, gradientEnd, gradientAngle, logoUrl) {
        const clean = (url || '').trim();
        if (!clean || !preview || typeof window.qrcode !== 'function') return false;

        let px = parseInt(size || '420', 10);
        if (Number.isNaN(px)) px = 420;
        px = Math.max(220, Math.min(1400, px));

        const containerPx = Math.min(280, Math.max(180, Math.round(px * 0.67)));
        const qr = window.qrcode(0, 'H');
        qr.addData(clean);
        qr.make();

        const modules = qr.getModuleCount();
        if (!modules) return false;

        const quiet = 1;
        const totalUnits = modules + (quiet * 2);
        const modulePx = Math.max(1, Math.floor(containerPx / totalUnits));
        const drawSize = modulePx * totalUnits;
        const offX = Math.floor((containerPx - drawSize) / 2);
        const offY = Math.floor((containerPx - drawSize) / 2);
        const codeX = offX + (quiet * modulePx);
        const codeY = offY + (quiet * modulePx);

        const canvas = document.createElement('canvas');
        canvas.width = containerPx;
        canvas.height = containerPx;
        const ctx = canvas.getContext('2d');
        if (!ctx) return false;

        const fg = normalizeHex(fgHex, '#000000');
        const bg = normalizeHex(bgHex, '#FFFFFF');
        const gs = normalizeHex(gradientStart || '#000000', '#000000');
        const ge = normalizeHex(gradientEnd || '#FF2FD2', '#FF2FD2');
        const gAngle = (parseInt(gradientAngle || '45', 10) || 45);
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, containerPx, containerPx);

        const mode = (fillMode || 'solid').toLowerCase();
        if (mode === 'solid') {
            ctx.fillStyle = fg;
        } else if (mode === 'radial') {
            const cx = containerPx / 2;
            const cy = containerPx / 2;
            const r = containerPx * 0.72;
            const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, r);
            grad.addColorStop(0, gs);
            grad.addColorStop(1, ge);
            ctx.fillStyle = grad;
        } else {
            const angle = (gAngle * Math.PI) / 180;
            const cx = containerPx / 2;
            const cy = containerPx / 2;
            const halfDiag = Math.sqrt(2 * containerPx * containerPx) / 2;
            const x1 = cx - Math.cos(angle) * halfDiag;
            const y1 = cy - Math.sin(angle) * halfDiag;
            const x2 = cx + Math.cos(angle) * halfDiag;
            const y2 = cy + Math.sin(angle) * halfDiag;
            const grad = ctx.createLinearGradient(x1, y1, x2, y2);
            grad.addColorStop(0, gs);
            grad.addColorStop(1, ge);
            ctx.fillStyle = grad;
        }

        for (let r = 0; r < modules; r++) {
            for (let c = 0; c < modules; c++) {
                if (!qr.isDark(r, c)) continue;
                if (isFinderCell(r, c, modules)) continue;
                const x = codeX + (c * modulePx);
                const y = codeY + (r * modulePx);
                drawShape(ctx, x, y, modulePx, dotStyle);
            }
        }

        const finders = [
            [0, 0],
            [modules - 7, 0],
            [0, modules - 7]
        ];
        for (const [col0, row0] of finders) {
            const x = codeX + (col0 * modulePx);
            const y = codeY + (row0 * modulePx);
            drawShape(ctx, x, y, modulePx * 7, eyeOuterStyle);
            ctx.fillStyle = bg;
            drawShape(ctx, x + modulePx, y + modulePx, modulePx * 5, eyeOuterStyle);
            if (mode === 'solid') {
                ctx.fillStyle = fg;
            } else if (mode === 'radial') {
                const cx = containerPx / 2;
                const cy = containerPx / 2;
                const rr = containerPx * 0.72;
                const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, rr);
                grad.addColorStop(0, gs);
                grad.addColorStop(1, ge);
                ctx.fillStyle = grad;
            } else {
                const angle = (gAngle * Math.PI) / 180;
                const cx = containerPx / 2;
                const cy = containerPx / 2;
                const halfDiag = Math.sqrt(2 * containerPx * containerPx) / 2;
                const x1 = cx - Math.cos(angle) * halfDiag;
                const y1 = cy - Math.sin(angle) * halfDiag;
                const x2 = cx + Math.cos(angle) * halfDiag;
                const y2 = cy + Math.sin(angle) * halfDiag;
                const grad = ctx.createLinearGradient(x1, y1, x2, y2);
                grad.addColorStop(0, gs);
                grad.addColorStop(1, ge);
                ctx.fillStyle = grad;
            }
            drawShape(ctx, x + (modulePx * 2), y + (modulePx * 2), modulePx * 3, eyeInnerStyle);
        }
        styledSvgContent = buildStyledSvg(
            clean,
            containerPx,
            modules,
            modulePx,
            codeX,
            codeY,
            dotStyle,
            eyeOuterStyle,
            eyeInnerStyle,
            mode,
            fg,
            bg,
            gs,
            ge,
            gAngle,
            logoUrl
        );
        drawLogoOnCanvas(canvas, logoUrl, function () {
            styledPngDataUrl = canvas.toDataURL('image/png');
            preview.innerHTML = '';
            preview.appendChild(canvas);
            pngInput.value = styledPngDataUrl;
            if (openBtn) {
                openBtn.href = styledPngDataUrl;
                openBtn.style.pointerEvents = 'auto';
                openBtn.style.opacity = '1';
            }
        });
        return true;
    }

    function renderPreview() {
        if (!urlInput || !sizeInput || !fgInput || !bgInput || !pngInput || !preview) return;
        const fallbackPng = buildPngUrl(urlInput.value, sizeInput.value, fgInput.value, bgInput.value);
        pngInput.value = fallbackPng;
        if (openBtn) {
            openBtn.href = fallbackPng || '#';
            openBtn.style.pointerEvents = fallbackPng ? 'auto' : 'none';
            openBtn.style.opacity = fallbackPng ? '1' : '0.65';
        }
        if (!fallbackPng) {
            styledPngDataUrl = '';
            styledSvgContent = '';
            preview.innerHTML = '<span style="color:#8d93a8;font-size:12px;">No URL yet</span>';
            return;
        }

        const styledRendered = renderStyledPreview(
            urlInput.value,
            sizeInput.value,
            fgInput.value,
            bgInput.value,
            dotInput?.value || 'square',
            eyeOuterInput?.value || 'square',
            eyeInnerInput?.value || 'square',
            fillModeInput?.value || 'solid',
            gradientStartInput?.value || '#000000',
            gradientEndInput?.value || '#FF2FD2',
            gradientAngleInput?.value || '45',
            initialLogoUrl
        );

        if (!styledRendered) {
            styledPngDataUrl = '';
            styledSvgContent = '';
            preview.innerHTML = '<img src="' + fallbackPng + '" alt="Custom QR preview">';
        } else {
            pngInput.value = styledPngDataUrl;
            if (openBtn) {
                openBtn.href = styledPngDataUrl || fallbackPng;
                openBtn.style.pointerEvents = 'auto';
                openBtn.style.opacity = '1';
            }
        }
    }

    tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const tab = btn.getAttribute('data-tab');
            tabButtons.forEach((b) => b.classList.toggle('active', b === btn));
            tabPanes.forEach((pane) => pane.classList.toggle('active', pane.getAttribute('data-pane') === tab));
        });
    });

    [urlInput, sizeInput, fgInput, bgInput, fillModeInput, gradientStartInput, gradientEndInput, gradientAngleInput, dotInput, eyeOuterInput, eyeInnerInput].forEach((el) => {
        el?.addEventListener('input', renderPreview);
        el?.addEventListener('change', renderPreview);
    });

    document.querySelectorAll('.admin-presets [data-fg][data-bg]').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!fgInput || !bgInput || !dotInput || !eyeOuterInput || !eyeInnerInput) return;
            fgInput.value = btn.getAttribute('data-fg') || '#000000';
            bgInput.value = btn.getAttribute('data-bg') || '#FFFFFF';
            dotInput.value = btn.getAttribute('data-dot') || dotInput.value || 'square';
            eyeOuterInput.value = btn.getAttribute('data-eyeo') || eyeOuterInput.value || 'square';
            eyeInnerInput.value = btn.getAttribute('data-eyei') || eyeInnerInput.value || 'square';
            if (fillModeInput) fillModeInput.value = 'solid';
            if (gradientStartInput) gradientStartInput.value = fgInput.value;
            if (gradientEndInput) gradientEndInput.value = '#FF2FD2';
            if (gradientAngleInput) gradientAngleInput.value = '45';
            renderPreview();
        });
    });

    copyBtn?.addEventListener('click', async () => {
        if (!pngInput || !pngInput.value) return;
        try {
            await navigator.clipboard.writeText(pngInput.value);
            copyBtn.textContent = 'Copied';
            setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1200);
        } catch (e) {}
    });

    downloadSvgBtn?.addEventListener('click', () => {
        if (!styledSvgContent) return;
        const blob = new Blob([styledSvgContent], { type: 'image/svg+xml;charset=utf-8' });
        const href = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = href;
        a.download = 'mydjrequests-qr.svg';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(href), 1200);
    });

    copyEmbedBtn?.addEventListener('click', async () => {
        const src = styledPngDataUrl || (pngInput?.value || '');
        if (!src) return;
        const size = clampPx(String(lastRenderSize || sizeInput?.value || 420));
        const snippet = '<img src="' + src + '" alt="QR Code" width="' + size + '" height="' + size + '" loading="lazy">';
        try {
            await navigator.clipboard.writeText(snippet);
            copyEmbedBtn.textContent = 'Copied';
            setTimeout(() => { copyEmbedBtn.textContent = 'Copy Embed'; }, 1200);
        } catch (e) {}
    });

    renderPreview();
})();
</script>
