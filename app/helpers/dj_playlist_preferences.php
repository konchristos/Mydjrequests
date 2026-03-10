<?php
declare(strict_types=1);

/**
 * Schema-only helper for DJ playlist import/preference tables.
 * Safe to call multiple times.
 */
function djPlaylistPreferencesEnsureSchema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_playlists (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dj_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            parent_playlist_id BIGINT UNSIGNED NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'rekordbox_xml',
            external_playlist_key VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_dj_playlists_dj_id (dj_id),
            INDEX idx_dj_playlists_parent (parent_playlist_id),
            UNIQUE KEY uq_dj_playlist_external (dj_id, source, external_playlist_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_playlist_tracks (
            playlist_id BIGINT UNSIGNED NOT NULL,
            dj_track_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (playlist_id, dj_track_id),
            INDEX idx_dj_playlist_tracks_dj_track (dj_track_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_preferred_playlists (
            dj_id BIGINT UNSIGNED NOT NULL,
            playlist_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (dj_id, playlist_id),
            INDEX idx_dj_preferred_playlists_playlist (playlist_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Idempotent index hardening for pre-existing installs.
    djPlaylistPreferencesEnsureIndex($db, 'dj_playlists', 'idx_dj_playlists_dj_id', 'dj_id');
    djPlaylistPreferencesEnsureIndex($db, 'dj_playlists', 'idx_dj_playlists_parent', 'parent_playlist_id');
    djPlaylistPreferencesEnsureUnique($db, 'dj_playlists', 'uq_dj_playlist_external', ['dj_id', 'source', 'external_playlist_key']);

    djPlaylistPreferencesEnsureIndex($db, 'dj_playlist_tracks', 'idx_dj_playlist_tracks_dj_track', 'dj_track_id');
    djPlaylistPreferencesEnsureIndex($db, 'dj_preferred_playlists', 'idx_dj_preferred_playlists_playlist', 'playlist_id');

    $done = true;
}

function djPlaylistPreferencesEnsureIndex(PDO $db, string $table, string $indexName, string $column): void
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

function djPlaylistPreferencesEnsureUnique(PDO $db, string $table, string $indexName, array $columns): void
{
    if (empty($columns)) {
        return;
    }

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

    $colsSql = implode(', ', array_map(
        static fn(string $c): string => "`" . str_replace('`', '', $c) . "`",
        $columns
    ));
    $db->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$indexName}` ({$colsSql})");
}
