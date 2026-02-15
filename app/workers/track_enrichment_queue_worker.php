<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once APP_ROOT . '/BPM/bpm_matching/matching.php';

$db = db();

function settingEnabledQueue(PDO $db, string $key, bool $default = false): bool
{
    try {
        $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            return $default;
        }
        $val = strtolower(trim((string)$val));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    } catch (Throwable $e) {
        return $default;
    }
}

function ensureQueueSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS track_enrichment_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            spotify_track_id VARCHAR(64) NOT NULL,
            status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL,
            next_attempt_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_track_enrichment_queue_spotify_id (spotify_track_id),
            KEY idx_track_enrichment_queue_status_next (status, next_attempt_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function setQueueStatus(PDO $db, int $id, string $status, ?string $error = null, ?string $retryAt = null): void
{
    $stmt = $db->prepare("
        UPDATE track_enrichment_queue
        SET status = :status,
            last_error = :err,
            next_attempt_at = :retry_at,
            updated_at = UTC_TIMESTAMP()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $status,
        ':err' => $error,
        ':retry_at' => $retryAt,
        ':id' => $id,
    ]);
}

ensureQueueSchema($db);

// Prevent overlapping cron runs. If lock is not available, exit quickly.
$lockName = 'track_enrichment_queue_worker_lock';
$lockStmt = $db->prepare("SELECT GET_LOCK(?, 0)");
$lockStmt->execute([$lockName]);
$gotLock = (int)$lockStmt->fetchColumn() === 1;
if (!$gotLock) {
    echo "Worker already running, skipping.\n";
    exit(0);
}
register_shutdown_function(static function () use ($db, $lockName): void {
    try {
        $unlockStmt = $db->prepare("SELECT RELEASE_LOCK(?)");
        $unlockStmt->execute([$lockName]);
    } catch (Throwable $e) {
        // no-op
    }
});

if (!settingEnabledQueue($db, 'bpm_fuzzy_on_request_enabled', false)) {
    echo "Queue worker disabled: bpm_fuzzy_on_request_enabled=0\n";
    exit(0);
}
if (!settingEnabledQueue($db, 'track_enrichment_worker_enabled', true)) {
    echo "Queue worker disabled: track_enrichment_worker_enabled=0\n";
    exit(0);
}

