<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));
$guestToken = trim((string)($_GET['guest_token'] ?? ''));

if ($eventUuid === '' || $guestToken === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event or guest']);
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

$pollsPremiumEnabled = mdjr_get_user_plan($db, (int)$_SESSION['dj_id']) === 'premium';

$guestRows = [];
try {
    $q = $db->prepare("
      SELECT id, message AS body, created_at, 'guest' AS sender
      FROM messages
      WHERE event_id = :event_id
        AND guest_token = :guest_token
      ORDER BY created_at DESC
      LIMIT 200
    ");
    $q->execute([
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
    ]);
    $guestRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $guestRows = [];
}

$djRows = [];
try {
    $q = $db->prepare("
      SELECT id, message AS body, created_at, 'dj' AS sender
      FROM message_replies
      WHERE event_id = :event_id
        AND guest_token = :guest_token
      ORDER BY created_at DESC
      LIMIT 200
    ");
    $q->execute([
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
    ]);
    $djRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $djRows = [];
}

$broadcastRows = [];
try {
    $q = $db->prepare("
      SELECT id, message AS body, created_at, 'broadcast' AS sender
      FROM event_broadcast_messages
      WHERE event_id = :event_id
      ORDER BY created_at DESC
      LIMIT 200
    "
    );
    $q->execute([':event_id' => $eventId]);
    $broadcastRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $broadcastRows = [];
}

$pollRows = [];
if ($pollsPremiumEnabled) {
try {
    $pollStmt = $db->prepare("
      SELECT id, question, status, created_at
      FROM event_polls
      WHERE event_id = :event_id
      ORDER BY created_at DESC
      LIMIT 200
    ");
    $pollStmt->execute([':event_id' => $eventId]);
    $polls = $pollStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($polls as $poll) {
        $pollId = (int)$poll['id'];

        $optStmt = $db->prepare("
          SELECT
            o.id,
            o.option_text,
            o.sort_order,
            COUNT(v.id) AS vote_count
          FROM event_poll_options o
          LEFT JOIN event_poll_votes v
            ON v.option_id = o.id
           AND v.poll_id = o.poll_id
          WHERE o.poll_id = :poll_id
          GROUP BY o.id, o.option_text, o.sort_order
          ORDER BY o.sort_order ASC, o.id ASC
        ");
        $optStmt->execute([':poll_id' => $pollId]);
        $options = $optStmt->fetchAll(PDO::FETCH_ASSOC);

        $guestVoteStmt = $db->prepare("
          SELECT option_id
          FROM event_poll_votes
          WHERE poll_id = :poll_id
            AND guest_token = :guest_token
          LIMIT 1
        ");
        $guestVoteStmt->execute([
          ':poll_id' => $pollId,
          ':guest_token' => $guestToken,
        ]);
        $selectedOptionId = (int)($guestVoteStmt->fetchColumn() ?: 0);

        $normalizedOptions = [];
        foreach ($options as $opt) {
            $normalizedOptions[] = [
                'id' => (int)$opt['id'],
                'option_text' => (string)$opt['option_text'],
                'sort_order' => (int)$opt['sort_order'],
                'vote_count' => (int)$opt['vote_count'],
            ];
        }

        $pollRows[] = [
          'id' => $pollId,
          'body' => (string)$poll['question'],
          'created_at' => (string)$poll['created_at'],
          'sender' => 'poll',
          'selected_option_id' => $selectedOptionId,
          'options' => $normalizedOptions,
          'poll' => [
            'id' => $pollId,
            'question' => (string)$poll['question'],
            'status' => (string)$poll['status'],
            'selected_option_id' => $selectedOptionId,
            'options' => $normalizedOptions,
          ],
        ];
    }
} catch (Throwable $e) {
    $pollRows = [];
}
}

$rows = array_merge($guestRows, $djRows, $broadcastRows, $pollRows);

$djName = '';
try {
    $djStmt = $db->prepare("
      SELECT dj_name, name
      FROM users
      WHERE id = :id
      LIMIT 1
    ");
    $djStmt->execute([':id' => (int)$_SESSION['dj_id']]);
    $dj = $djStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $djName = (string)($dj['dj_name'] ?? '');
    if ($djName === '') {
        $djName = (string)($dj['name'] ?? '');
    }
} catch (Throwable $e) {
    $djName = '';
}
$eventName = trim((string)($event['title'] ?? ''));
foreach ($rows as &$row) {
    if (($row['sender'] ?? '') !== 'broadcast') {
        continue;
    }
    $body = (string)($row['body'] ?? '');
    $body = str_replace('{{DJ_NAME}}', $djName, $body);
    $body = str_replace('{{EVENT_NAME}}', $eventName, $body);
    $row['body'] = $body;
}
unset($row);

usort($rows, static function (array $a, array $b): int {
    $cmp = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
    if ($cmp !== 0) return $cmp;
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
