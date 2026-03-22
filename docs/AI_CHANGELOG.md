# AI Changelog

## 2026-03-10
- Added schema-only helper: `app/helpers/dj_playlist_preferences.php`.
- Added idempotent DDL for new tables:
  - `dj_playlists`
  - `dj_playlist_tracks`
  - `dj_preferred_playlists`
- Added idempotent index/unique enforcement for existing installs:
  - `idx_dj_playlists_dj_id`
  - `idx_dj_playlists_parent`
  - `uq_dj_playlist_external` (`dj_id`, `source`, `external_playlist_key`)
  - `idx_dj_playlist_tracks_dj_track`
  - `idx_dj_preferred_playlists_playlist`
- Loaded helper in bootstrap files for availability:
  - `app/bootstrap.php`
  - `app/bootstrap_public.php`
- No importer, resolver, request, or playlist export logic was modified in this task.

## 2026-03-10 (Playlist Import Extension)
- Extended `library_import/RekordboxXMLImporter.php` to parse Rekordbox playlist hierarchy and membership via `XMLReader` streaming.
- Added two-phase import behavior (still streaming):
  - Phase 1: import `COLLECTION/TRACK` rows into `dj_tracks` (unchanged behavior).
  - Phase 2: parse `PLAYLISTS/NODE` and upsert:
    - `dj_playlists` (folders + playlists with parent hierarchy)
    - `dj_playlist_tracks` (playlist membership)
- Implemented `TrackID -> dj_track_id` mapping:
  - capture `TRACK@TrackID` during collection pass
  - map through imported track `normalized_hash` to resolved `dj_tracks.id`
  - use playlist `TRACK@Key` references to attach membership
- Idempotency protections:
  - playlist nodes upserted by unique `(dj_id, source, external_playlist_key)`
  - membership upserted with `INSERT IGNORE` on PK `(playlist_id, dj_track_id)`
  - unknown playlist track references are skipped safely.
- No request resolver flow changes were made.

## 2026-03-10 (Preferred Playlist Settings UI)
- Added DJ-facing preferred playlist settings section in `dj/library_import.php`.
- Page now lists imported playlists for the current DJ and allows mark/unmark via checkboxes.
- Preferences are saved to `dj_preferred_playlists` with CSRF protection.
- Selection logic is exact-playlist only (no folder inheritance).
- UI lists only playlists with track membership (`dj_playlist_tracks`), so folder-only nodes are not selectable.
- No resolver/request/export logic changes were made in this task.
- Added playlist search controls (`Search`, `Clear`) to filter imported playlists in-place.
- Added live selected-preferred confirmation panel with:
  - selected count
  - selected playlist chips (reminder before save)

## 2026-03-11 (Track Resolver Priority Update)
- Updated resolver selection in `api/dj/get_requests.php` for unmatched DJ track candidates.
- Resolver priority now follows:
  1. Manual/explicit override behavior remains highest priority (existing `manual_owned` + event/manual override path untouched).
  2. Candidate in any preferred playlist (`dj_preferred_playlists` via `dj_playlist_tracks`).
  3. 5-star candidate (rating >= 5 when rating column exists).
  4. Highest rating.
  5. Deterministic fallback by lowest `dj_tracks.id`.
- Added preferred-playlist join path to resolver candidate query:
  - `dj_tracks d`
  - `dj_playlist_tracks dpt`
  - `dj_preferred_playlists dpp`
- Added adaptive rating expression support for `dj_tracks` schemas that use:
  - `rating` (preferred)
  - fallback: `stars` or `star_rating`
  - fallback to `0` when none exist.
- Did not change request ordering, boosts, event_tracks projection behavior, or ingestion flow.

## 2026-03-11 (Metadata Match UI: Preferred + Rating)
- Updated `api/dj/search_bpm_candidates.php` to enrich manual metadata candidates with:
  - `is_preferred` flag (candidate exists in any DJ preferred playlist)
  - `rating_value` (schema-safe rating field resolution)
- Added preferred playlist joins for owned candidate metadata:
  - `dj_tracks d`
  - `dj_playlist_tracks dpt`
  - `dj_preferred_playlists dpp`
- Candidate ordering now prioritizes:
  1. Preferred-playlist candidates
  2. Higher rating
  3. Match score
  4. Deterministic ID tie-break
