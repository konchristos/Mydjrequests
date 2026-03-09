<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once APP_ROOT . '/app/helpers/event_tracks_projection.php';
require_once APP_ROOT . '/app/lib/spotify_playlist.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}

$eventUuid = trim((string)($_POST['event_uuid'] ?? ''));
$requestId = (int)($_POST['request_id'] ?? 0);
$guestToken = trim((string)($_COOKIE['mdjr_guest'] ?? ''));

if ($eventUuid === '' || $requestId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing parameters.']);
    exit;
}

if ($guestToken === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Guest token missing.']);
    exit;
}

$eventModel = new Event();
$event = $eventModel->findByUuid($eventUuid);
if (!$event) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Event not found.']);
    exit;
}

$eventId = (int)($event['id'] ?? 0);
if ($eventId <= 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Event not found.']);
    exit;
}

$db = db();

try {
    $db->beginTransaction();

    $sel = $db->prepare("
        SELECT
            id,
            event_id,
            track_identity_id,
            status,
            created_at,
            COALESCE(NULLIF(spotify_track_id, ''), CONCAT(LOWER(song_title), '::', LOWER(artist))) AS track_key
        FROM song_requests
        WHERE id = :id
          AND event_id = :event_id
          AND guest_token = :guest_token
        LIMIT 1
        FOR UPDATE
    ");
    $sel->execute([
        ':id' => $requestId,
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
    ]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Request not found.']);
        exit;
    }

    $status = strtolower((string)($row['status'] ?? ''));
    if ($status === 'played') {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Played requests cannot be removed.']);
        exit;
    }

    $trackKey = trim((string)($row['track_key'] ?? ''));
    if ($trackKey !== '') {
        $boostStmt = $db->prepare("
            SELECT COUNT(*)
            FROM event_track_boosts
            WHERE event_id = :event_id
              AND guest_token = :guest_token
              AND track_key = :track_key
              AND status = 'succeeded'
        ");
        $boostStmt->execute([
            ':event_id' => $eventId,
            ':guest_token' => $guestToken,
            ':track_key' => $trackKey,
        ]);
        $hasBoost = (int)$boostStmt->fetchColumn() > 0;
        if ($hasBoost) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Boosted requests cannot be removed.']);
            exit;
        }

        $voteStmt = $db->prepare("
            SELECT COUNT(*)
            FROM song_votes
            WHERE event_id = :event_id
              AND guest_token = :guest_token
              AND track_key = :track_key
        ");
        $voteStmt->execute([
            ':event_id' => $eventId,
            ':guest_token' => $guestToken,
            ':track_key' => $trackKey,
        ]);
        $hasVote = (int)$voteStmt->fetchColumn() > 0;
        if ($hasVote) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Voted requests cannot be removed.']);
            exit;
        }
    }

    $trackIdentityId = isset($row['track_identity_id']) ? (int)$row['track_identity_id'] : 0;
    if ($trackIdentityId > 0) {
        eventTracksProjectionDecrementRequest($db, $eventId, $trackIdentityId);
    }

    // Keep per-event aggregate stats in sync.
    $eventStatsStmt = $db->prepare("
        UPDATE event_request_stats
        SET total_requests = GREATEST(total_requests - 1, 0)
        WHERE event_id = :event_id
    ");
    $eventStatsStmt->execute([':event_id' => $eventId]);

    // Keep DJ monthly/lifetime aggregate stats in sync.
    $djId = (int)($event['user_id'] ?? 0);
    if ($djId > 0) {
        $createdAtRaw = trim((string)($row['created_at'] ?? ''));
        $dt = null;
        if ($createdAtRaw !== '') {
            try {
                $dt = new DateTimeImmutable($createdAtRaw);
            } catch (Throwable $e) {
                $dt = null;
            }
        }
        if ($dt === null) {
            $dt = new DateTimeImmutable('now');
        }
        $year = (int)$dt->format('Y');
        $month = (int)$dt->format('n');

        $monthlyStmt = $db->prepare("
            UPDATE song_request_stats_monthly
            SET total_requests = GREATEST(total_requests - 1, 0)
            WHERE dj_id = :dj_id
              AND year = :year
              AND month = :month
        ");
        $monthlyStmt->execute([
            ':dj_id' => $djId,
            ':year' => $year,
            ':month' => $month,
        ]);
    }

    $del = $db->prepare("
        DELETE FROM song_requests
        WHERE id = :id
          AND event_id = :event_id
          AND guest_token = :guest_token
        LIMIT 1
    ");
    $del->execute([
        ':id' => $requestId,
        ':event_id' => $eventId,
        ':guest_token' => $guestToken,
    ]);

    if ($del->rowCount() !== 1) {
        throw new RuntimeException('Failed to delete request row.');
    }

    $db->commit();

    // Keep Spotify playlist in sync with current request set.
    // This runs after DB commit so delete success is never rolled back by Spotify/API issues.
    $playlistSyncOk = null;
    $playlistSyncError = null;
    $djId = (int)($event['user_id'] ?? 0);
    if ($djId > 0) {
        try {
            $syncRes = syncEventPlaylistFromRequests($db, $djId, $eventId);
            $playlistSyncOk = (bool)($syncRes['ok'] ?? false);
            if (!$playlistSyncOk) {
                $playlistSyncError = (string)($syncRes['error'] ?? 'Playlist sync failed');
            }
        } catch (Throwable $syncErr) {
            $playlistSyncOk = false;
            $playlistSyncError = 'Playlist sync failed';
        }
    }

    echo json_encode([
        'ok' => true,
        'deleted' => true,
        'request_id' => $requestId,
        'playlist_sync_ok' => $playlistSyncOk,
        'playlist_sync_error' => $playlistSyncError,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to delete request.',
    ]);
}
