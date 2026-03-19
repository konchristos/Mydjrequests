<?php
declare(strict_types=1);

/**
 * Rekordbox XML importer that uses XMLReader streaming.
 *
 * Designed for large files (300MB+) by reading one TRACK node at a time.
 */
class RekordboxXMLImporter
{
    private PDO $db;
    private int $djId;
    private int $batchSize;
    private string $identityProvider;
    private string $sourceLabel;
    private int $importJobId;
    private string $importStartedAt = '';
    private array $djTrackColumns = [];
    private array $trackIdToHash = [];
    private array $trackIdToDjTrackId = [];
    private ?PDOStatement $playlistUpsertStmt = null;
    private ?PDOStatement $playlistTrackInsertStmt = null;
    /** @var callable|null */
    private $progressCallback = null;

    public function __construct(PDO $db, int $djId, array $options = [])
    {
        $this->db = $db;
        $this->djId = $djId;
        $this->batchSize = max(50, (int)($options['batch_size'] ?? 500));
        $this->identityProvider = (string)($options['identity_provider'] ?? 'manual');
        $this->sourceLabel = (string)($options['source'] ?? 'rekordbox_xml');
        $this->importJobId = max(0, (int)($options['import_job_id'] ?? 0));
    }

    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * @return array{
     *   file:string,
     *   total_tracks_seen:int,
     *   rows_buffered:int,
     *   rows_inserted:int,
     *   rows_updated:int,
     *   rows_skipped:int,
     *   playlists_imported:int,
     *   playlist_tracks_added:int,
     *   playlist_tracks_skipped:int,
     *   started_at:string,
     *   finished_at:string
     * }
     */
    public function import(string $xmlPath): array
    {
        if (!is_file($xmlPath) || !is_readable($xmlPath)) {
            throw new RuntimeException('XML file is missing or not readable: ' . $xmlPath);
        }

        $this->ensureIdentitySchema();
        $this->ensureDjLibraryStatsTable();
        $this->ensureDjTracksRatingColumn();
        $this->ensureDjTracksSyncColumns();
        $this->djTrackColumns = $this->loadTableColumns('dj_tracks');
        if (empty($this->djTrackColumns)) {
            throw new RuntimeException('Table `dj_tracks` does not exist in current schema.');
        }

        $startedAt = gmdate('c');
        $this->importStartedAt = date('Y-m-d H:i:s');
        $reader = new XMLReader();
        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE;

        if (!$reader->open($xmlPath, null, $flags)) {
            throw new RuntimeException('Unable to open Rekordbox XML file: ' . $xmlPath);
        }

        $rowsInserted = 0;
        $rowsUpdated = 0;
        $rowsSkipped = 0;
        $rowsBuffered = 0;
        $tracksSeen = 0;
        $playlistsImported = 0;
        $playlistTracksAdded = 0;
        $playlistTracksSkipped = 0;
        $inCollection = false;
        $batch = [];

        try {
            $this->emitProgress('processing_tracks', 'Processing track collection...');
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'COLLECTION') {
                    $inCollection = true;
                    continue;
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'COLLECTION') {
                    $inCollection = false;
                    continue;
                }

                if (!$inCollection || $reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'TRACK') {
                    continue;
                }

                $tracksSeen++;
                $track = $this->extractTrackFromReader($reader);
                if ($track === null) {
                    $rowsSkipped++;
                    continue;
                }

                $this->rememberTrackIdHashMapping($track);

                $batch[] = $track;
                $rowsBuffered++;

                if (count($batch) >= $this->batchSize) {
                    $batchStats = $this->flushBatch($batch);
                    $rowsInserted += (int)$batchStats['inserted'];
                    $rowsUpdated += (int)$batchStats['updated'];
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                $batchStats = $this->flushBatch($batch);
                $rowsInserted += (int)$batchStats['inserted'];
                $rowsUpdated += (int)$batchStats['updated'];
            }

            $this->resolveTrackIdMappings();
            $this->emitProgress('processing_playlists', 'Processing playlist hierarchy...');
            $playlistStats = $this->importPlaylists($xmlPath);
            $playlistsImported = (int)($playlistStats['playlists_imported'] ?? 0);
            $playlistTracksAdded = (int)($playlistStats['playlist_tracks_added'] ?? 0);
            $playlistTracksSkipped = (int)($playlistStats['playlist_tracks_skipped'] ?? 0);
            $this->markMissingTracksUnavailable();
        } finally {
            $reader->close();
        }