- Updated metadata match modal UI in `dj/dj.js` + `dj/dj.css`:
  - added `Preferred Playlist` badge
  - added `★ rating` badge
  - preferred/rating candidates now surface first in displayed results.

## 2026-03-11 (Metadata Match UI Tweaks)
- Updated metadata match modal candidate badges:
  - show `Preferred` badge first
  - show folder badge (derived from `dj_tracks.location`) after preferred badge
  - show `★★★★★` badge only for 5-star candidates (no stars shown for others)
- Added selected-candidate visual highlight in modal (`green` opaque row) using current track metadata (BPM/key/year) matching.
- Added request-tile preferred indicator:
  - `api/dj/get_requests.php` now emits `preferred_selected` per row when resolved `dj_track_id` belongs to any preferred playlist for that DJ.
  - `dj/dj.js` renders a small `Preferred` badge in the request title when set.

## 2026-03-11 (Metadata Match Precision + Preference Persistence Fix)
- Fixed selected-row highlight in metadata modal:
  - now uses exact selected BPM track (`is_selected`) from persisted override/link instead of BPM/key/year heuristic.
  - only the actually selected candidate row is highlighted.
- Persisted manual selection details in `dj_event_track_overrides`:
  - `bpm_track_id` (selected BPM candidate ID)
  - `manual_preferred` (whether selected candidate is from preferred playlists)
  - includes safe schema auto-add for existing installs.
- Preferred badge behavior on request tiles changed:
  - now manual-selection driven (shows when selected candidate was preferred), not generic auto-owned-preferred.
- Tightened manual metadata search filtering to reduce unrelated low-score candidates from keyword collisions.
- Fixed five-star badge logic:
  - `★★★★★` is now shown when candidate resolves to 5-star after owned/global rating merge.

## 2026-03-11 (5-Star Badge Compatibility Fix)
- Updated `api/dj/search_bpm_candidates.php` rating normalization so star badges work across mixed rating formats:
  - direct 0..5 ratings
  - 0..10 ratings
  - 0..100 ratings
  - Rekordbox-style 0..255 values
  - star glyph text values (e.g. `★★★★★`)
- Five-star badge now evaluates against normalized 0..5 rating consistently.

## 2026-03-11 (Rekordbox Rating Persistence Fix)
- Updated `library_import/RekordboxXMLImporter.php` to parse Rekordbox `TRACK@Rating` and store it into available DJ track rating column (`rating`, `stars`, `star_rating`, or `rekordbox_rating`).
- Added rating normalization handling in importer for:
  - 0..255 Rekordbox scale
  - 0..100 / 0..10 scales
  - direct 0..5 values
  - star glyph text (`★★★★★`)
- Expanded rating column detection in candidate/resolver logic to also support:
  - `rekordbox_rating`
  - `rb_rating`
  - `rating_raw`
- Note: tracks imported before this fix may not have rating persisted; a re-import is required for those rows to show 5-star badges.

## 2026-03-11 (XML Import Telemetry + History)
- Added import job telemetry fields (idempotent auto-add in import/worker/run/status paths):
  - `upload_bytes` (declared upload size)
  - `stored_bytes` (actual stored XML size on server after merge/move)
  - `stage` (queued/processing_tracks/processing_playlists/finalizing/done/failed)
  - `stage_message` (human-readable stage text)
- Extended `library_import/RekordboxXMLImporter.php` with an optional progress callback so long imports report stage transitions without loading XML into memory.
- Updated queue worker/manual run handlers to persist stage transitions and completion/failure stage messages into `dj_library_import_jobs`.
- Updated `/api/dj/import_rekordbox_xml.php` to capture and persist upload size + stored file size when creating jobs.
- Updated `/api/dj/import_rekordbox_xml_status.php` response to include:
  - `stage`, `stage_message`
  - `upload_bytes`, `stored_bytes`
  - `elapsed_seconds`
- Updated `dj/library_import.php` UI:
  - live status now shows stage + elapsed time + upload/stored sizes while polling
  - added **Recent Import History** table (latest jobs) with status, stage, elapsed, size metrics, and error snippet.

## 2026-03-11 (Telemetry Accuracy + Local Time Fixes)
- Fixed import status elapsed-time skew by parsing DB timestamps as UTC in `api/dj/import_rekordbox_xml_status.php`.
- Updated `dj/library_import.php` to render **Last Import** and history **Created** timestamps in browser-local timezone.
- Fixed history elapsed calculation to use UTC-safe parsing.
- Improved `rows_inserted` / `rows_updated` accuracy in `library_import/RekordboxXMLImporter.php` by deriving insert/update outcome from MySQL `rowCount()` per `INSERT ... ON DUPLICATE KEY UPDATE`.

