<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);
$djTimezone = 'UTC';
try {
    $tzStmt = $db->prepare("SELECT timezone FROM users WHERE id = ? LIMIT 1");
    $tzStmt->execute([$djId]);
    $tzValue = trim((string)($tzStmt->fetchColumn() ?: ''));
    if ($tzValue !== '') {
        $djTimezone = $tzValue;
    }
} catch (Throwable $e) {
    $djTimezone = 'UTC';
}

$view = trim((string)($_GET['view'] ?? 'performance'));
$allowedViews = ['performance', 'top_songs', 'revenue', 'top_activity', 'message_transcript'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'performance';
}

$viewTitles = [
    'performance' => 'Event Performance Summary',
    'top_songs' => 'Top Requested Songs',
    'revenue' => 'Tips/Boost Revenue Summary',
    'top_activity' => 'Top Activity Report',
    'message_transcript' => 'Message Transcript',
];
$activeTitle = $viewTitles[$view];

$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$selectedEventId = (int)($_GET['event_id'] ?? 0);
$selectedGuestToken = trim((string)($_GET['guest_token'] ?? ''));
$displayMode = trim((string)($_GET['display_mode'] ?? 'table'));
if (!in_array($displayMode, ['table', 'chat'], true)) {
    $displayMode = 'table';
}

