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

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}

$eventUuid = trim((string)($_POST['event_uuid'] ?? ''));
$trackKey = trim((string)($_POST['track_key'] ?? ''));
$spotifyTrackId = trim((string)($_POST['spotify_track_id'] ?? ''));
$bpmTrackId = (int)($_POST['bpm_track_id'] ?? 0);

if ($eventUuid === '' || $trackKey === '' || $bpmTrackId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing required parameters']);
    exit;
}

$db = db();

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

if ($spotifyTrackId === '') {
    $reqStmt = $db->prepare(
        "
        SELECT MAX(NULLIF(spotify_track_id, '')) AS spotify_track_id
        FROM song_requests
        WHERE event_id = :event_id
          AND COALESCE(NULLIF(spotify_track_id, ''), CONCAT(song_title, '::', artist)) = :track_key
        LIMIT 1
    "
    );
    $reqStmt->execute([
        ':event_id' => $eventId,
        ':track_key' => $trackKey,
    ]);
    $spotifyTrackId = trim((string)$reqStmt->fetchColumn());
}

if ($spotifyTrackId === '') {
    echo json_encode([
        'ok' => false,
        'error' => 'Track has no Spotify ID, cannot persist metadata yet'
    ]);
    exit;
}

$bpmStmt = $db->prepare("SELECT id, bpm, key_text, year FROM bpm_test_tracks WHERE id = ? LIMIT 1");
$bpmStmt->execute([$bpmTrackId]);
$bpm = $bpmStmt->fetch(PDO::FETCH_ASSOC);
if (!$bpm) {
    echo json_encode(['ok' => false, 'error' => 'Selected BPM track not found']);
    exit;
}

$bpmValue = (isset($bpm['bpm']) && is_numeric($bpm['bpm']) && (float)$bpm['bpm'] > 0)
    ? (float)$bpm['bpm']
    : null;
$keyValueRaw = trim((string)($bpm['key_text'] ?? ''));
$keyValue = $keyValueRaw !== '' ? substr(preg_replace('/\s+/', '', strtoupper($keyValueRaw)), 0, 16) : null;
$yearValue = (isset($bpm['year']) && is_numeric($bpm['year']) && (int)$bpm['year'] > 0)
    ? (int)$bpm['year']
    : null;

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
    $linkStmt->execute([
        ':spotify_track_id' => $spotifyTrackId,
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

    $updates = [];
    $params = [':spotify_track_id' => $spotifyTrackId];

    if ($bpmValue !== null) {
        $updates[] = 'bpm = :bpm';
        $params[':bpm'] = $bpmValue;
    }
    if ($keyValue !== null) {
        $updates[] = 'musical_key = :musical_key';
        $params[':musical_key'] = $keyValue;
    }
    if ($yearValue !== null) {
        $updates[] = 'release_year = :release_year';
        $params[':release_year'] = $yearValue;
    }

    $appliedKey = $keyValue;
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

    $db->commit();

    echo json_encode([
        'ok' => true,
        'spotify_track_id' => $spotifyTrackId,
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
