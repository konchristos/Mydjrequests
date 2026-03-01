<?php
// dj/public_profile.php
require_once __DIR__ . '/../app/bootstrap.php';

$slug = trim($_GET['slug'] ?? '');

if ($slug === '') {
    http_response_code(404);
    require __DIR__ . '/views/profile_locked_private.php';
    exit;
}

// Fetch profile by slug
$stmt = db()->prepare("
    SELECT p.*, u.subscription_status
    FROM dj_profiles p
    JOIN users u ON u.id = p.user_id
    WHERE p.page_slug = ?
    LIMIT 1
");
$stmt->execute([$slug]);

$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    http_response_code(404);
    require __DIR__ . '/views/profile_locked_private.php';
    exit;
}

/* ---------------------------------------
   PREVIEW MODE (DJ OWNER ONLY)
---------------------------------------- */
$isPreview = (
    isset($_GET['preview']) &&
    $_GET['preview'] === '1' &&
    isset($_SESSION['dj_id']) &&
    (int)$_SESSION['dj_id'] === (int)$profile['user_id']
);


$intentPublic = null;

if ($isPreview && isset($_GET['intent_public'])) {
    $intentPublic = ($_GET['intent_public'] === '1');
}

/* ---------------------------------------
   ENFORCEMENT GATES (PUBLIC ONLY)
---------------------------------------- */
if (!$isPreview) {

    // Subscription gate
    $subscriptionActive = in_array(
        $profile['subscription_status'],
        ['trial', 'active'],
        true
    );

    if (!$subscriptionActive) {
        http_response_code(403);
        require __DIR__ . '/views/profile_locked_subscription.php';
        exit;
    }

    // Visibility gate
    if ((int)$profile['is_public'] !== 1) {
        http_response_code(403);
        require __DIR__ . '/views/profile_locked_private.php';
        exit;
    }
}

/* ---------------------------------------
   PREVIEW VISIBILITY STATE (VIEW CONTEXT)
---------------------------------------- */
$previewVisibilityState = null;

if ($isPreview) {
    // If intent was passed from edit page, trust it
    if (isset($_GET['intent_public'])) {
        $previewVisibilityState = ($_GET['intent_public'] === '1');
    } else {
        // Fallback to DB value
        $previewVisibilityState = ((int)$profile['is_public'] === 1);
    }
}

$isPremiumPlan = false;
$galleryItems = [];
try {
    $isPremiumPlan = mdjr_user_has_premium(db(), (int)$profile['user_id']);
} catch (Throwable $e) {
    $isPremiumPlan = false;
}

if ($isPremiumPlan) {
    try {
        $profileModel = new DjProfile();
        $galleryItems = $profileModel->getGalleryByUserId((int)$profile['user_id'], true);
    } catch (Throwable $e) {
        $galleryItems = [];
    }
}

/* ---------------------------------------
   PUBLIC / PREVIEW PROFILE
---------------------------------------- */
require __DIR__ . '/views/profile_public.php';

