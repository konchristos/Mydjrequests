<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);
$isPremiumPlan = mdjr_get_user_plan($db, $djId) === 'premium';
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
$allowedViews = ['performance', 'top_songs', 'revenue', 'top_activity', 'message_transcript', 'advanced_analytics'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'performance';
}

$viewTitles = [
    'performance' => 'Event Performance Summary',
    'top_songs' => 'Top Requested Songs',
    'revenue' => 'Tips/Boost Revenue Summary',
    'top_activity' => 'Top Activity Report',
    'message_transcript' => 'Message Transcript',
    'advanced_analytics' => 'Advanced Analytics Pack',
];
$activeTitle = $viewTitles[$view];

$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$selectedEventId = (int)($_GET['event_id'] ?? 0);
$selectedGuestToken = trim((string)($_GET['guest_token'] ?? ''));
$selectedRepeatPatronKey = trim((string)($_GET['repeat_patron'] ?? ''));
$exportFormat = strtolower(trim((string)($_GET['format'] ?? '')));
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
$advancedKpis = [
    'total_requests' => 0,
    'total_votes' => 0,
    'connected_patrons' => 0,
    'unique_requesters' => 0,
    'unique_tippers' => 0,
    'tip_request_conversion_pct' => 0.0,
    'avg_mood_pct' => null,
];
$advancedPeakHours = [];
$advancedRepeatPatrons = [];
$advancedBenchmarks = [];
$advancedSelectedRepeatPatron = null;
$advancedSelectedRepeatRequests = [];

