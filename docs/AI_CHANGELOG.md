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