## 2026-03-11 (DJ Request Tile Manual Path Indicator)
- Added request-level `manual_path_matched` signal in `api/dj/get_requests.php` when a manual BPM track override (`selected_bpm_track_id` / `bpm_track_id`) is present.
- Updated grouped request merging in `dj/dj.js` to preserve manual-path state across grouped variants.
- Added a `Manual Path` badge in the DJ request tile metadata row so DJs can quickly see which requests are already set with a persistent manual path match.
- Added styling for the new badge in `dj/dj.css`.

## 2026-03-11 (Playlist Export Reliability Alignment)
- Updated `api/dj/export_event_playlist.php` owned export resolution to prefer deterministic path sources in this order:
  1. DJ event manual override (`dj_event_track_overrides.bpm_track_id`)
  2. Linked BPM mapping (`track_links.spotify_track_id -> bpm_track_id`)
  3. Direct `track_identity_id` match in `dj_tracks`
  4. Direct normalized hash match
  5. Exact core artist/title key fallback
- Removed broad fuzzy artist/title path fallback from owned export to avoid unrelated matches.
- Kept M3U path normalization and explicit filtering of non-local `spotify:` / `localhostspotify:` URIs.
- Aligned candidate selection with resolver-style ranking (preferred playlist membership, rating, deterministic ID tie-break) when choosing among multiple `dj_tracks` versions.
- Added lightweight export diagnostics headers for parity checks:
  - `X-MDJR-Export-Total`
  - `X-MDJR-Export-Exported`
  - `X-MDJR-Export-Unresolved`
  - `X-MDJR-Export-Mode`

## 2026-03-12 (Manual Match Export Key Alignment Fix)
- Fixed manual override export parity in `api/dj/export_event_playlist.php` by aligning override key generation to the same `artist_core|title_core` format used by:
  - `api/dj/apply_manual_bpm_match.php`
  - `api/dj/get_requests.php`
- Added override-title-core fallback mapping so manual matched versions still resolve when artist text differs slightly between request/cache rows.
- Result: owned M3U export now correctly prefers the manually matched version path for tracks with persisted manual overrides.

## 2026-03-17 (Full Library Sync Availability + Stale Match Review)
- Extended Rekordbox full-library imports to track current file availability in `dj_tracks`:
  - `is_available`
  - `last_seen_import_job_id`
  - `last_seen_at`
- Updated `library_import/RekordboxXMLImporter.php` so each successful full import:
  - marks seen rows as available
  - stamps the current import job on seen rows
  - marks previously imported rows for that DJ as unavailable when they were not seen in the latest XML
- Updated `dj_library_stats.track_count` and worker-side fallback counting to count only currently available DJ tracks.
- Updated owned/exportable logic to ignore unavailable `dj_tracks` rows:
  - `api/dj/export_event_playlist.php`
  - `api/dj/event_library_summary.php`
- Added stale match helper: `app/helpers/dj_stale_matches.php`
- Added stale review UI: `dj/stale_matches.php`
  - lists DJ-global saved manual matches whose previously linked local file is no longer present in the latest library import
  - allows one-at-a-time resolution
- Added stale review APIs:
  - `api/dj/search_stale_match_candidates.php`
  - `api/dj/apply_stale_match.php`
- Applying a stale match updates:
  - `dj_global_track_overrides`
  - any existing `dj_event_track_overrides` rows for the same DJ + override key
- Added entry point from `dj/library_import.php` to the stale review screen.
## 2026-03-18 - Exact DJ Track Persistence For Saved Matches

- Manual BPM matches now persist the exact selected `dj_track_id` alongside `bpm_track_id` in both `dj_event_track_overrides` and `dj_global_track_overrides`.
- Full-library re-import stale detection now treats a saved match as stale when that exact local DJ track is no longer available, even if another version with the same normalized hash still exists.
- Playlist export and event library summary now honor exact saved matches first and stop silently falling back to another local version when the originally matched file has gone stale.

## 2026-03-19 - Detailed Implementation Summary For Lockdown Review

