<?php
// api/dj/save_profile.php
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

require_dj_login();

function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function mdjr_detect_mime(string $tmpPath): string
{
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return '';
    }
    if (function_exists('finfo_open')) {
        $f = @finfo_open(FILEINFO_MIME_TYPE);
        if ($f) {
            $m = (string)@finfo_file($f, $tmpPath);
            @finfo_close($f);
            if ($m !== '') return strtolower($m);
        }
    }
    $meta = @getimagesize($tmpPath);
    if (is_array($meta) && !empty($meta['mime'])) {
        return strtolower((string)$meta['mime']);
    }
    return '';
}

function mdjr_safe_unlink_local_logo(string $logoUrl): void
{
    $logoUrl = trim($logoUrl);
    if ($logoUrl === '' || strpos($logoUrl, '/uploads/dj_profile/') !== 0) {
        return;
    }
    $file = APP_ROOT . '/' . ltrim($logoUrl, '/');
    if (is_file($file)) {
        @unlink($file);
    }
}

function mdjr_safe_unlink_local_gallery(string $imageUrl): void
{
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '' || strpos($imageUrl, '/uploads/dj_profile/') !== 0) {
        return;
    }
    $file = APP_ROOT . '/' . ltrim($imageUrl, '/');
    if (is_file($file)) {
        @unlink($file);
    }
}

function mdjr_ensure_profile_image_controls_columns(PDO $db): void
{
    $columns = [
        'logo_focus_x' => "ALTER TABLE dj_profiles ADD COLUMN logo_focus_x DECIMAL(5,2) NOT NULL DEFAULT 50.00",
        'logo_focus_y' => "ALTER TABLE dj_profiles ADD COLUMN logo_focus_y DECIMAL(5,2) NOT NULL DEFAULT 50.00",
        'logo_zoom_pct' => "ALTER TABLE dj_profiles ADD COLUMN logo_zoom_pct SMALLINT UNSIGNED NOT NULL DEFAULT 100",
        'show_logo_public_profile' => "ALTER TABLE dj_profiles ADD COLUMN show_logo_public_profile TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($columns as $col => $sql) {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'dj_profiles'
                  AND COLUMN_NAME = :col
            ");
            $stmt->execute(['col' => $col]);
            if ((int)$stmt->fetchColumn() === 0) {
                $db->exec($sql);
            }
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
}

function mdjr_normalize_gallery_url(string $raw): string
{
    $url = trim($raw);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '/uploads/') === 0) {
        return $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    return $url;
}

function mdjr_parse_gallery_items_from_post(): array
{
    $urls = $_POST['gallery_url'] ?? [];
    $captions = $_POST['gallery_caption'] ?? [];

    if (!is_array($urls)) {
        $urls = [];
    }
    if (!is_array($captions)) {
        $captions = [];
    }

    $items = [];
    $invalidCount = 0;
    $limit = min(count($urls), 50);
    for ($i = 0; $i < $limit; $i++) {
        $rawUrl = trim((string)($urls[$i] ?? ''));
        $url = mdjr_normalize_gallery_url($rawUrl);
        $caption = trim((string)($captions[$i] ?? ''));
        if ($rawUrl !== '' && $url === '') {
            $invalidCount++;
        }
        if ($url === '') {
            continue;
        }
        if (mb_strlen($caption) > 160) {
            $caption = mb_substr($caption, 0, 160);
        }
        $items[] = [
            'image_url' => $url,
            'caption' => $caption,
        ];
    }

    return [
        'items' => $items,
        'invalid_count' => $invalidCount,
    ];
}

