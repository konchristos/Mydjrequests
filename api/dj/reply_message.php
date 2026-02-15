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
$message = trim((string)($_POST['message'] ?? ''));

if ($eventUuid === '' || $guestToken === '' || $message === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
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

try {
    $stmt = $db->prepare("
        SELECT status
        FROM message_guest_states
        WHERE event_id = :event_id
          AND guest_token = :guest_token
        LIMIT 1
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':guest_token' => $guestToken
    ]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    if (($state['status'] ?? 'active') === 'blocked') {
        echo json_encode(['ok' => false, 'error' => 'Guest is blocked']);
        exit;
    }
} catch (Throwable $e) {
    // message_guest_states is optional for reply flow.
}

try {
    $stmt = $db->prepare("
        INSERT INTO message_replies (
          event_id,
          guest_token,
          dj_id,
          message,
          created_at
        ) VALUES (
          :event_id,
          :guest_token,
          :dj_id,
          :message,
          UTC_TIMESTAMP()
        )
    ");
    $stmt->execute([
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
        ':dj_id' => (int)$_SESSION['dj_id'],
        ':message' => $message,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Database upgrade required']);
    exit;
}

echo json_encode(['ok' => true]);