This section summarizes the current DJ library import, matching, resolver, stale review, and export behavior after the latest implementation round.

### 1. DJ Library Import Pipeline

- DJ library import is now centered on `library_import/RekordboxXMLImporter.php`.
- Import supports:
  - raw Rekordbox `.xml`
  - zipped `.zip` uploads that contain exactly one XML file
- Main entry points:
  - `dj/library_import.php`
  - `api/dj/import_rekordbox_xml.php`
  - `api/dj/import_rekordbox_xml_run.php`
  - `app/workers/rekordbox_import_worker.php`
- Imports are asynchronous and job-based.
- Import history is stored in `dj_library_import_jobs` and shown in the DJ UI.
- Upload telemetry now records:
  - upload size
  - stored file size
  - processing stage
  - stage message
  - elapsed time
  - tracks processed
  - tracks added
  - tracks updated
- Import status/history UI now shows:
  - current stage
  - elapsed processing time
  - recent import history
  - upload/stored byte metrics
- ZIP support was added to reduce upload bandwidth while preserving the same import pipeline after extraction.

### 2. Rekordbox Data Imported Into `dj_tracks`

- The Rekordbox importer currently imports and persists:
  - title
  - artist
  - BPM
  - musical key
  - genre
  - local file path / location
  - rating
  - release year
  - normalized hash / identity information
- Rekordbox ratings are normalized for multiple scales, including Rekordbox-style `0..255`.
- Rekordbox year is now stored into `dj_tracks.release_year`.
- Genre is already stored in `dj_tracks.genre`.

## 2026-03-20 - Phase 1 Upload Security Hardening

- Added shared upload hardening helper:
  - `app/helpers/rekordbox_import_security.php`
- Centralized secure upload storage path handling:
  - new default upload root is now outside the web root:
    - `dirname(APP_ROOT) . '/storage/dj_libraries'`
  - optional override via secret/config key:
    - `DJ_LIBRARY_UPLOAD_DIR`
- Added centralized file naming + directory helpers for:
  - upload target path generation
  - chunk session paths
  - directory creation
  - chunk cleanup

### Upload Validation

- Upload validation now applies to both:
  - single uploads
  - chunked uploads after merge
- Accepted file types remain:
  - `.xml`
  - `.zip`
- Added MIME/content sniffing using `finfo` plus signature checks:
  - XML must look like real XML
  - ZIP must have a valid `PK` ZIP signature
- App-layer size limits now enforce secret/config-driven maximums:
  - `REKORDBOX_XML_MAX_UPLOAD_BYTES`
  - `REKORDBOX_ZIP_MAX_UPLOAD_BYTES`
  - defaults currently remain `500MB`

### XML Hardening

- Added explicit XML content validation before import:
  - rejects `<!DOCTYPE`
  - rejects `<!ENTITY`
  - requires Rekordbox root marker `<DJ_PLAYLISTS`
- Existing streaming importer behavior remains unchanged:
  - `XMLReader`
  - `LIBXML_NONET`
  - no full XML load into memory

### ZIP Hardening

- ZIP uploads are now validated before extraction/use:
  - archive must contain exactly one file
  - entry must not be a directory
  - entry path cannot contain `/`, `\\`, or `..`
  - entry must end in `.xml`
- Added extracted XML size limit:
  - `REKORDBOX_XML_MAX_EXTRACTED_BYTES`
  - default `2GB`
- Added ZIP compression ratio guard:
  - `REKORDBOX_ZIP_MAX_RATIO`
  - default `40`
- ZIP extraction still uses streamed `ZipArchive::getStream()` extraction rather than `extractTo()`

### Import Queue Protections

- Added one-active-import-per-DJ enforcement at upload entry points:
  - DJs cannot queue a second import while another is `queued` or `processing`
- Added duplicate active import detection by SHA-256 hash:
  - new job column:
    - `dj_library_import_jobs.source_sha256`
  - blocks re-queuing the same file when an identical import is already active
- Updated schema bootstrapping in:
  - `api/dj/import_rekordbox_xml.php`
  - `api/dj/import_rekordbox_xml_run.php`
  - `app/workers/rekordbox_import_worker.php`
  - `api/dj/import_rekordbox_xml_status.php`

### Consistency Across Processing Paths

- Wired manual runner and background worker paths to the same shared upload/chunk helpers
- Manual run + worker now understand the hardened job schema including:
  - `source_sha256`
