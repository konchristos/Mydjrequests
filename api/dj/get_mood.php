<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$db = db();

$eventUuid = trim((string)($_GET['event'] ?? ''));
if ($eventUuid === '') {
  echo json_encode(['ok' => false, 'error' => 'Missing event']);
  exit;
}

// Resolve UUID → event_id with ownership check
$stmt = $db->prepare("
  SELECT id
  FROM events
  WHERE uuid = ?
    AND user_id = ?
  LIMIT 1
");
$stmt->execute([$eventUuid, (int)$_SESSION['dj_id']]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

$eventId = (int)$row['id'];

// Existing logic (unchanged)
$stmt = $db->prepare("
  SELECT
    SUM(mood = 1)  AS positive,
    SUM(mood = -1) AS negative,
    COUNT(*)       AS total
  FROM event_moods
  WHERE event_id = ?
");
$stmt->execute([$eventId]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$positive = (int)($row['positive'] ?? 0);
$negative = (int)($row['negative'] ?? 0);
$total    = $positive + $negative;
$percent  = $total ? round(($positive / $total) * 100) : 0;

echo json_encode([
  'ok'       => true,
  'positive' => $positive,
  'negative' => $negative,
  'total'    => $total,
  'percent'  => $percent
], JSON_UNESCAPED_UNICODE);