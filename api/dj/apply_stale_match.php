<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/dj_stale_matches.php';
require_dj_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Premium feature']);
    exit;
}

$djId = (int)($_SESSION['dj_id'] ?? 0);
$overrideKey = trim((string)($_POST['override_key'] ?? ''));
$bpmTrackId = (int)($_POST['bpm_track_id'] ?? 0);
$djTrackId = (int)($_POST['dj_track_id'] ?? 0);
$localOnly = (int)($_POST['local_only'] ?? 0) === 1;
if ($djId <= 0 || $overrideKey === '' || ($bpmTrackId <= 0 && $djTrackId <= 0)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required parameters']);
    exit;
}

mdjrEnsureDjGlobalTrackOverridesTable($db);
mdjrEnsureDjTrackAvailabilityColumns($db);
mdjrEnsureOverrideDjTrackIdColumn($db, 'dj_global_track_overrides');
if (mdjrTableExistsForStale($db, 'dj_event_track_overrides')) {
    mdjrEnsureOverrideDjTrackIdColumn($db, 'dj_event_track_overrides');
}

$existsStmt = $db->prepare("
    SELECT id
    FROM dj_global_track_overrides
    WHERE dj_id = ?
      AND override_key = ?
    LIMIT 1
");
$existsStmt->execute([$djId, $overrideKey]);
if (!(int)$existsStmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Saved match not found']);
    exit;
}

$selectedDjTrackId = 0;
$owned = false;
$preferred = false;
$bpmValue = null;
$keyValue = null;
$yearValue = null;

if ($localOnly && $djTrackId > 0) {
    $djTrackStmt = $db->prepare("
        SELECT id, bpm, musical_key, release_year
        FROM dj_tracks
        WHERE dj_id = ?
          AND id = ?
          AND COALESCE(is_available, 1) = 1
        LIMIT 1
    ");
    $djTrackStmt->execute([$djId, $djTrackId]);
    $selectedDjTrack = $djTrackStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$selectedDjTrack) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Selected local track not found']);
        exit;
    }
    $selectedDjTrackId = (int)($selectedDjTrack['id'] ?? 0);
    $owned = $selectedDjTrackId > 0;
    $preferred = mdjrDjTrackIsPreferredForStale($db, $djId, $selectedDjTrackId);
    $bpmTrackId = 0;
    $bpmValue = (isset($selectedDjTrack['bpm']) && is_numeric($selectedDjTrack['bpm']) && (float)$selectedDjTrack['bpm'] > 0)
        ? (float)$selectedDjTrack['bpm']
        : null;
    $keyValueRaw = trim((string)($selectedDjTrack['musical_key'] ?? ''));
    $keyValue = $keyValueRaw !== '' ? substr(preg_replace('/\s+/', '', strtoupper($keyValueRaw)), 0, 16) : null;
    $yearValue = (isset($selectedDjTrack['release_year']) && is_numeric($selectedDjTrack['release_year']) && (int)$selectedDjTrack['release_year'] > 0)
        ? (int)$selectedDjTrack['release_year']
        : null;
} else {
    $bpmStmt = $db->prepare("
        SELECT id, title, artist, bpm, key_text, year
        FROM bpm_test_tracks
        WHERE id = ?
        LIMIT 1
    ");
    $bpmStmt->execute([$bpmTrackId]);
    $bpm = $bpmStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$bpm) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Selected BPM track not found']);
        exit;
    }

    $hash = mdjrCandidateTrackHashForStale((string)($bpm['title'] ?? ''), (string)($bpm['artist'] ?? ''));
    $selectedDjTrack = $hash !== '' ? mdjrResolveBestAvailableDjTrackByHash($db, $djId, $hash) : null;
    $selectedDjTrackId = is_array($selectedDjTrack) ? (int)($selectedDjTrack['id'] ?? 0) : 0;
    $owned = $selectedDjTrackId > 0;
    $preferred = is_array($selectedDjTrack) && !empty($selectedDjTrack['is_preferred']);

    $bpmValue = (isset($bpm['bpm']) && is_numeric($bpm['bpm']) && (float)$bpm['bpm'] > 0) ? (float)$bpm['bpm'] : null;
    $keyValue = trim((string)($bpm['key_text'] ?? ''));
    $keyValue = $keyValue !== '' ? substr(preg_replace('/\s+/', '', strtoupper($keyValue)), 0, 16) : null;
    $yearValue = (isset($bpm['year']) && is_numeric($bpm['year']) && (int)$bpm['year'] > 0) ? (int)$bpm['year'] : null;
}

try {
    $db->beginTransaction();

    $globalStmt = $db->prepare("
        UPDATE dj_global_track_overrides
        SET bpm_track_id = :bpm_track_id,
            dj_track_id = :dj_track_id,
            bpm = :bpm,
            musical_key = :musical_key,
            release_year = :release_year,
            manual_owned = :manual_owned,
            manual_preferred = :manual_preferred,
            updated_at = CURRENT_TIMESTAMP
        WHERE dj_id = :dj_id
          AND override_key = :override_key
        LIMIT 1
    ");
    $globalStmt->execute([
        ':bpm_track_id' => $bpmTrackId,
        ':dj_track_id' => $selectedDjTrackId > 0 ? $selectedDjTrackId : null,
        ':bpm' => $bpmValue,
        ':musical_key' => $keyValue,
        ':release_year' => $yearValue,
        ':manual_owned' => $owned ? 1 : 0,
        ':manual_preferred' => $preferred ? 1 : 0,
        ':dj_id' => $djId,
        ':override_key' => $overrideKey,
    ]);

    if (mdjrTableExistsForStale($db, 'dj_event_track_overrides')) {
        $eventStmt = $db->prepare("
            UPDATE dj_event_track_overrides
            SET bpm_track_id = :bpm_track_id,
                dj_track_id = :dj_track_id,
                bpm = :bpm,
                musical_key = :musical_key,
                release_year = :release_year,
                manual_owned = :manual_owned,
                manual_preferred = :manual_preferred,
                updated_at = CURRENT_TIMESTAMP
            WHERE dj_id = :dj_id
              AND override_key = :override_key
        ");
        $eventStmt->execute([
            ':bpm_track_id' => $bpmTrackId,
            ':dj_track_id' => $selectedDjTrackId > 0 ? $selectedDjTrackId : null,
            ':bpm' => $bpmValue,
            ':musical_key' => $keyValue,
            ':release_year' => $yearValue,
            ':manual_owned' => $owned ? 1 : 0,
            ':manual_preferred' => $preferred ? 1 : 0,
            ':dj_id' => $djId,
            ':override_key' => $overrideKey,
        ]);
    }

    $db->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to apply stale match']);
}

function mdjrTableExistsForStale(PDO $db, string $table): bool
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

function mdjrDjTrackIsPreferredForStale(PDO $db, int $djId, int $djTrackId): bool
{
    if ($djId <= 0 || $djTrackId <= 0) {
        return false;
    }
    $stmt = $db->prepare("
        SELECT 1
        FROM dj_playlist_tracks dpt
        INNER JOIN dj_preferred_playlists dpp
            ON dpp.playlist_id = dpt.playlist_id
           AND dpp.dj_id = ?
        WHERE dpt.dj_track_id = ?
        LIMIT 1
    ");
    $stmt->execute([$djId, $djTrackId]);
    return (bool)$stmt->fetchColumn();
}
