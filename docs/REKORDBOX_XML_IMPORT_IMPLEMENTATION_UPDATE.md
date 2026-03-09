# Rekordbox XML Import - Implementation Update

## Project
MyDJRequests (`public_html/`)

## Goal Achieved
A production-capable Rekordbox XML library import pipeline is now implemented for DJs, including:

- browser upload UI with drag/drop + file picker
- large-file chunked upload (works for ~300MB+)
- async processing queue to avoid Cloudflare `524` request timeouts
- background worker processing
- status polling in UI
- manual fallback trigger button
- import metrics (processed, identities, added, updated)

---

## What Was Implemented

## 1) Rekordbox Importer Module

### File
- `library_import/RekordboxXMLImporter.php`

### Behavior
- Uses `XMLReader` streaming parser (no full file load in memory).
- Parses Rekordbox `COLLECTION > TRACK` elements.
- Extracts:
  - `Name`
  - `Artist`
  - `AverageBpm`
  - `Tonality`
  - `Genre`
  - `Location`
- Normalizes artist/title to `normalized_hash`.
- Resolves `track_identity_id` via `track_identities` upsert.
- Upserts into `dj_tracks`.

### Current metrics emitted by importer
- `rows_inserted`
- `rows_updated`
- `rows_buffered` / `total_tracks_seen`
- `rows_skipped`

---

## 2) DJ Upload Page

### File
- `dj/library_import.php`

### Features
- Requires DJ auth (`require_dj_login()`).
- Drag-and-drop upload area + click-to-browse.
- `.xml` client validation.
- Chunked upload from browser (`8MB` chunks).
- Upload statuses:
  - preparing
  - uploading
  - queued
  - processing
  - complete
  - error
- Polls job status endpoint until `done` / `failed`.
- Manual fallback button when queued:
  - `Process Queued Import Now`

### UI summary metrics
- Tracks Processed
- New Identities
- Existing Identities
- DJ Tracks Added
- DJ Tracks Updated

---

## 3) Import API (Chunk + Queue)

### File
- `api/dj/import_rekordbox_xml.php`

### Actions
- `action=start`
  - starts chunk upload session
  - validates `.xml`
- `action=chunk`
  - accepts one chunk and stores it to disk
- `action=finish`
  - verifies all chunks present
  - merges to XML file on disk
  - creates queued job in DB
  - attempts background dispatch
  - returns quickly with `job_id` (prevents request timeout)

### Backward compatibility
- Non-action single upload path still supported (`library_xml`), but queue-based result now used.

### Upload storage paths
- merged upload temp file:
  - `public_html/uploads/dj_libraries/`
- chunk sessions:
  - `public_html/uploads/dj_libraries/chunks/{djId}_{uploadId}/`

---

## 4) Import Job Status API

### File
- `api/dj/import_rekordbox_xml_status.php`

### Purpose
- Returns current job state for frontend polling.

### Returns
- `status` (`queued`, `processing`, `done`, `failed`)
- `tracks_processed`
- `new_identities`
- `existing_identities`
- `dj_tracks_added`
- `dj_tracks_updated`
- `error_message`
- timestamps (`created_at`, `started_at`, `finished_at`)

### Extra behavior
- If job is still queued, endpoint attempts worker dispatch.

---

## 5) Background Worker

### File
- `app/workers/rekordbox_import_worker.php`

### Purpose
- Claims queued import job.
- Marks it `processing`.
- Runs `RekordboxXMLImporter`.
- Updates job result metrics.
- Marks `done` or `failed`.
- Cleans up source/chunk temp files on success.

---

## 6) Manual Run Endpoint (Fallback)

### File
- `api/dj/import_rekordbox_xml_run.php`

### Purpose
- Allows manual force-start of one queued job from UI.
- Useful when cron/background dispatch is delayed.

### Important fix included
- Releases PHP session lock before long processing (`session_write_close()`), so status polling does not block.

---

## 7) Database Tables Used

## `dj_tracks`
Per-DJ imported collection table.

Writes include:
- `dj_id`
- `track_identity_id`
- `normalized_hash`
- title/artist/bpm/key/genre
- Rekordbox location path (`location` or mapped equivalent)
- `source='rekordbox_xml'`

## `track_identities`
Global canonical identity table.

Used to resolve/create identity IDs from normalized artist/title hash.

## `dj_library_import_jobs`
Queue + status + results table for async imports.

Key columns:
- `status`
- `source_file_path`
- `chunk_upload_id`
- `tracks_processed`
- `new_identities`
- `existing_identities`
- `dj_tracks_added`
- `dj_tracks_updated`
- `error_message`
- `started_at`, `finished_at`

---

## 8) Navigation/Access

`dj/layout.php` includes a Library Import nav link (with BPM access gating).

---

## 9) Cron Setup (Required for Reliable Async Processing)

Cron added (every minute):

```bash
/usr/local/bin/php /home/mydjrequests/public_html/app/workers/rekordbox_import_worker.php >/dev/null 2>&1
```

Without worker execution, jobs remain `queued`.

---

## 10) Operational Issues Encountered and Resolved

## Initial issue: large upload stuck / 0%
- Cause: large-file upload/proxy behavior.
- Fix: chunked browser upload + API chunk protocol.

## `Table dj_tracks does not exist`
- Fix: API auto-creates `dj_tracks` if missing.

## Cloudflare `524` on processing
- Cause: long-running synchronous request.
- Fix: async queue + worker + status polling.

## Manual run caused polling errors (HTML timeout)
- Cause: session lock held during long request.
- Fix: `session_write_close()` before processing.

---

## 11) Current Behavior Summary

- Large XML uploads are chunked and persisted server-side.
- `finish` is fast and returns `job_id`.
- UI polls status endpoint.
- Worker processes in background.
- Summary now differentiates adds vs updates.

Observed verified behavior:
- first 10-track import: `added=10`, `new identities=10`
- second import same file: `added=0`, `existing identities=10`

---

## 12) Important Notes for Next Iteration

- Incoming song requests do **not yet** use `track_identities` as first-query source.
- Current request path still relies on existing request/Spotify/link tables.
- If desired, next migration step is request lookup by `track_identity_id` first, fallback to legacy fields.