$options = getopt('', ['limit::', 'dry-run']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 50;
$dryRun = array_key_exists('dry-run', $options);
$fillYear = settingEnabledQueue($db, 'bpm_on_request_fill_year_enabled', false);

echo "Track enrichment queue worker started at " . date('c') . PHP_EOL;
echo "Mode: " . ($dryRun ? "DRY RUN" : "LIVE") . " | Limit: {$limit} | Fill year: " . ($fillYear ? 'YES' : 'NO') . PHP_EOL . PHP_EOL;

// Recovery: unlock stale processing rows (e.g. prior crash).
if (!$dryRun) {
    $db->exec("
        UPDATE track_enrichment_queue
        SET status = 'pending',
            next_attempt_at = NULL,
            last_error = 'auto_recovered_stale_processing',
            updated_at = UTC_TIMESTAMP()
        WHERE status = 'processing'
          AND updated_at < (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)
    ");
}

$pickStmt = $db->prepare("
    SELECT id, spotify_track_id, attempts
    FROM track_enrichment_queue
    WHERE status = 'pending'
      AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
    ORDER BY id ASC
    LIMIT :lim
");
$pickStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$pickStmt->execute();
$jobs = $pickStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$jobs) {
    echo "No pending queue items.\n";
    exit(0);
}

$lockStmt = $db->prepare("
    UPDATE track_enrichment_queue
    SET status = 'processing', attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
    WHERE id = :id AND status = 'pending'
");

$trackStmt = $db->prepare("
    SELECT spotify_track_id, track_name, artist_name, duration_ms, bpm, musical_key, release_year
    FROM spotify_tracks
    WHERE spotify_track_id = ?
    LIMIT 1
");

$linkStmt = $db->prepare("
    SELECT bt.id, bt.bpm, bt.key_text, bt.year
    FROM track_links tl
    INNER JOIN bpm_test_tracks bt ON bt.id = tl.bpm_track_id
    WHERE tl.spotify_track_id = ?
    LIMIT 1
");

$candStmt = $db->prepare("
    SELECT id, title, artist, bpm, key_text, year, genre, time_seconds
    FROM bpm_test_tracks
    WHERE bpm IS NOT NULL
      AND (artist LIKE :artist_like OR title LIKE :title_like)
    ORDER BY id DESC
    LIMIT 220
");

$linkUpsert = $db->prepare("
    INSERT INTO track_links
      (spotify_track_id, bpm_track_id, confidence_score, confidence_level, match_meta)
    VALUES
      (:spotify_id, :bpm_track_id, :score, :level, :meta)
    ON DUPLICATE KEY UPDATE
      bpm_track_id = VALUES(bpm_track_id),
      confidence_score = VALUES(confidence_score),
      confidence_level = VALUES(confidence_level),
      match_meta = VALUES(match_meta)
");

$done = 0;
$failed = 0;
$processed = 0;
$maxAttemptsBeforePermanentFail = 2;

foreach ($jobs as $job) {
    $id = (int)$job['id'];
    $spotifyId = (string)($job['spotify_track_id'] ?? '');

    try {
        if ($spotifyId === '') {
            setQueueStatus($db, $id, 'failed', 'missing_spotify_track_id');
            $failed++;
            continue;
        }

        if (!$dryRun) {
            $lockStmt->execute([':id' => $id]);
            if ($lockStmt->rowCount() === 0) {
                continue;
            }
        }

        $processed++;
        $trackStmt->execute([$spotifyId]);
        $track = $trackStmt->fetch(PDO::FETCH_ASSOC);
        if (!$track) {
            if (!$dryRun) setQueueStatus($db, $id, 'failed', 'spotify_track_not_found');
            $failed++;
            continue;
        }

        $missingBpm = !isset($track['bpm']) || (float)$track['bpm'] <= 0;
        // Keep queue focused on BPM/year only to avoid musical_key schema incompatibilities.
        $missingKey = false;
        $missingYear = $fillYear && (!isset($track['release_year']) || (int)$track['release_year'] <= 0);

        if (!$missingBpm && !$missingYear) {
            if (!$dryRun) setQueueStatus($db, $id, 'done', null, null);
            $done++;
            continue;
        }

    $applied = false;
    $chosen = null;

    // 1) Existing link first
    $linkStmt->execute([$spotifyId]);
    $chosen = $linkStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // 2) Fuzzy if no link
    if (!$chosen) {
        $artist = trim((string)($track['artist_name'] ?? ''));
        $title = trim((string)($track['track_name'] ?? ''));
        if ($artist !== '' && $title !== '') {
            $artistToken = strtok($artist, ' ') ?: $artist;
            $titleToken = strtok($title, ' ') ?: $title;
            $candStmt->execute([
                ':artist_like' => '%' . $artistToken . '%',
                ':title_like' => '%' . $titleToken . '%',
            ]);
            $candidates = $candStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($candidates)) {
                $spotifyTrack = [
                    'title' => $title,
                    'artist' => $artist,
                    'duration_seconds' => !empty($track['duration_ms']) ? (int)round(((int)$track['duration_ms']) / 1000) : null,
                    'bpm' => isset($track['bpm']) && is_numeric($track['bpm']) ? (float)$track['bpm'] : null,
                    'year' => isset($track['release_year']) && is_numeric($track['release_year']) ? (int)$track['release_year'] : null,
                ];

                $match = matchSpotifyToBpm($spotifyTrack, $candidates);
                if ($match) {
                    foreach ($candidates as $c) {
                        if ((int)$c['id'] === (int)$match['bpm_track_id']) {
                            $chosen = $c;
                            if (!$dryRun) {
                                $linkUpsert->execute([
                                    ':spotify_id' => $spotifyId,
                                    ':bpm_track_id' => (int)$match['bpm_track_id'],
                                    ':score' => (int)$match['score'],
                                    ':level' => (string)$match['confidence'],
                                    ':meta' => json_encode($match['meta'], JSON_UNESCAPED_UNICODE),
                                ]);
                            }
                            break;
                        }
                    }
                }
            }
        }
    }

        if ($chosen) {
            $set = [];
            $params = [':sid' => $spotifyId];

            if ($missingBpm && isset($chosen['bpm']) && is_numeric($chosen['bpm']) && (float)$chosen['bpm'] > 0) {
                $set[] = "bpm = :bpm";
                $params[':bpm'] = (float)$chosen['bpm'];
            }
            if ($fillYear && $missingYear && isset($chosen['year']) && is_numeric($chosen['year'])) {
                $year = (int)$chosen['year'];
                $maxYear = (int)date('Y') + 1;
                if ($year >= 1900 && $year <= $maxYear) {
                    $set[] = "release_year = :ryear";
                    $params[':ryear'] = $year;
                }
            }

            if (!empty($set)) {
                if (!$dryRun) {
                    $sql = "UPDATE spotify_tracks SET " . implode(', ', $set) . ", last_refreshed_at = NOW() WHERE spotify_track_id = :sid";
                    $upd = $db->prepare($sql);
                    $upd->execute($params);
                }
                $applied = true;
            }
        }

        if ($applied) {
            if (!$dryRun) setQueueStatus($db, $id, 'done');
            $done++;
            continue;
        }

        if (!$dryRun) {
            $attempts = (int)($job['attempts'] ?? 0) + 1;
            if ($attempts >= $maxAttemptsBeforePermanentFail) {
                setQueueStatus($db, $id, 'failed', 'no_confident_match_or_no_payload', null);
            } else {
                // backoff: 30 minutes, then retry as pending
                setQueueStatus($db, $id, 'pending', 'no_confident_match_or_no_payload', gmdate('Y-m-d H:i:s', time() + 1800));
            }
        }
        $failed++;
    } catch (Throwable $e) {
        if (!$dryRun) {
            $attempts = (int)($job['attempts'] ?? 0) + 1;
            $msg = substr($e->getMessage(), 0, 250);
            if ($attempts >= $maxAttemptsBeforePermanentFail) {
                setQueueStatus($db, $id, 'failed', $msg, null);
            } else {
                setQueueStatus($db, $id, 'pending', $msg, gmdate('Y-m-d H:i:s', time() + 1800));
            }
        }
        $failed++;
    }
}

echo "Processed: {$processed}\n";
echo "Done: {$done}\n";
echo "Failed: {$failed}\n";
echo "Finished at " . date('c') . PHP_EOL;
