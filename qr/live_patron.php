<?php
require_once __DIR__ . '/../app/bootstrap_public.php';

$db = db();

$djUuid = trim((string)($_GET['dj'] ?? ''));
if ($djUuid === '') {
    http_response_code(400);
    echo 'Missing DJ parameter.';
    exit;
}

$stmt = $db->prepare('
    SELECT id, dj_name, name
    FROM users
    WHERE uuid = ?
    LIMIT 1
');
$stmt->execute([$djUuid]);
$dj = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dj) {
    http_response_code(404);
    echo 'DJ not found.';
    exit;
}

$djId = (int)$dj['id'];
if (!mdjr_user_has_premium($db, $djId)) {
    http_response_code(403);
    echo 'Live patron dynamic link is available on Premium plan.';
    exit;
}

$stmt = $db->prepare('
    SELECT id, uuid
    FROM events
    WHERE user_id = ?
      AND event_state = "live"
    ORDER BY
      COALESCE(state_changed_at, created_at) DESC,
      id DESC
    LIMIT 1
');
$stmt->execute([$djId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    echo 'No live event right now. Please check back shortly.';
    exit;
}

mdjr_ensure_premium_tables($db);
$target = url('r/' . rawurlencode((string)$event['uuid']) . '?src=live_patron');
// Avoid browser-level sticky redirect caching by rendering a no-store bounce page
// instead of sending an HTTP Location redirect response.
$separator = (strpos($target, '?') === false) ? '?' : '&';
$target = $target . $separator . '_lr=' . time();

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <meta http-equiv="refresh" content="0;url=<?php echo e($target); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Redirecting…</title>
</head>
<body>
<script>
window.location.replace(<?php echo json_encode($target, JSON_UNESCAPED_SLASHES); ?>);
</script>
<noscript>
  <a href="<?php echo e($target); ?>">Continue</a>
</noscript>
</body>
</html>
<?php
exit;
