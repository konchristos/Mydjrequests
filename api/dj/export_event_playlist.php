<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$type = strtolower(trim((string)($_GET['type'] ?? $_POST['type'] ?? '')));

if ($eventId <= 0 || !in_array($type, ['owned', 'missing'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid event_id or type']);
    exit;
}

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

function hasTableColumn(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function mdjrRatingExprForDjTracks(PDO $db): string
{
    foreach (['rating', 'stars', 'star_rating', 'rekordbox_rating', 'rb_rating', 'rating_raw'] as $col) {
        if (hasTableColumn($db, 'dj_tracks', $col)) {
            return "COALESCE(d.`{$col}`, 0)";
        }
    }
    return "0";
}

function mdjrTrackHash(string $title, string $artist): string
{
    if (function_exists('trackIdentityNormalisedHash')) {
        $h = (string)(trackIdentityNormalisedHash($title, $artist) ?? '');
        if ($h !== '') {
            return $h;
        }
    }
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

function mdjrIsBetterDjPathCandidate(array $candidate, ?array $current): bool
{
    if ($current === null) {
        return true;
    }
    $cPref = !empty($candidate['is_preferred']) ? 1 : 0;
    $oPref = !empty($current['is_preferred']) ? 1 : 0;
    if ($cPref !== $oPref) {
        return $cPref > $oPref;
    }
    $cRating = isset($candidate['rating_value']) && is_numeric($candidate['rating_value']) ? (float)$candidate['rating_value'] : 0.0;
    $oRating = isset($current['rating_value']) && is_numeric($current['rating_value']) ? (float)$current['rating_value'] : 0.0;
    if (abs($cRating - $oRating) > 0.0001) {
        return $cRating > $oRating;
    }
    $cId = (int)($candidate['id'] ?? PHP_INT_MAX);
    $oId = (int)($current['id'] ?? PHP_INT_MAX);
    return $cId < $oId;
}

function mdjrCandidateHash(array $row): string
{
    $identityHash = trim((string)($row['identity_hash'] ?? ''));
    if ($identityHash !== '') {
        return $identityHash;
    }

    $title = (string)($row['title'] ?? '');
    $artist = (string)($row['artist'] ?? '');
    if (function_exists('trackIdentityNormalisedHash')) {
        $hash = (string)(trackIdentityNormalisedHash($title, $artist) ?? '');
        if ($hash !== '') {
            return $hash;
        }
    }

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

function mdjrTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function mdjrCoreTitle(string $title): string
{
    $title = mb_strtolower($title, 'UTF-8');
    $title = preg_replace('/\\(.*?\\)|\\[.*?\\]/u', ' ', $title);
    if (preg_match('/\\s[-–—]\\s/u', $title)) {
        $parts = preg_split('/\\s[-–—]\\s/u', $title);
        if (is_array($parts) && !empty($parts[0])) {
            $title = (string)$parts[0];
        }
    }
    $title = preg_replace('/\\b(feat|ft|featuring|remix|mix|edit|version|remaster(?:ed)?|radio|extended|club|original|live|explicit|clean|mono|stereo|instrumental|karaoke|rework|dub)\\b/u', ' ', (string)$title);
    $title = preg_replace('/\\b(19|20)\\d{2}\\b/u', ' ', (string)$title);
    $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string)$title);
    $title = preg_replace('/\s+/u', ' ', trim((string)$title));
    return (string)$title;
}

function mdjrCoreArtist(string $artist): string
{
    $artist = mb_strtolower($artist, 'UTF-8');
    $artist = preg_replace('/\\b(feat|ft|featuring|x|vs)\\b/u', ' ', $artist);
    $artist = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string)$artist);
    $artist = preg_replace('/\s+/u', ' ', trim((string)$artist));
    return (string)$artist;
}

function mdjrOverrideKey(string $title, string $artist): string
{
    $t = mdjrCoreTitle($title);
    $a = mdjrCoreArtist($artist);
    if ($a === '' || $t === '') {
        return '';
    }
    // Must match apply_manual_bpm_match + get_requests key format exactly.
    return $a . '|' . $t;
}

function mdjrOverrideTitleFromKey(string $overrideKey): string
{
    $parts = explode('|', $overrideKey, 2);
    if (count($parts) === 2) {
        return (string)$parts[1];
    }
    return '';
}

function mdjrLoadFallbackRows(PDO $db, int $eventId): array
{
    $sql = "
        SELECT
            COALESCE(NULLIF(sr.spotify_track_id, ''), CONCAT(sr.song_title, '::', sr.artist)) AS track_key,
            MAX(COALESCE(sr.track_identity_id, 0)) AS track_identity_id,
            MAX(NULLIF(sr.spotify_track_id, '')) AS spotify_track_id,
            COALESCE(
                MAX(NULLIF(sr.spotify_track_name, '')),
                MAX(NULLIF(st.track_name, '')),
                MAX(NULLIF(sr.song_title, '')),
                'Unknown Title'
            ) AS title,
            COALESCE(
                MAX(NULLIF(sr.spotify_artist_name, '')),
                MAX(NULLIF(st.artist_name, '')),
                MAX(NULLIF(sr.artist, '')),
                'Unknown Artist'
            ) AS artist,
            COUNT(*) AS request_count
        FROM song_requests sr
        LEFT JOIN spotify_tracks st
            ON st.spotify_track_id = NULLIF(sr.spotify_track_id, '')
        WHERE sr.event_id = :event_id
        GROUP BY COALESCE(NULLIF(sr.spotify_track_id, ''), CONCAT(sr.song_title, '::', sr.artist))
        ORDER BY request_count DESC, title ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':event_id' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['identity_hash'] = '';
    }
    unset($row);
    return $rows;
}

