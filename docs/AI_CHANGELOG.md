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
