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
    $title = preg_replace('/\(.*?\)|\[.*?\]/u', ' ', $title);
    $title = preg_replace('/\b(remaster(?:ed)?|radio|edit|mix|version|clean|explicit|mono|stereo)\b/u', ' ', (string)$title);
    $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string)$title);
    $title = preg_replace('/\s+/u', ' ', trim((string)$title));
    return (string)$title;
}

function mdjrCoreArtist(string $artist): string
{
    $artist = mb_strtolower($artist, 'UTF-8');
    $artist = preg_replace('/\b(feat|ft|featuring)\b\.?/u', ' ', $artist);
    $artist = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string)$artist);
    $artist = preg_replace('/\s+/u', ' ', trim((string)$artist));
    return (string)$artist;
}

function mdjrOverrideKey(string $title, string $artist): string
{
    $t = mdjrCoreTitle($title);
    $a = mdjrCoreArtist($artist);
    if ($t === '' && $a === '') {
        return '';
    }
    return hash('sha256', $t . '|' . $a);
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

    $ownedByIdentity = [];
    $ownedByHash = [];
    $ownedByManualSpotify = [];
    $ownedByEventOverrideKey = [];
    $pathByIdentity = [];
    $pathByHash = [];
    $pathByCoreKey = [];
    $pathCandidatesByArtist = [];

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

        $djSql = "
            SELECT id, track_identity_id, normalized_hash, artist, title, NULLIF(`{$pathCol}`, '') AS file_path
            FROM dj_tracks
            WHERE dj_id = ?
              AND (" . implode(' OR ', $matchConds) . ")
            ORDER BY id DESC
        ";
        $djStmt = $db->prepare($djSql);
        $djStmt->execute($params);
        foreach ($djStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tid = (int)($row['track_identity_id'] ?? 0);
            $hash = trim((string)($row['normalized_hash'] ?? ''));
            $path = trim((string)($row['file_path'] ?? ''));

            if ($tid > 0) {
                $ownedByIdentity[$tid] = true;
                if ($path !== '' && !isset($pathByIdentity[$tid])) {
                    $pathByIdentity[$tid] = $path;
                }
            }
            if ($hash !== '') {
                $ownedByHash[$hash] = true;
                if ($path !== '' && !isset($pathByHash[$hash])) {
                    $pathByHash[$hash] = $path;
                }
            }
        }
    }

    if (mdjrTableExists($db, 'dj_owned_track_overrides')) {
        $spotifyIds = [];
        foreach ($rows as $row) {
            $sid = trim((string)($row['spotify_track_id'] ?? ''));
            if ($sid !== '') {
                $spotifyIds[$sid] = true;
            }
        }
        if (!empty($spotifyIds)) {
            $ids = array_keys($spotifyIds);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("
                SELECT spotify_track_id
                FROM dj_owned_track_overrides
                WHERE dj_id = ?
                  AND spotify_track_id IN ($in)
            ");
            $stmt->execute(array_merge([$djId], $ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sid = trim((string)($r['spotify_track_id'] ?? ''));
                if ($sid !== '') {
                    $ownedByManualSpotify[$sid] = true;
                }
            }
        }
    }

    if (mdjrTableExists($db, 'dj_event_track_overrides')) {
        $stmt = $db->prepare("
            SELECT override_key, manual_owned
            FROM dj_event_track_overrides
            WHERE dj_id = ?
              AND event_id = ?
        ");
        $stmt->execute([$djId, $eventId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ov) {
            if ((int)($ov['manual_owned'] ?? 0) !== 1) {
                continue;
            }
            $k = trim((string)($ov['override_key'] ?? ''));
            if ($k !== '') {
                $ownedByEventOverrideKey[$k] = true;
            }
        }
    }

    // Path fallback index: helps when ownership is inferred (manual/global match)
    // but no direct identity/hash path exists for the request row.
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
              AND artist IN ($in)
              AND NULLIF(`{$pathCol}`, '') IS NOT NULL
            ORDER BY id DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$djId], $artists));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $coreKey = mdjrOverrideKey((string)($d['title'] ?? ''), (string)($d['artist'] ?? ''));
            $path = mdjrNormalizePlaylistPath((string)($d['file_path'] ?? ''));
            $artistCore = mdjrCoreArtist((string)($d['artist'] ?? ''));
            $titleCore = mdjrCoreTitle((string)($d['title'] ?? ''));
            if ($coreKey === '' || $path === '') {
                continue;
            }
            if (!isset($pathByCoreKey[$coreKey])) {
                $pathByCoreKey[$coreKey] = $path;
            }
            if ($artistCore !== '' && $titleCore !== '') {
                if (!isset($pathCandidatesByArtist[$artistCore])) {
                    $pathCandidatesByArtist[$artistCore] = [];
                }
                $pathCandidatesByArtist[$artistCore][] = [
                    'title_core' => $titleCore,
                    'path' => $path,
                ];
            }
        }
    }

    // Wide fallback index across full DJ library (non-empty file paths only).
    // Used only if direct identity/hash/core-key path resolution fails.
    try {
        $allStmt = $db->prepare("
            SELECT title, artist, NULLIF(`{$pathCol}`, '') AS file_path
            FROM dj_tracks
            WHERE dj_id = ?
              AND NULLIF(`{$pathCol}`, '') IS NOT NULL
            ORDER BY id DESC
        ");
        $allStmt->execute([$djId]);
        foreach ($allStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $path = mdjrNormalizePlaylistPath((string)($d['file_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $artistCore = mdjrCoreArtist((string)($d['artist'] ?? ''));
            $titleCore = mdjrCoreTitle((string)($d['title'] ?? ''));
            if ($artistCore === '' || $titleCore === '') {
                continue;
            }
            if (!isset($pathCandidatesByArtist[$artistCore])) {
                $pathCandidatesByArtist[$artistCore] = [];
            }
            $pathCandidatesByArtist[$artistCore][] = [
                'title_core' => $titleCore,
                'path' => $path,
            ];
        }
    } catch (Throwable $e) {
        // Non-blocking; export continues with existing maps.
    }

    $safeEvent = preg_replace('/[^a-z0-9\\-_]+/i', '_', (string)($event['title'] ?? 'event_' . $eventId));

    if ($type === 'owned') {
        $filename = sprintf('event_%d_%s_owned.m3u', $eventId, strtolower((string)$safeEvent));
        header('Content-Type: audio/x-mpegurl; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $emitOwned = static function (array $sourceRows) use (
            &$ownedByIdentity,
            &$ownedByHash,
            &$ownedByManualSpotify,
            &$ownedByEventOverrideKey,
            &$pathByIdentity,
            &$pathByHash,
            &$pathByCoreKey,
            &$pathCandidatesByArtist
        ): array {
            $lines = [];
            $seenPaths = [];
            foreach ($sourceRows as $row) {
                $tid = (int)($row['track_identity_id'] ?? 0);
                $hash = mdjrCandidateHash($row);
                $sid = trim((string)($row['spotify_track_id'] ?? ''));
                $ovk = mdjrOverrideKey((string)($row['title'] ?? ''), (string)($row['artist'] ?? ''));
                $isOwned = ($tid > 0 && isset($ownedByIdentity[$tid]))
                    || ($hash !== '' && isset($ownedByHash[$hash]))
                    || ($sid !== '' && isset($ownedByManualSpotify[$sid]))
                    || ($ovk !== '' && isset($ownedByEventOverrideKey[$ovk]));
                if (!$isOwned) {
                    continue;
                }

                $path = '';
                if ($tid > 0 && isset($pathByIdentity[$tid])) {
                    $path = $pathByIdentity[$tid];
                } elseif ($hash !== '' && isset($pathByHash[$hash])) {
                    $path = $pathByHash[$hash];
                } elseif ($ovk !== '' && isset($pathByCoreKey[$ovk])) {
                    $path = $pathByCoreKey[$ovk];
                } else {
                    // Last fallback: choose best title match within same normalized artist bucket.
                    $artistCore = mdjrCoreArtist((string)($row['artist'] ?? ''));
                    $titleCore = mdjrCoreTitle((string)($row['title'] ?? ''));
                    if ($artistCore !== '' && $titleCore !== '' && !empty($pathCandidatesByArtist[$artistCore])) {
                        $bestPath = '';
                        $bestScore = 0.0;
                        foreach ($pathCandidatesByArtist[$artistCore] as $cand) {
                            $candTitle = (string)($cand['title_core'] ?? '');
                            $candPath = (string)($cand['path'] ?? '');
                            if ($candTitle === '' || $candPath === '') {
                                continue;
                            }
                            similar_text($titleCore, $candTitle, $pct);
                            $score = (float)$pct;
                            if (str_contains($titleCore, $candTitle) || str_contains($candTitle, $titleCore)) {
                                $score = max($score, 90.0);
                            }
                            if ($score > $bestScore) {
                                $bestScore = $score;
                                $bestPath = $candPath;
                            }
                        }
                        if ($bestPath !== '' && $bestScore >= 55.0) {
                            $path = $bestPath;
                        }
                    }
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
                        $djSql = "
                            SELECT track_identity_id, normalized_hash, NULLIF(`{$pathCol}`, '') AS file_path
                            FROM dj_tracks
                            WHERE dj_id = ?
                              AND (" . implode(' OR ', $matchConds) . ")
                            ORDER BY id DESC
                        ";
                        $djStmt = $db->prepare($djSql);
                        $djStmt->execute($params);
                        foreach ($djStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                            $tid = (int)($d['track_identity_id'] ?? 0);
                            $hash = trim((string)($d['normalized_hash'] ?? ''));
                            $path = trim((string)($d['file_path'] ?? ''));
                            if ($tid > 0) {
                                $ownedByIdentity[$tid] = true;
                                if ($path !== '' && !isset($pathByIdentity[$tid])) $pathByIdentity[$tid] = $path;
                            }
                            if ($hash !== '') {
                                $ownedByHash[$hash] = true;
                                if ($path !== '' && !isset($pathByHash[$hash])) $pathByHash[$hash] = $path;
                            }
                        }
                    }
                }

                $ownedLines = $emitOwned($fallbackRows);
            }
        }

        echo "#EXTM3U\n";
        foreach ($ownedLines as $line) {
            echo $line . "\n";
        }
        exit;
    }

    $emitMissing = static function (array $sourceRows) use (
        $ownedByIdentity,
        $ownedByHash,
        $ownedByManualSpotify,
        $ownedByEventOverrideKey
    ): array {
        $lines = [];
        foreach ($sourceRows as $row) {
            $tid = (int)($row['track_identity_id'] ?? 0);
            $hash = mdjrCandidateHash($row);
            $sid = trim((string)($row['spotify_track_id'] ?? ''));
            $ovk = mdjrOverrideKey((string)($row['title'] ?? ''), (string)($row['artist'] ?? ''));
            $isOwned = ($tid > 0 && isset($ownedByIdentity[$tid]))
                || ($hash !== '' && isset($ownedByHash[$hash]))
                || ($sid !== '' && isset($ownedByManualSpotify[$sid]))
                || ($ovk !== '' && isset($ownedByEventOverrideKey[$ovk]));
            if ($isOwned) {
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
