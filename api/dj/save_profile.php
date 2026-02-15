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


try {

    $djId = (int)($_SESSION['dj_id'] ?? 0);
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
$stmt = db()->prepare("
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