- Chunk cleanup now routes through the shared helper implementation

### Outcome

- Phase 1 now materially reduces risk from:
  - invalid/mislabelled uploads
  - oversized uploads
  - XML entity / DTD attacks
  - ZIP traversal / malformed archive abuse
  - ZIP bomb-style high compression ratios
  - repeated parallel import abuse per DJ
  - accidental duplicate active imports

### Still Deferred To Later Phases

- per-DJ upload rate limiting
- global worker concurrency caps
- suspicious upload audit/admin review UI
- broader duplicate history checks beyond currently active jobs

## 2026-03-20 - Phase 2 Import Abuse Controls + Operational Logging

- Added upload/import operational controls in `app/helpers/rekordbox_import_security.php`:
  - per-DJ rate limiting
  - queued job cap per DJ
  - global concurrent processing cap
  - structured security/event logging

### Rate Limiting

- Added per-DJ rate limiting based on recent import job creation volume.
- New secret/config keys:
  - `REKORDBOX_IMPORT_RATE_WINDOW_MINUTES`
  - `REKORDBOX_IMPORT_MAX_ATTEMPTS_PER_WINDOW`
- Defaults:
  - `60` minute window
  - `6` attempts per window
- Upload attempts beyond the configured window threshold now return HTTP `429`.

### Queue Capacity

- Added queued job cap per DJ.
- New secret/config key:
  - `REKORDBOX_IMPORT_MAX_QUEUED_PER_DJ`
- Default:
  - `1`
- This sits alongside the existing one-active-import-per-DJ rule and provides an extra queue abuse guard.

### Global Worker Concurrency

- Added a global processing cap for Rekordbox import jobs.
- New secret/config key:
  - `REKORDBOX_IMPORT_MAX_CONCURRENT_JOBS`
- Default:
  - `2`
- Worker dispatch now checks the current number of `processing` jobs before spawning additional workers.
- Background worker claim logic now also checks this cap before claiming queued jobs.
- Manual run path (`api/dj/import_rekordbox_xml_run.php`) now respects the same concurrency cap and returns a clear message when capacity is full.

### Logging

- Added structured event logging to:
  - `storage/dj_libraries/security.log`
- Logged events now include:
  - rejected uploads
  - ZIP/XML validation failures
  - deferred worker dispatches due to concurrency caps
  - worker failures
  - manual-run failures
- Logging is JSON-lines style for easier operational review.

### Enforcement Points Updated

- `api/dj/import_rekordbox_xml.php`
  - now enforces:
    - per-DJ rate limit
    - per-DJ queue cap
    - active import lock
    - duplicate active hash rejection
  - now logs upload-side exceptions via the shared logging helper
- `api/dj/import_rekordbox_xml_status.php`
  - now respects the global concurrency cap before trying to dispatch a worker
- `app/workers/rekordbox_import_worker.php`
  - now respects the global concurrency cap when claiming queued jobs
  - now logs worker failures through the shared logging helper
- `api/dj/import_rekordbox_xml_run.php`
  - now respects the global concurrency cap
  - now logs manual-run failures through the shared logging helper

### Outcome

- Phase 2 now reduces operational abuse risk from:
  - repeated rapid-fire import attempts by one DJ
  - queue flooding
  - uncontrolled worker fan-out
  - missing audit visibility when uploads are rejected or imports fail

### 3. Playlist Hierarchy + Preferred Playlists

- Playlist schema was added:
  - `dj_playlists`
  - `dj_playlist_tracks`
  - `dj_preferred_playlists`
- Rekordbox playlist hierarchy and playlist membership are imported and kept idempotent.
- Preferred playlist selection UI was added in `dj/library_import.php`.
- DJs can:
  - search imported playlists
  - mark preferred playlists
  - review currently selected preferred playlists
- Preferred playlists affect resolver ranking, but do not block non-preferred tracks from appearing.

### 4. Full Library Sync State

- The system now treats each Rekordbox re-import as the current truth for that DJ library.
- `dj_tracks` now includes and uses:
  - `is_available`
  - `last_seen_import_job_id`
  - `last_seen_at`
- On full import:
  - seen tracks are marked available
  - seen tracks are stamped with the current import job
  - unseen previously imported tracks for that DJ are marked unavailable
- This means the system now handles:
  - existing track, same path
  - existing track, moved path
  - track removed from collection
