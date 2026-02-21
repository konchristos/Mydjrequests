<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));
$pollId = (int)($_GET['poll_id'] ?? 0);
if ($eventUuid === '' || $pollId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing event or poll']);
    exit;
}

$db = db();

$eventStmt = $db->prepare("
    SELECT id
    FROM events
    WHERE uuid = ?
      AND user_id = ?
    LIMIT 1
");
$eventStmt->execute([$eventUuid, (int)($_SESSION['dj_id'] ?? 0)]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
$eventId = (int)$event['id'];

if (mdjr_get_user_plan($db, (int)($_SESSION['dj_id'] ?? 0)) !== 'premium') {
    echo json_encode(['ok' => false, 'error' => 'Premium feature']);
    exit;
}

$pollStmt = $db->prepare("
    SELECT id, question, status, created_at
    FROM event_polls
    WHERE id = :poll_id
      AND event_id = :event_id
    LIMIT 1
");
$pollStmt->execute([
    ':poll_id' => $pollId,
    ':event_id' => $eventId,
]);
$poll = $pollStmt->fetch(PDO::FETCH_ASSOC);
if (!$poll) {
    echo json_encode(['ok' => false, 'error' => 'Poll not found']);
    exit;
}

function mdjr_table_has_column(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

$nameByToken = [];
$upsertName = static function (array &$nameMap, string $token, string $name, string $seenAtRaw): void {
    $token = trim($token);
    $name = trim($name);
    if ($token === '' || $name === '') {
        return;
    }
    $seenAt = $seenAtRaw !== '' ? (int)(strtotime($seenAtRaw) ?: 0) : 0;
    if (!isset($nameMap[$token]) || $seenAt >= (int)$nameMap[$token]['seen_at']) {
        $nameMap[$token] = [
            'name' => $name,
            'seen_at' => $seenAt,
        ];
    }
};

// Source-by-source resolution so one missing table/column never wipes all names.
$sources = [
    [
        'table' => 'song_requests',
        'required' => ['event_id', 'guest_token', 'requester_name', 'created_at'],
        'sql' => "SELECT guest_token, NULLIF(TRIM(requester_name), '') AS patron_name, created_at AS seen_at
                  FROM song_requests
                  WHERE event_id = :event_id
                    AND guest_token IS NOT NULL
                    AND guest_token <> ''",
    ],
    [
        'table' => 'song_votes',
        'required' => ['event_id', 'guest_token', 'patron_name', 'created_at'],
        'sql' => "SELECT guest_token, NULLIF(TRIM(patron_name), '') AS patron_name, created_at AS seen_at
                  FROM song_votes
                  WHERE event_id = :event_id
                    AND guest_token IS NOT NULL
                    AND guest_token <> ''",
    ],
    [
        'table' => 'messages',
        'required' => ['event_id', 'guest_token', 'patron_name', 'created_at'],
        'sql' => "SELECT guest_token, NULLIF(TRIM(patron_name), '') AS patron_name, created_at AS seen_at
                  FROM messages
                  WHERE event_id = :event_id
                    AND guest_token IS NOT NULL
                    AND guest_token <> ''",
    ],
    [
        'table' => 'event_moods',
        'required' => ['event_id', 'guest_token', 'patron_name', 'created_at'],
        'sql' => "SELECT guest_token, NULLIF(TRIM(patron_name), '') AS patron_name, created_at AS seen_at
                  FROM event_moods
                  WHERE event_id = :event_id
                    AND guest_token IS NOT NULL
                    AND guest_token <> ''",
    ],
    [
        'table' => 'event_tips',
        'required' => ['event_id', 'guest_token', 'patron_name', 'created_at'],
        'sql' => "SELECT guest_token, NULLIF(TRIM(patron_name), '') AS patron_name, created_at AS seen_at
                  FROM event_tips
                  WHERE event_id = :event_id
                    AND guest_token IS NOT NULL
                    AND guest_token <> ''",
    ],
    [
        'table' => 'event_track_boosts',
        'required' => ['event_id', 'guest_token', 'patron_name', 'created_at'],
        'sql' => "SELECT guest_token, NULLIF(TRIM(patron_name), '') AS patron_name, created_at AS seen_at
                  FROM event_track_boosts
                  WHERE event_id = :event_id
                    AND guest_token IS NOT NULL
                    AND guest_token <> ''",
    ],
    [
        'table' => 'event_poll_votes',
        'required' => ['event_id', 'guest_token', 'patron_name', 'created_at'],
        'sql' => "SELECT guest_token, NULLIF(TRIM(patron_name), '') AS patron_name, created_at AS seen_at
                  FROM event_poll_votes
                  WHERE event_id = :event_id
                    AND guest_token IS NOT NULL
                    AND guest_token <> ''",
    ],
    [
        'table' => 'event_page_views',
        'required' => ['event_id', 'guest_token', 'patron_name', 'last_seen_at'],
        'sql' => "SELECT guest_token, NULLIF(TRIM(patron_name), '') AS patron_name, last_seen_at AS seen_at
                  FROM event_page_views
                  WHERE event_id = :event_id
                    AND guest_token IS NOT NULL
                    AND guest_token <> ''",
    ],
];

foreach ($sources as $source) {
    $table = (string)$source['table'];
    $requiredCols = (array)$source['required'];
    $allColsPresent = true;
    foreach ($requiredCols as $col) {
        if (!mdjr_table_has_column($db, $table, (string)$col)) {
            $allColsPresent = false;
            break;
        }
    }
    if (!$allColsPresent) {
        continue;
    }

    try {
        $stmt = $db->prepare((string)$source['sql']);
        $stmt->execute([':event_id' => $eventId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $upsertName(
                $nameByToken,
                (string)($row['guest_token'] ?? ''),
                (string)($row['patron_name'] ?? ''),
                (string)($row['seen_at'] ?? '')
            );
        }
    } catch (Throwable $e) {
        // Skip broken source without affecting other sources.
        continue;
    }
}

$optionsStmt = $db->prepare("
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
$optionsStmt->execute([':poll_id' => $pollId]);
$optionsRows = $optionsStmt->fetchAll(PDO::FETCH_ASSOC);

$votesStmt = $db->prepare("
    SELECT
        option_id,
        guest_token,
        COALESCE(updated_at, created_at) AS voted_at
    FROM event_poll_votes
    WHERE poll_id = :poll_id
    ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
");
$votesStmt->execute([':poll_id' => $pollId]);
$votesRows = $votesStmt->fetchAll(PDO::FETCH_ASSOC);

$votesByOption = [];
foreach ($votesRows as $row) {
    $optionId = (int)($row['option_id'] ?? 0);
    $token = trim((string)($row['guest_token'] ?? ''));
    if ($optionId <= 0 || $token === '') {
        continue;
    }
    $votesByOption[$optionId][] = [
        'guest_token' => $token,
        'patron_name' => (string)($nameByToken[$token]['name'] ?? 'Guest'),
        'voted_at' => (string)($row['voted_at'] ?? ''),
    ];
}

$totalVotes = 0;
$options = [];
foreach ($optionsRows as $opt) {
    $optionId = (int)$opt['id'];
    $voteCount = (int)$opt['vote_count'];
    $totalVotes += $voteCount;
    $options[] = [
        'id' => $optionId,
        'option_text' => (string)$opt['option_text'],
        'sort_order' => (int)$opt['sort_order'],
        'vote_count' => $voteCount,
        'voters' => $votesByOption[$optionId] ?? [],
    ];
}

echo json_encode([
    'ok' => true,
    'poll' => [
        'id' => (int)$poll['id'],
        'question' => (string)$poll['question'],
        'status' => (string)$poll['status'],
        'created_at' => (string)$poll['created_at'],
        'total_votes' => $totalVotes,
        'options' => $options,
    ],
], JSON_UNESCAPED_UNICODE);
