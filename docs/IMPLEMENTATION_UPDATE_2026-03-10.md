# MyDJRequests Implementation Update (March 10, 2026)

## Scope
This update covers premium/BPM feature gating, XML import workflow updates, manual metadata matching behavior, ownership logic corrections, and request delete UX reliability.

## 1) Premium/BPM Access Model
- Removed legacy per-user BPM rollout controls from `admin/performance.php`.
- BPM tools now follow premium policy via `bpmCurrentUserHasAccess($db)`.
- Alpha Open Access remains supported through the premium-plan resolver path.
- Result: BPM/metadata availability is controlled by plan/alpha policy, not admin-only checks.

## 2) Navigation + Legacy Playlist Import Removal
- Added Premium badges to premium DJ nav features.
- Removed `Playlist Imports` nav entry.
- Redirected legacy endpoints:
  - `/BPM/index.php` -> `/dj/library_import.php`
  - `/BPM/rekordbox.php` -> `/dj/library_import.php`
- Removed obsolete TXT playlist import pipeline files:
  - `BPM/upload.php`
  - `BPM/import.php`
  - `BPM/map_fields.php`
  - `BPM/config.php`
  - `BPM/helpers.php`
  - `BPM/parse_rekordbox_txt.php`
  - `BPM/test_parse_txt.php`

## 3) Rekordbox XML Import
- XML import remains the canonical ingest path from DJ UI:
  - `/dj/library_import.php`
- Import writes/updates DJ collection records in `dj_tracks`.
- Identity mapping uses normalized hash + `track_identities`.
- `dj_library_stats` updates after successful import.

### Import behavior verified
- If XML is malformed (for example `Genre="R&B"` instead of `Genre="R&amp;B"`), parser stops early.
- Observed effect in production test account:
  - Job processed 6 tracks (first run) + 3 tracks (second run) = 9 total
  - This exactly matched `dj_tracks` count.
- Corrected sample XML generated with proper entity escaping.

## 4) Manual Metadata Match (DJ Page)
- Manual match access changed from admin-only to premium/alpha access.
- Updated gates in:
  - `api/dj/search_bpm_candidates.php`
  - `api/dj/apply_manual_bpm_match.php`
  - `dj/dj.js` UI gating
- Modal title changed from `Admin Metadata Match` to `Metadata Match`.

## 5) Ownership Logic Corrections

### Fixed
- Applying a global (not-owned) metadata candidate no longer auto-flips request ownership to owned.
- `manual_owned` is now set only when selected candidate is truly owned.
- Removed broad second-pass ownership fallback in `api/dj/get_requests.php` that caused false-positive ownership.
- Deduplicated modal owned candidates by normalized hash so one owned track does not appear as multiple owned variants.

### UI clarity
- Owned/missing indicators updated to clear ticks/crosses.
- Added explicit CSS classes for modal ownership labels:
  - `manual-match-owned` (green)
  - `manual-match-missing` (red)

## 6) Request Delete Reliability
Issue observed:
- Patron delete showed failure popup, but row was actually deleted and only disappeared after reload.

Fix implemented:
- `api/public/delete_my_request.php`
  - Commit + return success immediately.
  - Execute Spotify playlist sync after response flush (non-blocking).
- `request/index.php`
  - Added verification fallback: if response is malformed/timeout, check `get_my_requests`; if row is gone, treat as success.
  - Added optimistic UI removal and background refresh.

Result:
- Delete now removes row immediately without false error popups.

## 7) Database Tables Touched / Relied On
- `dj_tracks` (DJ collection, ownership basis)
- `track_identities` (canonical identities)
- `dj_library_stats` (per-DJ library totals/last import)
- `dj_owned_track_overrides` (manual spotify ownership override)
- `dj_event_track_overrides` (event-level metadata + manual_owned flag)
- `dj_library_import_jobs` (import audit/progress)
- `song_requests`, `event_tracks`, `event_request_stats`, `song_request_stats_monthly` (request/projection/stats)

## 8) Suggested Commit Message
`feat: stabilize premium BPM ownership flow, XML import routing, and request delete UX`
