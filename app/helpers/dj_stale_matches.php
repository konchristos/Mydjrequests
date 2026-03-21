<?php
declare(strict_types=1);

function mdjrEnsureDjTrackAvailabilityColumns(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_tracks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dj_id BIGINT UNSIGNED NOT NULL,
            track_identity_id BIGINT UNSIGNED NULL,
            normalized_hash CHAR(64) NULL,
            title VARCHAR(255) NOT NULL,
            artist VARCHAR(255) NOT NULL,
            bpm DECIMAL(6,2) NULL,
            musical_key VARCHAR(32) NULL,
            release_year INT NULL,
            genre VARCHAR(128) NULL,
            location TEXT NULL,
            is_available TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_import_job_id BIGINT UNSIGNED NULL,
            last_seen_at DATETIME NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'rekordbox_xml',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach ([
        'is_available' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'last_seen_import_job_id' => 'BIGINT UNSIGNED NULL',
        'last_seen_at' => 'DATETIME NULL',
        'release_year' => 'INT NULL',
    ] as $column => $ddl) {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dj_tracks'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE dj_tracks ADD COLUMN `{$column}` {$ddl}");
        }
    }
}

function mdjrEnsureDjGlobalTrackOverridesTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_global_track_overrides (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dj_id BIGINT UNSIGNED NOT NULL,
            override_key VARCHAR(512) NOT NULL,
            bpm_track_id BIGINT UNSIGNED NULL,
            dj_track_id BIGINT UNSIGNED NULL,
            bpm DECIMAL(6,2) NULL,
            musical_key VARCHAR(32) NULL,
            release_year INT NULL,
            manual_owned TINYINT(1) NOT NULL DEFAULT 1,
            manual_preferred TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dj_global_track_overrides (dj_id, override_key),
            KEY idx_dj_global_track_overrides_dj (dj_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    mdjrEnsureOverrideDjTrackIdColumn($db, 'dj_global_track_overrides');
}

function mdjrEnsureOverrideDjTrackIdColumn(PDO $db, string $table): void
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = 'dj_track_id'
    ");
    $stmt->execute([$table]);
    if ((int)$stmt->fetchColumn() === 0) {
        try {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN dj_track_id BIGINT UNSIGNED NULL AFTER bpm_track_id");
        } catch (Throwable $e) {
            // non-fatal
        }
    }
}

function mdjrDjTrackRatingExprForStale(PDO $db): string
{
    foreach (['rating', 'stars', 'star_rating', 'rekordbox_rating', 'rb_rating', 'rating_raw'] as $col) {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'dj_tracks'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$col]);
        if ((int)$stmt->fetchColumn() > 0) {
            return "COALESCE(d.`{$col}`, 0)";
        }
    }
    return "0";
}

function mdjrBestDjTrackCandidate(array $candidate, ?array $current): bool
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

function mdjrResolveBestAvailableDjTrackByHash(PDO $db, int $djId, string $hash): ?array
{
    $hash = trim($hash);
    if ($djId <= 0 || $hash === '') {
        return null;
    }

    $ratingExpr = mdjrDjTrackRatingExprForStale($db);
    $stmt = $db->prepare("
        SELECT
            d.id,
            MAX(CASE WHEN dpp.playlist_id IS NULL THEN 0 ELSE 1 END) AS is_preferred,
            {$ratingExpr} AS rating_value
        FROM dj_tracks d
        LEFT JOIN dj_playlist_tracks dpt
            ON dpt.dj_track_id = d.id
        LEFT JOIN dj_preferred_playlists dpp
            ON dpp.dj_id = d.dj_id
           AND dpp.playlist_id = dpt.playlist_id
        WHERE d.dj_id = ?
          AND COALESCE(d.is_available, 1) = 1
          AND d.normalized_hash = ?
        GROUP BY d.id
    ");
    $stmt->execute([$djId, $hash]);

    $best = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $candidate = [
            'id' => (int)($row['id'] ?? 0),
            'is_preferred' => !empty($row['is_preferred']) ? 1 : 0,
            'rating_value' => isset($row['rating_value']) && is_numeric($row['rating_value']) ? (float)$row['rating_value'] : 0.0,
        ];
        if ($candidate['id'] <= 0) {
            continue;
        }
        if (mdjrBestDjTrackCandidate($candidate, $best)) {
            $best = $candidate;
        }
    }

    return $best;
}