function mdjr_reports_patron_label(string $guestToken, string $patronName): string
{
    $name = trim($patronName);
    if ($name !== '') {
        return $name;
    }
    if ($guestToken !== '') {
        return 'Guest ' . strtoupper(substr(sha1($guestToken), 0, 6));
    }
    return 'Guest';
}

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
        $ledgerExists = false;
        try {
            $ledgerCheckStmt = $db->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'stripe_payment_ledger'
            ");
            $ledgerCheckStmt->execute();
            $ledgerExists = ((int)$ledgerCheckStmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            $ledgerExists = false;
        }

        if ($ledgerExists) {
            $ledgerEventClause = '';
            $ledgerParams = [$djId, $fromTs, $toTs];
            if ($selectedEventId > 0) {
                $ledgerEventClause = ' AND l.event_id = ?';
                $ledgerParams[] = $selectedEventId;
            }

            $moneySql = "
                SELECT
                    UPPER(COALESCE(l.currency, 'AUD')) AS currency,
                    SUM(CASE WHEN l.entry_type = 'payment' AND l.payment_type = 'dj_tip' THEN 1 ELSE 0 END) AS tip_count,
                    SUM(CASE WHEN l.entry_type = 'payment' AND l.payment_type = 'dj_tip' THEN l.gross_amount_cents ELSE 0 END) / 100 AS tip_amount,
                    SUM(CASE WHEN l.entry_type = 'payment' AND l.payment_type = 'track_boost' THEN 1 ELSE 0 END) AS boost_count,
                    SUM(CASE WHEN l.entry_type = 'payment' AND l.payment_type = 'track_boost' THEN l.gross_amount_cents ELSE 0 END) / 100 AS boost_amount,
                    SUM(l.platform_fee_cents) / 100 AS platform_fee_amount,
                    SUM(l.stripe_fee_cents) / 100 AS stripe_fee_amount,
                    SUM(l.net_to_dj_cents) / 100 AS net_to_dj_amount
                FROM stripe_payment_ledger l
                WHERE l.dj_user_id = ?
                  AND l.occurred_at BETWEEN ? AND ?
                  {$ledgerEventClause}
                GROUP BY UPPER(COALESCE(l.currency, 'AUD'))
                ORDER BY UPPER(COALESCE(l.currency, 'AUD')) ASC
            ";
            $moneyStmt = $db->prepare($moneySql);
            $moneyStmt->execute($ledgerParams);
            $tipBoostSummary = $moneyStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
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
                    SUM(totals.boost_amount) AS boost_amount,
                    0 AS platform_fee_amount,
                    0 AS stripe_fee_amount,
                    SUM(totals.tip_amount) + SUM(totals.boost_amount) AS net_to_dj_amount
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

    if ($view === 'advanced_analytics' && $isPremiumPlan) {
        $analyticsEventClause = '';
        if ($selectedEventId > 0) {
            $analyticsEventClause = ' AND e.id = ?';
        }

        // Per-event baseline stats (requests, connected patrons, mood, unique requesters)
        $eventStatsSql = "
            SELECT
                e.id AS event_id,
                e.uuid,
                e.title AS event_title,
                COALESCE(e.event_date, DATE(e.created_at)) AS event_date,
                COALESCE(req.total_requests, 0) AS total_requests,
                COALESCE(req.unique_requesters, 0) AS unique_requesters,
                COALESCE(conn.connected_patrons, 0) AS connected_patrons,
                COALESCE(mood.mood_positive, 0) AS mood_positive,
                COALESCE(mood.mood_negative, 0) AS mood_negative
            FROM events e
            LEFT JOIN (
                SELECT
                    event_id,
                    COUNT(*) AS total_requests,
                    COUNT(DISTINCT CASE WHEN guest_token IS NOT NULL AND guest_token <> '' THEN guest_token END) AS unique_requesters
                FROM song_requests
                WHERE created_at BETWEEN ? AND ?
                GROUP BY event_id
            ) req ON req.event_id = e.id
            LEFT JOIN (
                SELECT
                    event_id,
                    COUNT(DISTINCT guest_token) AS connected_patrons
                FROM event_page_views
                WHERE guest_token IS NOT NULL
                  AND guest_token <> ''
                  AND last_seen_at BETWEEN ? AND ?
                GROUP BY event_id
            ) conn ON conn.event_id = e.id
            LEFT JOIN (
                SELECT
                    event_id,
                    SUM(CASE WHEN mood = 1 THEN 1 ELSE 0 END) AS mood_positive,
                    SUM(CASE WHEN mood = -1 THEN 1 ELSE 0 END) AS mood_negative
                FROM event_moods
                GROUP BY event_id
            ) mood ON mood.event_id = e.id
            WHERE e.user_id = ?
              AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
              {$analyticsEventClause}
            ORDER BY event_date DESC, e.id DESC
        ";
        $eventStatsStmt = $db->prepare($eventStatsSql);
        $eventStatsStmt->execute(array_merge([
            $fromTs, $toTs,
            $fromTs, $toTs,
            $djId, $dateFrom, $dateTo,
        ], $selectedEventId > 0 ? [$selectedEventId] : []));
        $eventStatsRows = $eventStatsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Peak request times + repeat requesters + request totals.
        $requestRowsSql = "
            SELECT
                sr.event_id,
                sr.created_at,
                COALESCE(NULLIF(TRIM(sr.requester_name), ''), '') AS patron_name,
                COALESCE(sr.guest_token, '') AS guest_token,
                COALESCE(NULLIF(TRIM(sr.spotify_track_id), ''), CONCAT(LOWER(COALESCE(NULLIF(TRIM(sr.song_title), ''), 'unknown track')), '::', LOWER(COALESCE(NULLIF(TRIM(sr.artist), ''), 'unknown artist')))) AS track_key,
                COALESCE(NULLIF(TRIM(sr.spotify_track_name), ''), NULLIF(TRIM(sr.song_title), ''), 'Unknown Track') AS track_title,
                COALESCE(NULLIF(TRIM(sr.spotify_artist_name), ''), NULLIF(TRIM(sr.artist), ''), 'Unknown Artist') AS artist_name
            FROM song_requests sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.created_at BETWEEN ? AND ?
              AND e.user_id = ?
              AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
              {$analyticsEventClause}
        ";
        $requestRowsStmt = $db->prepare($requestRowsSql);
        $requestRowsStmt->execute(array_merge([
            $fromTs, $toTs, $djId, $dateFrom, $dateTo,
        ], $selectedEventId > 0 ? [$selectedEventId] : []));
        $requestRows = $requestRowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $voteRowsSql = "
            SELECT
                sv.event_id,
                sv.created_at,
                COALESCE(NULLIF(TRIM(sv.patron_name), ''), '') AS patron_name,
                COALESCE(sv.guest_token, '') AS guest_token,
                COALESCE(NULLIF(TRIM(sv.track_key), ''), CONCAT(LOWER(COALESCE(NULLIF(TRIM(sv.song_title), ''), 'unknown track')), '::', LOWER(COALESCE(NULLIF(TRIM(sv.artist), ''), 'unknown artist')))) AS track_key,
                COALESCE(NULLIF(TRIM(sv.song_title), ''), 'Unknown Track') AS track_title,
                COALESCE(NULLIF(TRIM(sv.artist), ''), 'Unknown Artist') AS artist_name
            FROM song_votes sv
            INNER JOIN events e ON e.id = sv.event_id
            WHERE sv.created_at BETWEEN ? AND ?
              AND e.user_id = ?
              AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
              {$analyticsEventClause}
        ";
        $voteRowsStmt = $db->prepare($voteRowsSql);
        $voteRowsStmt->execute(array_merge([
            $fromTs, $toTs, $djId, $dateFrom, $dateTo,
        ], $selectedEventId > 0 ? [$selectedEventId] : []));
        $voteRows = $voteRowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $hourBucketsReq = array_fill(0, 24, 0);
        $hourBucketsVote = array_fill(0, 24, 0);
        $repeatPatronMap = [];
        foreach ($requestRows as $rr) {
            $utc = (string)($rr['created_at'] ?? '');
            if ($utc !== '') {
                try {
                    $dt = new DateTime($utc, new DateTimeZone('UTC'));
                    $dt->setTimezone(new DateTimeZone($djTimezone));
                    $hour = (int)$dt->format('G');
                    if ($hour >= 0 && $hour <= 23) {
                        $hourBucketsReq[$hour]++;
                    }
                } catch (Throwable $e) {
                    // no-op
                }
            }

            $token = trim((string)($rr['guest_token'] ?? ''));
            $name = trim((string)($rr['patron_name'] ?? ''));
            // Prefer stable patron name across events; fallback to token when name is missing.
            $key = $name !== '' ? ('n:' . strtolower($name)) : ($token !== '' ? ('t:' . $token) : '');
            if ($key === '') {
                continue;
            }
            if (!isset($repeatPatronMap[$key])) {
                $repeatPatronMap[$key] = [
                    'key' => $key,
                    'guest_token' => $token,
                    'patron_name' => $name,
                    'event_ids' => [],
                    'total_requests' => 0,
                    'total_votes' => 0,
                ];
            }
            $repeatPatronMap[$key]['event_ids'][(int)($rr['event_id'] ?? 0)] = true;
            $repeatPatronMap[$key]['total_requests']++;
            if ($name !== '' && $repeatPatronMap[$key]['patron_name'] === '') {
                $repeatPatronMap[$key]['patron_name'] = $name;
            }
        }

        foreach ($voteRows as $vr) {
            $utc = (string)($vr['created_at'] ?? '');
            if ($utc !== '') {
                try {
                    $dt = new DateTime($utc, new DateTimeZone('UTC'));
                    $dt->setTimezone(new DateTimeZone($djTimezone));
                    $hour = (int)$dt->format('G');
                    if ($hour >= 0 && $hour <= 23) {
                        $hourBucketsVote[$hour]++;
                    }
                } catch (Throwable $e) {
                    // no-op
                }
            }

            $token = trim((string)($vr['guest_token'] ?? ''));
            $name = trim((string)($vr['patron_name'] ?? ''));
            $key = $name !== '' ? ('n:' . strtolower($name)) : ($token !== '' ? ('t:' . $token) : '');
            if ($key === '') {
                continue;
            }
            if (!isset($repeatPatronMap[$key])) {
                $repeatPatronMap[$key] = [
                    'key' => $key,
                    'guest_token' => $token,
                    'patron_name' => $name,
                    'event_ids' => [],
                    'total_requests' => 0,
                    'total_votes' => 0,
                ];
            }
            $repeatPatronMap[$key]['event_ids'][(int)($vr['event_id'] ?? 0)] = true;
            $repeatPatronMap[$key]['total_votes']++;
            if ($name !== '' && $repeatPatronMap[$key]['patron_name'] === '') {
                $repeatPatronMap[$key]['patron_name'] = $name;
            }
        }

        for ($hour = 23; $hour >= 0; $hour--) {
            $reqCount = (int)($hourBucketsReq[$hour] ?? 0);
            $voteCount = (int)($hourBucketsVote[$hour] ?? 0);
            $combinedCount = $reqCount + $voteCount;
            if ($combinedCount <= 0) {
                continue;
            }
            $hourLabel = sprintf('%02d:00 - %02d:00', $hour, ($hour + 1) % 24);
            $advancedPeakHours[] = [
                'hour' => $hour,
                'label' => $hourLabel,
                'request_count' => $reqCount,
                'vote_count' => $voteCount,
                'combined_count' => $combinedCount,
            ];
        }

        foreach ($repeatPatronMap as $patron) {
            $eventsCount = count($patron['event_ids']);
            if ($eventsCount < 2) {
                continue;
            }
            $advancedRepeatPatrons[] = [
                'key' => (string)$patron['key'],
                'patron_name' => mdjr_reports_patron_label((string)$patron['guest_token'], (string)$patron['patron_name']),
                'events_count' => $eventsCount,
                'total_requests' => (int)$patron['total_requests'],
                'total_votes' => (int)($patron['total_votes'] ?? 0),
                'total_activity' => (int)$patron['total_requests'] + (int)($patron['total_votes'] ?? 0),
            ];
        }
        usort($advancedRepeatPatrons, static function (array $a, array $b): int {
            if ((int)$a['total_activity'] === (int)$b['total_activity']) {
                return ((int)$b['events_count'] <=> (int)$a['events_count']);
            }
            return ((int)$b['total_activity'] <=> (int)$a['total_activity']);
        });
        $advancedRepeatPatrons = array_slice($advancedRepeatPatrons, 0, 10);

        if ($selectedRepeatPatronKey !== '' && isset($repeatPatronMap[$selectedRepeatPatronKey])) {
            $patronMeta = $repeatPatronMap[$selectedRepeatPatronKey];
            $advancedSelectedRepeatPatron = [
                'key' => $selectedRepeatPatronKey,
                'patron_name' => mdjr_reports_patron_label((string)$patronMeta['guest_token'], (string)$patronMeta['patron_name']),
                'events_count' => count((array)$patronMeta['event_ids']),
                'total_requests' => (int)$patronMeta['total_requests'],
                'total_votes' => (int)($patronMeta['total_votes'] ?? 0),
                'total_activity' => (int)$patronMeta['total_requests'] + (int)($patronMeta['total_votes'] ?? 0),
            ];

            $requestsAgg = [];
            foreach ($requestRows as $rr) {
                $token = trim((string)($rr['guest_token'] ?? ''));
                $name = trim((string)($rr['patron_name'] ?? ''));
                $rowKey = $name !== '' ? ('n:' . strtolower($name)) : ($token !== '' ? ('t:' . $token) : '');
                if ($rowKey !== $selectedRepeatPatronKey) {
                    continue;
                }

                $trackKey = trim((string)($rr['track_key'] ?? ''));
                $title = trim((string)($rr['track_title'] ?? 'Unknown Track'));
                $artist = trim((string)($rr['artist_name'] ?? 'Unknown Artist'));
                $songKey = $trackKey !== '' ? ('k:' . strtolower($trackKey)) : ('n:' . strtolower($title . '||' . $artist));
                if (!isset($requestsAgg[$songKey])) {
                    $requestsAgg[$songKey] = [
                        'track_title' => $title !== '' ? $title : 'Unknown Track',
                        'artist_name' => $artist !== '' ? $artist : 'Unknown Artist',
                        'request_count' => 0,
                        'vote_count' => 0,
                        'popularity' => 0,
                    ];
                }
                $requestsAgg[$songKey]['request_count']++;
            }

            foreach ($voteRows as $vr) {
                $token = trim((string)($vr['guest_token'] ?? ''));
                $name = trim((string)($vr['patron_name'] ?? ''));
                $rowKey = $name !== '' ? ('n:' . strtolower($name)) : ($token !== '' ? ('t:' . $token) : '');
                if ($rowKey !== $selectedRepeatPatronKey) {
                    continue;
                }

                $trackKey = trim((string)($vr['track_key'] ?? ''));
                $title = trim((string)($vr['track_title'] ?? 'Unknown Track'));
                $artist = trim((string)($vr['artist_name'] ?? 'Unknown Artist'));
                $songKey = $trackKey !== '' ? ('k:' . strtolower($trackKey)) : ('n:' . strtolower($title . '||' . $artist));
                if (!isset($requestsAgg[$songKey])) {
                    $requestsAgg[$songKey] = [
                        'track_title' => $title !== '' ? $title : 'Unknown Track',
                        'artist_name' => $artist !== '' ? $artist : 'Unknown Artist',
                        'request_count' => 0,
                        'vote_count' => 0,
                        'popularity' => 0,
                    ];
                }
                $requestsAgg[$songKey]['vote_count']++;
            }

            foreach ($requestsAgg as &$agg) {
                $agg['popularity'] = (int)$agg['request_count'] + (int)$agg['vote_count'];
            }
            unset($agg);

            $advancedSelectedRepeatRequests = array_values($requestsAgg);
            usort($advancedSelectedRepeatRequests, static function (array $a, array $b): int {
                if ((int)$a['popularity'] === (int)$b['popularity']) {
                    return strcasecmp((string)$a['track_title'], (string)$b['track_title']);
                }
                return ((int)$b['popularity'] <=> (int)$a['popularity']);
            });

            if ($exportFormat === 'csv') {
                $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)$advancedSelectedRepeatPatron['patron_name']);
                $safeName = trim((string)$safeName, '_');
                if ($safeName === '') {
                    $safeName = 'patron';
                }
                $filename = 'repeat_patron_' . $safeName . '_' . $dateFrom . '_to_' . $dateTo . '.csv';
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                $out = fopen('php://output', 'w');
                if ($out !== false) {
                    fputcsv($out, ['Patron', 'Track', 'Artist', 'Requests', 'Votes', 'Popularity', 'From', 'To', 'Event Filter']);
                    foreach ($advancedSelectedRepeatRequests as $req) {
                        fputcsv($out, [
                            (string)$advancedSelectedRepeatPatron['patron_name'],
                            (string)$req['track_title'],
                            (string)$req['artist_name'],
                            (int)$req['request_count'],
                            (int)$req['vote_count'],
                            (int)$req['popularity'],
                            $dateFrom,
                            $dateTo,
                            $selectedEventId > 0 ? ('Event #' . $selectedEventId) : 'All events',
                        ]);
                    }
                    fclose($out);
                }
                exit;
            }
        }

        // Tip-to-request conversion, unique tippers (ledger first, fallback event_tips)
        $uniqueRequesters = count($repeatPatronMap);
        $uniqueTippers = 0;
        $ledgerExists = false;
        try {
            $ledgerCheckStmt = $db->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'stripe_payment_ledger'
            ");
            $ledgerCheckStmt->execute();
            $ledgerExists = ((int)$ledgerCheckStmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            $ledgerExists = false;
        }

        if ($ledgerExists) {
            $tipperSql = "
                SELECT COUNT(DISTINCT l.guest_token) AS unique_tippers
                FROM stripe_payment_ledger l
                INNER JOIN events e ON e.id = l.event_id
                WHERE l.dj_user_id = ?
                  AND l.entry_type = 'payment'
                  AND l.payment_type = 'dj_tip'
                  AND l.guest_token IS NOT NULL
                  AND l.guest_token <> ''
                  AND l.occurred_at BETWEEN ? AND ?
                  AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
                  {$analyticsEventClause}
            ";
            $tipperStmt = $db->prepare($tipperSql);
            $tipperStmt->execute(array_merge([
                $djId, $fromTs, $toTs, $dateFrom, $dateTo,
            ], $selectedEventId > 0 ? [$selectedEventId] : []));
            $uniqueTippers = (int)($tipperStmt->fetchColumn() ?: 0);
        } else {
            $tipperSql = "
                SELECT COUNT(DISTINCT t.guest_token) AS unique_tippers
                FROM event_tips t
                INNER JOIN events e ON e.id = t.event_id
                WHERE e.user_id = ?
                  AND t.status = 'succeeded'
                  AND t.guest_token IS NOT NULL
                  AND t.guest_token <> ''
                  AND t.created_at BETWEEN ? AND ?
                  AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
                  {$analyticsEventClause}
            ";
            $tipperStmt = $db->prepare($tipperSql);
            $tipperStmt->execute(array_merge([
                $djId, $fromTs, $toTs, $dateFrom, $dateTo,
            ], $selectedEventId > 0 ? [$selectedEventId] : []));
            $uniqueTippers = (int)($tipperStmt->fetchColumn() ?: 0);
        }

        // Build benchmark scoring.
        $maxRequestIntensity = 0.0;
        $maxConversion = 0.0;
        $maxMood = 0.0;
        $benchmarkRaw = [];
        foreach ($eventStatsRows as $row) {
            $eventId = (int)($row['event_id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }
            $requests = (int)($row['total_requests'] ?? 0);
            $connected = (int)($row['connected_patrons'] ?? 0);
            $uniqueReq = (int)($row['unique_requesters'] ?? 0);
            $moodPos = (int)($row['mood_positive'] ?? 0);
            $moodNeg = (int)($row['mood_negative'] ?? 0);
            $moodTotal = $moodPos + $moodNeg;
            $moodPct = $moodTotal > 0 ? (($moodPos / $moodTotal) * 100.0) : 0.0;
            $engagementPct = $connected > 0 ? min(100.0, ($uniqueReq / $connected) * 100.0) : 0.0;
            $requestIntensity = $connected > 0 ? ($requests / $connected) : 0.0;

            $benchmarkRaw[$eventId] = [
                'event_id' => $eventId,
                'event_uuid' => (string)($row['uuid'] ?? ''),
                'event_title' => (string)($row['event_title'] ?? 'Untitled event'),
                'event_date' => (string)($row['event_date'] ?? ''),
                'requests' => $requests,
                'connected' => $connected,
                'engagement_pct' => $engagementPct,
                'request_intensity' => $requestIntensity,
                'tip_conversion_pct' => 0.0,
                'mood_pct' => $moodPct,
            ];

            $maxRequestIntensity = max($maxRequestIntensity, $requestIntensity);
            $maxMood = max($maxMood, $moodPct);
        }

        // Per-event tip conversions for benchmark
        if ($ledgerExists) {
            $eventTipConvSql = "
                SELECT
                    l.event_id,
                    COUNT(DISTINCT l.guest_token) AS unique_tippers
                FROM stripe_payment_ledger l
                INNER JOIN events e ON e.id = l.event_id
                WHERE l.dj_user_id = ?
                  AND l.entry_type = 'payment'
                  AND l.payment_type = 'dj_tip'
                  AND l.guest_token IS NOT NULL
                  AND l.guest_token <> ''
                  AND l.occurred_at BETWEEN ? AND ?
                  AND COALESCE(e.event_date, DATE(e.created_at)) BETWEEN ? AND ?
                  {$analyticsEventClause}
                GROUP BY l.event_id
            ";
            $eventTipConvStmt = $db->prepare($eventTipConvSql);
            $eventTipConvStmt->execute(array_merge([
                $djId, $fromTs, $toTs, $dateFrom, $dateTo,
            ], $selectedEventId > 0 ? [$selectedEventId] : []));
            foreach ($eventTipConvStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $eventId = (int)($row['event_id'] ?? 0);
                if (!isset($benchmarkRaw[$eventId])) {
                    continue;
                }
                $uniqueTippersEvent = (int)($row['unique_tippers'] ?? 0);
                $connected = max(1, (int)$benchmarkRaw[$eventId]['connected']);
                $benchmarkRaw[$eventId]['tip_conversion_pct'] = ($uniqueTippersEvent / $connected) * 100.0;
                $maxConversion = max($maxConversion, (float)$benchmarkRaw[$eventId]['tip_conversion_pct']);
            }
        }

        foreach ($benchmarkRaw as $eventId => $row) {
            $engagementNorm = min(1.0, (float)$row['engagement_pct'] / 100.0);
            $intensityNorm = $maxRequestIntensity > 0 ? ((float)$row['request_intensity'] / $maxRequestIntensity) : 0.0;
            $conversionNorm = $maxConversion > 0 ? ((float)$row['tip_conversion_pct'] / $maxConversion) : 0.0;
            $moodNorm = $maxMood > 0 ? ((float)$row['mood_pct'] / $maxMood) : 0.0;

            $score = ($engagementNorm * 0.35) + ($intensityNorm * 0.30) + ($conversionNorm * 0.20) + ($moodNorm * 0.15);
            $advancedBenchmarks[] = [
                'event_uuid' => $row['event_uuid'],
                'event_title' => $row['event_title'],
                'event_date' => $row['event_date'],
                'score' => round($score * 100),
                'engagement_pct' => round((float)$row['engagement_pct'], 1),
                'request_intensity' => round((float)$row['request_intensity'], 2),
                'tip_conversion_pct' => round((float)$row['tip_conversion_pct'], 1),
                'mood_pct' => round((float)$row['mood_pct'], 1),
            ];
        }
        usort($advancedBenchmarks, static function (array $a, array $b): int {
            return ((int)$b['score'] <=> (int)$a['score']);
        });
        $advancedBenchmarks = array_slice($advancedBenchmarks, 0, 10);

        // KPI totals
        $totalRequests = 0;
        $totalVotes = 0;
        $totalConnected = 0;
        $totalMoodPos = 0;
        $totalMoodTotal = 0;
        foreach ($eventStatsRows as $row) {
            $totalRequests += (int)($row['total_requests'] ?? 0);
            $totalConnected += (int)($row['connected_patrons'] ?? 0);
            $totalMoodPos += (int)($row['mood_positive'] ?? 0);
            $totalMoodTotal += (int)($row['mood_positive'] ?? 0) + (int)($row['mood_negative'] ?? 0);
        }
        $totalVotes = count($voteRows);

        $advancedKpis['total_requests'] = $totalRequests;
        $advancedKpis['total_votes'] = $totalVotes;
        $advancedKpis['connected_patrons'] = $totalConnected;
        $advancedKpis['unique_requesters'] = $uniqueRequesters;
        $advancedKpis['unique_tippers'] = $uniqueTippers;
        $advancedKpis['tip_request_conversion_pct'] = $uniqueRequesters > 0 ? round(($uniqueTippers / $uniqueRequesters) * 100, 1) : 0.0;
        $advancedKpis['avg_mood_pct'] = $totalMoodTotal > 0 ? round(($totalMoodPos / $totalMoodTotal) * 100, 1) : null;
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
.reports-btn { background:var(--brand-accent); color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; }
.reports-help { color:#b7b7c8; font-size:13px; margin-top:8px; }
.reports-view-links { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
.reports-view-link { display:inline-block; padding:8px 10px; border:1px solid #2a2a3a; border-radius:8px; color:#cfd0da; text-decoration:none; font-size:13px; }
.reports-view-link.active { border-color:var(--brand-accent); color:#ff8ae9; background:rgba(var(--brand-accent-rgb), 0.08); }
.reports-table-wrap { overflow-x:auto; }
.reports-table { width:100%; border-collapse:collapse; min-width:700px; }
.reports-table th, .reports-table td { text-align:left; padding:10px; border-bottom:1px solid #242432; }
.reports-table th + th,
.reports-table td + td { border-left: 1px solid #1c1d2a; }
.reports-table th { color:#d3d4e2; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
.reports-table td { color:#ececf3; }
.reports-link { color:#ff8ae9; text-decoration:none; }
.reports-link:hover { text-decoration:underline; }
.reports-grid-kpi { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:14px; }
.reports-kpi { background:#0f1018; border:1px solid #242432; border-radius:10px; padding:12px; }
.reports-kpi-label { color:#a9acc0; font-size:12px; margin-bottom:4px; }
.reports-kpi-value { color:#fff; font-size:24px; font-weight:700; line-height:1.1; }
.reports-kpi-sub { color:#8f95ad; font-size:12px; margin-top:4px; }
.reports-section-title { margin:18px 0 10px; color:#d3d4e2; font-size:15px; }
.reports-chart-card { background:#0f1018; border:1px solid #242432; border-radius:10px; padding:12px; }
.reports-chart-controls { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin:2px 0 10px; }
.reports-chart-controls label { color:#c8cade; font-size:12px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; }
.reports-bar-chart { display:flex; flex-direction:column; gap:10px; margin-top:6px; }
.reports-bar-row { display:grid; grid-template-columns:170px 1fr 56px; gap:8px; align-items:center; }
.reports-bar-label { color:#c8cade; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.reports-bar-track { height:10px; background:#1a1b28; border-radius:999px; overflow:hidden; }
.reports-bar-fill { height:100%; border-radius:999px; background:linear-gradient(90deg, var(--brand-accent), #7c6cff); }
.reports-bar-value { color:#e6e8f3; font-size:12px; text-align:right; }
.reports-table-compact { min-width:0; }
.premium-lock-note {
    border:1px solid #3d2b4d;
    background:rgba(var(--brand-accent-rgb), 0.08);
    border-radius:10px;
    padding:12px;
    color:#e8d8ef;
    margin-bottom:10px;
}
.premium-pill {
    display:inline-block;
    margin-left:6px;
    font-size:10px;
    border:1px solid #aa2c8b;
    border-radius:999px;
    padding:2px 7px;
    color:#ff8ae9;
}
@media (max-width: 900px) {
    .reports-bar-row { grid-template-columns:120px 1fr 44px; }
}
.transcript-row--guest td { background: rgba(255,255,255,0.02); }
.transcript-row--dj td { background: rgba(24,92,255,0.16); }
.transcript-row--broadcast td { background: rgba(var(--brand-accent-rgb), 0.16); }
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
    <p style="margin:0 0 8px;"><a href="/dj/dashboard.php" style="color:var(--brand-accent); text-decoration:none;">&larr; Back to Dashboard</a></p>
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
            <a class="reports-view-link <?php echo $view === 'advanced_analytics' ? 'active' : ''; ?>" href="<?php echo e(url('dj/reports.php?view=advanced_analytics&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) . '&event_id=' . (int)$selectedEventId)); ?>">Advanced Analytics <span class="premium-pill">Premium</span></a>
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
        <?php if ($view === 'advanced_analytics'): ?>
            <div class="reports-help">Analytics time windows are shown in your timezone: <?php echo e($djTimezone); ?>.</div>
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
                                <th>Platform Fee</th>
                                <th>Stripe Fee</th>
                                <th>Net to DJ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tipBoostSummary as $row): ?>
                                <?php
                                    $tipAmount = (float)($row['tip_amount'] ?? 0);
                                    $boostAmount = (float)($row['boost_amount'] ?? 0);
                                    $platformFee = (float)($row['platform_fee_amount'] ?? 0);
                                    $stripeFee = (float)($row['stripe_fee_amount'] ?? 0);
                                    $netToDj = (float)($row['net_to_dj_amount'] ?? ($tipAmount + $boostAmount));
                                ?>
                                <tr>
                                    <td><?php echo e(strtoupper((string)$row['currency'])); ?></td>
                                    <td><?php echo (int)$row['tip_count']; ?></td>
                                    <td><?php echo number_format($tipAmount, 2); ?></td>
                                    <td><?php echo (int)$row['boost_count']; ?></td>
                                    <td><?php echo number_format($boostAmount, 2); ?></td>
                                    <td><?php echo number_format($platformFee, 2); ?></td>
                                    <td><?php echo number_format($stripeFee, 2); ?></td>
                                    <td><?php echo number_format($netToDj, 2); ?></td>
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

        <?php if ($view === 'advanced_analytics'): ?>
            <?php if (!$isPremiumPlan): ?>
                <div class="premium-lock-note">
                    Advanced Analytics Pack is available on <strong>Premium</strong>. Upgrade to unlock peak request times, repeat patron insights, tip-to-request conversion, and event benchmark scoring.
                </div>
            <?php else: ?>
                <div class="reports-grid-kpi">
                    <div class="reports-kpi">
                        <div class="reports-kpi-label">Total Requests</div>
                        <div class="reports-kpi-value"><?php echo (int)$advancedKpis['total_requests']; ?></div>
                    </div>
                    <div class="reports-kpi">
                        <div class="reports-kpi-label">Total Votes</div>
                        <div class="reports-kpi-value"><?php echo (int)$advancedKpis['total_votes']; ?></div>
                    </div>
                    <div class="reports-kpi">
                        <div class="reports-kpi-label">Connected Patrons</div>
                        <div class="reports-kpi-value"><?php echo (int)$advancedKpis['connected_patrons']; ?></div>
                    </div>
                    <div class="reports-kpi">
                        <div class="reports-kpi-label">Unique Requesters</div>
                        <div class="reports-kpi-value"><?php echo (int)$advancedKpis['unique_requesters']; ?></div>
                    </div>
                    <div class="reports-kpi">
                        <div class="reports-kpi-label">Unique Tippers</div>
                        <div class="reports-kpi-value"><?php echo (int)$advancedKpis['unique_tippers']; ?></div>
                    </div>
                    <div class="reports-kpi">
                        <div class="reports-kpi-label">Tip-to-Request Conversion</div>
                        <div class="reports-kpi-value"><?php echo number_format((float)$advancedKpis['tip_request_conversion_pct'], 1); ?>%</div>
                        <div class="reports-kpi-sub">Unique tippers / unique requesters</div>
                    </div>
                    <div class="reports-kpi">
                        <div class="reports-kpi-label">Avg Mood Positivity</div>
                        <div class="reports-kpi-value">
                            <?php echo $advancedKpis['avg_mood_pct'] === null ? '—' : (number_format((float)$advancedKpis['avg_mood_pct'], 1) . '%'); ?>
                        </div>
                    </div>
                </div>

                <h3 class="reports-section-title">Peak Request Times</h3>
                <?php if (empty($advancedPeakHours)): ?>
                    <p class="reports-empty">No request timing data for this range.</p>
                <?php else: ?>
                    <?php
                        $peakMaxReq = 0;
                        $peakMaxVote = 0;
                        $peakMaxCombined = 0;
                        foreach ($advancedPeakHours as $peakRow) {
                            $peakMaxReq = max($peakMaxReq, (int)($peakRow['request_count'] ?? 0));
                            $peakMaxVote = max($peakMaxVote, (int)($peakRow['vote_count'] ?? 0));
                            $peakMaxCombined = max($peakMaxCombined, (int)($peakRow['combined_count'] ?? 0));
                        }
                        $peakMaxReq = max(1, $peakMaxReq);
                        $peakMaxVote = max(1, $peakMaxVote);
                        $peakMaxCombined = max(1, $peakMaxCombined);
                    ?>
                    <div class="reports-chart-card">
                        <div class="reports-chart-controls" role="group" aria-label="Peak chart metric">
                            <label><input type="radio" name="peak_metric" value="request_count" checked> Requests</label>
                            <label><input type="radio" name="peak_metric" value="vote_count"> Votes</label>
                            <label><input type="radio" name="peak_metric" value="combined_count"> Combined</label>
                        </div>
                        <div class="reports-bar-chart">
                            <?php foreach ($advancedPeakHours as $row): ?>
                                <?php
                                    $reqCount = (int)($row['request_count'] ?? 0);
                                    $voteCount = (int)($row['vote_count'] ?? 0);
                                    $combinedCount = (int)($row['combined_count'] ?? 0);
                                    $reqWidth = (int)round(($reqCount / $peakMaxReq) * 100);
                                    $voteWidth = (int)round(($voteCount / $peakMaxVote) * 100);
                                    $combinedWidth = (int)round(($combinedCount / $peakMaxCombined) * 100);
                                ?>
                                <div class="reports-bar-row">
                                    <div class="reports-bar-label"><?php echo e((string)$row['label']); ?></div>
                                    <div class="reports-bar-track">
                                        <div
                                            class="reports-bar-fill js-peak-fill"
                                            data-request_count="<?php echo $reqWidth; ?>"
                                            data-vote_count="<?php echo $voteWidth; ?>"
                                            data-combined_count="<?php echo $combinedWidth; ?>"
                                            style="width: <?php echo $reqWidth; ?>%;"
                                        ></div>
                                    </div>
                                    <div
                                        class="reports-bar-value js-peak-val"
                                        data-request_count="<?php echo $reqCount; ?>"
                                        data-vote_count="<?php echo $voteCount; ?>"
                                        data-combined_count="<?php echo $combinedCount; ?>"
                                    ><?php echo $reqCount; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <h3 class="reports-section-title">Event Benchmark Scoring</h3>
                <?php if (empty($advancedBenchmarks)): ?>
                    <p class="reports-empty">No benchmark data for this range.</p>
                <?php else: ?>
                    <?php
                        $benchMaxScore = 1;
                        $benchMaxEngagement = 1.0;
                        $benchMaxReqPerPatron = 1.0;
                        $benchMaxTipConv = 1.0;
                        $benchMaxMood = 1.0;
                        foreach ($advancedBenchmarks as $benchRow) {
                            $benchMaxScore = max($benchMaxScore, (int)($benchRow['score'] ?? 0));
                            $benchMaxEngagement = max($benchMaxEngagement, (float)($benchRow['engagement_pct'] ?? 0));
                            $benchMaxReqPerPatron = max($benchMaxReqPerPatron, (float)($benchRow['request_intensity'] ?? 0));
                            $benchMaxTipConv = max($benchMaxTipConv, (float)($benchRow['tip_conversion_pct'] ?? 0));
                            $benchMaxMood = max($benchMaxMood, (float)($benchRow['mood_pct'] ?? 0));
                        }
                    ?>
                    <div class="reports-chart-card" style="margin-bottom:12px;">
                        <div class="reports-chart-controls" role="group" aria-label="Benchmark chart metric">
                            <label><input type="radio" name="benchmark_metric" value="score" checked> Score</label>
                            <label><input type="radio" name="benchmark_metric" value="engagement_pct"> Engagement %</label>
                            <label><input type="radio" name="benchmark_metric" value="request_intensity"> Req / Patron</label>
                            <label><input type="radio" name="benchmark_metric" value="tip_conversion_pct"> Tip Conv %</label>
                            <label><input type="radio" name="benchmark_metric" value="mood_pct"> Mood %</label>
                        </div>
                        <div class="reports-bar-chart">
                            <?php foreach ($advancedBenchmarks as $row): ?>
                                <?php
                                    $scoreVal = (float)($row['score'] ?? 0);
                                    $engagementVal = (float)($row['engagement_pct'] ?? 0);
                                    $reqPerPatronVal = (float)($row['request_intensity'] ?? 0);
                                    $tipConvVal = (float)($row['tip_conversion_pct'] ?? 0);
                                    $moodVal = (float)($row['mood_pct'] ?? 0);

                                    $scoreWidth = (int)round(($scoreVal / max(1, $benchMaxScore)) * 100);
                                    $engagementWidth = (int)round(($engagementVal / max(0.0001, $benchMaxEngagement)) * 100);
                                    $reqPerPatronWidth = (int)round(($reqPerPatronVal / max(0.0001, $benchMaxReqPerPatron)) * 100);
                                    $tipConvWidth = (int)round(($tipConvVal / max(0.0001, $benchMaxTipConv)) * 100);
                                    $moodWidth = (int)round(($moodVal / max(0.0001, $benchMaxMood)) * 100);
                                ?>
                                <div class="reports-bar-row">
                                    <div class="reports-bar-label"><?php echo e((string)$row['event_title']); ?></div>
                                    <div class="reports-bar-track">
                                        <div
                                            class="reports-bar-fill js-bench-fill"
                                            data-score="<?php echo $scoreWidth; ?>"
                                            data-engagement_pct="<?php echo $engagementWidth; ?>"
                                            data-request_intensity="<?php echo $reqPerPatronWidth; ?>"
                                            data-tip_conversion_pct="<?php echo $tipConvWidth; ?>"
                                            data-mood_pct="<?php echo $moodWidth; ?>"
                                            style="width: <?php echo $scoreWidth; ?>%;"
                                        ></div>
                                    </div>
                                    <div
                                        class="reports-bar-value js-bench-val"
                                        data-score="<?php echo number_format($scoreVal, 0); ?>"
                                        data-engagement_pct="<?php echo number_format($engagementVal, 1); ?>%"
                                        data-request_intensity="<?php echo number_format($reqPerPatronVal, 2); ?>"
                                        data-tip_conversion_pct="<?php echo number_format($tipConvVal, 1); ?>%"
                                        data-mood_pct="<?php echo number_format($moodVal, 1); ?>%"
                                    ><?php echo number_format($scoreVal, 0); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="reports-table-wrap">
                        <table class="reports-table">
                            <thead>
                            <tr>
                                <th>Event</th>
                                <th>Date</th>
                                <th>Score</th>
                                <th>Engagement %</th>
                                <th>Req / Patron</th>
                                <th>Tip Conv %</th>
                                <th>Mood %</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($advancedBenchmarks as $row): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo e(url('dj/event_details.php?uuid=' . (string)$row['event_uuid'])); ?>" style="color:#ff78e6; text-decoration:none;">
                                            <?php echo e((string)$row['event_title']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo e((string)($row['event_date'] ?: '—')); ?></td>
                                    <td><?php echo (int)$row['score']; ?>/100</td>
                                    <td><?php echo number_format((float)$row['engagement_pct'], 1); ?>%</td>
                                    <td><?php echo number_format((float)$row['request_intensity'], 2); ?></td>
                                    <td><?php echo number_format((float)$row['tip_conversion_pct'], 1); ?>%</td>
                                    <td><?php echo number_format((float)$row['mood_pct'], 1); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <h3 class="reports-section-title" id="repeat-patron-insights">Repeat Patron Insights</h3>
                <?php if (empty($advancedRepeatPatrons)): ?>
                    <p class="reports-empty">No repeat patrons detected (2+ events) in this range.</p>
                <?php else: ?>
                    <div class="reports-table-wrap">
                        <table class="reports-table reports-table-compact">
                            <thead>
                            <tr>
                                <th>Patron</th>
                                <th>Events</th>
                                <th>Requests</th>
                                <th>Votes</th>
                                <th>Total Activity</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($advancedRepeatPatrons as $row): ?>
                                <?php
                                    $patronUrl = url('dj/reports.php?view=advanced_analytics'
                                        . '&from=' . urlencode($dateFrom)
                                        . '&to=' . urlencode($dateTo)
                                        . '&event_id=' . (int)$selectedEventId
                                        . '&repeat_patron=' . urlencode((string)$row['key']))
                                        . '#repeat-patron-insights';
                                ?>
                                <tr>
                                    <td>
                                        <a class="reports-link" href="<?php echo e($patronUrl); ?>">
                                            <?php echo e((string)$row['patron_name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo (int)$row['events_count']; ?></td>
                                    <td><?php echo (int)$row['total_requests']; ?></td>
                                    <td><?php echo (int)$row['total_votes']; ?></td>
                                    <td><?php echo (int)$row['total_activity']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($advancedSelectedRepeatPatron !== null): ?>
                    <h3 class="reports-section-title" id="repeat-patron-detail">Amalgamated Requests: <?php echo e((string)$advancedSelectedRepeatPatron['patron_name']); ?></h3>
                    <div class="reports-help" style="margin-top:0;">
                        Period total: <?php echo (int)$advancedSelectedRepeatPatron['total_requests']; ?> requests + <?php echo (int)$advancedSelectedRepeatPatron['total_votes']; ?> votes = <?php echo (int)$advancedSelectedRepeatPatron['total_activity']; ?> total activity across <?php echo (int)$advancedSelectedRepeatPatron['events_count']; ?> events.
                    </div>
                    <?php
                        $exportCsvUrl = url('dj/reports.php?view=advanced_analytics'
                            . '&from=' . urlencode($dateFrom)
                            . '&to=' . urlencode($dateTo)
                            . '&event_id=' . (int)$selectedEventId
                            . '&repeat_patron=' . urlencode((string)$advancedSelectedRepeatPatron['key'])
                            . '&format=csv');
                    ?>
                    <p style="margin:8px 0 10px;">
                        <a class="reports-link" href="<?php echo e($exportCsvUrl); ?>">Export To CSV</a>
                    </p>
                    <?php if (empty($advancedSelectedRepeatRequests)): ?>
                        <p class="reports-empty">No request rows found for this patron in the selected period.</p>
                    <?php else: ?>
                        <div class="reports-table-wrap">
                            <table class="reports-table reports-table-compact">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Track</th>
                                    <th>Artist</th>
                                    <th>Requests</th>
                                    <th>Votes</th>
                                    <th>Popularity</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($advancedSelectedRepeatRequests as $i => $req): ?>
                                    <tr>
                                        <td><?php echo (int)$i + 1; ?></td>
                                        <td><?php echo e((string)$req['track_title']); ?></td>
                                        <td><?php echo e((string)$req['artist_name']); ?></td>
                                        <td><?php echo (int)$req['request_count']; ?></td>
                                        <td><?php echo (int)$req['vote_count']; ?></td>
                                        <td><?php echo (int)$req['popularity']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const radios = Array.from(document.querySelectorAll('input[name="peak_metric"]'));
    if (!radios.length) return;
    const fills = Array.from(document.querySelectorAll('.js-peak-fill'));
    const values = Array.from(document.querySelectorAll('.js-peak-val'));
    const applyMetric = (metric) => {
        fills.forEach((el) => {
            const w = parseInt(el.getAttribute('data-' + metric) || '0', 10);
            el.style.width = String(Math.max(0, Math.min(100, w))) + '%';
        });
        values.forEach((el) => {
            el.textContent = String(parseInt(el.getAttribute('data-' + metric) || '0', 10));
        });
    };
    radios.forEach((r) => r.addEventListener('change', () => {
        if (r.checked) applyMetric(r.value);
    }));
    const selected = radios.find((r) => r.checked)?.value || 'request_count';
    applyMetric(selected);
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const radios = Array.from(document.querySelectorAll('input[name="benchmark_metric"]'));
    if (!radios.length) return;
    const fills = Array.from(document.querySelectorAll('.js-bench-fill'));
    const values = Array.from(document.querySelectorAll('.js-bench-val'));
    const applyMetric = (metric) => {
        fills.forEach((el) => {
            const w = parseInt(el.getAttribute('data-' + metric) || '0', 10);
            el.style.width = String(Math.max(0, Math.min(100, w))) + '%';
        });
        values.forEach((el) => {
            el.textContent = String(el.getAttribute('data-' + metric) || '0');
        });
    };
    radios.forEach((r) => r.addEventListener('change', () => {
        if (r.checked) applyMetric(r.value);
    }));
    const selected = radios.find((r) => r.checked)?.value || 'score';
    applyMetric(selected);
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
