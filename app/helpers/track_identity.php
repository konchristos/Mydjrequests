<?php
declare(strict_types=1);

function trackIdentityEnsureSchema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS track_identities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(32) NOT NULL,
            provider_track_id VARCHAR(191) NULL,
            normalized_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_track_identities_provider_track (provider, provider_track_id),
            UNIQUE KEY uq_track_identities_provider_hash (provider, normalized_hash),
            KEY idx_track_identities_provider_created (provider, created_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    trackIdentityEnsureColumn($db, 'song_requests', 'track_identity_id');
    trackIdentityEnsureColumn($db, 'spotify_tracks', 'track_identity_id');

    trackIdentityEnsureIndex($db, 'song_requests', 'idx_song_requests_track_identity_id', 'track_identity_id');
    trackIdentityEnsureIndex($db, 'spotify_tracks', 'idx_spotify_tracks_track_identity_id', 'track_identity_id');

    $done = true;
}

function trackIdentityEnsureColumn(PDO $db, string $table, string $column): void
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    $exists = ((int)$stmt->fetchColumn()) > 0;
    if ($exists) {
        return;
    }

    $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` BIGINT UNSIGNED NULL");
}

function trackIdentityEnsureIndex(PDO $db, string $table, string $indexName, string $column): void
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $indexName]);
    $exists = ((int)$stmt->fetchColumn()) > 0;
    if ($exists) {
        return;
    }

    $db->exec("CREATE INDEX `{$indexName}` ON `{$table}` (`{$column}`)");
}

function trackIdentityNormalisedHash(?string $title, ?string $artist): ?string
{
    $title = trackIdentityNormaliseText((string)$title);
    $artist = trackIdentityNormaliseText((string)$artist);

    if ($title === '' && $artist === '') {
        return null;
    }

    return hash('sha256', $artist . '|' . $title);
}

function trackIdentityNormaliseText(string $v): string
{
    $v = mb_strtolower($v, 'UTF-8');
    $v = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $v);
    $v = preg_replace('/\s+/', ' ', trim($v));
    return (string)$v;
}

function trackIdentityResolveId(PDO $db, string $provider, ?string $providerTrackId, ?string $normalizedHash): ?int
{
    $provider = trim(strtolower($provider));
    $providerTrackId = $providerTrackId !== null ? trim($providerTrackId) : null;
    $normalizedHash = $normalizedHash !== null ? trim(strtolower($normalizedHash)) : null;

    if ($provider === '') {
        return null;
    }

    if (($providerTrackId === null || $providerTrackId === '') && ($normalizedHash === null || $normalizedHash === '')) {
        return null;
    }

    $stmt = $db->prepare("
        INSERT INTO track_identities (provider, provider_track_id, normalized_hash, created_at)
        VALUES (:provider, :provider_track_id, :normalized_hash, NOW())
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ");
    $stmt->execute([
        ':provider' => $provider,
        ':provider_track_id' => ($providerTrackId === '') ? null : $providerTrackId,
        ':normalized_hash' => ($normalizedHash === '') ? null : $normalizedHash,
    ]);

    $id = (int)$db->lastInsertId();
    return $id > 0 ? $id : null;
}

function trackIdentityResolveForRequest(PDO $db, ?string $spotifyTrackId, ?string $title, ?string $artist): ?int
{
    $spotifyTrackId = trim((string)$spotifyTrackId);
    if ($spotifyTrackId !== '') {
        return trackIdentityResolveId($db, 'spotify', $spotifyTrackId, null);
    }

    $hash = trackIdentityNormalisedHash($title, $artist);
    if ($hash === null) {
        return null;
    }
    return trackIdentityResolveId($db, 'manual', null, $hash);
}
