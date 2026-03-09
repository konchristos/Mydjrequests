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
    private array $djTrackColumns = [];

    public function __construct(PDO $db, int $djId, array $options = [])
    {
        $this->db = $db;
        $this->djId = $djId;
        $this->batchSize = max(50, (int)($options['batch_size'] ?? 500));
        $this->identityProvider = (string)($options['identity_provider'] ?? 'manual');
        $this->sourceLabel = (string)($options['source'] ?? 'rekordbox_xml');
    }

    /**
     * @return array{
     *   file:string,
     *   total_tracks_seen:int,
     *   rows_buffered:int,
     *   rows_inserted:int,
     *   rows_updated:int,
     *   rows_skipped:int,
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
        $this->djTrackColumns = $this->loadTableColumns('dj_tracks');
        if (empty($this->djTrackColumns)) {
            throw new RuntimeException('Table `dj_tracks` does not exist in current schema.');
        }

        $startedAt = gmdate('c');
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
        $inCollection = false;
        $batch = [];

        try {
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
        } finally {
            $reader->close();
        }

        return [
            'file' => $xmlPath,
            'total_tracks_seen' => $tracksSeen,
            'rows_buffered' => $rowsBuffered,
            'rows_inserted' => $rowsInserted,
            'rows_updated' => $rowsUpdated,
            'rows_skipped' => $rowsSkipped,
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
        ];
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
        $normalizedHash = $this->normalisedHash($title, $artist);
        $trackIdentityId = $this->resolveTrackIdentityId($normalizedHash);

        return [
            'title' => $title,
            'artist' => $artist,
            'bpm' => $bpm,
            'tonality' => $tonality,
            'genre' => $genre,
            'location' => $location,
            'normalized_hash' => $normalizedHash,
            'track_identity_id' => $trackIdentityId,
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
            $existingHashes = $this->existingHashesForBatch(array_keys($hashes));
            $inserted = 0;
            $updated = 0;

            foreach ($batch as $row) {
                $params = $this->buildInsertParams($row, $stmt['columns']);
                $stmt['statement']->execute($params);
                $h = isset($row['normalized_hash']) ? trim((string)$row['normalized_hash']) : '';
                if ($h !== '' && isset($existingHashes[$h])) {
                    $updated++;
                } else {
                    $inserted++;
                    if ($h !== '') {
                        $existingHashes[$h] = true;
                    }
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
        $now = date('Y-m-d H:i:s');
        $out = [];

        $this->setFirstExistingColumn($out, ['dj_id', 'user_id'], $this->djId);
        $this->setFirstExistingColumn($out, ['track_identity_id'], $row['track_identity_id'] ?? null);
        $this->setFirstExistingColumn($out, ['normalized_hash'], $row['normalized_hash'] ?? null);
        $this->setFirstExistingColumn($out, ['title', 'track_name', 'song_title', 'name'], $row['title'] ?? null);
        $this->setFirstExistingColumn($out, ['artist', 'artist_name'], $row['artist'] ?? null);
        $this->setFirstExistingColumn($out, ['bpm', 'average_bpm'], $row['bpm'] ?? null);
        $this->setFirstExistingColumn($out, ['musical_key', 'tonality', 'key_text'], $row['tonality'] ?? null);
        $this->setFirstExistingColumn($out, ['genre'], $row['genre'] ?? null);
        $this->setFirstExistingColumn($out, ['location', 'file_path'], $row['location'] ?? null);
        $this->setFirstExistingColumn($out, ['source', 'source_name'], $this->sourceLabel);

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
}
