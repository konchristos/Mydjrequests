# Index Strategy for Track and Request Tables

## Scope

Reviewed tables:

- `spotify_tracks`
- `song_requests`
- `track_links`
- `bpm_test_tracks`
- `event_spotify_playlists`

## Required Index Targets

### `song_requests`

Required:

- `(event_id, created_at)`
- `(event_id, spotify_track_id)`
- `(event_id, status)`

Observed in codebase:

- `idx_song_requests_event_spotify_created (event_id, spotify_track_id, created_at)` is created by `admin/performance.php`.

Interpretation:

- `(event_id, spotify_track_id)` is already covered as a left-prefix of `(event_id, spotify_track_id, created_at)`.
- `(event_id, created_at)` is **not** covered efficiently by that index and should exist separately.
- `(event_id, status)` should exist separately.

### `spotify_tracks`

Required:

- `UNIQUE (spotify_track_id)`
- `(bpm)`

Observed in codebase:

- `idx_spotify_tracks_bpm_id (bpm, id)` is created by `admin/performance.php`.
- Upsert patterns strongly imply a unique constraint already exists on `spotify_track_id`, but it is not explicitly declared in repository DDL.

Interpretation:

- `(bpm, id)` already satisfies filtering on `bpm` via left-prefix.
- If you require an exact `(bpm)` index for strict policy, add it only if `(bpm, id)` is absent.
- Ensure unique constraint on `spotify_track_id` exists.

### `track_links`

Required:

- `UNIQUE (spotify_track_id)`

Observed in codebase:

- Non-unique `idx_track_links_spotify_track_id (spotify_track_id)` may be created by `admin/performance.php`.
- Upsert usage suggests there should already be a unique key, but this should be verified.

Interpretation:

- Enforce unique constraint on `spotify_track_id`.

### `bpm_test_tracks`

Required:

- `(artist, title)`

Observed in codebase:

- `idx_bpm_test_tracks_artist_title (artist, title)` may be created by `admin/performance.php`.

### `event_spotify_playlists`

Not in required index list, but in audit scope.

Recommended baseline:

- `UNIQUE (event_id)` to enforce one-playlist-per-event behavior used by app logic.

## Preflight Audit SQL

Use this first to inspect current keys:

```sql
SELECT
  TABLE_NAME,
  INDEX_NAME,
  NON_UNIQUE,
  SEQ_IN_INDEX,
  COLUMN_NAME
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'spotify_tracks',
    'song_requests',
    'track_links',
    'bpm_test_tracks',
    'event_spotify_playlists'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
```

## Idempotent Migration Script (MySQL)

Notes:

- This script uses `INFORMATION_SCHEMA.STATISTICS` checks before creating keys.
- It avoids creating redundant `(event_id, spotify_track_id)` if `(event_id, spotify_track_id, created_at)` already exists.
- It treats `(bpm, id)` as satisfying `(bpm)` lookup.

