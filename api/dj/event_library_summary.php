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
            if ($k === '') {
                continue;
            }
            $ownedByEventOverrideKey[$k] = true;
        }
    }

    $ownedTracks = 0;
    $missingTracks = 0;
    foreach ($eventTracks as $row) {
        $tid = (int)($row['track_identity_id'] ?? 0);
        $hash = mdjrCandidateHash($row);
        $sid = trim((string)($row['spotify_track_id'] ?? ''));
        $ovk = mdjrOverrideKey((string)($row['title'] ?? ''), (string)($row['artist'] ?? ''));
        $isOwned = ($tid > 0 && isset($ownedByIdentity[$tid]))
            || ($hash !== '' && isset($ownedByHash[$hash]))
            || ($sid !== '' && isset($ownedByManualSpotify[$sid]))
            || ($ovk !== '' && isset($ownedByEventOverrideKey[$ovk]));
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