function mdjrNormalizePlaylistPath(string $rawPath): string
{
    $p = trim($rawPath);
    if ($p === '') {
        return '';
    }

    // Drop non-local Spotify-style references from M3U local playlists.
    $lower = mb_strtolower($p, 'UTF-8');
    if (str_starts_with($lower, 'spotify:') || str_starts_with($lower, 'localhostspotify:')) {
        return '';
    }

    // Decode file:// URLs from Rekordbox exports.
    if (preg_match('#^file://localhost/(.+)$#i', $p, $m)) {
        $p = '/' . ltrim((string)$m[1], '/');
    } elseif (preg_match('#^file:///(.+)$#i', $p, $m)) {
        $p = '/' . ltrim((string)$m[1], '/');
    }

    $p = rawurldecode($p);

    // Common macOS absolute path accidentally stored without leading slash.
    if (preg_match('#^Users/#', $p)) {
        $p = '/' . $p;
    }

    // Normalize slashes for m3u readability.
    $p = str_replace('\\', '/', $p);
    return trim($p);
}

function mdjrLoadBpmPathMap(PDO $db, int $djId, string $pathCol, array $bpmIds): array
{
    $bpmIds = array_values(array_unique(array_map('intval', $bpmIds)));
    $bpmIds = array_values(array_filter($bpmIds, static fn(int $v): bool => $v > 0));
    if (empty($bpmIds)) {
        return [];
    }

    $in = implode(',', array_fill(0, count($bpmIds), '?'));
    $stmt = $db->prepare("
        SELECT id, title, artist
        FROM bpm_test_tracks
        WHERE id IN ($in)
    ");
    $stmt->execute($bpmIds);
    $bpmRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($bpmRows)) {
        return [];
    }

    $hashToBpmIds = [];
    foreach ($bpmRows as $r) {
        $bid = (int)($r['id'] ?? 0);
        if ($bid <= 0) {
            continue;
        }
        $hash = mdjrTrackHash((string)($r['title'] ?? ''), (string)($r['artist'] ?? ''));
        if ($hash === '') {
            continue;
        }
        if (!isset($hashToBpmIds[$hash])) {
            $hashToBpmIds[$hash] = [];
        }
        $hashToBpmIds[$hash][] = $bid;
    }
    if (empty($hashToBpmIds)) {
        return [];
    }

    $ratingExpr = mdjrRatingExprForDjTracks($db);
    $hashes = array_keys($hashToBpmIds);
    $hin = implode(',', array_fill(0, count($hashes), '?'));
    $sql = "
        SELECT
            d.id,
            d.normalized_hash,
            NULLIF(d.`{$pathCol}`, '') AS file_path,
            {$ratingExpr} AS rating_value,
            MAX(CASE WHEN dpp.playlist_id IS NULL THEN 0 ELSE 1 END) AS is_preferred
        FROM dj_tracks d
        LEFT JOIN dj_playlist_tracks dpt
            ON dpt.dj_track_id = d.id
        LEFT JOIN dj_preferred_playlists dpp
            ON dpp.dj_id = d.dj_id
           AND dpp.playlist_id = dpt.playlist_id
        WHERE d.dj_id = ?
          AND d.normalized_hash IN ($hin)
          AND COALESCE(d.is_available, 1) = 1
          AND NULLIF(d.`{$pathCol}`, '') IS NOT NULL
        GROUP BY d.id, d.normalized_hash, d.`{$pathCol}`
    ";
    $djStmt = $db->prepare($sql);
    $djStmt->execute(array_merge([$djId], $hashes));

    $bestByHash = [];
    foreach ($djStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $hash = trim((string)($d['normalized_hash'] ?? ''));
        $path = mdjrNormalizePlaylistPath((string)($d['file_path'] ?? ''));
        if ($hash === '' || $path === '') {
            continue;
        }
        $candidate = [
            'id' => (int)($d['id'] ?? 0),
            'path' => $path,
            'rating_value' => isset($d['rating_value']) && is_numeric($d['rating_value']) ? (float)$d['rating_value'] : 0.0,
            'is_preferred' => !empty($d['is_preferred']) ? 1 : 0,
        ];
        if (!isset($bestByHash[$hash]) || mdjrIsBetterDjPathCandidate($candidate, $bestByHash[$hash])) {
            $bestByHash[$hash] = $candidate;
        }
    }

    $out = [];
    foreach ($hashToBpmIds as $hash => $ids) {
        if (empty($bestByHash[$hash]['path'])) {
            continue;
        }
        $path = (string)$bestByHash[$hash]['path'];
        foreach ($ids as $bid) {
            $out[(int)$bid] = $path;
        }
    }
    return $out;
}

