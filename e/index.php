<?php
require_once __DIR__ . '/../app/bootstrap_public.php';

$db = db();
mdjr_ensure_premium_tables($db);

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
    http_response_code(404);
    exit('Link not found.');
}

$link = mdjr_get_premium_link_by_slug($db, $slug);
if (!$link) {
    http_response_code(404);
    exit('Link not found.');
}

$eventModel = new Event();
$event = $eventModel->findById((int)$link['event_id']);
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$ownerId = (int)$link['user_id'];
$ownerHasPremium = mdjr_user_has_premium($db, $ownerId);

$eventState = strtolower((string)($event['event_state'] ?? 'upcoming'));
if (!in_array($eventState, ['upcoming', 'live', 'ended'], true)) {
    $eventState = 'upcoming';
}

$defaultPath = '/r/' . urlencode((string)$event['uuid']);
$fallbackPath = mdjr_clean_internal_path((string)($link['fallback_path'] ?? '')) ?: $defaultPath;

$targetPath = $fallbackPath;
if ($ownerHasPremium && (int)($link['is_enabled'] ?? 0) === 1) {
    if ($eventState === 'live') {
        $targetPath = mdjr_clean_internal_path((string)($link['live_path'] ?? '')) ?: $fallbackPath;
    } elseif ($eventState === 'ended') {
        $targetPath = mdjr_clean_internal_path((string)($link['after_live_path'] ?? '')) ?: $fallbackPath;
    } else {
        $targetPath = mdjr_clean_internal_path((string)($link['before_live_path'] ?? '')) ?: $fallbackPath;
    }
}

$qs = $_GET;
unset($qs['slug']);

$source = trim((string)($qs['src'] ?? 'direct'));
$source = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $source) ?? 'direct';
if ($source === '') {
    $source = 'direct';
}

$targetUrl = url(ltrim($targetPath, '/'));
if (!empty($qs)) {
    $joiner = (strpos($targetUrl, '?') === false) ? '?' : '&';
    $targetUrl .= $joiner . http_build_query($qs);
}

$visitorToken = $_COOKIE['mdjr_link_guest'] ?? '';
if ($visitorToken === '') {
    $visitorToken = bin2hex(random_bytes(16));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('mdjr_link_guest', $visitorToken, time() + 86400 * 365, '/', '', $secure, true);
}

$visitorHash = hash('sha256', $visitorToken . '|' . (string)($link['event_id'] ?? 0));

try {
    mdjr_log_premium_hit($db, [
        'event_id' => (int)$link['event_id'],
        'user_id' => $ownerId,
        'slug' => $slug,
        'source' => $source,
        'event_state' => $eventState,
        'redirect_target' => $targetPath,
        'visitor_hash' => $visitorHash,
        'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'referer' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
        'query_string' => (string)($_SERVER['QUERY_STRING'] ?? ''),
    ]);
} catch (Throwable $e) {
    // Do not block redirect if analytics logging fails.
}

header('Cache-Control: no-store, max-age=0');
header('Location: ' . $targetUrl, true, 302);
exit;
