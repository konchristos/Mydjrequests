<?php
// /api/dj/request-detail.php
require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$db = db();

$requestId = (int)($_GET['id'] ?? 0);
$eventId   = (int)($_GET['event_id'] ?? 0);

if ($requestId <= 0 || $eventId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id or event_id']);
    exit;
}

try {

    // --------------------------------------------------
    // 1) Load selected request (must belong to event)
    // --------------------------------------------------
    $stmt = $db->prepare("
        SELECT
            id,
            event_id,
            guest_token,
            song_title AS track_title,
            artist,
            status,
            created_at,
            requester_name
        FROM song_requests
        WHERE id = ? AND event_id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId, $eventId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Request not found']);
        exit;
    }

    $guestToken = (string)$req['guest_token'];

    // --------------------------------------------------
    // 2) Resolve display name (priority-based)
    // --------------------------------------------------
    $displayName = trim((string)($req['requester_name'] ?? ''));

    if ($displayName === '') {
        // fallback: messages.patron_name
        $stmt = $db->prepare("
            SELECT patron_name
            FROM messages
            WHERE event_id = ?
              AND guest_token = ?
              AND patron_name IS NOT NULL
              AND patron_name != ''
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$eventId, $guestToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $displayName = trim((string)($row['patron_name'] ?? ''));
    }

    if ($displayName === '') {
        // fallback: event_moods.patron_name
        $stmt = $db->prepare("
            SELECT patron_name
            FROM event_moods
            WHERE event_id = ?
              AND guest_token = ?
              AND patron_name IS NOT NULL
              AND patron_name != ''
            ORDER BY updated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$eventId, $guestToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $displayName = trim((string)($row['patron_name'] ?? ''));
    }

    if ($displayName === '') {
        $displayName = 'Anonymous';
    }

    // --------------------------------------------------
    // 3) Other requests by same guest (same event)
    // --------------------------------------------------
    $stmt = $db->prepare("
        SELECT
            id,
            song_title AS track_title,
            artist,
            status,
            created_at,
            requester_name
        FROM song_requests
        WHERE event_id = ?
          AND guest_token = ?
        ORDER BY created_at ASC
        LIMIT 50
    ");
    $stmt->execute([$eventId, $guestToken]);
    $others = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --------------------------------------------------
    // 4) Sanitize response (never expose guest_token)
    // --------------------------------------------------
    $requestSafe = [
        'id'          => (int)$req['id'],
        'track_title' => $req['track_title'],
        'artist'      => $req['artist'],
        'status'      => $req['status'],
        'created_at'  => $req['created_at'],
    ];

    $otherSafe = array_map(function ($r) {
        return [
            'id'          => (int)$r['id'],
            'track_title' => $r['track_title'],
            'artist'      => $r['artist'],
            'status'      => $r['status'],
            'created_at'  => $r['created_at'],
        ];
    }, $others);

    echo json_encode([
        'ok'             => true,
        'request'        => $requestSafe,
        'display_name'   => $displayName,
        'other_requests' => $otherSafe,
    ]);
    exit;

} catch (PDOException $e) {
  error_log('request-detail PDO error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server error']);
  exit;
}