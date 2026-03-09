# BPM System Analysis

## Scope

Repository root: `public_html/`

Primary modules reviewed:

- `BPM/`
- `BPM/bpm_matching/`
- `BPM/helpers.php`
- `BPM/parse_xml.php`
- `BPM/bpm_matching/matching.php`

Related integration paths reviewed for workflow and performance:

- `api/public/submit_song.php`
- `api/dj/get_requests.php`
- `api/dj/search_bpm_candidates.php`
- `api/dj/apply_manual_bpm_match.php`
- `app/workers/track_enrichment_queue_worker.php`
- `app/workers/bpm_cache_backfill_worker.php`
- `admin/performance.php`

## 1) Current Database Tables Used

## Core tables

- `bpm_test_tracks`
  - Current master BPM metadata source table.
  - Populated by Rekordbox import (`BPM/import.php`).
  - Queried by all matching flows (manual/admin, queue fuzzy, fallback endpoints).
  - Fields used in code: `id`, `source`, `title`, `artist`, `genre`, `bpm`, `bpm_confidence`, `key_text`, `year`, `time_seconds`, `raw_hash`, `imported_at`.

- `track_links`
  - Link table between Spotify tracks and BPM master rows.
  - Maps `spotify_track_id -> bpm_track_id`.
  - Stores match confidence and metadata (`confidence_score`, `confidence_level`, `match_meta`).
  - Used as the preferred fast path for metadata retrieval.

- `spotify_tracks`
  - Track cache table for request-side rendering and persistence.
  - Receives BPM/key/year values either from existing links or fuzzy matching outcomes.
  - Used by DJ views as first read source (`api/dj/get_requests.php`).

## Supporting tables

- `track_enrichment_queue`
  - Async queue for fuzzy enrichment (`pending/processing/done/failed`).
  - Driven by `api/public/submit_song.php` and `app/workers/track_enrichment_queue_worker.php`.

- `bpm_backfill_skips`
  - Skip ledger used by `app/workers/bpm_cache_backfill_worker.php` when confidence is below threshold.

## Indexes observed in code

- `idx_bpm_test_tracks_artist_title ON bpm_test_tracks (artist, title)`
- `idx_track_links_spotify_track_id ON track_links (spotify_track_id)`
- `idx_spotify_tracks_bpm_id ON spotify_tracks (bpm, id)`
- `idx_spotify_tracks_year_id ON spotify_tracks (release_year, id)`

Note: `CREATE TABLE` DDL for `bpm_test_tracks`, `track_links`, and `spotify_tracks` is not present in this repository snapshot. Analysis of those table definitions is inferred from query usage.

## 2) How Artist/Title Matching Currently Works

## Matching engine

`BPM/bpm_matching/matching.php` implements scoring:

- Title normalization:
  - lowercasing
  - removes terms like `remix/edit/version/mix/extended/radio`
  - strips punctuation/brackets
  - whitespace collapse
- Artist matching:
  - lowercasing
  - splits on `feat/featuring/&` tokens
  - set intersection scoring
- Score components:
  - title score (max 40, via `similar_text`)
  - artist score (max 30)
  - duration score (max 15)
  - BPM score (max 10)
  - year score (max 5)
- Hard reject before full scoring:
  - title score `< 25` OR artist score `< 20`
- Acceptance threshold:
  - best score must be `>= 70`

## Candidate retrieval strategies

- `BPM/bpm_matching/resolve_bpm_for_spotify.php`
  - Pass 1: `bpm_test_tracks WHERE artist LIKE '%artist%' LIMIT 25`
  - Pass 2 fallback: `WHERE title LIKE '%title%' LIMIT 25`
  - Runs `matchSpotifyToBpm(...)` over these candidate subsets.

- `app/workers/track_enrichment_queue_worker.php`
  - Candidate fetch:
    - `WHERE bpm IS NOT NULL AND (artist LIKE :artist_like OR title LIKE :title_like) LIMIT 220`
    - Uses first token of artist/title as the `LIKE` needle.
  - Then applies `matchSpotifyToBpm(...)`.

- `api/dj/search_bpm_candidates.php` (admin manual search)
  - Multi-pass SQL retrieval with `LOWER(title|artist) LIKE ...`, token search, broad fallback, and optional `LIMIT 5000` fallback scan.
  - Performs additional PHP similarity scoring (`similar_text`) and ranking.

## 3) Where Fuzzy Matching Is Performed

Fuzzy logic is in multiple places:

- `BPM/bpm_matching/matching.php`
  - Primary fuzzy scoring and confidence classification.

- `app/workers/track_enrichment_queue_worker.php`
  - Async fuzzy enrichment for request-time metadata completion.
  - Controlled by `app_settings`:
    - `bpm_fuzzy_on_request_enabled`
    - `track_enrichment_worker_enabled`

- `app/workers/bpm_cache_backfill_worker.php`
  - Bulk fuzzy backfill comparing normalized artist+title text with `similar_text`.
  - Uses brute-force candidate scanning in memory.

- `api/dj/search_bpm_candidates.php`
  - Admin-only manual fuzzy candidate search and scoring.

Important distinction:

- `api/dj/get_requests.php` does **not** do fuzzy matching.
- It only reads from cached `spotify_tracks` and linked `track_links + bpm_test_tracks` (fast path).

