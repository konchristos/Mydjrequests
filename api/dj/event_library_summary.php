<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
if ($eventId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing event_id']);
    exit;
}

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

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

function mdjrHasTableColumn(PDO $db, string $table, string $column): bool
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
    if ($a === '' || $t === '') {
        return '';
    }
    return $a . '|' . $t;
}

try {
    $eventStmt = $db->prepare("
        SELECT id
        FROM events
        WHERE id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $eventStmt->execute([$eventId, $djId]);
    if (!$eventStmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $tracksStmt = $db->prepare("
        SELECT
            e.track_identity_id,
            e.request_count,
            MAX(NULLIF(ti.normalized_hash, '')) AS identity_hash,
            COALESCE(MAX(NULLIF(s.track_name, '')), MAX(NULLIF(sr.song_title, '')), 'Unknown Title') AS title,
            COALESCE(MAX(NULLIF(s.artist_name, '')), MAX(NULLIF(sr.artist, '')), 'Unknown Artist') AS artist,
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
        GROUP BY e.track_identity_id, e.request_count
    ");
    $tracksStmt->execute([':event_id' => $eventId]);
    $eventTracks = $tracksStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRequests = 0;
    foreach ($eventTracks as $row) {
        $totalRequests += (int)($row['request_count'] ?? 0);
    }

    if (empty($eventTracks)) {
        echo json_encode([
            'ok' => true,
            'total_requests' => 0,
            'owned_tracks' => 0,
            'missing_tracks' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identityIds = [];
    $candidateHashes = [];
    foreach ($eventTracks as $row) {
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
    if (!empty($identityIds)) {
        $ids = array_keys($identityIds);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $ownIdStmt = $db->prepare("
            SELECT DISTINCT track_identity_id
            FROM dj_tracks
            WHERE dj_id = ?
              AND COALESCE(is_available, 1) = 1
              AND track_identity_id IN ($in)
        ");
        $ownIdStmt->execute(array_merge([$djId], $ids));
        foreach ($ownIdStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tid = (int)($row['track_identity_id'] ?? 0);
            if ($tid > 0) {
                $ownedByIdentity[$tid] = true;
            }
        }
    }

    $ownedByHash = [];
    if (!empty($candidateHashes)) {
        $hashes = array_keys($candidateHashes);
        $in = implode(',', array_fill(0, count($hashes), '?'));
        $ownHashStmt = $db->prepare("
            SELECT DISTINCT normalized_hash
            FROM dj_tracks
            WHERE dj_id = ?
              AND COALESCE(is_available, 1) = 1
              AND normalized_hash IN ($in)
        ");
        $ownHashStmt->execute(array_merge([$djId], $hashes));
        foreach ($ownHashStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $h = trim((string)($row['normalized_hash'] ?? ''));
            if ($h !== '') {
                $ownedByHash[$h] = true;
            }
        }
    }

    $ownedByManualSpotify = [];
    if (mdjrTableExists($db, 'dj_owned_track_overrides')) {
        $spotifyIds = [];
        foreach ($eventTracks as $row) {
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

    $ownedByEventOverrideKey = [];
    $staleOverrideKeys = [];
    $hasEventOverrides = mdjrTableExists($db, 'dj_event_track_overrides');
    $hasGlobalOverrides = mdjrTableExists($db, 'dj_global_track_overrides');
    if ($hasEventOverrides || $hasGlobalOverrides) {
        $overrideRows = [];
        $overrideKeysPresent = [];
        if ($hasEventOverrides) {
            $eventSelect = mdjrHasTableColumn($db, 'dj_event_track_overrides', 'dj_track_id')
                ? 'override_key, manual_owned, dj_track_id'
                : 'override_key, manual_owned, NULL AS dj_track_id';
            $stmt = $db->prepare("
                SELECT {$eventSelect}
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
            $globalSelect = mdjrHasTableColumn($db, 'dj_global_track_overrides', 'dj_track_id')
                ? 'override_key, manual_owned, dj_track_id'
                : 'override_key, manual_owned, NULL AS dj_track_id';
            $gstmt = $db->prepare("
                SELECT {$globalSelect}
                FROM dj_global_track_overrides
                WHERE dj_id = ?
            ");
            $gstmt->execute([$djId]);
            foreach ($gstmt->fetchAll(PDO::FETCH_ASSOC) as $gov) {
                $k = trim((string)($gov['override_key'] ?? ''));
                if ($k === '' || isset($overrideKeysPresent[$k])) {
                    continue;
                }
                $overrideRows[] = $gov;
                $overrideKeysPresent[$k] = true;
            }
        }

        $exactIds = [];
        foreach ($overrideRows as $ov) {
            $exactId = isset($ov['dj_track_id']) && is_numeric($ov['dj_track_id']) ? (int)$ov['dj_track_id'] : 0;
            if ($exactId > 0) {
                $exactIds[$exactId] = true;
            }
        }
        $availableExactIds = [];
        if (!empty($exactIds)) {
            $ids = array_keys($exactIds);
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
                $availableExactIds[(int)$id] = true;
            }
        }

        foreach ($overrideRows as $ov) {
            if ((int)($ov['manual_owned'] ?? 0) !== 1) {
                continue;
            }
            $k = trim((string)($ov['override_key'] ?? ''));
            if ($k === '') {
                continue;
            }
            $exactId = isset($ov['dj_track_id']) && is_numeric($ov['dj_track_id']) ? (int)$ov['dj_track_id'] : 0;
            if ($exactId > 0) {
                if (isset($availableExactIds[$exactId])) {
                    $ownedByEventOverrideKey[$k] = true;
                } else {
                    $staleOverrideKeys[$k] = true;
                }
            } else {
                $ownedByEventOverrideKey[$k] = true;
            }
        }
    }

    $ownedTracks = 0;
    $missingTracks = 0;
    foreach ($eventTracks as $row) {
        $tid = (int)($row['track_identity_id'] ?? 0);
        $hash = mdjrCandidateHash($row);
        $sid = trim((string)($row['spotify_track_id'] ?? ''));
        $ovk = mdjrOverrideKey((string)($row['title'] ?? ''), (string)($row['artist'] ?? ''));
        $isOwned = (($ovk !== '' && isset($staleOverrideKeys[$ovk])) ? false : (
            ($tid > 0 && isset($ownedByIdentity[$tid]))
            || ($hash !== '' && isset($ownedByHash[$hash]))
            || ($sid !== '' && isset($ownedByManualSpotify[$sid]))
            || ($ovk !== '' && isset($ownedByEventOverrideKey[$ovk]))
        ));
        if ($isOwned) {
            $ownedTracks++;
        } else {
            $missingTracks++;
        }
    }

    echo json_encode([
        'ok' => true,
        'total_requests' => $totalRequests,
        'owned_tracks' => $ownedTracks,
        'missing_tracks' => $missingTracks,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load library summary']);
}
