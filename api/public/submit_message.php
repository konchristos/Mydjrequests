<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/bootstrap_public.php';

// -------------------------------------------------
// Validate method
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

// -------------------------------------------------
// Inputs
// -------------------------------------------------
$eventUuid   = trim($_POST['event_uuid'] ?? '');
$patronName  = trim($_POST['patron_name'] ?? '');
$messageText = trim($_POST['message'] ?? '');

// Stable guest identity (cookie)
$guestToken  = $_COOKIE['mdjr_guest'] ?? null;

// -------------------------------------------------
// Validation
// -------------------------------------------------
if ($eventUuid === '') {
    echo json_encode(['success' => false, 'message' => 'Missing event']);
    exit;
}

if ($messageText === '') {
    echo json_encode(['success' => false, 'message' => 'Message is required']);
    exit;
}

// -------------------------------------------------
// Load event (UUID → ID)
// -------------------------------------------------
$eventModel = new Event();
$event = $eventModel->findByUuid($eventUuid);

if (!$event) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

$pdo = db();

// Blocked guests cannot send new private messages for this event.
if ($guestToken) {
    try {
        $stateStmt = $pdo->prepare("
            SELECT status
            FROM message_guest_states
            WHERE event_id = :event_id
              AND guest_token = :guest_token
            LIMIT 1
        ");
        $stateStmt->execute([
            ':event_id' => (int)$event['id'],
            ':guest_token' => $guestToken
        ]);
        $state = $stateStmt->fetch(PDO::FETCH_ASSOC);
        if (($state['status'] ?? 'active') === 'blocked') {
            echo json_encode([
                'success' => false,
                'message' => 'You cannot send messages for this event.'
            ]);
            exit;
        }
    } catch (Throwable $e) {
        // Keep backwards compatibility when table is not present.
    }
}

// -------------------------------------------------
// Insert message
// -------------------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO messages (
        uuid,
        event_id,
        event_uuid,
        guest_token,
        patron_name,
        message,
        ip_address,
        user_agent
    ) VALUES (
        UUID(),
        :event_id,
        :event_uuid,
        :guest_token,
        :patron_name,
        :message,
        :ip,
        :ua
    )
");

$ok = $stmt->execute([
    ':event_id'    => $event['id'],
    ':event_uuid'  => $eventUuid,
    ':guest_token' => $guestToken,
    ':patron_name' => $patronName ?: null,
    ':message'     => $messageText,
    ':ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
    ':ua'          => $_SERVER['HTTP_USER_AGENT'] ?? null
]);

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'DB insert failed']);
    exit;
}

echo json_encode(['success' => true]);
exit;
