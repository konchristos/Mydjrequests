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