function mdjrOverrideKeySplit(string $overrideKey): array
{
    $parts = explode('|', trim($overrideKey), 2);
    return [
        'artist' => trim((string)($parts[0] ?? '')),
        'title' => trim((string)($parts[1] ?? '')),
    ];
}

function mdjrCandidateTrackHashForStale(string $title, string $artist): string
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

function mdjrLoadStaleGlobalMatches(PDO $db, int $djId): array
{
    mdjrEnsureDjGlobalTrackOverridesTable($db);
    mdjrEnsureDjTrackAvailabilityColumns($db);

    $stmt = $db->prepare("
        SELECT
            g.id,
            g.override_key,
            g.bpm_track_id,
            g.dj_track_id,
            g.updated_at,
            g.manual_preferred,
            b.title AS matched_title,
            b.artist AS matched_artist,
            b.bpm AS matched_bpm,
            b.key_text AS matched_key,
            b.year AS matched_year,
            dt.id AS exact_track_row_id,
            COALESCE(dt.is_available, 0) AS exact_track_is_available
        FROM dj_global_track_overrides g
        LEFT JOIN bpm_test_tracks b
            ON b.id = g.bpm_track_id
        LEFT JOIN dj_tracks dt
            ON dt.id = g.dj_track_id
           AND dt.dj_id = ?
        WHERE g.dj_id = ?
          AND (
              g.bpm_track_id IS NOT NULL
              OR g.dj_track_id IS NOT NULL
          )
        ORDER BY g.updated_at DESC, g.id DESC
    ");
    $stmt->execute([$djId, $djId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (mdjrTableExistsForHelper($db, 'dj_event_track_overrides')) {
        mdjrEnsureOverrideDjTrackIdColumn($db, 'dj_event_track_overrides');
        $legacyStmt = $db->prepare("
            SELECT
                e.id,
                e.override_key,
                e.bpm_track_id,
                e.dj_track_id,
                e.updated_at,
                e.manual_preferred,
                b.title AS matched_title,
                b.artist AS matched_artist,
                b.bpm AS matched_bpm,
                b.key_text AS matched_key,
                b.year AS matched_year,
                dt.id AS exact_track_row_id,
                COALESCE(dt.is_available, 0) AS exact_track_is_available
            FROM dj_event_track_overrides e
            LEFT JOIN bpm_test_tracks b
                ON b.id = e.bpm_track_id
            LEFT JOIN dj_tracks dt
                ON dt.id = e.dj_track_id
               AND dt.dj_id = ?
            WHERE e.dj_id = ?
              AND (
                  e.bpm_track_id IS NOT NULL
                  OR e.dj_track_id IS NOT NULL
              )
            ORDER BY e.updated_at DESC, e.id DESC
        ");
        $legacyStmt->execute([$djId, $djId]);
        $existingKeys = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['override_key'] ?? ''));
            if ($key !== '') {
                $existingKeys[$key] = true;
            }
        }
        foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = trim((string)($row['override_key'] ?? ''));
            if ($key === '' || isset($existingKeys[$key])) {
                continue;
            }
            $rows[] = $row;
            $existingKeys[$key] = true;
        }
    }

    if (empty($rows)) {
        return [];
    }

    $stale = [];
    foreach ($rows as $row) {
        $matchedTitle = trim((string)($row['matched_title'] ?? ''));
        $matchedArtist = trim((string)($row['matched_artist'] ?? ''));
        $exactDjTrackId = isset($row['dj_track_id']) && is_numeric($row['dj_track_id']) ? (int)$row['dj_track_id'] : 0;
        if ($exactDjTrackId <= 0) {
            // Legacy saved matches created before exact dj_track_id persistence
            // are not actionable stale rows. Skip them here so this review page
            // stays focused on exact saved local files that truly disappeared.
            continue;
        }
        $exactAvailable = ((int)($row['exact_track_row_id'] ?? 0) > 0) && ((int)($row['exact_track_is_available'] ?? 0) === 1);
        if ($exactAvailable) {
            continue;
        }

        $fallback = mdjrOverrideKeySplit((string)($row['override_key'] ?? ''));
        $row['display_title'] = $matchedTitle !== '' ? $matchedTitle : (string)$fallback['title'];
        $row['display_artist'] = $matchedArtist !== '' ? $matchedArtist : (string)$fallback['artist'];
        $row['stale_reason'] = 'exact_track_missing';
        $stale[] = $row;
    }

    return $stale;
}

function mdjrTableExistsForHelper(PDO $db, string $table): bool
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