- File moves are supported because the importer updates the stored local path for the same normalized track identity.
- File removal is supported because the importer now marks missing tracks unavailable instead of continuing to treat them as active.

### 5. Preferred / Rating / Manual Resolver Order

- Resolver priority currently follows this order:
  1. manual match / explicit override
  2. preferred playlist membership
  3. 5-star tracks
  4. higher rating
  5. best title/artist match
  6. deterministic fallback
- Preferred playlists and stars improve ranking, but are not required for a track to be eligible.

### 6. Metadata Match Modal

- Main matching UI is driven by:
  - `api/dj/search_bpm_candidates.php`
  - `api/dj/apply_manual_bpm_match.php`
  - `dj/dj.js`
  - `dj/dj.css`
- Metadata Match now shows:
  - preferred badge
  - folder / playlist badge
  - rating / 5-star badge
  - BPM
  - key
  - year
  - genre
  - ownership state
- Unavailable rows are visually distinct:
  - muted / red-tinted styling
  - disabled action button
  - clear “not in your library” state
- Deleted matched versions can still be shown for context, but are no longer treated as owned or playable.

### 7. Manual Match Persistence

- Manual matches now persist the exact selected local `dj_track_id`, not only a global BPM metadata row.
- Manual match state is written to:
  - `dj_event_track_overrides`
  - `dj_global_track_overrides`
- This lets the system reuse selected versions across future events for the same DJ.
- DJ request tiles now use compact badges:
  - `P` = preferred
  - `M` = matched/manual path

### 8. Local-Only Candidate Support

- Some owned tracks exist in `dj_tracks` without a fully matching `bpm_test_tracks` row.
- These are now surfaced in Metadata Match as owned local versions.
- This solved cases where a DJ-owned version existed locally but was missing from the previous candidate list.
- Local-only owned rows can now be applied as the chosen version.
- Applying a local-only version persists the exact `dj_track_id` so it can be reused later.
- This is especially important for cases where:
  - artist text differs slightly
  - there is no exact BPM cache row
  - the DJ still owns a usable local file

### 9. Stale Matched Tracks Review

- A dedicated stale review flow was added:
  - `app/helpers/dj_stale_matches.php`
  - `dj/stale_matches.php`
  - `api/dj/search_stale_match_candidates.php`
  - `api/dj/apply_stale_match.php`
- This view is used when a previously saved exact local match no longer points to an available DJ file.
- Stale review now:
  - searches only current library tracks
  - avoids unrelated noisy global rows
  - uses a display style that matches the main Metadata Match modal
  - allows one-at-a-time reassignment to a replacement local file
- The stale review modal now uses the same styled Apply button treatment as the main Metadata Match modal.

### 10. Event Library Summary + Playlist Export

- Event library summary and export endpoints are in place:
  - `api/dj/event_library_summary.php`
  - `api/dj/export_event_playlist.php`
- Event summary uses event projection data and current library availability rather than expensive direct request aggregation.
- Playlist export now prefers:
  - exact manual override path
  - deterministic local file resolution
  - currently available DJ tracks only
- Export skips invalid local-unusable URI entries such as `spotify:` style paths in owned M3U output.
- The compact library summary UI was moved into the DJ event header to save vertical space.

### 11. Current Source-Of-Truth Model

- Rekordbox remains the source of truth for a DJ’s library.
- MyDJRequests mirrors library state for:
  - matching requests
  - identifying owned tracks
  - saving preferred playlist choices
  - saving reusable matched versions
  - exporting playable preparation playlists
- The platform is intentionally not becoming a separate library editor.
- Library edits should continue to happen in DJ software, then be re-imported into MyDJRequests.

### 12. Validated Behaviors Achieved

- DJs can upload large Rekordbox XML libraries through the web UI.
- ZIP upload support reduces bandwidth substantially for repeated re-imports.
- Imported tracks preserve path, genre, rating, year, and ownership state.
- When a track moves to a new location, the stored path can be updated on re-import.
- When a track is removed from the library, it can now be marked unavailable and excluded from exports.
- DJs can manually choose the exact version they want to use.
- Those choices can be reused in future events.
- Preferred playlists and ratings now influence version ranking.
- Local-only owned tracks can be surfaced and selected when no BPM cache row exists.
- Stale exact-file matches can now be reviewed and reassigned.

### 13. Known Areas To Review In The Next Lockdown Pass