function mdjr_format_local_datetime(?string $utcTs, string $timezone): string
{
    if (!$utcTs) {
        return '';
    }
    try {
        $dt = new DateTime($utcTs, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return (string)$utcTs;
    }
}

if ($dateFrom === '') {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}
if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$rangeNotice = '';
$maxRangeDays = 183;
try {
    $fromDateObj = new DateTimeImmutable($dateFrom);
    $toDateObj = new DateTimeImmutable($dateTo);
    $rangeDays = (int)$fromDateObj->diff($toDateObj)->format('%a');
    if ($rangeDays > $maxRangeDays) {
        $dateFrom = $toDateObj->sub(new DateInterval('P' . $maxRangeDays . 'D'))->format('Y-m-d');
        $rangeNotice = 'Date range capped to 6 months (183 days).';
    }
} catch (Throwable $e) {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo = date('Y-m-d');
}

$fromTs = $dateFrom . ' 00:00:00';
$toTs = $dateTo . ' 23:59:59';
$today = date('Y-m-d');

$eventsStmt = $db->prepare("
    SELECT id, title, event_date
    FROM events
    WHERE user_id = ?
      AND COALESCE(event_date, DATE(created_at)) BETWEEN ? AND ?
    ORDER BY event_date DESC, created_at DESC
");
$eventsStmt->execute([$djId, $dateFrom, $dateTo]);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedEventMeta = null;
$validEventIds = array_map(static fn($e) => (int)$e['id'], $events);
if ($selectedEventId > 0 && !in_array($selectedEventId, $validEventIds, true)) {
    $selectedEventId = 0;
}
foreach ($events as $evt) {
    if ((int)$evt['id'] === $selectedEventId) {
        $selectedEventMeta = $evt;
        break;
    }
}

$eventFilterSql = '';
$eventFilterParams = [];
if ($selectedEventId > 0) {
    $eventFilterSql = ' AND e.id = ?';
    $eventFilterParams[] = $selectedEventId;
}

$reportError = '';
$eventPerformance = [];
$topSongs = [];
$tipBoostSummary = [];
$topRequestersPerEvent = [];
$topCombinedActivity = [];
$transcriptPatrons = [];
$transcriptRows = [];

try {
    if ($view === 'performance') {
        $sql = "
            SELECT
                e.id,
                e.uuid,
                e.title,
                e.event_date,
                COALESCE(req.total_requests, 0) AS total_requests,
                COALESCE(req.unique_requesters, 0) AS unique_requesters,
                COALESCE(msg.total_messages, 0) AS total_messages,
                COALESCE(vw.connected_patrons, 0) AS connected_patrons
            FROM events e
            LEFT JOIN (
                SELECT
                    event_id,
                    COUNT(*) AS total_requests,
                    COUNT(DISTINCT guest_token) AS unique_requesters
                FROM song_requests
                WHERE created_at BETWEEN ? AND ?
                GROUP BY event_id
            ) req ON req.event_id = e.id
            LEFT JOIN (
                SELECT
                    event_id,
                    COUNT(*) AS total_messages
                FROM messages
                WHERE created_at BETWEEN ? AND ?
                GROUP BY event_id
            ) msg ON msg.event_id = e.id
            LEFT JOIN (
                SELECT
                    event_id,
                    COUNT(DISTINCT guest_token) AS connected_patrons
                FROM event_page_views
                WHERE last_seen_at BETWEEN ? AND ?
                GROUP BY event_id
            ) vw ON vw.event_id = e.id
            WHERE e.user_id = ?
              AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
              $eventFilterSql
              AND (
                  COALESCE(req.total_requests, 0) > 0
                  OR COALESCE(msg.total_messages, 0) > 0
                  OR COALESCE(vw.connected_patrons, 0) > 0
              )
            ORDER BY COALESCE(e.event_date, DATE(e.created_at)) DESC, e.created_at DESC, total_requests DESC
            LIMIT 100
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([
            $fromTs, $toTs,
            $fromTs, $toTs,
            $fromTs, $toTs,
            $djId,
            $dateFrom, $dateTo,
        ], $eventFilterParams));
        $eventPerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($view === 'top_songs') {
        $songEventClause = '';
        $songParams = [$fromTs, $toTs, $djId, $dateFrom, $dateTo];
        if ($selectedEventId > 0) {
            $songEventClause = ' AND sr.event_id = ?';
            $songParams[] = $selectedEventId;
        }

        $topSongsSql = "
            SELECT
                COALESCE(NULLIF(sr.spotify_track_name, ''), sr.song_title) AS song_title,
                COALESCE(NULLIF(sr.spotify_artist_name, ''), sr.artist) AS artist_name,
                COUNT(*) AS request_count
            FROM song_requests sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.created_at BETWEEN ? AND ?
              AND e.user_id = ?
              AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
              $songEventClause
              AND COALESCE(NULLIF(sr.spotify_track_name, ''), sr.song_title) <> ''
            GROUP BY
                COALESCE(NULLIF(sr.spotify_track_name, ''), sr.song_title),
                COALESCE(NULLIF(sr.spotify_artist_name, ''), sr.artist)
            ORDER BY request_count DESC, song_title ASC
            LIMIT 25
        ";

        $songStmt = $db->prepare($topSongsSql);
        $songStmt->execute($songParams);
        $topSongs = $songStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($view === 'revenue') {
        $moneyEventClause = '';
        $moneyParams = [$djId, $dateFrom, $dateTo, $fromTs, $toTs];
        if ($selectedEventId > 0) {
            $moneyEventClause = ' AND e.id = ?';
            $moneyParams[] = $selectedEventId;
        }

        $moneySql = "
            SELECT
                totals.currency,
                SUM(totals.tip_count) AS tip_count,
                SUM(totals.tip_amount) AS tip_amount,
                SUM(totals.boost_count) AS boost_count,
                SUM(totals.boost_amount) AS boost_amount
            FROM (
                SELECT
                    e.id AS event_id,
                    t.currency AS currency,
                    COUNT(*) AS tip_count,
                    SUM(t.amount_cents) / 100 AS tip_amount,
                    0 AS boost_count,
                    0 AS boost_amount
                FROM events e
                INNER JOIN event_tips t ON t.event_id = e.id
                WHERE e.user_id = ?
                  AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
                  AND t.status = 'succeeded'
                  AND t.created_at BETWEEN ? AND ?
                  $moneyEventClause
                GROUP BY e.id, t.currency

                UNION ALL

                SELECT
                    e.id AS event_id,
                    b.currency AS currency,
                    0 AS tip_count,
                    0 AS tip_amount,
                    COUNT(*) AS boost_count,
                    SUM(b.amount_cents) / 100 AS boost_amount
                FROM events e
                INNER JOIN event_track_boosts b ON b.event_id = e.id
                WHERE e.user_id = ?
                  AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
                  AND b.status = 'succeeded'
                  AND b.created_at BETWEEN ? AND ?
                  $moneyEventClause
                GROUP BY e.id, b.currency
            ) totals
            GROUP BY totals.currency
            ORDER BY totals.currency ASC
        ";

        $moneyStmt = $db->prepare($moneySql);
        $unionParams = [$djId, $dateFrom, $dateTo, $fromTs, $toTs];
        if ($selectedEventId > 0) {
            $unionParams[] = $selectedEventId;
        }
        $moneyStmt->execute(array_merge($moneyParams, $unionParams));
        $tipBoostSummary = $moneyStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($view === 'top_activity') {
        $activityEventClause = '';
        $activityParams = [$fromTs, $toTs, $djId, $dateFrom, $dateTo];
        if ($selectedEventId > 0) {
            $activityEventClause = ' AND e.id = ?';
            $activityParams[] = $selectedEventId;
        }

        $requestsByEventSql = "
            SELECT
                e.id AS event_id,
                e.title AS event_title,
                sr.guest_token,
                NULLIF(TRIM(sr.requester_name), '') AS patron_name,
                COUNT(*) AS request_count
            FROM song_requests sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.created_at BETWEEN ? AND ?
              AND e.user_id = ?
              AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
              $activityEventClause
            GROUP BY
                e.id,
                e.title,
                sr.guest_token,
                NULLIF(TRIM(sr.requester_name), '')
            ORDER BY e.id DESC, request_count DESC
        ";
        $requestsByEventStmt = $db->prepare($requestsByEventSql);
        $requestsByEventStmt->execute($activityParams);
        $rows = $requestsByEventStmt->fetchAll(PDO::FETCH_ASSOC);

        $perEvent = [];
        foreach ($rows as $row) {
            $token = trim((string)($row['guest_token'] ?? ''));
            $name = trim((string)($row['patron_name'] ?? ''));
            $key = $token !== '' ? ('t:' . $token) : ($name !== '' ? ('n:' . strtolower($name)) : '');
            if ($key === '') {
                continue;
            }

            $eventId = (int)$row['event_id'];
            if (!isset($perEvent[$eventId])) {
                $perEvent[$eventId] = [
                    'event_title' => (string)$row['event_title'],
                    'rows' => [],
                ];
            }
            if (!isset($perEvent[$eventId]['rows'][$key])) {
                $perEvent[$eventId]['rows'][$key] = [
                    'patron_name' => $name !== '' ? $name : 'Guest',
                    'request_count' => 0,
                ];
            }
            $perEvent[$eventId]['rows'][$key]['request_count'] += (int)$row['request_count'];
            if ($name !== '' && $perEvent[$eventId]['rows'][$key]['patron_name'] === 'Guest') {
                $perEvent[$eventId]['rows'][$key]['patron_name'] = $name;
            }
        }

        foreach ($perEvent as $eventId => $eventData) {
            $eventRows = array_values($eventData['rows']);
            usort($eventRows, static function ($a, $b) {
                if ((int)$a['request_count'] === (int)$b['request_count']) {
                    return strcasecmp((string)$a['patron_name'], (string)$b['patron_name']);
                }
                return ((int)$b['request_count'] <=> (int)$a['request_count']);
            });
            $topRequestersPerEvent[] = [
                'event_id' => $eventId,
                'event_title' => $eventData['event_title'],
                'rows' => array_slice($eventRows, 0, 10),
            ];
        }

        usort($topRequestersPerEvent, static function ($a, $b) {
            return ((int)$b['event_id'] <=> (int)$a['event_id']);
        });

        $requestTotalsSql = "
            SELECT
                sr.guest_token,
                NULLIF(TRIM(sr.requester_name), '') AS patron_name,
                COUNT(*) AS request_count
            FROM song_requests sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.created_at BETWEEN ? AND ?
              AND e.user_id = ?
              AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
              $activityEventClause
            GROUP BY sr.guest_token, NULLIF(TRIM(sr.requester_name), '')
        ";
        $requestTotalsStmt = $db->prepare($requestTotalsSql);
        $requestTotalsStmt->execute($activityParams);

        $voteTotalsSql = "
            SELECT
                sv.guest_token,
                NULLIF(TRIM(sv.patron_name), '') AS patron_name,
                COUNT(*) AS vote_count
            FROM song_votes sv
            INNER JOIN events e ON e.id = sv.event_id
            WHERE sv.created_at BETWEEN ? AND ?
              AND e.user_id = ?
              AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
              $activityEventClause
            GROUP BY sv.guest_token, NULLIF(TRIM(sv.patron_name), '')
        ";
        $voteTotalsStmt = $db->prepare($voteTotalsSql);
        $voteTotalsStmt->execute($activityParams);

        $combinedMap = [];
        foreach ($requestTotalsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $token = trim((string)($row['guest_token'] ?? ''));
            $name = trim((string)($row['patron_name'] ?? ''));
            $key = $token !== '' ? ('t:' . $token) : ($name !== '' ? ('n:' . strtolower($name)) : '');
            if ($key === '') {
                continue;
            }
            if (!isset($combinedMap[$key])) {
                $combinedMap[$key] = ['patron_name' => $name !== '' ? $name : 'Guest', 'request_count' => 0, 'vote_count' => 0];
            }
            $combinedMap[$key]['request_count'] += (int)$row['request_count'];
            if ($name !== '' && $combinedMap[$key]['patron_name'] === 'Guest') {
                $combinedMap[$key]['patron_name'] = $name;
            }
        }

        foreach ($voteTotalsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $token = trim((string)($row['guest_token'] ?? ''));
            $name = trim((string)($row['patron_name'] ?? ''));
            $key = $token !== '' ? ('t:' . $token) : ($name !== '' ? ('n:' . strtolower($name)) : '');
            if ($key === '') {
                continue;
            }
            if (!isset($combinedMap[$key])) {
                $combinedMap[$key] = ['patron_name' => $name !== '' ? $name : 'Guest', 'request_count' => 0, 'vote_count' => 0];
            }
            $combinedMap[$key]['vote_count'] += (int)$row['vote_count'];
            if ($name !== '' && $combinedMap[$key]['patron_name'] === 'Guest') {
                $combinedMap[$key]['patron_name'] = $name;
            }
        }

        $topCombinedActivity = array_values($combinedMap);
        foreach ($topCombinedActivity as &$row) {
            $row['combined_total'] = (int)$row['request_count'] + (int)$row['vote_count'];
        }
        unset($row);

        usort($topCombinedActivity, static function ($a, $b) {
            if ((int)$a['combined_total'] === (int)$b['combined_total']) {
                return strcasecmp((string)$a['patron_name'], (string)$b['patron_name']);
            }
            return ((int)$b['combined_total'] <=> (int)$a['combined_total']);
        });
        $topCombinedActivity = array_slice($topCombinedActivity, 0, 10);
    }

    if ($view === 'message_transcript') {
        if ($selectedEventId <= 0) {
            $selectedGuestToken = '';
        } else {
            $patronStmt = $db->prepare("
                SELECT
                    guest_token,
                    MAX(NULLIF(TRIM(patron_name), '')) AS patron_name,
                    MAX(created_at) AS last_message_at,
                    COUNT(*) AS message_count
                FROM messages
                WHERE event_id = ?
                  AND guest_token IS NOT NULL
                  AND guest_token <> ''
                GROUP BY guest_token
                ORDER BY MAX(created_at) DESC
                LIMIT 500
            ");
            $patronStmt->execute([$selectedEventId]);
            $transcriptPatrons = $patronStmt->fetchAll(PDO::FETCH_ASSOC);

            // Include name-only records where guest_token is missing.
            $nameOnlyStmt = $db->prepare("
                SELECT
                    NULL AS guest_token,
                    NULLIF(TRIM(patron_name), '') AS patron_name,
                    MAX(created_at) AS last_message_at,
                    COUNT(*) AS message_count
                FROM messages
                WHERE event_id = ?
                  AND (guest_token IS NULL OR guest_token = '')
                  AND NULLIF(TRIM(patron_name), '') IS NOT NULL
                GROUP BY NULLIF(TRIM(patron_name), '')
                ORDER BY MAX(created_at) DESC
                LIMIT 500
            ");
            $nameOnlyStmt->execute([$selectedEventId]);
            foreach ($nameOnlyStmt->fetchAll(PDO::FETCH_ASSOC) as $nameOnlyRow) {
                $name = trim((string)($nameOnlyRow['patron_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $transcriptPatrons[] = [
                    'guest_token' => 'name:' . rawurlencode($name),
                    'patron_name' => $name,
                    'last_message_at' => (string)($nameOnlyRow['last_message_at'] ?? ''),
                    'message_count' => (int)($nameOnlyRow['message_count'] ?? 0),
                ];
            }

            $validTokens = [];
            foreach ($transcriptPatrons as $patron) {
                $token = (string)($patron['guest_token'] ?? '');
                if ($token !== '') {
                    $validTokens[$token] = true;
                }
            }
            if ($selectedGuestToken !== '' && !isset($validTokens[$selectedGuestToken])) {
                $selectedGuestToken = '';
            }

            if ($selectedGuestToken !== '') {
                $guestRows = [];
                $djRows = [];
                $broadcastRows = [];
                $isNameOnlySelection = str_starts_with($selectedGuestToken, 'name:');
                $selectedPatronName = $isNameOnlySelection ? rawurldecode(substr($selectedGuestToken, 5)) : '';

                if ($isNameOnlySelection) {
                    $guestStmt = $db->prepare("
                        SELECT id, message AS body, created_at, 'guest' AS sender
                        FROM messages
                        WHERE event_id = :event_id
                          AND (guest_token IS NULL OR guest_token = '')
                          AND NULLIF(TRIM(patron_name), '') = :patron_name
                        ORDER BY created_at DESC, id DESC
                        LIMIT 500
                    ");
                    $guestStmt->execute([
                        ':event_id' => $selectedEventId,
                        ':patron_name' => $selectedPatronName,
                    ]);
                } else {
                    $guestStmt = $db->prepare("
                        SELECT id, message AS body, created_at, 'guest' AS sender
                        FROM messages
                        WHERE event_id = :event_id
                          AND guest_token = :guest_token
                        ORDER BY created_at DESC, id DESC
                        LIMIT 500
                    ");
                    $guestStmt->execute([
                        ':event_id' => $selectedEventId,
                        ':guest_token' => $selectedGuestToken,
                    ]);
                }
                $guestRows = $guestStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$isNameOnlySelection) {
                    $djStmt = $db->prepare("
                        SELECT id, message AS body, created_at, 'dj' AS sender
                        FROM message_replies
                        WHERE event_id = :event_id
                          AND guest_token = :guest_token
                        ORDER BY created_at DESC, id DESC
                        LIMIT 500
                    ");
                    $djStmt->execute([
                        ':event_id' => $selectedEventId,
                        ':guest_token' => $selectedGuestToken,
                    ]);
                    $djRows = $djStmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $broadcastStmt = $db->prepare("
                    SELECT id, message AS body, created_at, 'broadcast' AS sender
                    FROM event_broadcast_messages
                    WHERE event_id = :event_id
                    ORDER BY created_at DESC, id DESC
                    LIMIT 200
                ");
                $broadcastStmt->execute([':event_id' => $selectedEventId]);
                $broadcastRows = $broadcastStmt->fetchAll(PDO::FETCH_ASSOC);

                $djName = '';
                try {
                    $djNameStmt = $db->prepare("
                        SELECT dj_name, name
                        FROM users
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $djNameStmt->execute([$djId]);
                    $djRow = $djNameStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $djName = trim((string)($djRow['dj_name'] ?? ''));
                    if ($djName === '') {
                        $djName = trim((string)($djRow['name'] ?? ''));
                    }
                } catch (Throwable $e) {
                    $djName = '';
                }
                $eventName = trim((string)($selectedEventMeta['title'] ?? ''));
                foreach ($broadcastRows as &$broadcastRow) {
                    $body = (string)($broadcastRow['body'] ?? '');
                    $body = str_replace('{{DJ_NAME}}', $djName, $body);
                    $body = str_replace('{{EVENT_NAME}}', $eventName, $body);
                    $broadcastRow['body'] = $body;
                }
                unset($broadcastRow);

                $transcriptRows = array_merge($guestRows, $djRows, $broadcastRows);
                usort($transcriptRows, static function (array $a, array $b): int {
                    $cmp = strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                    return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
                });
            }
        }
    }
} catch (Throwable $e) {
    $reportError = 'Could not load report data.';
}

$pageTitle = 'Reports';
require __DIR__ . '/layout.php';
?>

<style>
.reports-wrap { max-width: 1080px; margin: 0 auto; }
.reports-card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; margin-bottom:16px; }
.reports-card h2 { margin:0 0 14px; }
.reports-filter-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; align-items:end; }
.reports-label { display:block; margin-bottom:6px; color:#cfd0da; font-weight:600; font-size:13px; }
.reports-input, .reports-select { width:100%; box-sizing:border-box; border:1px solid #2a2a3a; border-radius:8px; padding:10px 12px; background:#0e0f17; color:#fff; }
.reports-input[type="date"] { color-scheme: dark; cursor: pointer; }
.reports-input[type="date"]::-webkit-calendar-picker-indicator {
    filter: invert(1) brightness(1.1);
    opacity: 0.9;
    cursor: pointer;
}
.reports-btn { background:#ff2fd2; color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; }
.reports-help { color:#b7b7c8; font-size:13px; margin-top:8px; }
.reports-view-links { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
.reports-view-link { display:inline-block; padding:8px 10px; border:1px solid #2a2a3a; border-radius:8px; color:#cfd0da; text-decoration:none; font-size:13px; }
.reports-view-link.active { border-color:#ff2fd2; color:#ff8ae9; background:rgba(255,47,210,0.08); }
.reports-table-wrap { overflow-x:auto; }
.reports-table { width:100%; border-collapse:collapse; min-width:700px; }
.reports-table th, .reports-table td { text-align:left; padding:10px; border-bottom:1px solid #242432; }
.reports-table th { color:#d3d4e2; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
.reports-table td { color:#ececf3; }
.transcript-row--guest td { background: rgba(255,255,255,0.02); }
.transcript-row--dj td { background: rgba(24,92,255,0.16); }
.transcript-row--broadcast td { background: rgba(255,47,210,0.16); }
.transcript-chat { display:flex; flex-direction:column; gap:10px; }
.transcript-chat-row { display:flex; }
.transcript-chat-row.guest { justify-content:flex-start; }
.transcript-chat-row.dj { justify-content:flex-end; }
.transcript-chat-row.broadcast { justify-content:center; }
.transcript-chat-bubble {
    max-width: 78%;
    border-radius: 12px;
    padding: 10px 12px;
    border: 1px solid #2b2b36;
    white-space: pre-wrap;
}
.transcript-chat-row.guest .transcript-chat-bubble { background:#151723; }
.transcript-chat-row.dj .transcript-chat-bubble { background:#14305d; }
.transcript-chat-row.broadcast .transcript-chat-bubble { background:#3d1435; }
.transcript-chat-meta { font-size: 11px; color:#b7b7c8; margin-top:6px; display:block; }
.reports-empty { color:#9fa1b5; margin:0; }
.reports-error { color:#ff8f8f; margin-bottom:10px; }
</style>

<div class="reports-wrap">
    <p style="margin:0 0 8px;"><a href="/dj/dashboard.php" style="color:#ff2fd2; text-decoration:none;">&larr; Back to Dashboard</a></p>
    <h1>Reports</h1>

    <?php if ($reportError !== ''): ?>
        <div class="reports-error"><?php echo e($reportError); ?></div>
    <?php endif; ?>

    <div class="reports-card">
        <div class="reports-view-links">
            <a class="reports-view-link <?php echo $view === 'performance' ? 'active' : ''; ?>" href="<?php echo e(url('dj/reports.php?view=performance&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) . '&event_id=' . (int)$selectedEventId)); ?>">Event Performance Summary</a>
            <a class="reports-view-link <?php echo $view === 'top_songs' ? 'active' : ''; ?>" href="<?php echo e(url('dj/reports.php?view=top_songs&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) . '&event_id=' . (int)$selectedEventId)); ?>">Top Requested Songs</a>
            <a class="reports-view-link <?php echo $view === 'revenue' ? 'active' : ''; ?>" href="<?php echo e(url('dj/reports.php?view=revenue&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) . '&event_id=' . (int)$selectedEventId)); ?>">Tips/Boost Revenue Summary</a>
            <a class="reports-view-link <?php echo $view === 'top_activity' ? 'active' : ''; ?>" href="<?php echo e(url('dj/reports.php?view=top_activity&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) . '&event_id=' . (int)$selectedEventId)); ?>">Top Activity Report</a>
            <a class="reports-view-link <?php echo $view === 'message_transcript' ? 'active' : ''; ?>" href="<?php echo e(url('dj/reports.php?view=message_transcript&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) . '&event_id=' . (int)$selectedEventId)); ?>">Message Transcript</a>
        </div>
        <h2>Filters</h2>
        <form method="GET" class="reports-filter-grid">
            <input type="hidden" name="view" value="<?php echo e($view); ?>">
            <div>
                <label class="reports-label" for="from">From</label>
                <input class="reports-input js-date-picker" type="date" id="from" name="from" value="<?php echo e($dateFrom); ?>" max="<?php echo e($today); ?>">
            </div>
            <div>
                <label class="reports-label" for="to">To</label>
                <input class="reports-input js-date-picker" type="date" id="to" name="to" value="<?php echo e($dateTo); ?>" max="<?php echo e($today); ?>">
            </div>
            <div>
                <label class="reports-label" for="event_id">Event</label>
                <select class="reports-select" id="event_id" name="event_id">
                    <option value="0">All events</option>
                    <?php foreach ($events as $event): ?>
                        <option value="<?php echo (int)$event['id']; ?>" <?php echo ((int)$event['id'] === $selectedEventId) ? 'selected' : ''; ?>>
                            <?php echo e((string)($event['title'] ?? 'Untitled event')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($view === 'message_transcript'): ?>
            <div>
                <label class="reports-label" for="guest_token">Patron</label>
                <select class="reports-select" id="guest_token" name="guest_token">
                    <option value="">Select patron</option>
                    <?php foreach ($transcriptPatrons as $patron): ?>
                        <?php
                            $token = (string)($patron['guest_token'] ?? '');
                            $name = trim((string)($patron['patron_name'] ?? ''));
                            $label = $name !== '' ? $name : 'Guest';
                            $label .= ' (' . (int)($patron['message_count'] ?? 0) . ' msgs)';
                        ?>
                        <option value="<?php echo e($token); ?>" <?php echo $token === $selectedGuestToken ? 'selected' : ''; ?>>
                            <?php echo e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="reports-label" for="display_mode">View Mode</label>
                <select class="reports-select" id="display_mode" name="display_mode">
                    <option value="table" <?php echo $displayMode === 'table' ? 'selected' : ''; ?>>Table</option>
                    <option value="chat" <?php echo $displayMode === 'chat' ? 'selected' : ''; ?>>Chat (Patron-style)</option>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <button type="submit" class="reports-btn">Apply Filters</button>
            </div>
        </form>
        <div class="reports-help">Showing <?php echo e($activeTitle); ?> from <?php echo e($dateFrom); ?> to <?php echo e($dateTo); ?>. Only events in this date range are included.</div>
        <?php if ($view === 'message_transcript'): ?>
            <div class="reports-help">Transcript times shown in your timezone: <?php echo e($djTimezone); ?>.</div>
        <?php endif; ?>
        <?php if ($rangeNotice !== ''): ?>
            <div class="reports-help"><?php echo e($rangeNotice); ?></div>
        <?php endif; ?>
    </div>

    <div class="reports-card">
        <h2><?php echo e($activeTitle); ?></h2>

        <?php if ($view === 'performance'): ?>
            <?php if (empty($eventPerformance)): ?>
                <p class="reports-empty">No event activity for this period.</p>
            <?php else: ?>
                <div class="reports-table-wrap">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Event Date</th>
                                <th>Requests</th>
                                <th>Unique Requesters</th>
                                <th>Messages</th>
                                <th>Connected Patrons</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventPerformance as $row): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo e(url('dj/event_details.php?uuid=' . (string)$row['uuid'])); ?>" style="color:#ff78e6; text-decoration:none;">
                                            <?php echo e((string)$row['title']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo e((string)($row['event_date'] ?: '—')); ?></td>
                                    <td><?php echo (int)$row['total_requests']; ?></td>
                                    <td><?php echo (int)$row['unique_requesters']; ?></td>
                                    <td><?php echo (int)$row['total_messages']; ?></td>
                                    <td><?php echo (int)$row['connected_patrons']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'top_songs'): ?>
            <?php if (empty($topSongs)): ?>
                <p class="reports-empty">No song requests found for this period.</p>
            <?php else: ?>
                <div class="reports-table-wrap">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Song</th>
                                <th>Artist</th>
                                <th>Requests</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topSongs as $i => $row): ?>
                                <tr>
                                    <td><?php echo (int)$i + 1; ?></td>
                                    <td><?php echo e((string)$row['song_title']); ?></td>
                                    <td><?php echo e((string)($row['artist_name'] ?: '—')); ?></td>
                                    <td><?php echo (int)$row['request_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'revenue'): ?>
            <?php if (empty($tipBoostSummary)): ?>
                <p class="reports-empty">No successful tips or boosts for this period.</p>
            <?php else: ?>
                <div class="reports-table-wrap">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Currency</th>
                                <th>Tips (Count)</th>
                                <th>Tips (Amount)</th>
                                <th>Boosts (Count)</th>
                                <th>Boosts (Amount)</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tipBoostSummary as $row): ?>
                                <?php
                                    $tipAmount = (float)($row['tip_amount'] ?? 0);
                                    $boostAmount = (float)($row['boost_amount'] ?? 0);
                                ?>
                                <tr>
                                    <td><?php echo e(strtoupper((string)$row['currency'])); ?></td>
                                    <td><?php echo (int)$row['tip_count']; ?></td>
                                    <td><?php echo number_format($tipAmount, 2); ?></td>
                                    <td><?php echo (int)$row['boost_count']; ?></td>
                                    <td><?php echo number_format($boostAmount, 2); ?></td>
                                    <td><?php echo number_format($tipAmount + $boostAmount, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'top_activity'): ?>
            <h3 style="margin:16px 0 10px;">Top 10 Requesters Per Event</h3>
            <?php if (empty($topRequestersPerEvent)): ?>
                <p class="reports-empty">No requester activity for this period.</p>
            <?php else: ?>
                <?php foreach ($topRequestersPerEvent as $eventBlock): ?>
                    <h4 style="margin:14px 0 8px; color:#d3d4e2;"><?php echo e($eventBlock['event_title']); ?></h4>
                    <div class="reports-table-wrap">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Requester</th>
                                    <th>Requests</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eventBlock['rows'] as $i => $row): ?>
                                    <tr>
                                        <td><?php echo (int)$i + 1; ?></td>
                                        <td><?php echo e((string)$row['patron_name']); ?></td>
                                        <td><?php echo (int)$row['request_count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <h3 style="margin:18px 0 10px;">Top 10 Combined Requesters And Votes</h3>
            <?php if (empty($topCombinedActivity)): ?>
                <p class="reports-empty">No combined requester/vote activity for this period.</p>
            <?php else: ?>
                <div class="reports-table-wrap">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Patron</th>
                                <th>Requests</th>
                                <th>Votes</th>
                                <th>Total Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topCombinedActivity as $i => $row): ?>
                                <tr>
                                    <td><?php echo (int)$i + 1; ?></td>
                                    <td><?php echo e((string)$row['patron_name']); ?></td>
                                    <td><?php echo (int)$row['request_count']; ?></td>
                                    <td><?php echo (int)$row['vote_count']; ?></td>
                                    <td><?php echo (int)$row['combined_total']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($view === 'message_transcript'): ?>
            <?php if ($selectedEventId <= 0): ?>
                <p class="reports-empty">Select an event to load patrons and transcript.</p>
            <?php elseif ($selectedGuestToken === ''): ?>
                <p class="reports-empty">Select a patron to view the transcript.</p>
            <?php elseif (empty($transcriptRows)): ?>
                <p class="reports-empty">No transcript messages found for this patron in this event.</p>
            <?php else: ?>
                <?php if ($displayMode === 'chat'): ?>
                    <div class="transcript-chat">
                        <?php foreach ($transcriptRows as $row): ?>
                            <?php
                                $sender = (string)($row['sender'] ?? '');
                                $senderClass = $sender === 'dj' ? 'dj' : ($sender === 'broadcast' ? 'broadcast' : 'guest');
                                $senderLabel = $sender === 'dj' ? 'DJ' : ($sender === 'broadcast' ? 'System' : 'Patron');
                                $localTs = mdjr_format_local_datetime((string)($row['created_at'] ?? ''), $djTimezone);
                            ?>
                            <div class="transcript-chat-row <?php echo e($senderClass); ?>">
                                <div class="transcript-chat-bubble">
                                    <?php echo e((string)($row['body'] ?? '')); ?>
                                    <span class="transcript-chat-meta"><?php echo e($senderLabel); ?> · <?php echo e($localTs); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="reports-table-wrap">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Sender</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transcriptRows as $row): ?>
                                    <?php
                                        $sender = (string)($row['sender'] ?? '');
                                        $senderClass = $sender === 'dj' ? 'transcript-row--dj' : ($sender === 'broadcast' ? 'transcript-row--broadcast' : 'transcript-row--guest');
                                        $senderLabel = $sender === 'dj' ? 'DJ' : ($sender === 'broadcast' ? 'System/Broadcast' : 'Patron');
                                        $localTs = mdjr_format_local_datetime((string)($row['created_at'] ?? ''), $djTimezone);
                                    ?>
                                    <tr class="<?php echo e($senderClass); ?>">
                                        <td><?php echo e($localTs); ?></td>
                                        <td><?php echo e($senderLabel); ?></td>
                                        <td style="white-space:pre-wrap;"><?php echo e((string)($row['body'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.js-date-picker').forEach((input) => {
        input.addEventListener('click', () => {
            if (typeof input.showPicker === 'function') {
                try { input.showPicker(); } catch (e) {}
            }
        });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const viewInput = document.querySelector('input[name="view"]');
    const eventSelect = document.getElementById('event_id');
    if (!viewInput || !eventSelect || viewInput.value !== 'message_transcript') return;
    eventSelect.addEventListener('change', () => {
        const form = eventSelect.closest('form');
        if (form) form.submit();
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