        $this->emitProgress('finalizing', 'Finalizing import...');
        $this->updateDjLibraryStats();

        return [
            'file' => $xmlPath,
            'total_tracks_seen' => $tracksSeen,
            'rows_buffered' => $rowsBuffered,
            'rows_inserted' => $rowsInserted,
            'rows_updated' => $rowsUpdated,
            'rows_skipped' => $rowsSkipped,
            'playlists_imported' => $playlistsImported,
            'playlist_tracks_added' => $playlistTracksAdded,
            'playlist_tracks_skipped' => $playlistTracksSkipped,
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
        ];
    }

    private function emitProgress(string $stage, string $message): void
    {
        if (!is_callable($this->progressCallback)) {
            return;
        }
        try {
            call_user_func($this->progressCallback, $stage, $message);
        } catch (Throwable $e) {
            // Progress callback failures should never fail the import itself.
        }
    }

    private function extractTrackFromReader(XMLReader $reader): ?array
    {
        $title = trim((string)$reader->getAttribute('Name'));
        $artist = trim((string)$reader->getAttribute('Artist'));

        if ($title === '' && $artist === '') {
            return null;
        }

        $bpm = $this->parseBpm($reader->getAttribute('AverageBpm'));
        $tonality = $this->nullIfEmpty($reader->getAttribute('Tonality'));
        $genre = $this->nullIfEmpty($reader->getAttribute('Genre'));
        $location = $this->normaliseLocation($this->nullIfEmpty($reader->getAttribute('Location')));
        $rating = $this->parseRating($reader->getAttribute('Rating'));
        $releaseYear = $this->parseYear($reader->getAttribute('Year'));
        $normalizedHash = $this->normalisedHash($title, $artist);
        $trackIdentityId = $this->resolveTrackIdentityId($normalizedHash);
        $xmlTrackId = $this->nullIfEmpty($reader->getAttribute('TrackID'));

        return [
            'title' => $title,
            'artist' => $artist,
            'bpm' => $bpm,
            'tonality' => $tonality,
            'genre' => $genre,
            'location' => $location,
            'rating' => $rating,
            'release_year' => $releaseYear,
            'normalized_hash' => $normalizedHash,
            'track_identity_id' => $trackIdentityId,
            'xml_track_id' => $xmlTrackId,
        ];
    }

    /**
     * @return array{inserted:int,updated:int}
     */
    private function flushBatch(array $batch): array
    {
        if (empty($batch)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->buildDjTracksInsertStatement($batch[0]);
            $hashes = [];
            foreach ($batch as $row) {
                $h = isset($row['normalized_hash']) ? trim((string)$row['normalized_hash']) : '';
                if ($h !== '') {
                    $hashes[$h] = true;
                }
            }
            $inserted = 0;
            $updated = 0;

            foreach ($batch as $row) {
                $params = $this->buildInsertParams($row, $stmt['columns']);
                $stmt['statement']->execute($params);
                // MySQL ON DUPLICATE KEY behavior:
                // rowCount() == 1 => insert
                // rowCount() == 2 => update (changed values)
                // rowCount() == 0 => duplicate, no value changes
                $affected = (int)$stmt['statement']->rowCount();
                if ($affected === 1) {
                    $inserted++;
                } else {
                    $updated++;
                }
            }

            $this->db->commit();
            return ['inserted' => $inserted, 'updated' => $updated];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{statement:PDOStatement,columns:string[]}
     */
    private function buildDjTracksInsertStatement(array $sample): array
    {
        $mapped = $this->mapRowToAvailableColumns($sample);
        $columns = array_keys($mapped);

        if (empty($columns)) {
            throw new RuntimeException('No compatible columns found for `dj_tracks` insert.');
        }

        $insertCols = [];
        $insertVals = [];
        foreach ($columns as $col) {
            $insertCols[] = "`{$col}`";
            $insertVals[] = ":{$col}";
        }

        $updatable = [];
        foreach ($columns as $col) {
            if (in_array($col, ['id', 'dj_id', 'created_at'], true)) {
                continue;
            }
            $updatable[] = "`{$col}` = VALUES(`{$col}`)";
        }

        $sql = "INSERT INTO `dj_tracks` (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
        if (!empty($updatable)) {
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updatable);
        }

        return [
            'statement' => $this->db->prepare($sql),
            'columns' => $columns,
        ];
    }

    private function buildInsertParams(array $row, array $columns): array
    {
        $mapped = $this->mapRowToAvailableColumns($row);
        $params = [];
        foreach ($columns as $col) {
            $params[":{$col}"] = $mapped[$col] ?? null;
        }
        return $params;
    }

    private function mapRowToAvailableColumns(array $row): array
    {
        $now = $this->importStartedAt !== '' ? $this->importStartedAt : date('Y-m-d H:i:s');
        $out = [];

        $this->setFirstExistingColumn($out, ['dj_id', 'user_id'], $this->djId);
        $this->setFirstExistingColumn($out, ['track_identity_id'], $row['track_identity_id'] ?? null);
        $this->setFirstExistingColumn($out, ['normalized_hash'], $row['normalized_hash'] ?? null);
        $this->setFirstExistingColumn($out, ['title', 'track_name', 'song_title', 'name'], $row['title'] ?? null);
        $this->setFirstExistingColumn($out, ['artist', 'artist_name'], $row['artist'] ?? null);
        $this->setFirstExistingColumn($out, ['bpm', 'average_bpm'], $row['bpm'] ?? null);
        $this->setFirstExistingColumn($out, ['musical_key', 'tonality', 'key_text'], $row['tonality'] ?? null);
        $this->setFirstExistingColumn($out, ['release_year', 'year'], $row['release_year'] ?? null);
        $this->setFirstExistingColumn($out, ['genre'], $row['genre'] ?? null);
        $this->setFirstExistingColumn($out, ['location', 'file_path'], $row['location'] ?? null);
        $this->setFirstExistingColumn($out, ['rating', 'stars', 'star_rating', 'rekordbox_rating'], $row['rating'] ?? null);
        $this->setFirstExistingColumn($out, ['source', 'source_name'], $this->sourceLabel);
        $this->setFirstExistingColumn($out, ['is_available'], 1);
        $this->setFirstExistingColumn($out, ['last_seen_import_job_id'], $this->importJobId > 0 ? $this->importJobId : null);
        $this->setFirstExistingColumn($out, ['last_seen_at'], $now);

        if (isset($this->djTrackColumns['created_at'])) {
            $out['created_at'] = $now;
        }
        if (isset($this->djTrackColumns['updated_at'])) {
            $out['updated_at'] = $now;
        }

        return $out;
    }

    private function setFirstExistingColumn(array &$out, array $candidates, $value): void
    {
        foreach ($candidates as $col) {
            if (isset($this->djTrackColumns[$col])) {
                $out[$col] = $value;
                return;
            }
        }
    }

    private function parseBpm(?string $value): ?float
    {
        $value = $this->nullIfEmpty($value);
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $bpm = round((float)$value, 2);
        return $bpm > 0 ? $bpm : null;
    }

    private function parseRating(?string $value): ?float
    {
        $value = $this->nullIfEmpty($value);
        if ($value === null) {
            return null;
        }
        // Rekordbox rating can be 0..255 (0,51,102,153,204,255) or already 0..5.
        if (is_numeric($value)) {
            $v = (float)$value;
            if ($v <= 0) {
                return null;
            }
            if ($v > 10) {
                if ($v >= 250) {
                    return 5.0;
                }
                if ($v <= 100) {
                    return round($v / 20.0, 2);
                }
                return round($v / 51.0, 2);
            }
            if ($v > 5) {
                return round($v / 2.0, 2);
            }
            return round($v, 2);
        }
        // Star glyph format like "★★★★★"
        if (mb_strpos($value, '★') !== false) {
            $count = mb_substr_count($value, '★');
            if ($count > 0) {
                return (float)min(5, $count);
            }
        }
        return null;
    }

    private function parseYear(?string $value): ?int
    {
        $value = $this->nullIfEmpty($value);
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        $year = (int)$value;
        if ($year < 1900 || $year > 2100) {
            return null;
        }
        return $year;
    }

    private function normalisedHash(string $title, string $artist): ?string
    {
        $titleNorm = $this->normaliseText($title);
        $artistNorm = $this->normaliseText($artist);

        if ($titleNorm === '' && $artistNorm === '') {
            return null;
        }

        return hash('sha256', $artistNorm . '|' . $titleNorm);
    }

    private function normaliseText(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim($value));
        return (string)$value;
    }

    private function normaliseLocation(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $location = rawurldecode($location);
        if (stripos($location, 'file://') === 0) {
            $location = preg_replace('#^file://(localhost/)?#i', '', $location);
        }

        return trim((string)$location);
    }

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function resolveTrackIdentityId(?string $normalizedHash): ?int
    {
        if ($normalizedHash === null) {
            return null;
        }

        $stmt = $this->db->prepare("
            INSERT INTO track_identities (provider, provider_track_id, normalized_hash, created_at)
            VALUES (:provider, NULL, :normalized_hash, NOW())
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");
        $stmt->execute([
            ':provider' => $this->identityProvider,
            ':normalized_hash' => $normalizedHash,
        ]);

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    private function ensureIdentitySchema(): void
    {
        if (function_exists('trackIdentityEnsureSchema')) {
            trackIdentityEnsureSchema($this->db);
            return;
        }

        $this->db->exec("
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
    }

    private function loadTableColumns(string $table): array
    {
        $stmt = $this->db->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute([':table_name' => $table]);

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $name) {
            $out[(string)$name] = true;
        }
        return $out;
    }

    private function existingHashesForBatch(array $hashes): array
    {
        if (empty($hashes) || !isset($this->djTrackColumns['normalized_hash'])) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $sql = "SELECT normalized_hash FROM dj_tracks WHERE dj_id = ? AND normalized_hash IN ({$placeholders})";
        $stmt = $this->db->prepare($sql);

        $params = [$this->djId];
        foreach ($hashes as $h) {
            $params[] = $h;
        }
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $h) {
            $key = trim((string)$h);
            if ($key !== '') {
                $out[$key] = true;
            }
        }
        return $out;
    }

    private function ensureDjLibraryStatsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS dj_library_stats (
                dj_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                track_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_imported_at DATETIME NULL,
                source VARCHAR(64) NOT NULL DEFAULT 'rekordbox_xml',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function ensureDjTracksRatingColumn(): void
    {
        // If none of the known rating columns exist, add a dedicated Rekordbox rating column.
        try {
            $cols = $this->loadTableColumns('dj_tracks');
            if (empty($cols)) {
                return;
            }
            $hasRating = isset($cols['rating']) || isset($cols['stars']) || isset($cols['star_rating']) || isset($cols['rekordbox_rating']);
            if ($hasRating) {
                return;
            }
            $this->db->exec("ALTER TABLE dj_tracks ADD COLUMN rekordbox_rating DECIMAL(5,2) NULL");
        } catch (Throwable $e) {
            // Non-fatal: importer can still run without rating persistence.
        }
    }

    private function ensureDjTracksSyncColumns(): void
    {
        try {
            $cols = $this->loadTableColumns('dj_tracks');
            if (empty($cols)) {
                return;
            }
            if (!isset($cols['is_available'])) {
                $this->db->exec("ALTER TABLE dj_tracks ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!isset($cols['last_seen_import_job_id'])) {
                $this->db->exec("ALTER TABLE dj_tracks ADD COLUMN last_seen_import_job_id BIGINT UNSIGNED NULL");
            }
            if (!isset($cols['last_seen_at'])) {
                $this->db->exec("ALTER TABLE dj_tracks ADD COLUMN last_seen_at DATETIME NULL");
            }
            if (!isset($cols['release_year'])) {
                $this->db->exec("ALTER TABLE dj_tracks ADD COLUMN release_year INT NULL");
            }
            $this->djTrackColumns = $this->loadTableColumns('dj_tracks');
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }

    private function markMissingTracksUnavailable(): void
    {
        if (!isset($this->djTrackColumns['is_available'])) {
            return;
        }

        if ($this->importJobId > 0) {
            $sql = "
                UPDATE dj_tracks
                SET is_available = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE dj_id = :dj_id
                  AND COALESCE(last_seen_import_job_id, 0) <> :job_id
            ";
            if (isset($this->djTrackColumns['last_seen_at'])) {
                $sql = "
                    UPDATE dj_tracks
                    SET is_available = 0,
                        last_seen_at = COALESCE(last_seen_at, NOW()),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE dj_id = :dj_id
                      AND COALESCE(last_seen_import_job_id, 0) <> :job_id
                ";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':dj_id' => $this->djId,
                ':job_id' => $this->importJobId,
            ]);
            return;
        }

        if (isset($this->djTrackColumns['last_seen_at']) && $this->importStartedAt !== '') {
            $stmt = $this->db->prepare("
                UPDATE dj_tracks
                SET is_available = 0,
                    updated_at = CURRENT_TIMESTAMP
                WHERE dj_id = :dj_id
                  AND (
                    last_seen_at IS NULL
                    OR last_seen_at < :import_started_at
                  )
            ");
            $stmt->execute([
                ':dj_id' => $this->djId,
                ':import_started_at' => $this->importStartedAt,
            ]);
        }
    }

    private function updateDjLibraryStats(): void
    {
        $countStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM dj_tracks
            WHERE dj_id = :dj_id
              AND COALESCE(is_available, 1) = 1
        ");
        $countStmt->execute([':dj_id' => $this->djId]);
        $trackCount = (int)$countStmt->fetchColumn();
        if ($trackCount < 0) {
            $trackCount = 0;
        }

        $upsert = $this->db->prepare("
            INSERT INTO dj_library_stats (
                dj_id,
                track_count,
                last_imported_at,
                source
            ) VALUES (
                :dj_id,
                :track_count,
                NOW(),
                :source
            )
            ON DUPLICATE KEY UPDATE
                track_count = VALUES(track_count),
                last_imported_at = VALUES(last_imported_at),
                source = VALUES(source),
                updated_at = CURRENT_TIMESTAMP
        ");
        $upsert->execute([
            ':dj_id' => $this->djId,
            ':track_count' => $trackCount,
            ':source' => $this->sourceLabel,
        ]);
    }

    private function rememberTrackIdHashMapping(array $track): void
    {
        $trackId = trim((string)($track['xml_track_id'] ?? ''));
        $hash = trim((string)($track['normalized_hash'] ?? ''));
        if ($trackId === '' || $hash === '') {
            return;
        }
        $this->trackIdToHash[$trackId] = $hash;
    }

    private function resolveTrackIdMappings(): void
    {
        if (empty($this->trackIdToHash)) {
            return;
        }

        $hashes = array_values(array_unique(array_filter(array_values($this->trackIdToHash))));
        if (empty($hashes)) {
            return;
        }

        $hashToDjTrackId = [];
        $chunkSize = 500;
        for ($offset = 0; $offset < count($hashes); $offset += $chunkSize) {
            $chunk = array_slice($hashes, $offset, $chunkSize);
            if (empty($chunk)) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "
                SELECT id, normalized_hash
                FROM dj_tracks
                WHERE dj_id = ?
                  AND normalized_hash IN ({$placeholders})
            ";
            $stmt = $this->db->prepare($sql);
            $params = array_merge([$this->djId], $chunk);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $hash = trim((string)($row['normalized_hash'] ?? ''));
                $id = (int)($row['id'] ?? 0);
                if ($hash !== '' && $id > 0 && !isset($hashToDjTrackId[$hash])) {
                    $hashToDjTrackId[$hash] = $id;
                }
            }
        }

        foreach ($this->trackIdToHash as $trackId => $hash) {
            if (isset($hashToDjTrackId[$hash])) {
                $this->trackIdToDjTrackId[$trackId] = (int)$hashToDjTrackId[$hash];
            }
        }
    }

    /**
     * @return array{playlists_imported:int,playlist_tracks_added:int,playlist_tracks_skipped:int}
     */
    private function importPlaylists(string $xmlPath): array
    {
        $this->ensurePlaylistSchema();

        $stats = [
            'playlists_imported' => 0,
            'playlist_tracks_added' => 0,
            'playlist_tracks_skipped' => 0,
        ];

        $reader = new XMLReader();
        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE;
        if (!$reader->open($xmlPath, null, $flags)) {
            return $stats;
        }

        $inPlaylists = false;
        $rootOrdinals = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'PLAYLISTS') {
                    $inPlaylists = true;
                    continue;
                }
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'PLAYLISTS') {
                    break;
                }
                if (!$inPlaylists || $reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'NODE') {
                    continue;
                }

                $token = $this->playlistNodeToken($reader);
                $rootOrdinals[$token] = ($rootOrdinals[$token] ?? 0) + 1;
                $pathSeed = 'root/' . $token . '#' . $rootOrdinals[$token];
                $this->parsePlaylistNode($reader, null, $pathSeed, $stats);
            }
        } finally {
            $reader->close();
        }

        return $stats;
    }

    private function parsePlaylistNode(XMLReader $reader, ?int $parentPlaylistId, string $pathSeed, array &$stats): void
    {
        $type = trim((string)$reader->getAttribute('Type'));
        $name = $this->nullIfEmpty($reader->getAttribute('Name')) ?? 'Untitled';
        $isPlaylist = ($type === '1');
        $depth = $reader->depth;

        $playlistId = $this->upsertPlaylistNode($name, $parentPlaylistId, $pathSeed);
        $stats['playlists_imported']++;

        if ($reader->isEmptyElement) {
            return;
        }

        $childOrdinals = [];
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'NODE' && $reader->depth === $depth) {
                return;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'NODE') {
                $token = $this->playlistNodeToken($reader);
                $childOrdinals[$token] = ($childOrdinals[$token] ?? 0) + 1;
                $childPathSeed = $pathSeed . '/' . $token . '#' . $childOrdinals[$token];
                $this->parsePlaylistNode($reader, $playlistId, $childPathSeed, $stats);
                continue;
            }

            if ($isPlaylist && $reader->name === 'TRACK') {
                $trackRef = $this->nullIfEmpty($reader->getAttribute('Key'))
                    ?? $this->nullIfEmpty($reader->getAttribute('TrackID'));
                if ($trackRef === null || !isset($this->trackIdToDjTrackId[$trackRef])) {
                    $stats['playlist_tracks_skipped']++;
                    continue;
                }

                $djTrackId = (int)$this->trackIdToDjTrackId[$trackRef];
                if ($djTrackId <= 0) {
                    $stats['playlist_tracks_skipped']++;
                    continue;
                }

                if ($this->upsertPlaylistTrack($playlistId, $djTrackId)) {
                    $stats['playlist_tracks_added']++;
                }
            }
        }
    }

    private function playlistNodeToken(XMLReader $reader): string
    {
        $type = trim((string)$reader->getAttribute('Type'));
        $name = $this->nullIfEmpty($reader->getAttribute('Name')) ?? 'Untitled';
        return ($type === '' ? 'x' : $type) . '|' . mb_strtolower($name, 'UTF-8');
    }

    private function upsertPlaylistNode(string $name, ?int $parentPlaylistId, string $pathSeed): int
    {
        if ($this->playlistUpsertStmt === null) {
            $this->playlistUpsertStmt = $this->db->prepare("
                INSERT INTO dj_playlists (
                    dj_id,
                    name,
                    parent_playlist_id,
                    source,
                    external_playlist_key,
                    created_at,
                    updated_at
                ) VALUES (
                    :dj_id,
                    :name,
                    :parent_playlist_id,
                    :source,
                    :external_playlist_key,
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    name = VALUES(name),
                    parent_playlist_id = VALUES(parent_playlist_id),
                    updated_at = NOW()
            ");
        }

        $externalKey = 'rbx:' . hash('sha256', $pathSeed);
        $this->playlistUpsertStmt->execute([
            ':dj_id' => $this->djId,
            ':name' => $name,
            ':parent_playlist_id' => $parentPlaylistId,
            ':source' => $this->sourceLabel,
            ':external_playlist_key' => $externalKey,
        ]);

        $id = (int)$this->db->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('Failed to resolve playlist id for Rekordbox NODE: ' . $name);
        }
        return $id;
    }

    private function upsertPlaylistTrack(int $playlistId, int $djTrackId): bool
    {
        if ($this->playlistTrackInsertStmt === null) {
            $this->playlistTrackInsertStmt = $this->db->prepare("
                INSERT IGNORE INTO dj_playlist_tracks (
                    playlist_id,
                    dj_track_id,
                    created_at
                ) VALUES (
                    :playlist_id,
                    :dj_track_id,
                    NOW()
                )
            ");
        }

        $this->playlistTrackInsertStmt->execute([
            ':playlist_id' => $playlistId,
            ':dj_track_id' => $djTrackId,
        ]);
        return $this->playlistTrackInsertStmt->rowCount() > 0;
    }

    private function ensurePlaylistSchema(): void
    {
        if (function_exists('djPlaylistPreferencesEnsureSchema')) {
            djPlaylistPreferencesEnsureSchema($this->db);
            return;
        }

        $this->db->exec("
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

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS dj_playlist_tracks (
                playlist_id BIGINT UNSIGNED NOT NULL,
                dj_track_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (playlist_id, dj_track_id),
                INDEX idx_dj_playlist_tracks_dj_track (dj_track_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
