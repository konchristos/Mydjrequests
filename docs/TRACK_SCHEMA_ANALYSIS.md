# Track Schema Analysis

## Scope

Repository root: `public_html/`

Focus requested:

- `spotify_tracks`
- `requests` domain tables
- track metadata tables
- playlist tables

This analysis is based on repository SQL usage (`INSERT/SELECT/UPDATE`, comments, and helper code). Full DDL for several production tables is not present in this repo, so some key/index details are inferred and marked accordingly.

## 1) Track-Related Tables Identified

## Core track identity + metadata

- `spotify_tracks`
  - Role: canonical Spotify track cache + mutable metadata (BPM/year/key/etc.).
  - Used by:
    - request ingest upsert (`api/public/submit_song.php`)
    - DJ request view hydration (`api/dj/get_requests.php`)
    - enrichment workers (`app/workers/*`)
    - manual BPM application (`api/dj/apply_manual_bpm_match.php`)

- `song_requests`
  - Role: event-level request fact table (who requested what, when, status).
  - Stores both Spotify and non-Spotify requests.
  - Used throughout DJ/public APIs and reports.

- `track_links`
  - Role: bridge between `spotify_tracks.spotify_track_id` and BPM metadata table row (`bpm_test_tracks.id`).
  - Stores confidence and match metadata.

- `bpm_test_tracks`
  - Role: imported BPM metadata master (currently from Rekordbox TXT).
  - Queried by matching/fuzzy/manual search and link fallback.

## Playlist-related tables

- `event_spotify_playlists`
  - Role: one Spotify playlist per event (playlist id/url/name/visibility).
  - Used by creation/sync/rebuild/remove flows.

- `event_spotify_playlist_tracks` (referenced)
  - Role: local playlist membership tracking (rebuild flow deletes by `event_id`).
  - Referenced in comments as dedupe tracking; DDL not found in repo.

- `dj_spotify_accounts`
  - Role: DJ OAuth token storage for Spotify API actions.

## Legacy/parallel request table

- `requests`
  - Used by legacy `Request` model/controller + `/api/requests/submit.php`.
  - Parallel request schema appears to coexist with `song_requests`.

## 2) Primary Keys and Indexes (Observed/Inferable)

## Explicitly observed in code

- `track_enrichment_queue`
  - PK: `id`
  - Unique: `uq_track_enrichment_queue_spotify_id (spotify_track_id)`
  - Index: `(status, next_attempt_at, id)`

- Performance/index recommendations in `admin/performance.php`:
  - `idx_spotify_tracks_bpm_id ON spotify_tracks (bpm, id)`
  - `idx_spotify_tracks_year_id ON spotify_tracks (release_year, id)`
  - `idx_track_links_spotify_track_id ON track_links (spotify_track_id)`
  - `idx_bpm_test_tracks_artist_title ON bpm_test_tracks (artist, title)`
  - `idx_song_requests_event_spotify_created ON song_requests (event_id, spotify_track_id, created_at)`

## Inferred from upsert behavior

- `spotify_tracks`
  - `INSERT ... ON DUPLICATE KEY UPDATE` by `spotify_track_id` implies unique key on `spotify_track_id`.

- `track_links`
  - `INSERT ... ON DUPLICATE KEY UPDATE` keyed by `spotify_track_id` behavior implies unique key on `spotify_track_id` (or equivalent unique constraint).

- `bpm_test_tracks`
  - `INSERT ... ON DUPLICATE KEY UPDATE` with `raw_hash` payload strongly suggests unique key likely based on `raw_hash`.

- `event_spotify_playlists`
  - Code assumes single row per event (`WHERE event_id = ? LIMIT 1` before insert).
  - Comment says unique-per-event behavior; likely unique index on `event_id`.

- `dj_spotify_accounts`
  - Upsert on insert suggests unique key likely on `dj_id` (possibly also `spotify_user_id`).

- `song_requests`
  - `lastInsertId()` usage implies numeric auto-increment PK (likely `id`).

## 3) How Track Identity Is Stored

Identity is currently **multi-model**:

- Primary identity (Spotify-backed):
  - `spotify_track_id` (string) across `song_requests`, `spotify_tracks`, `track_links`.

- Fallback identity (non-Spotify requests):
  - derived key from title/artist text:
    - e.g. `CONCAT(song_title, '::', artist)` or lowercase variants
  - appears in grouping and vote/boost joins as `track_key`.