- Harden ZIP upload validation further:
  - enforce exact archive contents
  - protect against malicious archive structures
  - tighten extracted-size policy
- Add stronger upload abuse protection:
  - per-DJ rate limiting
  - one active import job per DJ
  - upload frequency limits
  - better logging for operational review
- Review legacy overrides created before exact `dj_track_id` persistence to determine whether a cleanup/migration path is needed.
- Continue unifying candidate search behavior across:
  - main Metadata Match
  - stale resolver
  - export selection
- Review any remaining edge cases where request-tile ownership/match indicators could drift from modal truth after legacy data changes.

### 14. Recommended Summary For ChatGPT

At this point the platform has moved from a BPM-only matching proof-of-concept to a DJ-library-aware resolution workflow with:

- full Rekordbox library import
- playlist hierarchy import
- preferred playlist ranking
- star/rating-aware ranking
- reusable exact manual match persistence
- stale matched track recovery
- event library ownership summary
- deterministic local playlist export
- support for moved and removed local files

The next phase should focus on lockdown/hardening rather than new user-facing features.

## 2026-03-20 - Phase 3 Import Guardrails + Admin Review

- Added importer-side logical caps in `library_import/RekordboxXMLImporter.php`:
  - max tracks (`REKORDBOX_IMPORT_MAX_TRACKS`, default `150000`)
  - max playlists (`REKORDBOX_IMPORT_MAX_PLAYLISTS`, default `10000`)
  - max playlist depth (`REKORDBOX_IMPORT_MAX_PLAYLIST_DEPTH`, default `32`)
- Added importer watchdog checks:
  - max runtime (`REKORDBOX_IMPORT_MAX_RUNTIME_SECONDS`, default `3600`)
  - max memory usage (`REKORDBOX_IMPORT_MAX_MEMORY_MB`, default `768`)
- Import now aborts safely if XML content is structurally too large or exceeds configured ingestion guardrails.
- Added security log helper readers in `app/helpers/rekordbox_import_security.php`:
  - `mdjr_rekordbox_log_entries()`
  - `mdjr_rekordbox_log_summary()`
- Added admin review page `admin/import_security.php`:
  - recent security log entries
  - recent import jobs
  - category counts
  - queued/processing/failed job visibility
  - storage log path visibility
- Added navigation access to the review page from:
  - `admin/dashboard.php`
  - `admin/performance.php`
- This phase improves observability and keeps import abuse controls auditable without changing DJ-facing workflow.

## 2026-03-21 - Import Timing Breakdown + Live AJAX Import History

- Added per-stage import timing columns to `dj_library_import_jobs`:
  - `tracks_started_at`
  - `tracks_finished_at`
  - `playlists_started_at`
  - `playlists_finished_at`
  - `finalizing_started_at`
  - `finalizing_finished_at`
- Updated import stage transitions in:
  - `app/workers/rekordbox_import_worker.php`
  - `api/dj/import_rekordbox_xml_run.php`
  so the job now stamps stage boundaries when moving through:
  - `processing_tracks`
  - `processing_playlists`
  - `finalizing`
  - `done` / `failed`
- Extended `api/dj/import_rekordbox_xml_status.php` to return stage-duration metrics:
  - `tracks_seconds`
  - `playlists_seconds`
  - `finalizing_seconds`
- Added a new history endpoint:
  - `api/dj/import_rekordbox_xml_history.php`
  which returns the latest import jobs with:
  - local display timestamps
  - elapsed time
  - stage breakdown summaries
  - upload/stored sizes
  - counts and error summaries
- Updated `dj/library_import.php` AJAX UX so the page now:
  - inserts newly created jobs into Recent Import History without a refresh
  - refreshes the history table while a job is queued/processing
  - shows per-stage breakdown text in history rows such as:
    - `Tracks 42s • Playlists 4m 11s • Finalize 3s`
  - keeps the existing pre-job upload lock/restore behavior
- Result:
  - import slowdowns can now be diagnosed by stage instead of guesswork
  - DJs no longer need to refresh the page to see a new import job appear in history

## 2026-03-21 - Library Import Live Overview + Friendlier Re-import Copy

- Added a live overview endpoint:
  - `api/dj/library_import_overview.php`
- The endpoint now returns JSON for the Library Import page with:
  - `track_count`
  - `last_imported_at`
  - `last_imported_iso_utc`
  - `last_imported_display`
  - `last_import_source`
  - `stale_count`
