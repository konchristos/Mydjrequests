<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$eventUuid = trim((string)($_POST['event_uuid'] ?? ''));
$guestToken = trim((string)($_POST['guest_token'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'active'));

if ($eventUuid === '' || $guestToken === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event or guest']);
    exit;
}

$allowed = ['active', 'muted', 'blocked'];
if (!in_array($status, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
    exit;
}

$db = db();

$stmt = $db->prepare("
    SELECT id
    FROM events
    WHERE uuid = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$eventUuid, (int)$_SESSION['dj_id']]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$eventId = (int)$event['id'];

if ($status === 'active') {
    try {
        $stmt = $db->prepare("
            DELETE FROM message_guest_states
            WHERE event_id = :event_id
              AND guest_token = :guest_token
        ");
        $stmt->execute([
            ':event_id' => $eventId,
            ':guest_token' => $guestToken,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Database upgrade required']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

try {
    $stmt = $db->prepare("
        INSERT INTO message_guest_states (event_id, guest_token, status, updated_by)
        VALUES (:event_id, :guest_token, :status, :updated_by)
        ON DUPLICATE KEY UPDATE
          status = VALUES(status),
          updated_by = VALUES(updated_by),
          updated_at = UTC_TIMESTAMP()
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
        ':status' => $status,
        ':updated_by' => (int)$_SESSION['dj_id'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Database upgrade required']);
    exit;
}

echo json_encode(['ok' => true]);
