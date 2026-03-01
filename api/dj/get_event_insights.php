<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/ip_geo.php';
require_dj_login();

header('Content-Type: application/json; charset=utf-8');

$db = db();
$eventUuid = trim((string)($_GET['event_uuid'] ?? ''));

if ($eventUuid === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing event_uuid']);
    exit;
}

$eventStmt = $db->prepare('
    SELECT id
    FROM events
    WHERE uuid = ? AND user_id = ?
    LIMIT 1
');
$eventStmt->execute([$eventUuid, (int)($_SESSION['dj_id'] ?? 0)]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$eventId = (int)$event['id'];

function tableHasColumn(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare('
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ');
        $stmt->execute([$table, $column]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$connectedGuests = [];
$connectedPatrons = 0;
$connectedTokens = [];
$connectedSource = 'page_views';

try {
    $hasCountryCodeCol = tableHasColumn($db, 'event_page_views', 'country_code');
    $hasIpAddressCol = tableHasColumn($db, 'event_page_views', 'ip_address');
    $countrySelect = $hasCountryCodeCol
        ? "MAX(NULLIF(UPPER(TRIM(epv.country_code)), '')) AS country_code"
        : "NULL AS country_code";
    $ipSelect = $hasIpAddressCol
        ? "MAX(NULLIF(TRIM(epv.ip_address), '')) AS ip_address"
        : "NULL AS ip_address";

    $connectedGuestsStmt = $db->prepare("
        SELECT
            epv.guest_token,
            MAX(epv.last_seen_at) AS last_seen_at,
            {$ipSelect},
            {$countrySelect}
        FROM event_page_views epv
        WHERE epv.event_id = ?
          AND epv.guest_token IS NOT NULL
          AND epv.guest_token <> ''
        GROUP BY epv.guest_token
        ORDER BY MAX(epv.last_seen_at) DESC
        LIMIT 200
    ");
    $connectedGuestsStmt->execute([$eventId]);
    $connectedGuests = $connectedGuestsStmt->fetchAll(PDO::FETCH_ASSOC);

    $countryUpdateStmt = null;
    if ($hasCountryCodeCol) {
        $countryUpdateStmt = $db->prepare('
            UPDATE event_page_views
            SET country_code = :country_code
            WHERE event_id = :event_id
              AND guest_token = :guest_token
              AND (country_code IS NULL OR country_code = "")
        ');
    }
    $lookupBudget = 3;

    foreach ($connectedGuests as &$cg) {
        $token = (string)($cg['guest_token'] ?? '');
        if ($token === '') {
            continue;
        }
        $connectedTokens[$token] = true;
        $cg['patron_name'] = 'Guest';
        $cc = strtoupper(trim((string)($cg['country_code'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $cc)) {
            $cc = '';
        }

        if ($cc === '' && $lookupBudget > 0 && $hasCountryCodeCol && $hasIpAddressCol) {
            $ip = trim((string)($cg['ip_address'] ?? ''));
            if ($ip !== '') {
                $resolved = mdjr_ip_country_code($ip);
                if (is_string($resolved) && preg_match('/^[A-Z]{2}$/', strtoupper($resolved))) {
                    $cc = strtoupper($resolved);
                    if ($countryUpdateStmt) {
                        try {
                            $countryUpdateStmt->execute([
                                ':country_code' => $cc,
                                ':event_id' => $eventId,
                                ':guest_token' => $token,
                            ]);
                        } catch (Throwable $e) {
                            // no-op: keep response country even if persistence fails
                        }
                    }
                }
                $lookupBudget--;
            }
        }

        $cg['country_code'] = ($cc !== '' ? $cc : null);
        unset($cg['ip_address']);
    }
    unset($cg);

    $connectedPatrons = count($connectedTokens);
} catch (Throwable $e) {
    // Fallback: if event_page_views schema differs/missing, derive from activity.
    $connectedSource = 'activity';
    $connectedGuests = [];
    $connectedPatrons = 0;
    $connectedTokens = [];
}

$patrons = [];

$requestStmt = $db->prepare('
    SELECT
        guest_token,
        MAX(NULLIF(TRIM(requester_name), "")) AS patron_name,
        COUNT(*) AS request_count
    FROM song_requests
    WHERE event_id = ?
      AND guest_token IS NOT NULL
      AND guest_token <> ""
    GROUP BY guest_token
');
$requestStmt->execute([$eventId]);
foreach ($requestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $token = (string)$row['guest_token'];
    $patrons[$token] = [
        'guest_token' => $token,
        'patron_name' => (string)($row['patron_name'] ?? ''),
        'request_count' => (int)$row['request_count'],
        'vote_count' => 0,
    ];
}

$voteStmt = $db->prepare('
    SELECT
        guest_token,
        MAX(NULLIF(TRIM(patron_name), "")) AS patron_name,
        COUNT(*) AS vote_count
    FROM song_votes
    WHERE event_id = ?
      AND guest_token IS NOT NULL
      AND guest_token <> ""
    GROUP BY guest_token
');
$voteStmt->execute([$eventId]);
foreach ($voteStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $token = (string)$row['guest_token'];

    if (!isset($patrons[$token])) {
        $patrons[$token] = [
            'guest_token' => $token,
            'patron_name' => (string)($row['patron_name'] ?? ''),
            'request_count' => 0,
            'vote_count' => 0,
        ];
    }

    if (!empty($row['patron_name'])) {
        $patrons[$token]['patron_name'] = (string)$row['patron_name'];
    }

    $patrons[$token]['vote_count'] = (int)$row['vote_count'];
}

$messageStmt = $db->prepare('
    SELECT
        guest_token,
        MAX(NULLIF(TRIM(patron_name), "")) AS patron_name
    FROM messages
    WHERE event_id = ?
      AND guest_token IS NOT NULL
      AND guest_token <> ""
    GROUP BY guest_token
');
$messageStmt->execute([$eventId]);
foreach ($messageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $token = (string)$row['guest_token'];

    if (!isset($patrons[$token])) {
        $patrons[$token] = [
            'guest_token' => $token,
            'patron_name' => (string)($row['patron_name'] ?? ''),
            'request_count' => 0,
            'vote_count' => 0,
        ];
        continue;
    }

    if (!empty($row['patron_name'])) {
        $patrons[$token]['patron_name'] = (string)$row['patron_name'];
    }
}

$moodStmt = $db->prepare('
    SELECT
        guest_token,
        MAX(NULLIF(TRIM(patron_name), "")) AS patron_name
    FROM event_moods
    WHERE event_id = ?
      AND guest_token IS NOT NULL
      AND guest_token <> ""
    GROUP BY guest_token
');
$moodStmt->execute([$eventId]);
foreach ($moodStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $token = (string)$row['guest_token'];

    if (!isset($patrons[$token])) {
        $patrons[$token] = [
            'guest_token' => $token,
            'patron_name' => (string)($row['patron_name'] ?? ''),
            'request_count' => 0,
            'vote_count' => 0,
        ];
        continue;
    }

    if (!empty($row['patron_name'])) {
        $patrons[$token]['patron_name'] = (string)$row['patron_name'];
    }
}

// Resolve the latest non-empty name per guest across all sources.
$latestNamesByToken = [];
try {
    $nameTimelineStmt = $db->prepare('
        SELECT guest_token, patron_name, seen_at FROM (
            SELECT
                guest_token,
                NULLIF(TRIM(requester_name), "") AS patron_name,
                created_at AS seen_at
            FROM song_requests
            WHERE event_id = ?
              AND guest_token IS NOT NULL
              AND guest_token <> ""

            UNION ALL

            SELECT
                guest_token,
                NULLIF(TRIM(patron_name), "") AS patron_name,
                created_at AS seen_at
            FROM song_votes
            WHERE event_id = ?
              AND guest_token IS NOT NULL
              AND guest_token <> ""

            UNION ALL

            SELECT
                guest_token,
                NULLIF(TRIM(patron_name), "") AS patron_name,
                created_at AS seen_at
            FROM messages
            WHERE event_id = ?
              AND guest_token IS NOT NULL
              AND guest_token <> ""

            UNION ALL

            SELECT
                guest_token,
                NULLIF(TRIM(patron_name), "") AS patron_name,
                COALESCE(updated_at, created_at) AS seen_at
            FROM event_moods
            WHERE event_id = ?
              AND guest_token IS NOT NULL
              AND guest_token <> ""
        ) t
        WHERE patron_name IS NOT NULL
    ');
    $nameTimelineStmt->execute([$eventId, $eventId, $eventId, $eventId]);

    foreach ($nameTimelineStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $token = (string)($row['guest_token'] ?? '');
        $name = trim((string)($row['patron_name'] ?? ''));
        $seenAtRaw = (string)($row['seen_at'] ?? '');
        $seenAt = $seenAtRaw !== '' ? (int)(strtotime($seenAtRaw) ?: 0) : 0;

        if ($token === '' || $name === '') {
            continue;
        }

        if (!isset($latestNamesByToken[$token]) || $seenAt >= (int)$latestNamesByToken[$token]['seen_at']) {
            $latestNamesByToken[$token] = [
                'name' => $name,
                'seen_at' => $seenAt,
            ];
        }
    }
} catch (Throwable $e) {
    $latestNamesByToken = [];
}

$activePatronsAll = 0;
$activeConnectedPatrons = 0;
$totalRequests = 0;
$totalVotes = 0;

foreach ($patrons as &$patron) {
    $token = (string)($patron['guest_token'] ?? '');
    if ($token !== '' && isset($latestNamesByToken[$token])) {
        $patron['patron_name'] = (string)$latestNamesByToken[$token]['name'];
    }

    $patron['patron_name'] = $patron['patron_name'] !== '' ? $patron['patron_name'] : 'Guest';
    $patron['total_actions'] = $patron['request_count'] + $patron['vote_count'];

    $totalRequests += $patron['request_count'];
    $totalVotes += $patron['vote_count'];

    if ($patron['total_actions'] > 0) {
        $activePatronsAll++;
        if (isset($connectedTokens[(string)$patron['guest_token']])) {
            $activeConnectedPatrons++;
        }
    }
}
unset($patron);

if ($connectedSource === 'activity' || $connectedPatrons === 0) {
    $connectedTokens = [];
    $connectedGuests = [];

    foreach ($patrons as $patron) {
        $token = (string)($patron['guest_token'] ?? '');
        if ($token === '') {
            continue;
        }
        $connectedTokens[$token] = true;
        $connectedGuests[] = [
            'guest_token' => $token,
            'patron_name' => (string)($patron['patron_name'] ?? 'Guest'),
            'last_seen_at' => null,
            'country_code' => null,
        ];
    }

    $connectedPatrons = count($connectedTokens);
}

// Recalculate active connected patrons against final connected token set.
$activeConnectedPatrons = 0;
foreach ($patrons as $patron) {
    if (($patron['total_actions'] ?? 0) > 0 && isset($connectedTokens[(string)$patron['guest_token']])) {
        $activeConnectedPatrons++;
    }
}

// Fill patron names for connected guests where possible.
if (!empty($connectedGuests)) {
    foreach ($connectedGuests as &$cg) {
        $token = (string)($cg['guest_token'] ?? '');
        if ($token !== '' && isset($latestNamesByToken[$token])) {
            $cg['patron_name'] = (string)$latestNamesByToken[$token]['name'];
        } elseif ($token !== '' && isset($patrons[$token]) && !empty($patrons[$token]['patron_name'])) {
            $cg['patron_name'] = (string)$patrons[$token]['patron_name'];
        } elseif (empty($cg['patron_name'])) {
            $cg['patron_name'] = 'Guest';
        }
    }
    unset($cg);
}

$engagementRate = $connectedPatrons > 0
    ? round(($activeConnectedPatrons / $connectedPatrons) * 100, 1)
    : 0.0;

$topPatrons = array_values($patrons);
usort($topPatrons, static function (array $a, array $b): int {
    if ($a['total_actions'] !== $b['total_actions']) {
        return $b['total_actions'] <=> $a['total_actions'];
    }
    if ($a['request_count'] !== $b['request_count']) {
        return $b['request_count'] <=> $a['request_count'];
    }
    if ($a['vote_count'] !== $b['vote_count']) {
        return $b['vote_count'] <=> $a['vote_count'];
    }
    return strcasecmp($a['patron_name'], $b['patron_name']);
});

// Top Patrons modal should only include guests with actual request/vote activity.
$topPatrons = array_values(array_filter($topPatrons, static function (array $p): bool {
    return ((int)($p['total_actions'] ?? 0)) > 0;
}));

echo json_encode([
    'ok' => true,
    'connected_patrons' => $connectedPatrons,
    'active_patrons' => $activeConnectedPatrons,
    'active_patrons_all' => $activePatronsAll,
    'engagement_rate' => $engagementRate,
    'total_requests' => $totalRequests,
    'total_votes' => $totalVotes,
    'top_patrons' => $topPatrons,
    'connected_guests' => $connectedGuests,
], JSON_UNESCAPED_UNICODE);