- Updated `dj/library_import.php` so the page now refreshes overview data via AJAX:
  - on page load
  - on successful import status polling
  - on window focus
  - on `pageshow`
  - on a timed interval while the page is open
- This keeps the following areas current without a full page reload:
  - Tracks in Library
  - Last Import
  - Stale Matched Tracks count
- Added a small live-status note to the page so DJs can see that the overview is actively refreshing in the background.
- Updated the Re-import workflow copy on `dj/library_import.php` to be less technical and more user-friendly:
  - removed internal language such as `normalized hash`
  - explained moved tracks in plain English
  - explained unavailable tracks in plain English
  - encouraged DJs to ZIP large XML files before uploading to reduce upload time and bandwidth

## 2026-03-21 - Final Upload Hardening Pass

- Tightened XML validation in `app/helpers/rekordbox_import_security.php`:
  - still rejects `DOCTYPE` / `ENTITY`
  - now also performs streaming structural validation with `XMLReader`
  - requires Rekordbox root and expected sections:
    - `DJ_PLAYLISTS`
    - `COLLECTION`
    - `TRACK`
    - `PLAYLISTS`
- Tightened ZIP validation in `app/helpers/rekordbox_import_security.php`:
  - reduced max compression ratio default from `40` to `30`
  - added safer ZIP entry name validation
  - still rejects nested archives, traversal paths, and multiple XML payloads
  - added streamed byte cap while reading `ZipArchive::getStream()` so extracted bytes cannot exceed declared ZIP entry size
- Added per-DJ temporary storage quota enforcement:
  - counts active import files and chunk uploads under the private storage area
  - configurable via `REKORDBOX_IMPORT_MAX_STORAGE_BYTES_PER_DJ`
  - enforced for both single uploads and chunked uploads in `api/dj/import_rekordbox_xml.php`
- Tightened importer watchdog checks in `library_import/RekordboxXMLImporter.php`:
  - checks now run more frequently during track import
  - checks now run before batch flushes
  - playlist processing checks run more frequently by playlist count
  - playlist track membership parsing also triggers periodic watchdog checks
- These changes are focused on parser safety, resource control, and abuse prevention rather than antivirus-style scanning.

## 2026-03-21 - Admin Import Review UX

- Updated `admin/import_security.php` to make the admin review page easier to navigate:
  - added tabbed switching between `Import Jobs` and `Security Log`
  - removed the need to scroll all the way to the bottom just to review security events
- Extended the Import Jobs view to show historical stage timing breakdowns per job:
  - `Tracks`
  - `Playlists`
  - `Finalize`
- Formatted admin timestamps into local-friendly display values so slow-run investigations are easier to compare visually against the DJ-facing import history.

## 2026-03-22 - Phase 4 Reports and Dashboard Scoring Alignment

- Updated `dj/reports.php` so report ranking now aligns with the weighted scoring engine introduced earlier:
  - `score = (request_count * 2) + (vote_count * 1) + (boost_count * 10)`
- Performance report:
  - added per-event `total_votes`, `total_boosts`, and `score`
  - ordering now uses `score` instead of falling back to request totals
  - table now displays requests, votes, boosts, and score together
- Top Requested Songs report:
  - now aggregates requests, votes, and boosts by track
  - computes weighted `score` per track
  - orders by `score DESC`, then request count
  - table now includes requests, votes, boosts, and score
- Top Activity report:
  - per-event patron rankings now include requests, votes, boosts, and score
  - per-event patron ordering now uses `score`
  - combined activity ordering now uses `score` instead of request+vote totals alone
  - headings were updated to reflect broader patron activity, not just requests
- Advanced Analytics repeat patron section:
  - repeat patron ranking now uses `score` instead of total activity only
  - per-track repeat patron breakdown now uses `score`
  - summary copy now explains requests + votes + boosts -> weighted score
- CSV export:
  - repeat patron CSV now includes:
    - `request_count`
    - `vote_count`
    - `boost_count`
    - `popularity`
    - `score`
- Dashboard:
  - next-event summary now includes boosts alongside requests and votes so the dashboard reflects all three scoring inputs
- Backward compatibility:
  - existing request/vote/boost counters remain intact
  - legacy popularity-style fields remain available for display and compatibility
  - ranking logic now consistently follows weighted score in reports where ranking is the primary purpose
