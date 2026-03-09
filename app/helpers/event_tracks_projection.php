<?php
declare(strict_types=1);

function eventTracksProjectionEnsureTable(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS event_tracks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NOT NULL,
            track_identity_id BIGINT UNSIGNED NOT NULL,
            request_count INT UNSIGNED NOT NULL DEFAULT 0,
            vote_count INT UNSIGNED NOT NULL DEFAULT 0,
            boost_count INT UNSIGNED NOT NULL DEFAULT 0,
            first_requested_at DATETIME NOT NULL,
            last_requested_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_track (event_id, track_identity_id),
            INDEX idx_event_tracks_event_request (event_id, request_count DESC),
            INDEX idx_event_tracks_identity (track_identity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $done = true;
}

function eventTracksProjectionIncrementRequest(PDO $db, int $eventId, ?int $trackIdentityId): void
{
    if ($eventId <= 0 || $trackIdentityId === null || $trackIdentityId <= 0) {
        return;
    }

    eventTracksProjectionEnsureTable($db);

    $stmt = $db->prepare("
        INSERT INTO event_tracks
            (event_id, track_identity_id, request_count, first_requested_at, last_requested_at)
        VALUES
            (:event_id, :track_identity_id, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            request_count = request_count + 1,
            last_requested_at = NOW()
    ");

    $stmt->execute([
        ':event_id' => $eventId,
        ':track_identity_id' => $trackIdentityId,
    ]);
}

