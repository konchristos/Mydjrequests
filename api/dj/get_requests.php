<?php
// api/dj/get_requests.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';

$db = db();

$eventUuid = $_GET['event'] ?? '';
if (!$eventUuid) {
    echo json_encode(['ok' => false, 'error' => 'Missing event']);
    exit;
}

$stmt = $db->prepare("\n  SELECT id\n  FROM events\n  WHERE uuid = ?\n    AND user_id = ?\n  LIMIT 1\n");
$stmt->execute([$eventUuid, $_SESSION['dj_id']]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$eventId = (int)$row['id'];

// BPM display in DJ view is independent from queue-fuzzy toggle.
// Queue toggle controls ingestion only (submit_song + worker), not display.
$allowBpmMeta = bpmCurrentUserHasAccess($db);

$sql = "
SELECT
  r_group.track_key,
  r_group.track_identity_id,
  r_group.spotify_track_id,
  r_group.song_title,
  r_group.artist,
  r_group.album_art,
  r_group.request_count,
  r_group.last_requested_at,
  r_group.requester_data,
  r_group.track_status,
  r_group.dj_track_id,

  COALESCE(v.vote_count, 0) AS vote_count,
  v.voter_data,

  COALESCE(b.boost_count, 0) AS boost_count,
  b.booster_data,

  (r_group.request_count + COALESCE(v.vote_count, 0)) AS popularity

FROM (
    SELECT
      e.track_identity_id,
      COALESCE(
        NULLIF(sr.spotify_track_id, ''),
        NULLIF(s.spotify_track_id, ''),
        CONCAT(
          COALESCE(NULLIF(sr.song_title, ''), NULLIF(s.track_name, ''), 'Unknown title'),
          '::',
          COALESCE(NULLIF(sr.artist, ''), NULLIF(s.artist_name, ''), 'Unknown artist')
        )
      ) AS track_key,
      COALESCE(NULLIF(sr.spotify_track_id, ''), NULLIF(s.spotify_track_id, ''), '') AS spotify_track_id,
      COALESCE(NULLIF(s.track_name, ''), NULLIF(sr.song_title, ''), 'Unknown title') AS song_title,
      COALESCE(NULLIF(s.artist_name, ''), NULLIF(sr.artist, ''), 'Unknown artist') AS artist,
      COALESCE(NULLIF(s.album_art_url, ''), NULLIF(sr.album_art, '')) AS album_art,
      COALESCE(s.bpm, NULL) AS cache_bpm,
      COALESCE(s.musical_key, '') AS cache_musical_key,
      COALESCE(s.release_year, 0) AS release_year,
      e.request_count,
      e.last_requested_at,
      sr.requester_data,
      COALESCE(sr.track_status, 'active') AS track_status,
      d.id AS dj_track_id
    FROM event_tracks e
    LEFT JOIN spotify_tracks s
      ON s.track_identity_id = e.track_identity_id
    LEFT JOIN dj_tracks d
      ON d.track_identity_id = e.track_identity_id
     AND d.dj_id = ?
    LEFT JOIN (
      SELECT
        track_identity_id,
        MAX(NULLIF(spotify_track_id, '')) AS spotify_track_id,
        MAX(song_title) AS song_title,
        MAX(artist) AS artist,
        MAX(spotify_album_art_url) AS album_art,
        GROUP_CONCAT(
          DISTINCT CONCAT(requester_name, '::', created_at)
          ORDER BY created_at DESC
          SEPARATOR '||'
        ) AS requester_data,
        CASE
          WHEN SUM(status = 'played') > 0 THEN 'played'
          WHEN SUM(status = 'skipped') = COUNT(*) THEN 'skipped'
          ELSE 'active'
        END AS track_status
      FROM song_requests
      WHERE event_id = ?
        AND track_identity_id IS NOT NULL
      GROUP BY track_identity_id
    ) sr ON sr.track_identity_id = e.track_identity_id
    WHERE e.event_id = ?
) r_group

LEFT JOIN (
    SELECT
      track_key,
      COUNT(*) AS vote_count,
      GROUP_CONCAT(
        DISTINCT CONCAT(patron_name, '::', created_at)
        ORDER BY created_at DESC
        SEPARATOR '||'
      ) AS voter_data
    FROM song_votes
    WHERE event_id = ?
    GROUP BY track_key
) v ON v.track_key = r_group.track_key

LEFT JOIN (
    SELECT
      track_key,
      COUNT(*) AS boost_count,
      GROUP_CONCAT(
        DISTINCT CONCAT(patron_name, '::', created_at)
        ORDER BY created_at DESC
        SEPARATOR '||'
      ) AS booster_data
    FROM event_track_boosts
    WHERE event_id = ?
      AND status = 'succeeded'
    GROUP BY track_key
) b ON b.track_key = r_group.track_key

ORDER BY popularity DESC, r_group.last_requested_at DESC
";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$_SESSION['dj_id'], $eventId, $eventId, $eventId, $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Fast BPM/Key enrichment (NO fuzzy)
|--------------------------------------------------------------------------
| Uses pre-linked tracks only via track_links + bpm_test_tracks.
| If no link exists, BPM/Key remains null.
*/
$bpmMap = [];
$spotifyMetaMap = [];
$spotifyIds = $allowBpmMeta ? array_values(array_filter(array_unique(array_map(
    static fn(array $r): string => (string)($r['spotify_track_id'] ?? ''),
    $rows
)), static fn(string $id): bool => $id !== '')) : [];

if (!empty($spotifyIds)) {
    try {
        // 1) Read directly from spotify cache (fast path)
        $inCache = implode(',', array_fill(0, count($spotifyIds), '?'));
        $cacheSql = "
            SELECT
              spotify_track_id,
              bpm,
              musical_key,
              release_year
            FROM spotify_tracks
            WHERE spotify_track_id IN ($inCache)
        ";
        $cacheStmt = $db->prepare($cacheSql);
        $cacheStmt->execute($spotifyIds);
        foreach ($cacheStmt->fetchAll(PDO::FETCH_ASSOC) as $cacheRow) {
            $sid = (string)($cacheRow['spotify_track_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $spotifyMetaMap[$sid] = [
                'bpm' => (isset($cacheRow['bpm']) && is_numeric($cacheRow['bpm']) && (float)$cacheRow['bpm'] > 0)
                    ? (float)$cacheRow['bpm']
                    : null,
                'musical_key' => trim((string)($cacheRow['musical_key'] ?? '')),
                'release_year' => (isset($cacheRow['release_year']) && is_numeric($cacheRow['release_year']) && (int)$cacheRow['release_year'] > 0)
                    ? (int)$cacheRow['release_year']
                    : null,
            ];
        }

        // 2) Optional linked enrichment (used mostly for key/year fallback)
        $in = implode(',', array_fill(0, count($spotifyIds), '?'));
        $bpmSql = "
            SELECT
              tl.spotify_track_id,
              MAX(bt.bpm) AS bpm,
              MAX(bt.key_text) AS musical_key,
              MAX(bt.year) AS matched_year
            FROM track_links tl
            INNER JOIN bpm_test_tracks bt ON bt.id = tl.bpm_track_id
            WHERE tl.spotify_track_id IN ($in)
            GROUP BY tl.spotify_track_id
        ";

        $bpmStmt = $db->prepare($bpmSql);
        $bpmStmt->execute($spotifyIds);

        foreach ($bpmStmt->fetchAll(PDO::FETCH_ASSOC) as $bpmRow) {
            $sid = (string)($bpmRow['spotify_track_id'] ?? '');
            if ($sid === '') {
                continue;
            }

            $bpmMap[$sid] = [
                'bpm' => isset($bpmRow['bpm']) ? (float)$bpmRow['bpm'] : null,
                'musical_key' => trim((string)($bpmRow['musical_key'] ?? '')),
                'release_year' => isset($bpmRow['matched_year']) && is_numeric($bpmRow['matched_year'])
                    ? (int)$bpmRow['matched_year']
                    : null,
            ];
        }
    } catch (Throwable $e) {
        $bpmMap = [];
    }
}

foreach ($rows as &$row) {
    $row['bpm'] = null;
    $row['musical_key'] = null;
    $row['release_year'] = $allowBpmMeta && isset($row['release_year']) && is_numeric($row['release_year'])
        ? (int)$row['release_year']
        : null;

    $sid = (string)($row['spotify_track_id'] ?? '');
    if ($sid !== '' && isset($spotifyMetaMap[$sid])) {
        if ($spotifyMetaMap[$sid]['bpm']) {
            $row['bpm'] = $spotifyMetaMap[$sid]['bpm'];
        }
        if (!empty($spotifyMetaMap[$sid]['musical_key'])) {
            $row['musical_key'] = $spotifyMetaMap[$sid]['musical_key'];
        }
        if (!$row['release_year'] && !empty($spotifyMetaMap[$sid]['release_year'])) {
            $row['release_year'] = (int)$spotifyMetaMap[$sid]['release_year'];
        }
    }

    if ($sid !== '' && isset($bpmMap[$sid])) {
        if (!$row['bpm'] && !empty($bpmMap[$sid]['bpm'])) {
            $row['bpm'] = $bpmMap[$sid]['bpm'];
        }
        if (!$row['musical_key'] && !empty($bpmMap[$sid]['musical_key'])) {
            $row['musical_key'] = $bpmMap[$sid]['musical_key'];
        }
        if (!$row['release_year'] && !empty($bpmMap[$sid]['release_year'])) {
            $row['release_year'] = (int)$bpmMap[$sid]['release_year'];
        }
    }

    $row['requesters'] = [];
    if (!empty($row['requester_data'])) {
        foreach (explode('||', $row['requester_data']) as $item) {
            [$name, $ts] = array_pad(explode('::', $item, 2), 2, null);
            $row['requesters'][] = [
                'name' => trim($name),
                'created_at' => $ts
            ];
        }
    }

    $row['voters'] = [];
    if (!empty($row['voter_data'])) {
        foreach (explode('||', $row['voter_data']) as $item) {
            [$name, $ts] = array_pad(explode('::', $item, 2), 2, null);
            $row['voters'][] = [
                'name' => trim($name),
                'voted_at' => $ts
            ];
        }
    }

    $row['boosters'] = [];
    if (!empty($row['booster_data'])) {
        foreach (explode('||', $row['booster_data']) as $item) {
            [$name, $ts] = array_pad(explode('::', $item, 2), 2, null);
            $row['boosters'][] = [
                'name' => trim($name),
                'created_at' => $ts
            ];
        }
    }

    unset(
        $row['requester_data'],
        $row['voter_data'],
        $row['booster_data'],
        $row['cache_bpm'],
        $row['cache_musical_key']
    );
}
unset($row);

echo json_encode([
    'ok'   => true,
    'rows' => $rows
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