function mdjr_parse_gallery_upload_items(array $files, int $djId): array
{
    $items = [];
    $errors = [];

    if (empty($files) || !array_key_exists('name', $files)) {
        return ['items' => [], 'errors' => []];
    }

    $names = $files['name'] ?? [];
    $tmpNames = $files['tmp_name'] ?? [];
    $sizes = $files['size'] ?? [];
    $uploadErrors = $files['error'] ?? [];

    if (!is_array($names) || !is_array($tmpNames) || !is_array($sizes) || !is_array($uploadErrors)) {
        return ['items' => [], 'errors' => ['Invalid gallery upload payload.']];
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    $dir = APP_ROOT . '/uploads/dj_profile/user_' . $djId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['items' => [], 'errors' => ['Failed to prepare gallery upload directory.']];
    }

    $total = min(count($names), 20);
    for ($i = 0; $i < $total; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $tmp = (string)($tmpNames[$i] ?? '');
        $size = (int)($sizes[$i] ?? 0);
        $err = (int)($uploadErrors[$i] ?? UPLOAD_ERR_NO_FILE);

        if ($err === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = 'One or more gallery images failed to upload.';
            continue;
        }
        if ($size > (2 * 1024 * 1024)) {
            $errors[] = 'Gallery images must be 2MB or smaller.';
            continue;
        }

        $mime = mdjr_detect_mime($tmp);
        if (!isset($allowed[$mime])) {
            $errors[] = 'Gallery images must be PNG, JPG, or WEBP.';
            continue;
        }

        $filename = 'gallery_' . $djId . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = 'Failed to save one or more gallery images.';
            continue;
        }

        $items[] = [
            'image_url' => '/uploads/dj_profile/user_' . $djId . '/' . $filename,
            'caption' => '',
        ];
    }

    return ['items' => $items, 'errors' => $errors];
}


try {

    $djId = (int)($_SESSION['dj_id'] ?? 0);
    $db = db();
    mdjr_ensure_profile_image_controls_columns($db);
    if ($djId <= 0) {
        echo json_encode(["success" => false, "message" => "Not logged in."]);
        exit;
    }

    $profileModel = new DjProfile();
    $profile = $profileModel->findByUserId($djId);

    if (!$profile) {
        echo json_encode(["success" => false, "message" => "Profile not found."]);
        exit;
    }

    // Collect POST data safely
    $fields = [
        'display_name',
        'public_email',
        'phone',
        'bio',
        'social_spotify',
        'social_instagram',
        'social_facebook',
        'social_tiktok',
        'social_youtube',
        'social_soundcloud',
        'city',
        'country',
        'logo_url',
        'logo_focus_x',
        'logo_focus_y',
        'logo_zoom_pct',
        'show_logo_public_profile',
        'social_website',
        'theme_color',
        'page_slug'
    ];

$data = [];
foreach ($fields as $f) {
    // Keep old DB value if form did not submit this field
    $data[$f] = array_key_exists($f, $_POST)
        ? ($_POST[$f] !== "" ? $_POST[$f] : null)
        : $profile[$f];
}

    // Clean + enforce rules
    // Ensure slug is a string, not null
$slug = slugify((string)($data['page_slug'] ?? ''));

if ($slug === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Public profile URL cannot be empty.'
    ]);
    exit;
}

