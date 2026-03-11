<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Premium feature']);
    exit;
}

$eventUuid = trim((string)($_POST['event_uuid'] ?? ''));
$trackKey = trim((string)($_POST['track_key'] ?? ''));
$spotifyTrackId = trim((string)($_POST['spotify_track_id'] ?? ''));
$spotifyTrackIdsJson = (string)($_POST['spotify_track_ids_json'] ?? '');
$bpmTrackId = (int)($_POST['bpm_track_id'] ?? 0);

if ($eventUuid === '' || $trackKey === '' || $bpmTrackId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing required parameters']);
    exit;
}

ensureDjOwnedTrackOverridesTable($db);

$eventStmt = $db->prepare(
    "SELECT id FROM events WHERE uuid = ? AND user_id = ? LIMIT 1"
);
$eventStmt->execute([$eventUuid, (int)($_SESSION['dj_id'] ?? 0)]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
$eventId = (int)$event['id'];
ensureDjEventTrackOverridesTable($db);

$targetSpotifyIds = [];
if ($spotifyTrackId !== '') {
    $targetSpotifyIds[$spotifyTrackId] = true;
}

if ($spotifyTrackIdsJson !== '') {
    $decoded = json_decode($spotifyTrackIdsJson, true);
    if (is_array($decoded)) {
        foreach ($decoded as $sid) {
            $sid = trim((string)$sid);
            if ($sid !== '') {
                $targetSpotifyIds[$sid] = true;
            }
        }
    }
}

$reqStmt = $db->prepare(
    "
    SELECT DISTINCT NULLIF(spotify_track_id, '') AS spotify_track_id
    FROM song_requests
    WHERE event_id = :event_id
      AND COALESCE(NULLIF(spotify_track_id, ''), CONCAT(song_title, '::', artist)) = :track_key
"
);
$reqStmt->execute([
    ':event_id' => $eventId,
    ':track_key' => $trackKey,
]);
foreach ($reqStmt->fetchAll(PDO::FETCH_ASSOC) as $rr) {
    $sid = trim((string)($rr['spotify_track_id'] ?? ''));
    if ($sid !== '') {
        $targetSpotifyIds[$sid] = true;
    }
}

$targetSpotifyIds = array_keys($targetSpotifyIds);
$hasSpotifyIds = !empty($targetSpotifyIds);
$spotifyTrackId = $hasSpotifyIds ? (string)$targetSpotifyIds[0] : '';

$bpmStmt = $db->prepare("SELECT id, title, artist, bpm, key_text, year FROM bpm_test_tracks WHERE id = ? LIMIT 1");
$bpmStmt->execute([$bpmTrackId]);
$bpm = $bpmStmt->fetch(PDO::FETCH_ASSOC);
if (!$bpm) {
    echo json_encode(['ok' => false, 'error' => 'Selected BPM track not found']);
    exit;
}

$selectedOwned = false;
$selectedPreferred = false;
$selectedHash = mdjrCandidateTrackHash(
    (string)($bpm['title'] ?? ''),
    (string)($bpm['artist'] ?? '')
);
if ($selectedHash !== '') {
    $ownCheck = $db->prepare("
        SELECT id
        FROM dj_tracks
        WHERE dj_id = ?
          AND normalized_hash = ?
        LIMIT 1
    ");
    $ownCheck->execute([(int)($_SESSION['dj_id'] ?? 0), $selectedHash]);
    $selectedOwned = (bool)$ownCheck->fetchColumn();

    $prefCheck = $db->prepare("
        SELECT 1
        FROM dj_tracks d
        INNER JOIN dj_playlist_tracks dpt
            ON dpt.dj_track_id = d.id
        INNER JOIN dj_preferred_playlists dpp
            ON dpp.dj_id = d.dj_id
           AND dpp.playlist_id = dpt.playlist_id
        WHERE d.dj_id = ?
          AND d.normalized_hash = ?
        LIMIT 1
    ");
    $prefCheck->execute([(int)($_SESSION['dj_id'] ?? 0), $selectedHash]);
    $selectedPreferred = (bool)$prefCheck->fetchColumn();
}

$bpmValue = (isset($bpm['bpm']) && is_numeric($bpm['bpm']) && (float)$bpm['bpm'] > 0)
    ? (float)$bpm['bpm']
    : null;
$keyValueRaw = trim((string)($bpm['key_text'] ?? ''));
$keyValue = $keyValueRaw !== '' ? substr(preg_replace('/\s+/', '', strtoupper($keyValueRaw)), 0, 16) : null;
$yearValue = (isset($bpm['year']) && is_numeric($bpm['year']) && (int)$bpm['year'] > 0)
    ? (int)$bpm['year']
    : null;

$reqMetaStmt = $db->prepare(
    "
    SELECT
      COALESCE(MAX(NULLIF(spotify_track_name, '')), MAX(song_title)) AS song_title,
      COALESCE(MAX(NULLIF(spotify_artist_name, '')), MAX(artist)) AS artist
    FROM song_requests
    WHERE event_id = :event_id
      AND COALESCE(NULLIF(spotify_track_id, ''), CONCAT(song_title, '::', artist)) = :track_key
    LIMIT 1
"
);
$reqMetaStmt->execute([
    ':event_id' => $eventId,
    ':track_key' => $trackKey,
]);
$reqMeta = $reqMetaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$overrideTitle = trim((string)($reqMeta['song_title'] ?? ''));
$overrideArtist = trim((string)($reqMeta['artist'] ?? ''));
$overrideKey = mdjrOverrideTrackKey($overrideTitle, $overrideArtist);

try {
    $db->beginTransaction();

    $linkSql = "
        INSERT INTO track_links (
            spotify_track_id,
            bpm_track_id,
            confidence_score,
            confidence_level,
            match_meta
        ) VALUES (
            :spotify_track_id,
            :bpm_track_id,
            :confidence_score,
            :confidence_level,
            :match_meta
        )
        ON DUPLICATE KEY UPDATE
            bpm_track_id = VALUES(bpm_track_id),
            confidence_score = VALUES(confidence_score),
            confidence_level = VALUES(confidence_level),
            match_meta = VALUES(match_meta)
    ";

    $linkStmt = $db->prepare($linkSql);

    $ownStmt = $db->prepare("
        INSERT INTO dj_owned_track_overrides (
            dj_id,
            spotify_track_id,
            source
        ) VALUES (
            :dj_id,
            :spotify_track_id,
            :source
        )
        ON DUPLICATE KEY UPDATE
            source = VALUES(source),
            updated_at = CURRENT_TIMESTAMP
    ");
    $updates = [];

    if ($bpmValue !== null) {
        $updates[] = 'bpm = :bpm';
    }
    if ($keyValue !== null) {
        $updates[] = 'musical_key = :musical_key';
    }
    if ($yearValue !== null) {
        $updates[] = 'release_year = :release_year';
    }

    $appliedKey = $keyValue;
    if ($hasSpotifyIds) {
        foreach ($targetSpotifyIds as $sid) {
            $linkStmt->execute([
                ':spotify_track_id' => $sid,
                ':bpm_track_id' => $bpmTrackId,
                ':confidence_score' => 99,
                ':confidence_level' => 'very_high',
                ':match_meta' => json_encode([
                    'source' => 'manual_admin_override',
                    'event_id' => $eventId,
                    'track_key' => $trackKey,
                    'admin_user_id' => (int)($_SESSION['dj_id'] ?? 0),
                    'at_utc' => gmdate('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE),
            ]);

            if ($selectedOwned) {
                $ownStmt->execute([
                    ':dj_id' => (int)($_SESSION['dj_id'] ?? 0),
                    ':spotify_track_id' => $sid,
                    ':source' => 'manual_metadata_match',
                ]);
            }

            $params = [':spotify_track_id' => $sid];
            if ($bpmValue !== null) {
                $params[':bpm'] = $bpmValue;
            }
            if ($keyValue !== null) {
                $params[':musical_key'] = $keyValue;
            }
            if ($yearValue !== null) {
                $params[':release_year'] = $yearValue;
            }

            if ($updates) {
                try {
                    $metaStmt = $db->prepare(
                        'UPDATE spotify_tracks SET ' . implode(', ', $updates) . ' WHERE spotify_track_id = :spotify_track_id LIMIT 1'
                    );
                    $metaStmt->execute($params);
                } catch (PDOException $e) {
                    // Some installs use a strict ENUM for musical_key. If key does not fit,
                    // retry without key instead of failing the whole manual match.
                    if ($keyValue !== null && stripos($e->getMessage(), 'musical_key') !== false) {
                        $appliedKey = null;
                        $updatesNoKey = array_values(array_filter($updates, static fn(string $s): bool => stripos($s, 'musical_key') === false));
                        $paramsNoKey = $params;
                        unset($paramsNoKey[':musical_key']);
                        if ($updatesNoKey) {
                            $metaStmt = $db->prepare(
                                'UPDATE spotify_tracks SET ' . implode(', ', $updatesNoKey) . ' WHERE spotify_track_id = :spotify_track_id LIMIT 1'
                            );
                            $metaStmt->execute($paramsNoKey);
                        }
                    } else {
                        throw $e;
                    }
                }
            }
        }
    }

    if ($overrideKey !== '') {
        $ovStmt = $db->prepare("
            INSERT INTO dj_event_track_overrides (
                dj_id,
                event_id,
                override_key,
                bpm_track_id,
                bpm,
                musical_key,
                release_year,
                manual_owned,
                manual_preferred
            ) VALUES (
                :dj_id,
                :event_id,
                :override_key,
                :bpm_track_id,
                :bpm,
                :musical_key,
                :release_year,
                :manual_owned,
                :manual_preferred
            )
            ON DUPLICATE KEY UPDATE
                bpm_track_id = VALUES(bpm_track_id),
                bpm = VALUES(bpm),
                musical_key = VALUES(musical_key),
                release_year = VALUES(release_year),
                manual_owned = VALUES(manual_owned),
                manual_preferred = VALUES(manual_preferred),
                updated_at = CURRENT_TIMESTAMP
        ");
        $ovStmt->execute([
            ':dj_id' => (int)($_SESSION['dj_id'] ?? 0),
            ':event_id' => $eventId,
            ':override_key' => $overrideKey,
            ':bpm_track_id' => $bpmTrackId,
            ':bpm' => $bpmValue,
            ':musical_key' => $appliedKey,
            ':release_year' => $yearValue,
            ':manual_owned' => $selectedOwned ? 1 : 0,
            ':manual_preferred' => $selectedPreferred ? 1 : 0,
        ]);
    }

    $db->commit();

    echo json_encode([
        'ok' => true,
        'spotify_track_id' => $spotifyTrackId,
        'applied_spotify_track_ids' => $targetSpotifyIds,
        'non_spotify_override' => !$hasSpotifyIds,
        'owned_marked' => $selectedOwned,
        'selected_preferred' => $selectedPreferred,
        'applied' => [
            'bpm' => $bpmValue,
            'musical_key' => $appliedKey,
            'release_year' => $yearValue,
        ]
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $msg = is_admin() ? ('Failed to apply manual match: ' . $e->getMessage()) : 'Failed to apply manual match';
    echo json_encode(['ok' => false, 'error' => $msg]);
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
        $colStmt = $db->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dj_event_track_overrides'
        ");
        $colStmt->execute();
        $cols = [];
        foreach (($colStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $col) {
            $cols[strtolower((string)$col)] = true;
        }
        if (empty($cols['bpm_track_id'])) {
            $db->exec("ALTER TABLE dj_event_track_overrides ADD COLUMN bpm_track_id BIGINT UNSIGNED NULL AFTER override_key");
        }
        if (empty($cols['manual_preferred'])) {
            $db->exec("ALTER TABLE dj_event_track_overrides ADD COLUMN manual_preferred TINYINT(1) NOT NULL DEFAULT 0 AFTER manual_owned");
        }
    } catch (Throwable $e) {
        // non-fatal
    }
}

function mdjrOverrideTrackKey(string $title, string $artist): string
{
    $a = mdjrCoreArtist($artist);
    $t = mdjrCoreTitle($title);
    if ($a === '' || $t === '') {
        return '';
    }
    return $a . '|' . $t;
}

function mdjrCoreArtist(string $artist): string
{
    $artist = mb_strtolower($artist, 'UTF-8');
    $artist = preg_replace('/\\b(feat|ft|featuring|x|vs)\\b/u', ' ', $artist);
    $artist = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $artist);
    $artist = preg_replace('/\\s+/u', ' ', trim($artist));
    return (string)$artist;
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
    $title = preg_replace('/\\b(feat|ft|featuring|remix|mix|edit|version|remaster(?:ed)?|radio|extended|club|original|live|explicit|clean|mono|stereo|instrumental|karaoke|rework|dub)\\b/u', ' ', $title);
    $title = preg_replace('/\\b(19|20)\\d{2}\\b/u', ' ', $title);
    $title = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $title);
    $title = preg_replace('/\\s+/u', ' ', trim($title));
    return (string)$title;
}

function mdjrCandidateTrackHash(string $title, string $artist): string
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
