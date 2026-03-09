# Identity Migration Status

## Scope

Audit target: canonical identity layer centered on `track_identities` and `track_identity_id` references.

Repository reviewed under `public_html/`:

- `app/helpers/track_identity.php`
- `app/workers/track_identity_backfill_worker.php`
- `app/bootstrap.php`
- `app/bootstrap_public.php`
- `api/public/submit_song.php`

## Executive Status

Code-level implementation is in place for:

- creating/ensuring `track_identities`
- adding `track_identity_id` columns to both `spotify_tracks` and `song_requests`
- populating identities on new request writes
- background backfill worker for existing rows

Database-level completion still requires runtime verification (actual DB state + backfill completion metrics).

## Verification Against Requested Questions

## 1) Does table `track_identities` exist?

Status: **Implemented in code; DB presence must be verified in environment**.

Evidence:

- `trackIdentityEnsureSchema()` executes:
  - `CREATE TABLE IF NOT EXISTS track_identities (...)`
- File: `app/helpers/track_identity.php`

Implication:

- Table will be created lazily when code path calls `trackIdentityEnsureSchema()`.

## 2) Does `spotify_tracks.track_identity_id` exist?

Status: **Implemented in code; DB presence must be verified in environment**.

Evidence:

- `trackIdentityEnsureSchema()` calls:
  - `trackIdentityEnsureColumn($db, 'spotify_tracks', 'track_identity_id')`
  - `trackIdentityEnsureIndex($db, 'spotify_tracks', 'idx_spotify_tracks_track_identity_id', 'track_identity_id')`
- File: `app/helpers/track_identity.php`

Additionally:

- `api/public/submit_song.php` writes `track_identity_id` into `spotify_tracks` upsert path.

## 3) Are identities already backfilled for existing Spotify tracks?

Status: **Backfill mechanism exists; completion unknown until executed/checked**.

Evidence:

- Worker exists: `app/workers/track_identity_backfill_worker.php`
- It scans:
  - `spotify_tracks WHERE track_identity_id IS NULL LIMIT :lim`
- It resolves identity and updates `spotify_tracks.track_identity_id`.

Important detail:

- Worker is not auto-scheduled in repository code shown; it appears intended for manual/cron execution.
- Therefore, historical rows may still be null unless worker has been run repeatedly to completion.

## 4) Does `song_requests.track_identity_id` exist?

Status: **Implemented in code; DB presence must be verified in environment**.

Evidence:

- `trackIdentityEnsureSchema()` calls:
  - `trackIdentityEnsureColumn($db, 'song_requests', 'track_identity_id')`
  - `trackIdentityEnsureIndex($db, 'song_requests', 'idx_song_requests_track_identity_id', 'track_identity_id')`
- `api/public/submit_song.php` includes `track_identity_id` in `INSERT INTO song_requests`.

## 5) If not, safe migration steps to add `song_requests.track_identity_id`

Use this phased approach (no API breakage):

### Phase A: Schema add (nullable + index)

```sql
ALTER TABLE song_requests
  ADD COLUMN track_identity_id BIGINT UNSIGNED NULL;

CREATE INDEX idx_song_requests_track_identity_id
  ON song_requests (track_identity_id);
```

If you need idempotency:

- check `INFORMATION_SCHEMA.COLUMNS` and `INFORMATION_SCHEMA.STATISTICS` before each statement.

### Phase B: Ensure identity table exists

```sql
CREATE TABLE IF NOT EXISTS track_identities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(32) NOT NULL,
  provider_track_id VARCHAR(191) NULL,
  normalized_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_track_identities_provider_track (provider, provider_track_id),
  UNIQUE KEY uq_track_identities_provider_hash (provider, normalized_hash),
  KEY idx_track_identities_provider_created (provider, created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Phase C: Backfill in batches

- Run `app/workers/track_identity_backfill_worker.php` via cron with a bounded limit.
- Example:

```bash
php app/workers/track_identity_backfill_worker.php --limit=1000
```

- Repeat until both are zero:

```sql
SELECT COUNT(*) FROM spotify_tracks WHERE track_identity_id IS NULL;
SELECT COUNT(*) FROM song_requests WHERE track_identity_id IS NULL;
```

### Phase D: Maintain compatibility

- Keep legacy identifiers (`spotify_track_id`, title/artist fields) unchanged.
- Continue dual-read/dual-write behavior during transition.

### Phase E: Optional hardening after full backfill

Only after monitoring and confirming no regressions:

1. Add FK constraints (if desired):

```sql
ALTER TABLE song_requests
  ADD CONSTRAINT fk_song_requests_track_identity
  FOREIGN KEY (track_identity_id) REFERENCES track_identities(id)
  ON DELETE SET NULL;
```

2. Keep `track_identity_id` nullable initially to avoid ingest failures.

## Runtime Validation Queries

Run these in target DB to confirm actual migration state:

```sql
-- Table exists?
SELECT COUNT(*) AS track_identities_exists
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'track_identities';

-- Column exists?
SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('spotify_tracks', 'song_requests')
  AND COLUMN_NAME = 'track_identity_id';

-- Backfill progress
SELECT
  (SELECT COUNT(*) FROM spotify_tracks WHERE track_identity_id IS NULL) AS spotify_remaining,
  (SELECT COUNT(*) FROM song_requests WHERE track_identity_id IS NULL) AS requests_remaining;
```

## Current Conclusion

- Canonical identity layer is **implemented at code level**.
- Final migration status is **environment-dependent** and should be confirmed with the SQL checks above.
- If `song_requests.track_identity_id` is absent in any environment, follow the phased migration above for safe rollout.