try {
    $eventStmt = $db->prepare("
        SELECT id, title
        FROM events
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $eventStmt->execute([$eventId, $djId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $pathCol = hasTableColumn($db, 'dj_tracks', 'file_path') ? 'file_path' : 'location';

    $rowsSql = "
        SELECT
            e.track_identity_id,
            MAX(e.request_count) AS request_count,
            MAX(NULLIF(ti.normalized_hash, '')) AS identity_hash,
            COALESCE(MAX(NULLIF(s.artist_name, '')), MAX(NULLIF(sr.artist, '')), 'Unknown Artist') AS artist,
            COALESCE(MAX(NULLIF(s.track_name, '')), MAX(NULLIF(sr.song_title, '')), 'Unknown Title') AS title,
            COALESCE(MAX(NULLIF(sr.spotify_track_id, '')), MAX(NULLIF(s.spotify_track_id, '')), '') AS spotify_track_id
        FROM event_tracks e
        LEFT JOIN track_identities ti
            ON ti.id = e.track_identity_id
        LEFT JOIN spotify_tracks s
            ON s.track_identity_id = e.track_identity_id
        LEFT JOIN (
            SELECT
                track_identity_id,
                MAX(NULLIF(spotify_track_id, '')) AS spotify_track_id,
                MAX(song_title) AS song_title,
                MAX(artist) AS artist
            FROM song_requests
            WHERE event_id = :event_id
              AND track_identity_id IS NOT NULL
            GROUP BY track_identity_id
        ) sr ON sr.track_identity_id = e.track_identity_id
        WHERE e.event_id = :event_id
        GROUP BY e.track_identity_id
        ORDER BY request_count DESC, title ASC
    ";
    $rowsStmt = $db->prepare($rowsSql);
    $rowsStmt->execute([':event_id' => $eventId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        // Projection can be empty for legacy events; fallback to grouped song_requests.
        $rows = mdjrLoadFallbackRows($db, $eventId);
    }

    if (empty($rows)) {
        $safeEvent = preg_replace('/[^a-z0-9\\-_]+/i', '_', (string)($event['title'] ?? 'event_' . $eventId));
        if ($type === 'owned') {
            $filename = sprintf('event_%d_%s_owned.m3u', $eventId, strtolower((string)$safeEvent));
            header('Content-Type: audio/x-mpegurl; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "#EXTM3U\n";
            exit;
        }
        $filename = sprintf('event_%d_%s_missing.txt', $eventId, strtolower((string)$safeEvent));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        exit;
    }

    $identityIds = [];
    $candidateHashes = [];
    foreach ($rows as $row) {
        $tid = (int)($row['track_identity_id'] ?? 0);
        if ($tid > 0) {
            $identityIds[$tid] = true;
        }
        $h = mdjrCandidateHash($row);
        if ($h !== '') {
            $candidateHashes[$h] = true;
        }
    }

    $pathByIdentity = [];
    $pathByHash = [];
    $pathByCoreKey = [];
    $pathByOverrideKey = [];
    $pathByOverrideTitleCore = [];
    $pathBySpotifyLinkedBpm = [];
    $staleOverrideKeys = [];

    if (!empty($identityIds) || !empty($candidateHashes)) {
        $matchConds = [];
        $params = [$djId];
        if (!empty($identityIds)) {
            $ids = array_keys($identityIds);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $matchConds[] = "track_identity_id IN ($in)";
            $params = array_merge($params, $ids);
        }
        if (!empty($candidateHashes)) {
            $hashes = array_keys($candidateHashes);
            $in = implode(',', array_fill(0, count($hashes), '?'));
            $matchConds[] = "normalized_hash IN ($in)";
            $params = array_merge($params, $hashes);
        }
        if (empty($matchConds)) {
            $matchConds[] = "1=0";
        }

        $ratingExpr = mdjrRatingExprForDjTracks($db);
        $djSql = "
            SELECT
                d.id,
                d.track_identity_id,
                d.normalized_hash,
                d.artist,
                d.title,
                NULLIF(d.`{$pathCol}`, '') AS file_path,
                {$ratingExpr} AS rating_value,
                MAX(CASE WHEN dpp.playlist_id IS NULL THEN 0 ELSE 1 END) AS is_preferred
            FROM dj_tracks d
            LEFT JOIN dj_playlist_tracks dpt
                ON dpt.dj_track_id = d.id
            LEFT JOIN dj_preferred_playlists dpp
                ON dpp.dj_id = d.dj_id
               AND dpp.playlist_id = dpt.playlist_id
            WHERE d.dj_id = ?
              AND COALESCE(d.is_available, 1) = 1
              AND (" . implode(' OR ', $matchConds) . ")
            GROUP BY d.id, d.track_identity_id, d.normalized_hash, d.artist, d.title, d.`{$pathCol}`
        ";
        $djStmt = $db->prepare($djSql);
        $djStmt->execute($params);
        $bestIdentityCandidate = [];
        $bestHashCandidate = [];
        foreach ($djStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tid = (int)($row['track_identity_id'] ?? 0);
            $hash = trim((string)($row['normalized_hash'] ?? ''));
            $path = mdjrNormalizePlaylistPath((string)($row['file_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $candidate = [
                'id' => (int)($row['id'] ?? 0),
                'path' => $path,
                'rating_value' => isset($row['rating_value']) && is_numeric($row['rating_value']) ? (float)$row['rating_value'] : 0.0,
                'is_preferred' => !empty($row['is_preferred']) ? 1 : 0,
            ];

            if ($tid > 0 && (!isset($bestIdentityCandidate[$tid]) || mdjrIsBetterDjPathCandidate($candidate, $bestIdentityCandidate[$tid]))) {
                $bestIdentityCandidate[$tid] = $candidate;
            }
            if ($hash !== '' && (!isset($bestHashCandidate[$hash]) || mdjrIsBetterDjPathCandidate($candidate, $bestHashCandidate[$hash]))) {
                $bestHashCandidate[$hash] = $candidate;
            }
        }

        foreach ($bestIdentityCandidate as $tid => $cand) {
            $pathByIdentity[(int)$tid] = (string)($cand['path'] ?? '');
        }
        foreach ($bestHashCandidate as $hash => $cand) {
            $pathByHash[(string)$hash] = (string)($cand['path'] ?? '');
        }
    }

    $hasEventOverrides = mdjrTableExists($db, 'dj_event_track_overrides');
    $hasGlobalOverrides = mdjrTableExists($db, 'dj_global_track_overrides');
    $eventOverrideHasDjTrackId = $hasEventOverrides && hasTableColumn($db, 'dj_event_track_overrides', 'dj_track_id');
    if ($hasEventOverrides || $hasGlobalOverrides) {
        $overrideRows = [];
        $overrideKeysPresent = [];
        if ($hasEventOverrides) {
        $overrideSelect = $eventOverrideHasDjTrackId ? 'override_key, bpm_track_id, dj_track_id, manual_owned' : 'override_key, bpm_track_id, NULL AS dj_track_id, manual_owned';
        $stmt = $db->prepare("
            SELECT {$overrideSelect}
            FROM dj_event_track_overrides
            WHERE dj_id = ?
              AND event_id = ?
        ");
        $stmt->execute([$djId, $eventId]);
        $overrideRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($overrideRows as $ov) {
            $k = trim((string)($ov['override_key'] ?? ''));
            if ($k !== '') {
                $overrideKeysPresent[$k] = true;
            }
        }
        }
        if ($hasGlobalOverrides) {
            $globalOverrideHasDjTrackId = hasTableColumn($db, 'dj_global_track_overrides', 'dj_track_id');
            $globalSelect = $globalOverrideHasDjTrackId ? 'override_key, bpm_track_id, dj_track_id, manual_owned' : 'override_key, bpm_track_id, NULL AS dj_track_id, manual_owned';
            $globalStmt = $db->prepare("
                SELECT {$globalSelect}
                FROM dj_global_track_overrides
                WHERE dj_id = ?
            ");
            $globalStmt->execute([$djId]);
            foreach ($globalStmt->fetchAll(PDO::FETCH_ASSOC) as $gov) {
                $k = trim((string)($gov['override_key'] ?? ''));
                if ($k === '' || isset($overrideKeysPresent[$k])) {
                    continue;
                }
                $overrideRows[] = $gov;
                $overrideKeysPresent[$k] = true;
            }
        }
        $overrideBpmIds = [];
        $overrideDjTrackIds = [];
        foreach ($overrideRows as $ov) {
            $bid = isset($ov['bpm_track_id']) && is_numeric($ov['bpm_track_id']) ? (int)$ov['bpm_track_id'] : 0;
            if ($bid > 0) {
                $overrideBpmIds[$bid] = true;
            }
            $exactId = isset($ov['dj_track_id']) && is_numeric($ov['dj_track_id']) ? (int)$ov['dj_track_id'] : 0;
            if ($exactId > 0) {
                $overrideDjTrackIds[$exactId] = true;
            }
        }
        $overridePathByBpmId = mdjrLoadBpmPathMap($db, $djId, $pathCol, array_keys($overrideBpmIds));
        $overrideExactPathByDjTrackId = [];
        if (!empty($overrideDjTrackIds)) {
            $ids = array_keys($overrideDjTrackIds);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pathStmt = $db->prepare("
                SELECT id, NULLIF(`{$pathCol}`, '') AS file_path
                FROM dj_tracks
                WHERE dj_id = ?
                  AND COALESCE(is_available, 1) = 1
                  AND id IN ($in)
            ");
            $pathStmt->execute(array_merge([$djId], $ids));
            foreach ($pathStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $exactId = (int)($r['id'] ?? 0);
                $path = mdjrNormalizePlaylistPath((string)($r['file_path'] ?? ''));
                if ($exactId > 0 && $path !== '') {
                    $overrideExactPathByDjTrackId[$exactId] = $path;
                }
            }
        }
        foreach ($overrideRows as $ov) {
            if ((int)($ov['manual_owned'] ?? 0) !== 1) {
                continue;
            }
            $k = trim((string)($ov['override_key'] ?? ''));
            $bid = isset($ov['bpm_track_id']) && is_numeric($ov['bpm_track_id']) ? (int)$ov['bpm_track_id'] : 0;
            $exactId = isset($ov['dj_track_id']) && is_numeric($ov['dj_track_id']) ? (int)$ov['dj_track_id'] : 0;
            if ($k === '') {
                continue;
            }
            $resolvedPath = '';
            if ($exactId > 0 && !empty($overrideExactPathByDjTrackId[$exactId])) {
                $resolvedPath = (string)$overrideExactPathByDjTrackId[$exactId];
            } elseif ($exactId > 0) {
                $staleOverrideKeys[$k] = true;
                continue;
            } elseif ($bid > 0 && !empty($overridePathByBpmId[$bid])) {
                $resolvedPath = (string)$overridePathByBpmId[$bid];
            }
            if ($resolvedPath !== '') {
                $pathByOverrideKey[$k] = $resolvedPath;
                $titleCore = mdjrOverrideTitleFromKey($k);
                if ($titleCore !== '' && !isset($pathByOverrideTitleCore[$titleCore])) {
                    $pathByOverrideTitleCore[$titleCore] = $resolvedPath;
                }
            }
        }
    }

    // Map spotify track links to local DJ paths via linked bpm_track_id.
    $spotifyIds = [];
    foreach ($rows as $row) {
        $sid = trim((string)($row['spotify_track_id'] ?? ''));
        if ($sid !== '') {
            $spotifyIds[$sid] = true;
        }
    }
    if (!empty($spotifyIds) && mdjrTableExists($db, 'track_links')) {
        $ids = array_values(array_keys($spotifyIds));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT spotify_track_id, bpm_track_id
            FROM track_links
            WHERE spotify_track_id IN ($in)
              AND bpm_track_id IS NOT NULL
        ");
        $stmt->execute($ids);
        $spotifyToBpm = [];
        $bpmIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = trim((string)($r['spotify_track_id'] ?? ''));
            $bid = isset($r['bpm_track_id']) && is_numeric($r['bpm_track_id']) ? (int)$r['bpm_track_id'] : 0;
            if ($sid === '' || $bid <= 0) {
                continue;
            }
            $spotifyToBpm[$sid] = $bid;
            $bpmIds[$bid] = true;
        }
        if (!empty($spotifyToBpm) && !empty($bpmIds)) {
            $bpmPathMap = mdjrLoadBpmPathMap($db, $djId, $pathCol, array_keys($bpmIds));
            foreach ($spotifyToBpm as $sid => $bid) {
                if (!empty($bpmPathMap[$bid])) {
                    $pathBySpotifyLinkedBpm[$sid] = (string)$bpmPathMap[$bid];
                }
            }
        }
    }

    // Exact core title/artist fallback index (deterministic, no fuzzy).
    $artistSeeds = [];
    foreach ($rows as $r) {
        $a = trim((string)($r['artist'] ?? ''));
        if ($a !== '') {
            $artistSeeds[$a] = true;
        }
    }
    if (!empty($artistSeeds)) {
        $artists = array_values(array_keys($artistSeeds));
        $in = implode(',', array_fill(0, count($artists), '?'));
        $sql = "
            SELECT title, artist, NULLIF(`{$pathCol}`, '') AS file_path
            FROM dj_tracks
            WHERE dj_id = ?
              AND COALESCE(is_available, 1) = 1
              AND artist IN ($in)
              AND NULLIF(`{$pathCol}`, '') IS NOT NULL
            ORDER BY id DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$djId], $artists));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $coreKey = mdjrOverrideKey((string)($d['title'] ?? ''), (string)($d['artist'] ?? ''));
            $path = mdjrNormalizePlaylistPath((string)($d['file_path'] ?? ''));
            if ($coreKey === '' || $path === '') {
                continue;
            }
            if (!isset($pathByCoreKey[$coreKey])) {
                $pathByCoreKey[$coreKey] = $path;
            }
        }
    }

    $safeEvent = preg_replace('/[^a-z0-9\\-_]+/i', '_', (string)($event['title'] ?? 'event_' . $eventId));

    if ($type === 'owned') {
        $filename = sprintf('event_%d_%s_owned.m3u', $eventId, strtolower((string)$safeEvent));
        header('Content-Type: audio/x-mpegurl; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $emitOwned = static function (array $sourceRows) use (
            &$pathByIdentity,
            &$pathByHash,
            &$pathByCoreKey,
            &$pathByOverrideKey,
            &$pathByOverrideTitleCore,
            &$pathBySpotifyLinkedBpm,
            &$staleOverrideKeys
        ): array {
            $lines = [];
            $seenPaths = [];
            foreach ($sourceRows as $row) {
                $tid = (int)($row['track_identity_id'] ?? 0);
                $hash = mdjrCandidateHash($row);
                $sid = trim((string)($row['spotify_track_id'] ?? ''));
                $ovk = mdjrOverrideKey((string)($row['title'] ?? ''), (string)($row['artist'] ?? ''));
                $titleCore = mdjrCoreTitle((string)($row['title'] ?? ''));

                $path = '';
                if ($ovk !== '' && isset($pathByOverrideKey[$ovk])) {
                    $path = $pathByOverrideKey[$ovk];
                } elseif ($titleCore !== '' && isset($pathByOverrideTitleCore[$titleCore])) {
                    $path = $pathByOverrideTitleCore[$titleCore];
                } elseif ($sid !== '' && isset($pathBySpotifyLinkedBpm[$sid])) {
                    $path = $pathBySpotifyLinkedBpm[$sid];
                } elseif ($tid > 0 && isset($pathByIdentity[$tid])) {
                    $path = $pathByIdentity[$tid];
                } elseif ($hash !== '' && isset($pathByHash[$hash])) {
                    $path = $pathByHash[$hash];
                } elseif ($ovk !== '' && isset($pathByCoreKey[$ovk])) {
                    $path = $pathByCoreKey[$ovk];
                }
                $path = mdjrNormalizePlaylistPath($path);
                if ($path === '' || isset($seenPaths[$path])) {
                    continue;
                }
                $seenPaths[$path] = true;

                $artist = trim((string)($row['artist'] ?? 'Unknown Artist'));
                $title = trim((string)($row['title'] ?? 'Unknown Title'));
                $lines[] = "#EXTINF:-1," . $artist . " - " . $title;
                $lines[] = $path;
            }
            return $lines;
        };

        $ownedLines = $emitOwned($rows);
        if (empty($ownedLines)) {
            $fallbackRows = mdjrLoadFallbackRows($db, $eventId);
            if (!empty($fallbackRows)) {
                $identityIds = [];
                $candidateHashes = [];
                foreach ($fallbackRows as $r) {
                    $tid = (int)($r['track_identity_id'] ?? 0);
                    if ($tid > 0) $identityIds[$tid] = true;
                    $h = mdjrCandidateHash($r);
                    if ($h !== '') $candidateHashes[$h] = true;
                }

                if (!empty($identityIds) || !empty($candidateHashes)) {
                    $matchConds = [];
                    $params = [$djId];
                    if (!empty($identityIds)) {
                        $ids = array_keys($identityIds);
                        $in = implode(',', array_fill(0, count($ids), '?'));
                        $matchConds[] = "track_identity_id IN ($in)";
                        $params = array_merge($params, $ids);
                    }
                    if (!empty($candidateHashes)) {
                        $hashes = array_keys($candidateHashes);
                        $in = implode(',', array_fill(0, count($hashes), '?'));
                        $matchConds[] = "normalized_hash IN ($in)";
                        $params = array_merge($params, $hashes);
                    }
                    if (!empty($matchConds)) {
                        $ratingExpr = mdjrRatingExprForDjTracks($db);
                        $djSql = "
                            SELECT
                                d.id,
                                d.track_identity_id,
                                d.normalized_hash,
                                NULLIF(d.`{$pathCol}`, '') AS file_path,
                                {$ratingExpr} AS rating_value,
                                MAX(CASE WHEN dpp.playlist_id IS NULL THEN 0 ELSE 1 END) AS is_preferred
                            FROM dj_tracks d
                            LEFT JOIN dj_playlist_tracks dpt
                                ON dpt.dj_track_id = d.id
                            LEFT JOIN dj_preferred_playlists dpp
                                ON dpp.dj_id = d.dj_id
                               AND dpp.playlist_id = dpt.playlist_id
                            WHERE d.dj_id = ?
                              AND COALESCE(d.is_available, 1) = 1
                              AND (" . implode(' OR ', $matchConds) . ")
                            GROUP BY d.id, d.track_identity_id, d.normalized_hash, d.`{$pathCol}`
                        ";
                        $djStmt = $db->prepare($djSql);
                        $djStmt->execute($params);
                        $bestIdentityCandidate = [];
                        $bestHashCandidate = [];
                        foreach ($djStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                            $tid = (int)($d['track_identity_id'] ?? 0);
                            $hash = trim((string)($d['normalized_hash'] ?? ''));
                            $path = mdjrNormalizePlaylistPath((string)($d['file_path'] ?? ''));
                            if ($path === '') {
                                continue;
                            }
                            $candidate = [
                                'id' => (int)($d['id'] ?? 0),
                                'path' => $path,
                                'rating_value' => isset($d['rating_value']) && is_numeric($d['rating_value']) ? (float)$d['rating_value'] : 0.0,
                                'is_preferred' => !empty($d['is_preferred']) ? 1 : 0,
                            ];
                            if ($tid > 0 && (!isset($bestIdentityCandidate[$tid]) || mdjrIsBetterDjPathCandidate($candidate, $bestIdentityCandidate[$tid]))) {
                                $bestIdentityCandidate[$tid] = $candidate;
                            }
                            if ($hash !== '' && (!isset($bestHashCandidate[$hash]) || mdjrIsBetterDjPathCandidate($candidate, $bestHashCandidate[$hash]))) {
                                $bestHashCandidate[$hash] = $candidate;
                            }
                        }
                        foreach ($bestIdentityCandidate as $tid => $cand) {
                            if (!isset($pathByIdentity[(int)$tid])) {
                                $pathByIdentity[(int)$tid] = (string)($cand['path'] ?? '');
                            }
                        }
                        foreach ($bestHashCandidate as $hash => $cand) {
                            if (!isset($pathByHash[(string)$hash])) {
                                $pathByHash[(string)$hash] = (string)($cand['path'] ?? '');
                            }
                        }
                    }
                }

                $ownedLines = $emitOwned($fallbackRows);
            }
        }

        $exportedTrackCount = (int)floor(count($ownedLines) / 2);
        $totalRequested = count($rows);
        $unresolved = max(0, $totalRequested - $exportedTrackCount);
        header('X-MDJR-Export-Total: ' . $totalRequested);
        header('X-MDJR-Export-Exported: ' . $exportedTrackCount);
        header('X-MDJR-Export-Unresolved: ' . $unresolved);
        header('X-MDJR-Export-Mode: deterministic-resolved-paths');

        echo "#EXTM3U\n";
        foreach ($ownedLines as $line) {
            echo $line . "\n";
        }
        exit;
    }

    $emitMissing = static function (array $sourceRows) use (
        $pathByIdentity,
        $pathByHash,
        $pathByCoreKey,
        $pathByOverrideKey,
        $pathByOverrideTitleCore,
        $pathBySpotifyLinkedBpm,
        $staleOverrideKeys
    ): array {
        $lines = [];
        foreach ($sourceRows as $row) {
            $tid = (int)($row['track_identity_id'] ?? 0);
            $hash = mdjrCandidateHash($row);
            $sid = trim((string)($row['spotify_track_id'] ?? ''));
            $ovk = mdjrOverrideKey((string)($row['title'] ?? ''), (string)($row['artist'] ?? ''));
            $titleCore = mdjrCoreTitle((string)($row['title'] ?? ''));
            $path = '';
            if ($ovk !== '' && isset($pathByOverrideKey[$ovk])) {
                $path = (string)$pathByOverrideKey[$ovk];
            } elseif ($titleCore !== '' && isset($pathByOverrideTitleCore[$titleCore])) {
                $path = (string)$pathByOverrideTitleCore[$titleCore];
            } elseif ($sid !== '' && isset($pathBySpotifyLinkedBpm[$sid])) {
                $path = (string)$pathBySpotifyLinkedBpm[$sid];
            } elseif ($tid > 0 && isset($pathByIdentity[$tid])) {
                $path = (string)$pathByIdentity[$tid];
            } elseif ($hash !== '' && isset($pathByHash[$hash])) {
                $path = (string)$pathByHash[$hash];
            } elseif ($ovk !== '' && isset($pathByCoreKey[$ovk])) {
                $path = (string)$pathByCoreKey[$ovk];
            }
            if (mdjrNormalizePlaylistPath($path) !== '') {
                continue;
            }
            $artist = trim((string)($row['artist'] ?? 'Unknown Artist'));
            $title = trim((string)($row['title'] ?? 'Unknown Title'));
            $lines[] = $artist . ' - ' . $title;
        }
        return $lines;
    };

    $filename = sprintf('event_%d_%s_missing.txt', $eventId, strtolower((string)$safeEvent));
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $missingLines = $emitMissing($rows);
    if (empty($missingLines)) {
        $fallbackRows = mdjrLoadFallbackRows($db, $eventId);
        if (!empty($fallbackRows)) {
            $missingLines = $emitMissing($fallbackRows);
        }
    }
    foreach ($missingLines as $line) {
        echo $line . "\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Failed to export playlist']);
}
