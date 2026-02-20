<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

header('Content-Type: application/json');

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);
$eventId = (int)($_POST['event_id'] ?? 0);

if ($djId <= 0 || $eventId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

$eventModel = new Event();
$event = $eventModel->findById($eventId);
if (!$event || (int)$event['user_id'] !== $djId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

if (!mdjr_user_has_premium($db, $djId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Premium plan required']);
    exit;
}

try {
    mdjr_ensure_premium_tables($db);
    $link = mdjr_get_or_create_premium_link($db, $event, $djId);

    $isEnabled = ((string)($_POST['is_enabled'] ?? '0') === '1');

    $fallbackPath = mdjr_clean_internal_path((string)($_POST['fallback_path'] ?? ''));
    $beforeLivePath = mdjr_clean_internal_path((string)($_POST['before_live_path'] ?? ''));
    $livePath = mdjr_clean_internal_path((string)($_POST['live_path'] ?? ''));
    $afterLivePath = mdjr_clean_internal_path((string)($_POST['after_live_path'] ?? ''));

    $defaultPath = '/r/' . urlencode((string)$event['uuid']);

    if ($fallbackPath === '') {
        $fallbackPath = $defaultPath;
    }
    if ($beforeLivePath === '') {
        $beforeLivePath = $fallbackPath;
    }
    if ($livePath === '') {
        $livePath = $fallbackPath;
    }
    if ($afterLivePath === '') {
        $afterLivePath = $fallbackPath;
    }

    mdjr_save_premium_link_settings($db, $eventId, $djId, [
        'is_enabled' => $isEnabled,
        'fallback_path' => $fallbackPath,
        'before_live_path' => $beforeLivePath,
        'live_path' => $livePath,
        'after_live_path' => $afterLivePath,
    ]);

    $updated = mdjr_get_or_create_premium_link($db, $event, $djId);
    $slug = (string)($updated['slug'] ?? '');

    echo json_encode([
        'ok' => true,
        'event_id' => $eventId,
        'is_enabled' => (int)($updated['is_enabled'] ?? 0),
        'slug' => $slug,
        'dynamic_url' => url('e/' . rawurlencode($slug)),
        'fallback_path' => $updated['fallback_path'] ?? $defaultPath,
        'before_live_path' => $updated['before_live_path'] ?? $defaultPath,
        'live_path' => $updated['live_path'] ?? $defaultPath,
        'after_live_path' => $updated['after_live_path'] ?? $defaultPath,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save premium event settings']);
}
