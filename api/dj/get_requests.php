<?php
// api/dj/get_requests.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';

$db = db();
ensureDjOwnedTrackOverridesTable($db);
ensureDjEventTrackOverridesTable($db);
ensureDjGlobalTrackOverridesTable($db);

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
    -- Projection path (preferred): event_tracks
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

    UNION ALL

    -- Compatibility fallback: legacy requests not yet projected
    SELECT
      NULL AS track_identity_id,
      COALESCE(
        NULLIF(r.spotify_track_id, ''),
        CONCAT(r.song_title, '::', r.artist)
      ) AS track_key,
      MAX(NULLIF(r.spotify_track_id, '')) AS spotify_track_id,
      COALESCE(MAX(st.track_name), MAX(r.song_title)) AS song_title,
      COALESCE(MAX(st.artist_name), MAX(r.artist)) AS artist,
      COALESCE(MAX(st.album_art_url), MAX(r.spotify_album_art_url)) AS album_art,
      MAX(st.bpm) AS cache_bpm,
      MAX(st.musical_key) AS cache_musical_key,
      COALESCE(MAX(st.release_year), 0) AS release_year,
      COUNT(*) AS request_count,
      MAX(r.created_at) AS last_requested_at,
      GROUP_CONCAT(
        DISTINCT CONCAT(r.requester_name, '::', r.created_at)
        ORDER BY r.created_at DESC
        SEPARATOR '||'
      ) AS requester_data,
      CASE
        WHEN SUM(r.status = 'played') > 0 THEN 'played'
        WHEN SUM(r.status = 'skipped') = COUNT(*) THEN 'skipped'
        ELSE 'active'
      END AS track_status,
      NULL AS dj_track_id
    FROM song_requests r
    LEFT JOIN spotify_tracks st
      ON st.spotify_track_id = NULLIF(r.spotify_track_id, '')
    WHERE r.event_id = ?
      AND (
        r.track_identity_id IS NULL
        OR NOT EXISTS (
            SELECT 1
            FROM event_tracks et
            WHERE et.event_id = r.event_id
              AND et.track_identity_id = r.track_identity_id
        )
      )
    GROUP BY
      COALESCE(
        NULLIF(r.spotify_track_id, ''),
        CONCAT(r.song_title, '::', r.artist)
      ),
      COALESCE(st.track_name, r.song_title),
      COALESCE(st.artist_name, r.artist)
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
    $stmt->execute([
        (int)$_SESSION['dj_id'],
        $eventId,
        $eventId,
        $eventId,
        $eventId,
        $eventId
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

// DJ/event scoped manual track overrides (sticky and DJ-specific).
$djId = (int)($_SESSION['dj_id'] ?? 0);
$eventOverrideMap = [];
$eventOverrideTitleMap = [];
$globalOverrideMap = [];
$globalOverrideTitleMap = [];
if ($djId > 0 && !empty($rows)) {
    try {
        $ovStmt = $db->prepare("
            SELECT override_key, bpm_track_id, dj_track_id, bpm, musical_key, release_year, manual_owned, manual_preferred
            FROM dj_event_track_overrides
            WHERE dj_id = ?
              AND event_id = ?
        ");
        $ovStmt->execute([$djId, $eventId]);
        foreach ($ovStmt->fetchAll(PDO::FETCH_ASSOC) as $ov) {
            $k = trim((string)($ov['override_key'] ?? ''));
            if ($k === '') {
                continue;
            }
            $eventOverrideMap[$k] = $ov;

            $titleCore = mdjr_override_title_from_key($k);
            if ($titleCore !== '' && !isset($eventOverrideTitleMap[$titleCore])) {
                $eventOverrideTitleMap[$titleCore] = $ov;
            }
        }
    } catch (Throwable $e) {
        // Non-blocking.
    }

    try {
        $govStmt = $db->prepare("
            SELECT override_key, bpm_track_id, dj_track_id, bpm, musical_key, release_year, manual_owned, manual_preferred
            FROM dj_global_track_overrides
            WHERE dj_id = ?
        ");
        $govStmt->execute([$djId]);
        foreach ($govStmt->fetchAll(PDO::FETCH_ASSOC) as $gov) {
            $k = trim((string)($gov['override_key'] ?? ''));
            if ($k === '') {
                continue;
            }
            $globalOverrideMap[$k] = $gov;

            $titleCore = mdjr_override_title_from_key($k);
            if ($titleCore !== '' && !isset($globalOverrideTitleMap[$titleCore])) {
                $globalOverrideTitleMap[$titleCore] = $gov;
            }
        }
    } catch (Throwable $e) {
        // Non-blocking.
    }

    // Legacy fallback: if a global key is missing, reuse the latest known
    // event-level override from any event for this DJ.
    try {
        $legacyStmt = $db->prepare("
            SELECT override_key, bpm_track_id, dj_track_id, bpm, musical_key, release_year, manual_owned, manual_preferred
            FROM dj_event_track_overrides
            WHERE dj_id = ?
            ORDER BY updated_at DESC, id DESC
        ");
        $legacyStmt->execute([$djId]);
        foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $legacy) {
            $k = trim((string)($legacy['override_key'] ?? ''));
            if ($k === '' || isset($globalOverrideMap[$k])) {
                continue;
            }
            $globalOverrideMap[$k] = $legacy;

            $titleCore = mdjr_override_title_from_key($k);
            if ($titleCore !== '' && !isset($globalOverrideTitleMap[$titleCore])) {
                $globalOverrideTitleMap[$titleCore] = $legacy;
            }
        }
    } catch (Throwable $e) {
        // Non-blocking.
    }
}

$availableExactOverrideDjTrackIds = [];
if ($djId > 0) {
    $overrideDjTrackIds = [];
    foreach ([$eventOverrideMap, $globalOverrideMap] as $map) {
        foreach ($map as $ov) {
            $exactId = isset($ov['dj_track_id']) && is_numeric($ov['dj_track_id']) ? (int)$ov['dj_track_id'] : 0;
            if ($exactId > 0) {
                $overrideDjTrackIds[$exactId] = true;
            }
        }
    }
    if (!empty($overrideDjTrackIds)) {
        try {
            $ids = array_keys($overrideDjTrackIds);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $availStmt = $db->prepare("
                SELECT id
                FROM dj_tracks
                WHERE dj_id = ?
                  AND COALESCE(is_available, 1) = 1
                  AND id IN ($in)
            ");
            $availStmt->execute(array_merge([$djId], $ids));
            foreach ($availStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $availableExactOverrideDjTrackIds[(int)$id] = true;
            }
        } catch (Throwable $e) {
            $availableExactOverrideDjTrackIds = [];
        }
    }
}

// Manual ownership overrides: if admin confirmed ownership via manual match,
// trust that marker for this DJ/spotify_track_id.
if ($djId > 0 && !empty($rows)) {
    try {
        $spotifyIds = [];
        foreach ($rows as $r) {
            $sid = trim((string)($r['spotify_track_id'] ?? ''));
            if ($sid !== '') {
                $spotifyIds[$sid] = true;
            }
        }

        $ownedSpotify = [];
        if (!empty($spotifyIds)) {
            $ids = array_keys($spotifyIds);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ownSql = "
                SELECT spotify_track_id
                FROM dj_owned_track_overrides
                WHERE dj_id = ?
                  AND spotify_track_id IN ($in)
            ";
            $ownStmt = $db->prepare($ownSql);
            $ownStmt->execute(array_merge([$djId], $ids));
            foreach ($ownStmt->fetchAll(PDO::FETCH_ASSOC) as $orow) {
                $sid = trim((string)($orow['spotify_track_id'] ?? ''));
                if ($sid !== '') {
                    $ownedSpotify[$sid] = true;
                }
            }
        }

        foreach ($rows as $idx => $r) {
            $sid = trim((string)($r['spotify_track_id'] ?? ''));
            $rows[$idx]['manual_owned'] = ($sid !== '' && isset($ownedSpotify[$sid])) ? 1 : 0;
        }
    } catch (Throwable $e) {
        foreach ($rows as $idx => $r) {
            $rows[$idx]['manual_owned'] = 0;
        }
    }
}

// Ownership fallback: when track_identity_id providers differ (spotify vs manual),
// mark ownership by normalized title/artist hash against dj_tracks.normalized_hash.
if ($djId > 0 && !empty($rows) && function_exists('trackIdentityNormalisedHash')) {
    $needFallback = [];
    $hashToRowIdx = [];

    foreach ($rows as $idx => $r) {
        $hasDirect = (isset($r['manual_owned']) && (int)$r['manual_owned'] === 1)
            || (isset($r['dj_track_id']) && is_numeric($r['dj_track_id']) && (int)$r['dj_track_id'] > 0);
        if ($hasDirect) {
            continue;
        }

        $hash = trackIdentityNormalisedHash(
            (string)($r['song_title'] ?? ''),
            (string)($r['artist'] ?? '')
        );
        if ($hash === null || $hash === '') {
            continue;
        }

        $needFallback[$hash] = true;
        if (!isset($hashToRowIdx[$hash])) {
            $hashToRowIdx[$hash] = [];
        }
        $hashToRowIdx[$hash][] = $idx;
    }

    if (!empty($needFallback)) {
        try {
            $hashes = array_keys($needFallback);
            $in = implode(',', array_fill(0, count($hashes), '?'));
            $ownSql = "
                SELECT id, normalized_hash
                FROM dj_tracks
                WHERE dj_id = ?
                  AND normalized_hash IN ($in)
            ";
            $ownStmt = $db->prepare($ownSql);
            $ownStmt->execute(array_merge([$djId], $hashes));

            $ownedMap = [];
            foreach ($ownStmt->fetchAll(PDO::FETCH_ASSOC) as $orow) {
                $h = (string)($orow['normalized_hash'] ?? '');
                if ($h !== '' && !isset($ownedMap[$h])) {
                    $ownedMap[$h] = (int)($orow['id'] ?? 0);
                }
            }

            foreach ($ownedMap as $hash => $ownedId) {
                if ($ownedId <= 0 || empty($hashToRowIdx[$hash])) {
                    continue;
                }
                foreach ($hashToRowIdx[$hash] as $idx) {
                    $rows[$idx]['dj_track_id'] = $ownedId;
                }
            }
        } catch (Throwable $e) {
            // Ignore ownership fallback failures; keep base response.
        }
    }

    // NOTE: Broad second-pass ownership heuristics were removed because they
    // produced false positives (one owned track marking unrelated requests owned).
}

// Controlled fallback: for unresolved rows only, match by same artist plus a
// normalized core title/artist hash (version/remaster/mix text removed).
// This keeps "I own another version" behavior without broad cross-artist drift.
if ($djId > 0 && !empty($rows)) {
    $unresolved = [];
    $artistSeeds = [];

    foreach ($rows as $idx => $r) {
        $isOwned = (isset($r['manual_owned']) && (int)$r['manual_owned'] === 1)
            || (isset($r['dj_track_id']) && is_numeric($r['dj_track_id']) && (int)$r['dj_track_id'] > 0);
        if ($isOwned) {
            continue;
        }

        $artist = trim((string)($r['artist'] ?? ''));
        $coreHash = mdjr_relaxed_track_hash(
            (string)($r['song_title'] ?? ''),
            (string)($r['artist'] ?? '')
        );
        if ($artist === '' || $coreHash === '') {
            continue;
        }

        $unresolved[$idx] = [
            'artist' => $artist,
            'core_hash' => $coreHash,
        ];
        $artistSeeds[$artist] = true;
    }

    if (!empty($unresolved) && !empty($artistSeeds)) {
        try {
            $artists = array_values(array_keys($artistSeeds));
            $in = implode(',', array_fill(0, count($artists), '?'));
            $ratingExpr = mdjr_dj_tracks_rating_expr($db);
            $sql = "
                SELECT
                    d.id,
                    d.title,
                    d.artist,
                    {$ratingExpr} AS rating_value,
                    MAX(CASE WHEN dpp.playlist_id IS NULL THEN 0 ELSE 1 END) AS is_preferred
                FROM dj_tracks d
                LEFT JOIN dj_playlist_tracks dpt
                    ON dpt.dj_track_id = d.id
                LEFT JOIN dj_preferred_playlists dpp
                    ON dpp.dj_id = d.dj_id
                   AND dpp.playlist_id = dpt.playlist_id
                WHERE d.dj_id = ?
                  AND artist IN ($in)
                GROUP BY d.id, d.title, d.artist
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge([$djId], $artists));

            $ownedByCoreHash = [];
            $ownedCoreTitlesByArtist = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $drow) {
                $artistCore = mdjr_core_artist((string)($drow['artist'] ?? ''));
                $ratingValue = isset($drow['rating_value']) && is_numeric($drow['rating_value'])
                    ? (float)$drow['rating_value']
                    : 0.0;
                $isPreferred = !empty($drow['is_preferred']) ? 1 : 0;
                $h = mdjr_relaxed_track_hash(
                    (string)($drow['title'] ?? ''),
                    (string)($drow['artist'] ?? '')
                );
                if ($h !== '') {
                    $candidate = [
                        'id' => (int)($drow['id'] ?? 0),
                        'title_core' => mdjr_core_title((string)($drow['title'] ?? '')),
                        'rating' => $ratingValue,
                        'is_preferred' => $isPreferred,
                    ];
                    if (!isset($ownedByCoreHash[$h]) || mdjr_is_better_resolver_candidate($candidate, $ownedByCoreHash[$h])) {
                        $ownedByCoreHash[$h] = $candidate;
                    }
                }
                $titleCore = mdjr_core_title((string)($drow['title'] ?? ''));
                if ($artistCore !== '' && $titleCore !== '') {
                    if (!isset($ownedCoreTitlesByArtist[$artistCore])) {
                        $ownedCoreTitlesByArtist[$artistCore] = [];
                    }
                    $ownedCoreTitlesByArtist[$artistCore][] = [
                        'id' => (int)($drow['id'] ?? 0),
                        'title_core' => $titleCore,
                        'rating' => $ratingValue,
                        'is_preferred' => $isPreferred,
                    ];
                }
            }

            foreach ($unresolved as $idx => $meta) {
                $h = (string)($meta['core_hash'] ?? '');
                if ($h !== '' && !empty($ownedByCoreHash[$h])) {
                    $rows[$idx]['dj_track_id'] = (int)($ownedByCoreHash[$h]['id'] ?? 0);
                    continue;
                }

                $artistCore = mdjr_core_artist((string)($meta['artist'] ?? ''));
                $rowTitleCore = mdjr_core_title((string)($rows[$idx]['song_title'] ?? ''));
                if ($artistCore === '' || $rowTitleCore === '' || empty($ownedCoreTitlesByArtist[$artistCore])) {
                    continue;
                }

                $best = null;
                foreach ($ownedCoreTitlesByArtist[$artistCore] as $owned) {
                    $ownedTitleCore = (string)($owned['title_core'] ?? '');
                    if ($ownedTitleCore === '') {
                        continue;
                    }

                    $containsHit = (str_contains($ownedTitleCore, $rowTitleCore) || str_contains($rowTitleCore, $ownedTitleCore));
                    similar_text($rowTitleCore, $ownedTitleCore, $pct);
                    $score = (float)$pct;
                    if ($containsHit) {
                        $score = max($score, 92.0);
                    }

                    if ($score < 70.0) {
                        continue;
                    }

                    $candidate = [
                        'id' => (int)($owned['id'] ?? 0),
                        'title_core' => $ownedTitleCore,
                        'rating' => isset($owned['rating']) && is_numeric($owned['rating']) ? (float)$owned['rating'] : 0.0,
                        'is_preferred' => !empty($owned['is_preferred']) ? 1 : 0,
                    ];

                    if ($best === null || mdjr_is_better_resolver_candidate($candidate, $best)) {
                        $best = $candidate;
                    }
                }

                if (is_array($best) && (int)($best['id'] ?? 0) > 0) {
                    $rows[$idx]['dj_track_id'] = (int)$best['id'];
                }
            }
        } catch (Throwable $e) {
            // Non-blocking: keep response from primary ownership path.
        }
    }
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
              MAX(tl.bpm_track_id) AS bpm_track_id,
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
                'bpm_track_id' => isset($bpmRow['bpm_track_id']) && is_numeric($bpmRow['bpm_track_id']) && (int)$bpmRow['bpm_track_id'] > 0
                    ? (int)$bpmRow['bpm_track_id']
                    : null,
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
    $row['manual_path_matched'] = 0;
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

    // Authoritative override: when a link exists, linked BPM catalog metadata
    // should win over raw Spotify cache values (manual/admin apply relies on this).
    if ($sid !== '' && isset($bpmMap[$sid])) {
        if (!empty($bpmMap[$sid]['bpm'])) {
            $row['bpm'] = $bpmMap[$sid]['bpm'];
        }
        if (!empty($bpmMap[$sid]['musical_key'])) {
            $row['musical_key'] = $bpmMap[$sid]['musical_key'];
        }
        if (!empty($bpmMap[$sid]['release_year'])) {
            $row['release_year'] = (int)$bpmMap[$sid]['release_year'];
        }
    }

    // Final event-scoped manual override must win over all cache/enrichment paths.
    $overrideKey = mdjr_core_track_key((string)($row['song_title'] ?? ''), (string)($row['artist'] ?? ''));
    $overrideTitleCore = mdjr_core_title((string)($row['song_title'] ?? ''));
    $ov = null;
    if ($overrideKey !== '' && isset($eventOverrideMap[$overrideKey])) {
        $ov = $eventOverrideMap[$overrideKey];
    } elseif ($overrideKey !== '' && isset($globalOverrideMap[$overrideKey])) {
        $ov = $globalOverrideMap[$overrideKey];
    } elseif ($overrideTitleCore !== '' && isset($eventOverrideTitleMap[$overrideTitleCore])) {
        $ov = $eventOverrideTitleMap[$overrideTitleCore];
    } elseif ($overrideTitleCore !== '' && isset($globalOverrideTitleMap[$overrideTitleCore])) {
        $ov = $globalOverrideTitleMap[$overrideTitleCore];
    }

    if (is_array($ov)) {
        $exactDjTrackId = isset($ov['dj_track_id']) && is_numeric($ov['dj_track_id']) ? (int)$ov['dj_track_id'] : 0;
        $exactDjTrackAvailable = ($exactDjTrackId > 0 && isset($availableExactOverrideDjTrackIds[$exactDjTrackId]));
        if (isset($ov['bpm_track_id']) && is_numeric($ov['bpm_track_id']) && (int)$ov['bpm_track_id'] > 0) {
            $row['selected_bpm_track_id'] = (int)$ov['bpm_track_id'];
            $row['manual_path_matched'] = $exactDjTrackAvailable ? 1 : 0;
            $row['selected_dj_track_id'] = $exactDjTrackId;
        } elseif ($exactDjTrackAvailable) {
            // Local-only saved selections from stale resolution or manual rebinding
            // are still exact path matches even without a linked BPM catalog row.
            $row['selected_dj_track_id'] = $exactDjTrackId;
            $row['dj_track_id'] = $exactDjTrackId;
            $row['manual_path_matched'] = 1;
        } elseif ($sid !== '' && isset($bpmMap[$sid]['bpm_track_id']) && is_numeric($bpmMap[$sid]['bpm_track_id']) && (int)$bpmMap[$sid]['bpm_track_id'] > 0) {
            // Legacy/manual rows may have override metadata but missing bpm_track_id.
            // Hydrate the selected BPM row for metadata display, but do not mark
            // the request as path-matched unless an exact local dj_track_id is
            // still available.
            $row['selected_bpm_track_id'] = (int)$bpmMap[$sid]['bpm_track_id'];
            $row['manual_path_matched'] = 0;
        }
        if (isset($ov['bpm']) && is_numeric($ov['bpm']) && (float)$ov['bpm'] > 0) {
            $row['bpm'] = (float)$ov['bpm'];
        }
        $mKey = trim((string)($ov['musical_key'] ?? ''));
        if ($mKey !== '') {
            $row['musical_key'] = $mKey;
        }
        if (isset($ov['release_year']) && is_numeric($ov['release_year']) && (int)$ov['release_year'] > 0) {
            $row['release_year'] = (int)$ov['release_year'];
        }
        if ((int)($ov['manual_owned'] ?? 0) === 1) {
            if ($exactDjTrackAvailable) {
                $row['manual_owned'] = 1;
            }
        }
        if ((int)($ov['manual_preferred'] ?? 0) === 1) {
            if ($exactDjTrackAvailable) {
                $row['preferred_selected'] = 1;
            }
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

// Preferred tile badge:
// 1) explicit manual_preferred persists highest priority,
// 2) fallback: derive from selected_bpm_track_id hash -> preferred playlist membership.
$selectedBpmIds = [];
foreach ($rows as $r) {
    $sid = isset($r['selected_bpm_track_id']) && is_numeric($r['selected_bpm_track_id']) ? (int)$r['selected_bpm_track_id'] : 0;
    if ($sid > 0) {
        $selectedBpmIds[$sid] = true;
    }
}

$preferredSelectedBpmIds = [];
if ($djId > 0 && !empty($selectedBpmIds)) {
    try {
        $bpmIds = array_keys($selectedBpmIds);
        $in = implode(',', array_fill(0, count($bpmIds), '?'));
        $bpmStmt = $db->prepare("
            SELECT id, title, artist
            FROM bpm_test_tracks
            WHERE id IN ($in)
        ");
        $bpmStmt->execute($bpmIds);

        $hashToBpmIds = [];
        foreach ($bpmStmt->fetchAll(PDO::FETCH_ASSOC) as $bpmRow) {
            $bid = isset($bpmRow['id']) && is_numeric($bpmRow['id']) ? (int)$bpmRow['id'] : 0;
            if ($bid <= 0) {
                continue;
            }
            $h = mdjr_candidate_track_hash(
                (string)($bpmRow['title'] ?? ''),
                (string)($bpmRow['artist'] ?? '')
            );
            if ($h === '') {
                continue;
            }
            if (!isset($hashToBpmIds[$h])) {
                $hashToBpmIds[$h] = [];
            }
            $hashToBpmIds[$h][] = $bid;
        }

        if (!empty($hashToBpmIds)) {
            $hashes = array_keys($hashToBpmIds);
            $hin = implode(',', array_fill(0, count($hashes), '?'));
            $prefStmt = $db->prepare("
                SELECT DISTINCT d.normalized_hash
                FROM dj_tracks d
                INNER JOIN dj_playlist_tracks dpt
                    ON dpt.dj_track_id = d.id
                INNER JOIN dj_preferred_playlists dpp
                    ON dpp.dj_id = d.dj_id
                   AND dpp.playlist_id = dpt.playlist_id
                WHERE d.dj_id = ?
                  AND d.normalized_hash IN ($hin)
            ");
            $prefStmt->execute(array_merge([$djId], $hashes));
            foreach ($prefStmt->fetchAll(PDO::FETCH_COLUMN) as $hv) {
                $h = trim((string)$hv);
                if ($h === '' || empty($hashToBpmIds[$h])) {
                    continue;
                }
                foreach ($hashToBpmIds[$h] as $bid) {
                    $preferredSelectedBpmIds[(int)$bid] = true;
                }
            }
        }
    } catch (Throwable $e) {
        $preferredSelectedBpmIds = [];
    }
}

foreach ($rows as &$row) {
    $manualPreferred = isset($row['preferred_selected']) && (int)$row['preferred_selected'] === 1 ? 1 : 0;
    $selectedBpmId = isset($row['selected_bpm_track_id']) && is_numeric($row['selected_bpm_track_id']) ? (int)$row['selected_bpm_track_id'] : 0;
    $derivedPreferred = ($selectedBpmId > 0 && isset($preferredSelectedBpmIds[$selectedBpmId])) ? 1 : 0;
    $row['preferred_selected'] = ($manualPreferred === 1 || $derivedPreferred === 1) ? 1 : 0;
    $manualPathMatched = isset($row['manual_path_matched']) && (int)$row['manual_path_matched'] === 1 ? 1 : 0;
    $row['manual_path_matched'] = $manualPathMatched === 1 ? 1 : 0;
}
unset($row);

echo json_encode([
    'ok'   => true,
    'rows' => $rows
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

function mdjr_relaxed_track_hash(string $title, string $artist): string
{
    $title = mb_strtolower($title, 'UTF-8');
    $artist = mb_strtolower($artist, 'UTF-8');

    $title = preg_replace('/\\(.*?\\)|\\[.*?\\]/u', ' ', $title);
    $title = preg_replace('/\\b(feat|ft|featuring|remix|mix|edit|version|remaster(?:ed)?|radio|extended|club|original|live|explicit|clean)\\b/u', ' ', $title);
    $artist = preg_replace('/\\b(feat|ft|featuring)\\b/u', ' ', $artist);

    $title = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $title);
    $artist = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $artist);
    $title = preg_replace('/\\s+/u', ' ', trim($title));
    $artist = preg_replace('/\\s+/u', ' ', trim($artist));

    if ($title === '' && $artist === '') {
        return '';
    }

    return hash('sha256', $artist . '|' . $title);
}

function mdjr_candidate_track_hash(string $title, string $artist): string
{
    $title = mb_strtolower($title, 'UTF-8');
    $artist = mb_strtolower($artist, 'UTF-8');
    $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title);
    $artist = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $artist);
    $title = preg_replace('/\s+/u', ' ', trim((string)$title));
    $artist = preg_replace('/\s+/u', ' ', trim((string)$artist));
    if ($title === '' && $artist === '') {
        return '';
    }
    return hash('sha256', $artist . '|' . $title);
}

function mdjr_core_track_key(string $title, string $artist): string
{
    $artistCore = mdjr_core_artist($artist);
    $titleCore = mdjr_core_title($title);
    if ($artistCore === '' || $titleCore === '') {
        return '';
    }
    return $artistCore . '|' . $titleCore;
}

function mdjr_core_artist(string $artist): string
{
    $artist = mb_strtolower($artist, 'UTF-8');
    $artist = preg_replace('/\\b(feat|ft|featuring|x|vs)\\b/u', ' ', $artist);
    $artist = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $artist);
    $artist = preg_replace('/\\s+/u', ' ', trim($artist));
    return (string)$artist;
}

function mdjr_core_title(string $title): string
{
    $title = mb_strtolower($title, 'UTF-8');

    // Remove bracketed descriptors first.
    $title = preg_replace('/\\(.*?\\)|\\[.*?\\]/u', ' ', $title);

    // If there is a dash descriptor, keep the left side (common in remaster strings).
    if (preg_match('/\\s[-–—]\\s/u', $title)) {
        $parts = preg_split('/\\s[-–—]\\s/u', $title);
        if (is_array($parts) && !empty($parts[0])) {
            $title = (string)$parts[0];
        }
    }

    // Remove common non-identity tokens.
    $title = preg_replace('/\\b(feat|ft|featuring|remix|mix|edit|version|remaster(?:ed)?|radio|extended|club|original|live|explicit|clean|mono|stereo|instrumental|karaoke|rework|dub)\\b/u', ' ', $title);

    // Drop standalone years that often appear in remaster variants.
    $title = preg_replace('/\\b(19|20)\\d{2}\\b/u', ' ', $title);

    $title = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $title);
    $title = preg_replace('/\\s+/u', ' ', trim($title));
    return (string)$title;
}

function mdjr_title_tokens(string $coreTitle): array
{
    $parts = preg_split('/\\s+/u', trim($coreTitle));
    if (!is_array($parts)) {
        return [];
    }

    $out = [];
    foreach ($parts as $p) {
        $t = trim((string)$p);
        if ($t === '' || mb_strlen($t, 'UTF-8') < 2) {
            continue;
        }
        if (preg_match('/^(the|a|an|and|or|of|to|for|in|on)$/u', $t)) {
            continue;
        }
        $out[$t] = true;
    }

    return array_keys($out);
}

function mdjr_dj_tracks_rating_expr(PDO $db): string
{
    static $cachedExpr = null;
    if (is_string($cachedExpr)) {
        return $cachedExpr;
    }

    try {
        $stmt = $db->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dj_tracks'
        ");
        $stmt->execute();
        $cols = array_map(
            static function ($v): string {
                return strtolower((string)$v);
            },
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
    } catch (Throwable $e) {
        $cols = [];
    }

    foreach (['rating', 'stars', 'star_rating', 'rekordbox_rating', 'rb_rating', 'rating_raw'] as $col) {
        if (in_array($col, $cols, true)) {
            $cachedExpr = "COALESCE(d.{$col}, 0)";
            return $cachedExpr;
        }
    }

    $cachedExpr = "0";
    return $cachedExpr;
}

function mdjr_is_better_resolver_candidate(array $a, array $b): bool
{
    $aPreferred = !empty($a['is_preferred']) ? 1 : 0;
    $bPreferred = !empty($b['is_preferred']) ? 1 : 0;
    if ($aPreferred !== $bPreferred) {
        return $aPreferred > $bPreferred;
    }

    $aRating = isset($a['rating']) && is_numeric($a['rating']) ? (float)$a['rating'] : 0.0;
    $bRating = isset($b['rating']) && is_numeric($b['rating']) ? (float)$b['rating'] : 0.0;
    $aFive = ($aRating >= 5.0) ? 1 : 0;
    $bFive = ($bRating >= 5.0) ? 1 : 0;
    if ($aFive !== $bFive) {
        return $aFive > $bFive;
    }

    if ($aRating !== $bRating) {
        return $aRating > $bRating;
    }

    $aId = (int)($a['id'] ?? PHP_INT_MAX);
    $bId = (int)($b['id'] ?? PHP_INT_MAX);
    return $aId < $bId;
}

function mdjr_tokens_overlap_strong(array $aTokens, array $bTokens): bool
{
    if (empty($aTokens) || empty($bTokens)) {
        return false;
    }

    $aSet = array_fill_keys($aTokens, true);
    $bSet = array_fill_keys($bTokens, true);
    $common = array_intersect_key($aSet, $bSet);
    $commonCount = count($common);
    if ($commonCount === 0) {
        return false;
    }

    $aCount = count($aSet);
    $bCount = count($bSet);
    $minCount = min($aCount, $bCount);
    $maxCount = max($aCount, $bCount);

    if ($minCount <= 2) {
        return $commonCount >= $minCount;
    }

    // Strong overlap requirement to avoid cross-song false ownership.
    return $commonCount >= 2 && ($commonCount / $maxCount) >= 0.6;
}

function mdjr_artist_tokens(string $artistCore): array
{
    $parts = preg_split('/\\s+/u', trim($artistCore));
    if (!is_array($parts)) {
        return [];
    }

    $out = [];
    foreach ($parts as $p) {
        $t = trim((string)$p);
        if ($t === '' || mb_strlen($t, 'UTF-8') < 2) {
            continue;
        }
        if (preg_match('/^(the|dj|mc|mr|mrs|ms|and|or|of)$/u', $t)) {
            continue;
        }
        $out[$t] = true;
    }

    return array_keys($out);
}

function mdjr_tokens_overlap_artist(array $aTokens, array $bTokens): bool
{
    if (empty($aTokens) || empty($bTokens)) {
        return false;
    }

    $aSet = array_fill_keys($aTokens, true);
    $bSet = array_fill_keys($bTokens, true);
    $commonCount = count(array_intersect_key($aSet, $bSet));
    if ($commonCount === 0) {
        return false;
    }

    $minCount = min(count($aSet), count($bSet));
    if ($minCount <= 2) {
        return $commonCount >= 1;
    }

    return ($commonCount / $minCount) >= 0.5;
}

function ensureDjOwnedTrackOverridesTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_owned_track_overrides (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dj_id BIGINT UNSIGNED NOT NULL,
            spotify_track_id VARCHAR(128) NOT NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'manual_metadata_match',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dj_owned_track_overrides_dj_spotify (dj_id, spotify_track_id),
            KEY idx_dj_owned_track_overrides_spotify (spotify_track_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensureDjEventTrackOverridesTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_event_track_overrides (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dj_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            override_key VARCHAR(512) NOT NULL,
            bpm_track_id BIGINT UNSIGNED NULL,
            dj_track_id BIGINT UNSIGNED NULL,
            bpm DECIMAL(6,2) NULL,
            musical_key VARCHAR(32) NULL,
            release_year INT NULL,
            manual_owned TINYINT(1) NOT NULL DEFAULT 1,
            manual_preferred TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dj_event_track_overrides (dj_id, event_id, override_key),
            KEY idx_dj_event_track_overrides_event (event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $stmt = $db->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dj_event_track_overrides'
        ");
        $stmt->execute();
        $cols = [];
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $col) {
            $cols[strtolower((string)$col)] = true;
        }
        if (empty($cols['bpm_track_id'])) {
            $db->exec("ALTER TABLE dj_event_track_overrides ADD COLUMN bpm_track_id BIGINT UNSIGNED NULL AFTER override_key");
        }
        if (empty($cols['dj_track_id'])) {
            $db->exec("ALTER TABLE dj_event_track_overrides ADD COLUMN dj_track_id BIGINT UNSIGNED NULL AFTER bpm_track_id");
        }
        if (empty($cols['manual_preferred'])) {
            $db->exec("ALTER TABLE dj_event_track_overrides ADD COLUMN manual_preferred TINYINT(1) NOT NULL DEFAULT 0 AFTER manual_owned");
        }
    } catch (Throwable $e) {
        // non-fatal schema backfill
    }
}

function ensureDjGlobalTrackOverridesTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_global_track_overrides (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dj_id BIGINT UNSIGNED NOT NULL,
            override_key VARCHAR(512) NOT NULL,
            bpm_track_id BIGINT UNSIGNED NULL,
            dj_track_id BIGINT UNSIGNED NULL,
            bpm DECIMAL(6,2) NULL,
            musical_key VARCHAR(32) NULL,
            release_year INT NULL,
            manual_owned TINYINT(1) NOT NULL DEFAULT 1,
            manual_preferred TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dj_global_track_overrides (dj_id, override_key),
            KEY idx_dj_global_track_overrides_dj (dj_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $stmt = $db->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dj_global_track_overrides'
        ");
        $stmt->execute();
        $cols = [];
        foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $col) {
            $cols[strtolower((string)$col)] = true;
        }
        if (empty($cols['bpm_track_id'])) {
            $db->exec("ALTER TABLE dj_global_track_overrides ADD COLUMN bpm_track_id BIGINT UNSIGNED NULL AFTER override_key");
        }
        if (empty($cols['dj_track_id'])) {
            $db->exec("ALTER TABLE dj_global_track_overrides ADD COLUMN dj_track_id BIGINT UNSIGNED NULL AFTER bpm_track_id");
        }
        if (empty($cols['manual_preferred'])) {
            $db->exec("ALTER TABLE dj_global_track_overrides ADD COLUMN manual_preferred TINYINT(1) NOT NULL DEFAULT 0 AFTER manual_owned");
        }
    } catch (Throwable $e) {
        // non-fatal schema backfill
    }
}

function mdjr_override_title_from_key(string $overrideKey): string
{
    $parts = explode('|', $overrideKey, 2);
    if (count($parts) !== 2) {
        return '';
    }
    return trim((string)$parts[1]);
}