## 4) Performance Impact for Large Rekordbox Libraries (30k+)

## Import path bottlenecks

- `parseRekordboxTxt()` currently has `maxRows = 20000`.
  - 30k+ rows will be truncated by default.
- Parser memory pattern is expensive:
  - `file_get_contents()` entire file
  - encoding conversion in-memory
  - writes full content into `php://temp`
  - then parses into full `$rows` array
  - high peak memory footprint.
- File is parsed twice in the normal workflow:
  - once in `map_fields.php`
  - again in `import.php`
- Import writes row-by-row (`$stmt->execute` per row), no explicit transaction wrapping, no batch insert.
  - This increases latency and lock overhead for large imports.

## Matching path bottlenecks

- Core candidate retrieval uses leading wildcard `LIKE '%...%'`.
  - Limits index effectiveness and degrades with table growth.
- Candidate limits (`25`, `220`) can miss valid matches on large catalogs.
- `similar_text` is comparatively expensive; repeated heavily in fuzzy flows.

## Backfill path bottlenecks (highest risk)

- `bpm_cache_backfill_worker.php` loads **all** `bpm_test_tracks` source rows into memory.
- For each target Spotify row, loops over all source rows and runs `similar_text`.
  - Effective complexity is approximately O(N*M).
  - At large scales this becomes a major CPU bottleneck and long-running job risk.

## Operational side effects

- `_parse_debug.log` appends per parse; without rotation this can grow indefinitely.
- Synchronous import path can be slow under high-row counts and ties up web request lifecycle.

## 5) Current Workflow (End-to-End)

## A. Playlist import (Rekordbox TXT)

1. User uploads TXT (`BPM/upload.php`).
2. System saves file to `BPM/uploads/`.
3. `BPM/map_fields.php` parses TXT and presents column mapping UI.
4. `BPM/import.php` reparses file and inserts into `bpm_test_tracks`:
   - dedupe hash `raw_hash` derived from artist/title/bpm/time
   - `ON DUPLICATE KEY UPDATE imported_at = CURRENT_TIMESTAMP`

## B. Request-time metadata path

1. Patron submits song (`api/public/submit_song.php`).
2. Immediate fast path:
   - if `track_links` exists, fill missing values in `spotify_tracks`.
3. If still missing and fuzzy enabled:
   - enqueue `track_enrichment_queue`.
4. Worker (`app/workers/track_enrichment_queue_worker.php`) processes queue:
   - tries existing link first
   - fuzzy candidate search + `matchSpotifyToBpm`
   - upserts `track_links`
   - updates `spotify_tracks` BPM/year

## C. DJ display path

1. DJ page fetches data (`api/dj/get_requests.php`).
2. Reads BPM metadata from:
   - `spotify_tracks` (preferred fast cache)
   - fallback from linked `track_links + bpm_test_tracks`
3. No inline fuzzy computation during request rendering.

## D. Manual admin correction path

1. Admin searches candidates (`api/dj/search_bpm_candidates.php`).
2. Admin applies selected candidate (`api/dj/apply_manual_bpm_match.php`).
3. System upserts `track_links` and updates `spotify_tracks`.

## Recommended Refactor Strategy

## Phase 1: Stabilize and de-risk (low complexity, high value)

- Make parser scalable:
  - stream TXT parse line-by-line (avoid full-file in-memory buffering).
  - support 30k+ rows by removing hard 20k default cap or making it explicit/configurable.
- Wrap import writes in transaction and perform batched inserts.
- Parse once per upload:
  - store normalized intermediate rows (temporary table or serialized staging) used by mapping/import.
- Add log rotation or disable verbose parse logging in production.

## Phase 2: Improve match quality and query performance

- Add normalized search columns (e.g., `title_norm`, `artist_norm`) in `bpm_test_tracks`.
- Replace broad `%LIKE%` with more index-friendly retrieval strategy:
  - prefix matching on normalized tokens and/or precomputed token tables.
- Increase candidate recall without exploding cost:
  - multi-stage retrieval (cheap prefilter -> score smaller set).
- Keep `matchSpotifyToBpm` as scoring layer but run it over better candidates.

## Phase 3: Rework heavy fuzzy/backfill architecture

- Replace O(N*M) backfill with indexed retrieval strategy.
- Introduce dedicated match queue and incremental processing windows.
- Add observability:
  - queue depth, match hit rate, false-positive review signals, per-job latency.
- Introduce confidence policies:
  - auto-apply high confidence
  - medium confidence -> review queue
  - low confidence -> skip with reason.

## Phase 4: Data model cleanup

- Rename `bpm_test_tracks` to production-oriented naming (e.g., `track_metadata_master`) via migration plan.
- Enforce explicit unique keys (including `raw_hash` behavior) and document DDL in repo.
- Normalize key/year constraints and consistency rules across ingestion and manual overrides.

## Summary

- Master BPM metadata table is currently `bpm_test_tracks`.
- Matching uses rule-based fuzzy scoring (`similar_text` + heuristics) with SQL `LIKE` candidate preselection.
- Fuzzy runs in queue worker, backfill worker, and admin search endpoint.
- Current architecture works for moderate scale but has clear bottlenecks for 30k+ imports and large metadata corpora, especially in parse memory usage and brute-force backfill matching.