```sql
-- song_requests: ensure (event_id, created_at)
SET @exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'song_requests'
    AND INDEX_NAME = 'idx_song_requests_event_created'
);
SET @sql := IF(
  @exists = 0,
  'CREATE INDEX idx_song_requests_event_created ON song_requests (event_id, created_at)',
  'SELECT ''idx_song_requests_event_created already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- song_requests: ensure (event_id, spotify_track_id), unless covered by existing (event_id, spotify_track_id, ...)
SET @covered := (
  SELECT COUNT(*)
  FROM (
    SELECT s.INDEX_NAME
    FROM INFORMATION_SCHEMA.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.TABLE_NAME = 'song_requests'
    GROUP BY s.INDEX_NAME
    HAVING
      SUM(CASE WHEN s.SEQ_IN_INDEX = 1 AND s.COLUMN_NAME = 'event_id' THEN 1 ELSE 0 END) = 1
      AND SUM(CASE WHEN s.SEQ_IN_INDEX = 2 AND s.COLUMN_NAME = 'spotify_track_id' THEN 1 ELSE 0 END) = 1
  ) x
);
SET @sql := IF(
  @covered = 0,
  'CREATE INDEX idx_song_requests_event_spotify ON song_requests (event_id, spotify_track_id)',
  'SELECT ''song_requests(event_id, spotify_track_id) already covered'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- song_requests: ensure (event_id, status)
SET @exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'song_requests'
    AND INDEX_NAME = 'idx_song_requests_event_status'
);
SET @sql := IF(
  @exists = 0,
  'CREATE INDEX idx_song_requests_event_status ON song_requests (event_id, status)',
  'SELECT ''idx_song_requests_event_status already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- spotify_tracks: ensure UNIQUE (spotify_track_id)
SET @has_unique := (
  SELECT COUNT(*)
  FROM (
    SELECT s.INDEX_NAME
    FROM INFORMATION_SCHEMA.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.TABLE_NAME = 'spotify_tracks'
      AND s.NON_UNIQUE = 0
    GROUP BY s.INDEX_NAME
    HAVING
      COUNT(*) = 1
      AND SUM(CASE WHEN s.COLUMN_NAME = 'spotify_track_id' THEN 1 ELSE 0 END) = 1
  ) u
);
SET @sql := IF(
  @has_unique = 0,
  'ALTER TABLE spotify_tracks ADD UNIQUE KEY uq_spotify_tracks_spotify_track_id (spotify_track_id)',
  'SELECT ''UNIQUE spotify_tracks(spotify_track_id) already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- spotify_tracks: ensure bpm-leading index (accept either (bpm) or (bpm, ...))
SET @has_bpm_leading := (
  SELECT COUNT(*)
  FROM (
    SELECT s.INDEX_NAME
    FROM INFORMATION_SCHEMA.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.TABLE_NAME = 'spotify_tracks'
    GROUP BY s.INDEX_NAME
    HAVING SUM(CASE WHEN s.SEQ_IN_INDEX = 1 AND s.COLUMN_NAME = 'bpm' THEN 1 ELSE 0 END) = 1
  ) i
);
SET @sql := IF(
  @has_bpm_leading = 0,
  'CREATE INDEX idx_spotify_tracks_bpm ON spotify_tracks (bpm)',
  'SELECT ''spotify_tracks bpm-leading index already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- track_links: ensure UNIQUE (spotify_track_id)
SET @has_unique := (
  SELECT COUNT(*)
  FROM (
    SELECT s.INDEX_NAME
    FROM INFORMATION_SCHEMA.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.TABLE_NAME = 'track_links'
      AND s.NON_UNIQUE = 0
    GROUP BY s.INDEX_NAME
    HAVING
      COUNT(*) = 1
      AND SUM(CASE WHEN s.COLUMN_NAME = 'spotify_track_id' THEN 1 ELSE 0 END) = 1
  ) u
);
SET @sql := IF(
  @has_unique = 0,
  'ALTER TABLE track_links ADD UNIQUE KEY uq_track_links_spotify_track_id (spotify_track_id)',
  'SELECT ''UNIQUE track_links(spotify_track_id) already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- bpm_test_tracks: ensure (artist, title)
SET @exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bpm_test_tracks'
    AND INDEX_NAME = 'idx_bpm_test_tracks_artist_title'
);
SET @sql := IF(
  @exists = 0,
  'CREATE INDEX idx_bpm_test_tracks_artist_title ON bpm_test_tracks (artist, title)',
  'SELECT ''idx_bpm_test_tracks_artist_title already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- optional hardening for event_spotify_playlists (recommended)
SET @has_unique := (
  SELECT COUNT(*)
  FROM (
    SELECT s.INDEX_NAME
    FROM INFORMATION_SCHEMA.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.TABLE_NAME = 'event_spotify_playlists'
      AND s.NON_UNIQUE = 0
    GROUP BY s.INDEX_NAME
    HAVING
      COUNT(*) = 1
      AND SUM(CASE WHEN s.COLUMN_NAME = 'event_id' THEN 1 ELSE 0 END) = 1
  ) u
);
SET @sql := IF(
  @has_unique = 0,
  'ALTER TABLE event_spotify_playlists ADD UNIQUE KEY uq_event_spotify_playlists_event_id (event_id)',
  'SELECT ''UNIQUE event_spotify_playlists(event_id) already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

## Post-Migration Validation

```sql
SELECT
  TABLE_NAME,
  INDEX_NAME,
  NON_UNIQUE,
  GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'spotify_tracks',
    'song_requests',
    'track_links',
    'bpm_test_tracks',
    'event_spotify_playlists'
  )
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
ORDER BY TABLE_NAME, INDEX_NAME;
```

## Recommended Rollout Order

1. Apply in staging first and capture query plans for high-traffic `song_requests` reads.
2. Apply production during low-traffic window.
3. Monitor slow query log for:
   - `song_requests` event timeline/status filters
   - BPM hydration joins through `track_links`
4. If write amplification becomes noticeable, reassess overlapping indexes on `song_requests`.
