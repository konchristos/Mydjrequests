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

// Resolve UUID -> event_id with ownership check
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

try {
    $stmt = $db->prepare("
        SELECT
            m.id,
            patron_name,
            m.guest_token,
            m.message,
            m.created_at,
            COALESCE(gs.status, 'active') AS guest_status
        FROM messages m
        LEFT JOIN message_guest_states gs
          ON gs.event_id = m.event_id
         AND gs.guest_token = m.guest_token
        WHERE m.event_id = ?
        ORDER BY m.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$eventId]);
} catch (Throwable $e) {
    // Fallback before DB upgrade is applied.
    $stmt = $db->prepare("
        SELECT
            id,
            patron_name,
            guest_token,
            message,
            created_at,
            'active' AS guest_status
        FROM messages
        WHERE event_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$eventId]);
}

echo json_encode([
    'ok'   => true,
    'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
], JSON_UNESCAPED_UNICODE);