// Check uniqueness BEFORE saving
$stmt = $db->prepare("
    SELECT id
    FROM dj_profiles
    WHERE page_slug = ?
      AND user_id != ?
    LIMIT 1
");
$stmt->execute([$slug, $djId]);

if ($stmt->fetch()) {
    echo json_encode([
        'success' => false,
        'message' => 'That profile URL is already taken.'
    ]);
    exit;
}

$data['page_slug'] = $slug;

$data['is_public'] = isset($_POST['is_public']) ? 1 : 0;

    $isPremiumPlan = mdjr_user_has_premium($db, $djId);
    $currentLogoUrl = trim((string)($profile['logo_url'] ?? ''));
    $defaultFocusX = isset($profile['logo_focus_x']) ? (float)$profile['logo_focus_x'] : 50.0;
    $defaultFocusY = isset($profile['logo_focus_y']) ? (float)$profile['logo_focus_y'] : 50.0;
    $defaultZoom = isset($profile['logo_zoom_pct']) ? (int)$profile['logo_zoom_pct'] : 100;

    if ($isPremiumPlan) {
        $data['logo_focus_x'] = max(0, min(100, (float)($data['logo_focus_x'] ?? $defaultFocusX)));
        $data['logo_focus_y'] = max(0, min(100, (float)($data['logo_focus_y'] ?? $defaultFocusY)));
        $data['logo_zoom_pct'] = max(100, min(220, (int)($data['logo_zoom_pct'] ?? $defaultZoom)));
        $data['show_logo_public_profile'] = isset($_POST['show_logo_public_profile']) ? 1 : 0;
    } else {
        // Keep existing values for non-premium accounts.
        $data['logo_focus_x'] = $defaultFocusX;
        $data['logo_focus_y'] = $defaultFocusY;
        $data['logo_zoom_pct'] = $defaultZoom;
        $data['show_logo_public_profile'] = isset($profile['show_logo_public_profile']) ? (int)$profile['show_logo_public_profile'] : 1;
    }
    if (!empty($_POST['remove_logo_image']) && $isPremiumPlan) {
        mdjr_safe_unlink_local_logo($currentLogoUrl);
        $data['logo_url'] = null;
        $currentLogoUrl = '';
    }

    if (!empty($_FILES['logo_file']['name'])) {
        if (!$isPremiumPlan) {
            echo json_encode([
                'success' => false,
                'message' => 'Image upload is available on Premium plan.'
            ]);
            exit;
        }
        $file = $_FILES['logo_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode([
                'success' => false,
                'message' => 'Image upload failed.'
            ]);
            exit;
        }
        if ((int)($file['size'] ?? 0) > (2 * 1024 * 1024)) {
            echo json_encode([
                'success' => false,
                'message' => 'Image is too large (max 2MB).'
            ]);
            exit;
        }

        $mime = mdjr_detect_mime((string)$file['tmp_name']);
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid image format. Use PNG, JPG, or WEBP.'
            ]);
            exit;
        }

        $dir = APP_ROOT . '/uploads/dj_profile/user_' . $djId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to prepare upload directory.'
            ]);
            exit;
        }

        $filename = 'contact_' . $djId . '_' . time() . '.' . $allowed[$mime];
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to save uploaded image.'
            ]);
            exit;
        }

        if ($currentLogoUrl !== '') {
            mdjr_safe_unlink_local_logo($currentLogoUrl);
        }
        $data['logo_url'] = '/uploads/dj_profile/user_' . $djId . '/' . $filename;
    }

    $galleryItems = [];
    $existingGallery = [];
    if ($isPremiumPlan) {
        $existingGallery = $profileModel->getGalleryByUserId($djId, false);
        $galleryParsed = mdjr_parse_gallery_items_from_post();
        $galleryItems = $galleryParsed['items'];
        if ((int)($galleryParsed['invalid_count'] ?? 0) > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'One or more gallery image URLs are invalid. Use https:// links.'
            ]);
            exit;
        }
        $uploadParsed = mdjr_parse_gallery_upload_items((array)($_FILES['gallery_files'] ?? []), $djId);
        if (!empty($uploadParsed['errors'])) {
            echo json_encode([
                'success' => false,
                'message' => (string)$uploadParsed['errors'][0]
            ]);
            exit;
        }
        $galleryItems = array_merge($galleryItems, $uploadParsed['items']);
        if (count($galleryItems) > 5) {
            echo json_encode([
                'success' => false,
                'message' => 'Gallery supports up to 5 images total (uploads + URLs).'
            ]);
            exit;
        }
    }

    // Save profile
    try {
    $ok = $profileModel->update($profile['id'], $data);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode([
            'success' => false,
            'message' => 'That profile URL is already taken.'
        ]);
        exit;
    }
    throw $e;
}

    if ($ok && $isPremiumPlan) {
        $profileModel->replaceGalleryForUser($djId, $galleryItems);

        $newUrls = [];
        foreach ($galleryItems as $item) {
            $url = trim((string)($item['image_url'] ?? ''));
            if ($url !== '') {
                $newUrls[$url] = true;
            }
        }
        foreach ($existingGallery as $oldItem) {
            $oldUrl = trim((string)($oldItem['image_url'] ?? ''));
            if ($oldUrl !== '' && !isset($newUrls[$oldUrl])) {
                mdjr_safe_unlink_local_gallery($oldUrl);
            }
        }
    }

    echo json_encode([
        "success" => (bool)$ok,
        "message" => $ok ? "Saved." : "Failed to save."
    ]);

} catch (Throwable $e) {

    // Prevent HTML errors breaking JSON output
    echo json_encode([
        "success" => false,
        "message" => "Server error.",
        "error" => $e->getMessage()
    ]);
}