- BPM identity:
  - `bpm_test_tracks.id` (row id) and dedupe hash (`raw_hash`) for imports.
  - linked to Spotify identity through `track_links`.

Implication:

- There is no single global immutable track ID that covers both Spotify and non-Spotify entries.
- Multiple identity expressions are used in SQL (case-sensitive vs lowercase variants), increasing drift risk.

## 4) Are Track Metadata and Identity Combined?

Yes, in multiple places:

- `spotify_tracks` combines:
  - identity (`spotify_track_id`)
  - descriptive metadata (`track_name`, `artist_name`, album fields)
  - musical metadata (`bpm`, `musical_key`, `release_year`, audio features)
  - operational timestamps (`last_refreshed_at`, `added_to_playlist_at`)

- `bpm_test_tracks` combines:
  - source identity-like fields (`title`, `artist`, `raw_hash`)
  - metadata (`bpm`, `key_text`, `year`, `genre`, `time_seconds`)

This is pragmatic but couples identity and enrichment state tightly, which can complicate long-term scaling and dedupe quality.

## 5) Scaling Risks at Millions of Tracks

## Identity and dedupe risks

- Text-concatenated fallback identity (`title::artist`) is fragile:
  - punctuation/case/feature-tag variants create logical duplicates.
  - inconsistent normalization across endpoints.

- Coexistence of `requests` and `song_requests` risks split data paths and duplicate writes.

## Query and index risks

- Large group-by workloads on `song_requests` for live dashboards/reports.
- Heavy dependence on `LIKE` and fallback scans in metadata matching paths.
- Potentially missing/weak indexes on high-cardinality request filters and track-key expressions.

## Data model coupling risks

- Metadata updates and identity references are mixed in same tables.
- Track lifecycle states (requested/accepted/played/skipped) tied directly to request rows rather than a normalized event-track projection.

## Operational risks

- Playlist sync repeatedly scans distinct request tracks per event.
- Fuzzy/backfill matching paths can become CPU-bound with corpus growth.
- Lack of repository-visible DDL/migrations for key track tables increases schema drift risk across environments.

## 6) Non-Breaking Improvement Strategy

## Phase 1: Hardening with backward compatibility

- Keep current tables and APIs unchanged externally.
- Add/verify critical indexes:
  - `song_requests(event_id, status, spotify_track_id, created_at)`
  - `song_requests(event_id, created_at)`
  - `track_links(spotify_track_id)` unique + `track_links(bpm_track_id)`
  - `bpm_test_tracks(raw_hash)` unique (if not already)
  - `event_spotify_playlists(event_id)` unique

- Standardize fallback key generation in one shared SQL/PHP helper (same normalization everywhere).

## Phase 2: Introduce canonical identity layer (additive)

- Add new table `track_identities`:
  - `id` (surrogate PK)
  - `provider` (`spotify`, `manual_text`, future providers)
  - `provider_track_id` (nullable)
  - normalized text hash for non-Spotify
  - unique constraints by provider/provider_track_id and by normalized hash where applicable.

- Add nullable `track_identity_id` FK columns to `song_requests` and `spotify_tracks`.
- Backfill gradually while preserving existing reads/writes on legacy columns.

## Phase 3: Decouple metadata from identity

- Add `track_metadata_cache` (or similar) keyed by `track_identity_id`.
- Mirror current fields from `spotify_tracks`/`bpm_test_tracks`.
- Keep existing columns for compatibility; dual-write during migration.

## Phase 4: Event-track projection for scale

- Add `event_tracks` materialized/projection table:
  - one row per event + track identity
  - counters (`request_count`, `vote_count`, `boost_count`)
  - latest state/timestamps
- Update from request/vote/boost events.
- Shift dashboard/report reads to this projection to reduce expensive live aggregations on raw request rows.

## Phase 5: Legacy path cleanup

- Audit and retire legacy `requests` table/API once `song_requests` is confirmed as single source of truth.
- Introduce migration/DDL source-of-truth scripts in repo for all track tables.

## Summary

- The current system is functional and pragmatic, centered on `spotify_track_id` plus text fallback identity.
- Track identity and metadata are currently combined in core tables.
- At million-scale, identity drift, heavy request aggregation, and fuzzy matching costs become key constraints.
- A staged additive refactor (canonical identity + metadata decoupling + event-track projection) can improve scale without breaking existing functionality.
