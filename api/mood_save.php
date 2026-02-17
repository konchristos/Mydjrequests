<?php
require_once __DIR__ . '/../app/bootstrap_public.php';
require_once __DIR__ . '/../app/config/database.php';

header('Content-Type: application/json');

// --------------------------------------------------
// Method check
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// --------------------------------------------------
// Read JSON body
// --------------------------------------------------
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$eventUuid = trim($data['event'] ?? '');
$mood      = (int)($data['mood'] ?? 0);

if ($eventUuid === '' || !in_array($mood, [1, -1], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

// --------------------------------------------------
// Ensure guest token
// --------------------------------------------------
$guestToken = $_COOKIE['mdjr_guest'] ?? '';
if ($guestToken === '') {
    $guestToken = bin2hex(random_bytes(16));
    setcookie('mdjr_guest', $guestToken, time() + 86400 * 30, '/', '', !empty($_SERVER['HTTPS']), true);
}

$db = db();

try {

    // --------------------------------------------------
    // Resolve event
    // --------------------------------------------------
    $stmt = $db->prepare("SELECT id FROM events WHERE uuid = ? LIMIT 1");
    $stmt->execute([$eventUuid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Event not found']);
        exit;
    }

    $eventId = (int)$event['id'];

    // --------------------------------------------------
    // Resolve patron name
    // --------------------------------------------------
    
    // song_requests.requester_name → patron_name (normalized)
    
    
    $patronName = trim($data['patron_name'] ?? '') ?: null;

    if ($patronName === null) {

        $stmt = $db->prepare("
            SELECT patron_name
            FROM messages
            WHERE event_id = ? AND guest_token = ?
              AND patron_name IS NOT NULL AND patron_name != ''
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$eventId, $guestToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row['patron_name'] ?? false) {
            $patronName = $row['patron_name'];
        } else {
            $stmt = $db->prepare("
            
                SELECT requester_name AS patron_name
                FROM song_requests
                WHERE event_id = ? AND guest_token = ?
                  AND requester_name IS NOT NULL AND requester_name != ''
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$eventId, $guestToken]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row['patron_name'] ?? false) {
                $patronName = $row['patron_name'];
            }
        }
    }

    // --------------------------------------------------
    // Upsert mood
    // --------------------------------------------------
    $stmt = $db->prepare("
        SELECT id FROM event_moods
        WHERE event_id = ? AND guest_token = ?
        LIMIT 1
    ");
    $stmt->execute([$eventId, $guestToken]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $db->prepare("
            UPDATE event_moods
            SET mood = ?, patron_name = COALESCE(?, patron_name)
            WHERE id = ?
        ");
        $stmt->execute([$mood, $patronName, $existing['id']]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO event_moods (event_id, guest_token, patron_name, mood)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$eventId, $guestToken, $patronName, $mood]);
    }

    // If guest provided a non-empty name, keep name in sync across this event+token.
    if ($patronName !== null && trim((string)$patronName) !== '') {
        $syncName = trim((string)$patronName);

        $stmt = $db->prepare("
            UPDATE messages
            SET patron_name = ?
            WHERE event_id = ?
              AND guest_token = ?
        ");
        $stmt->execute([$syncName, $eventId, $guestToken]);

        $stmt = $db->prepare("
            UPDATE song_requests
            SET requester_name = ?
            WHERE event_id = ?
              AND guest_token = ?
        ");
        $stmt->execute([$syncName, $eventId, $guestToken]);

        $stmt = $db->prepare("
            UPDATE song_votes
            SET patron_name = ?
            WHERE event_id = ?
              AND guest_token = ?
        ");
        $stmt->execute([$syncName, $eventId, $guestToken]);

        $stmt = $db->prepare("
            UPDATE event_moods
            SET patron_name = ?
            WHERE event_id = ?
              AND guest_token = ?
        ");
        $stmt->execute([$syncName, $eventId, $guestToken]);
    }

    // --------------------------------------------------
    // Success response
    // --------------------------------------------------
    echo json_encode([
        'ok'         => true,
        'guest_mood' => $mood,
        'patron'     => $patronName
    ]);
    exit;

} catch (PDOException $e) {
    error_log('mood_save PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save mood']);
    exit;
}
