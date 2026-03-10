<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    exit('Premium feature only.');
}
$djId = (int)($_SESSION['dj_id'] ?? 0);
ensureDjLibraryStatsTable($db);

$libraryTrackCount = 0;
$lastImportedAt = null;
$lastImportSource = 'rekordbox_xml';

try {
    $statsStmt = $db->prepare("
        SELECT track_count, last_imported_at, source
        FROM dj_library_stats
        WHERE dj_id = ?
        LIMIT 1
    ");
    $statsStmt->execute([$djId]);
    $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($statsRow) {
        $libraryTrackCount = max(0, (int)($statsRow['track_count'] ?? 0));
        $lastImportedAt = $statsRow['last_imported_at'] ?? null;
        $lastImportSource = trim((string)($statsRow['source'] ?? 'rekordbox_xml'));
    } else {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM dj_tracks WHERE dj_id = ?");
        $countStmt->execute([$djId]);
        $libraryTrackCount = max(0, (int)$countStmt->fetchColumn());
    }
} catch (Throwable $e) {
    $libraryTrackCount = 0;
    $lastImportedAt = null;
    $lastImportSource = 'rekordbox_xml';
}

$lastImportedDisplay = 'Never';
if (!empty($lastImportedAt)) {
    try {
        $lastImportedDisplay = (new DateTime((string)$lastImportedAt))->format('j M Y, g:i a');
    } catch (Throwable $e) {
        $lastImportedDisplay = (string)$lastImportedAt;
    }
}

$pageTitle = 'Library Import';
$pageBodyClass = 'dj-page';
include __DIR__ . '/layout.php';

function ensureDjLibraryStatsTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS dj_library_stats (
            dj_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            track_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_imported_at DATETIME NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'rekordbox_xml',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
?>
<style>
.library-import-wrap {
    max-width: 900px;
    margin: 0 auto;
}

.library-import-title {
    margin: 0 0 8px;
    font-size: 34px;
    line-height: 1.15;
}

.library-import-subtitle {
    margin: 0 0 22px;
    color: #b7b7c8;
    font-size: 15px;
}

.library-overview {
    display: grid;
    grid-template-columns: repeat(2, minmax(180px, 1fr));
    gap: 12px;
    margin: 0 0 14px;
}

.library-overview-card {
    border: 1px solid #2a2a3f;
    border-radius: 12px;
    background: #111116;
    padding: 12px 14px;
}

.library-overview-label {
    margin: 0 0 6px;
    color: #a8a8bd;
    font-size: 12px;
}

.library-overview-value {
    margin: 0;
    color: #fff;
    font-size: 22px;
    font-weight: 700;
}

.library-reimport-help {
    margin: 0 0 20px;
    border: 1px solid #2a2a3f;
    border-radius: 12px;
    background: #111116;
    padding: 12px 14px;
    color: #d8d8e5;
    font-size: 13px;
    line-height: 1.5;
}

.library-reimport-help ul {
    margin: 8px 0 0 18px;
    padding: 0;
}

.library-dropzone {
    border: 2px dashed rgba(var(--brand-accent-rgb), 0.55);
    border-radius: 14px;
    padding: 42px 24px;
    text-align: center;
    background: rgba(var(--brand-accent-rgb), 0.08);
    transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease;
    cursor: pointer;
}

.library-dropzone:hover,
.library-dropzone.is-hover {
    border-color: rgba(var(--brand-accent-rgb), 0.95);
    background: rgba(var(--brand-accent-rgb), 0.14);
    transform: translateY(-1px);
}

.library-dropzone-title {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
}

.library-dropzone-help {
    margin: 12px 0 0;
    color: #c7c7d7;
    font-size: 14px;
}

.library-upload-progress {
    margin-top: 18px;
    height: 10px;
    background: #1a1a25;
    border-radius: 999px;
    overflow: hidden;
    display: none;
}

.library-upload-progress-bar {
    width: 0;
    height: 100%;
    background: linear-gradient(90deg, var(--brand-accent) 0%, var(--brand-accent-strong) 100%);
    transition: width 0.15s ease;
}

.library-upload-progress-bar.is-indeterminate {
    width: 35% !important;
    animation: library-progress-indeterminate 1.05s ease-in-out infinite;
}

@keyframes library-progress-indeterminate {
    0% { transform: translateX(-115%); }
    100% { transform: translateX(285%); }
}

.library-status {
    margin-top: 14px;
    min-height: 24px;
    color: #ddd;
    font-weight: 600;
}

.library-status.is-error {
    color: #ff8686;
}

.library-actions {
    margin-top: 10px;
    display: none;
}

.library-run-btn {
    border: 1px solid rgba(var(--brand-accent-rgb), 0.55);
    border-radius: 10px;
    padding: 9px 14px;
    background: rgba(var(--brand-accent-rgb), 0.12);
    color: #fff;
    font-weight: 700;
    cursor: pointer;
}

.library-run-btn:hover:not(:disabled) {
    background: rgba(var(--brand-accent-rgb), 0.20);
}

.library-run-btn:disabled {
    opacity: 0.6;
    cursor: default;
}

.library-results {
    margin-top: 20px;
    padding: 16px;
    border: 1px solid #2a2a3f;
    border-radius: 12px;
    background: #111116;
    display: none;
}

.library-results h3 {
    margin: 0 0 12px;
    font-size: 18px;
}

.library-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(180px, 1fr));
    gap: 12px;
}

.library-stat {
    border: 1px solid #25253a;
    border-radius: 10px;
    padding: 12px;
    background: #0e0e16;
}

.library-stat-label {
    font-size: 12px;
    color: #a8a8bd;
    margin: 0 0 6px;
}

.library-stat-value {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: #fff;
}

@media (max-width: 640px) {
    .library-stats {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="library-import-wrap">
    <h1 class="library-import-title">Rekordbox XML Library Import</h1>
    <p class="library-import-subtitle">
        Upload your Rekordbox XML library to sync track metadata into your DJ library.
    </p>

    <div class="library-overview">
        <div class="library-overview-card">
            <p class="library-overview-label">Tracks in Library</p>
            <p class="library-overview-value"><?php echo (int)$libraryTrackCount; ?></p>
        </div>
        <div class="library-overview-card">
            <p class="library-overview-label">Last Import</p>
            <p class="library-overview-value" style="font-size:18px;"><?php echo e($lastImportedDisplay); ?></p>
            <p class="library-overview-label" style="margin-top:6px;">Source: <?php echo e($lastImportSource !== '' ? $lastImportSource : 'rekordbox_xml'); ?></p>
        </div>
    </div>

    <div class="library-reimport-help">
        Re-import workflow:
        <ul>
            <li>Export your latest Rekordbox library XML and upload it here.</li>
            <li>Imports are incremental and update existing tracks by normalized hash.</li>
            <li>Re-import any time after playlist/library edits to refresh metadata.</li>
        </ul>
    </div>

    <div id="libraryDropzone" class="library-dropzone" tabindex="0" role="button" aria-label="Upload Rekordbox XML">
        <p class="library-dropzone-title">Drag Rekordbox XML here or click to upload</p>
        <p class="library-dropzone-help">XML only • large files supported (up to 500MB)</p>
    </div>

    <input id="libraryFileInput" type="file" accept=".xml,text/xml,application/xml" style="display:none;">

    <div id="libraryUploadProgress" class="library-upload-progress">
        <div id="libraryUploadProgressBar" class="library-upload-progress-bar"></div>
    </div>

    <div id="libraryStatus" class="library-status" aria-live="polite"></div>
    <div id="libraryActions" class="library-actions">
        <button id="runQueuedImportBtn" type="button" class="library-run-btn">Process Queued Import Now</button>
    </div>

    <div id="libraryResults" class="library-results">
        <h3>Import Summary</h3>
        <div class="library-stats">
            <div class="library-stat">
                <p class="library-stat-label">Tracks Processed</p>
                <p id="statTracksProcessed" class="library-stat-value">0</p>
            </div>
            <div class="library-stat">
                <p class="library-stat-label">New Identities</p>
                <p id="statNewIdentities" class="library-stat-value">0</p>
            </div>
            <div class="library-stat">
                <p class="library-stat-label">Existing Identities</p>
                <p id="statExistingIdentities" class="library-stat-value">0</p>
            </div>
            <div class="library-stat">
                <p class="library-stat-label">DJ Tracks Added</p>
                <p id="statDjTracksAdded" class="library-stat-value">0</p>
            </div>
            <div class="library-stat">
                <p class="library-stat-label">DJ Tracks Updated</p>
                <p id="statDjTracksUpdated" class="library-stat-value">0</p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const CHUNK_SIZE = 8 * 1024 * 1024; // 8MB
    const dropzone = document.getElementById('libraryDropzone');
    const input = document.getElementById('libraryFileInput');
    const statusEl = document.getElementById('libraryStatus');
    const progressWrap = document.getElementById('libraryUploadProgress');
    const progressBar = document.getElementById('libraryUploadProgressBar');
    const results = document.getElementById('libraryResults');
    const actions = document.getElementById('libraryActions');
    const runQueuedBtn = document.getElementById('runQueuedImportBtn');
    let activeJobId = 0;

    const statTracks = document.getElementById('statTracksProcessed');
    const statNewIds = document.getElementById('statNewIdentities');
    const statExistingIds = document.getElementById('statExistingIdentities');
    const statTracksAdded = document.getElementById('statDjTracksAdded');
    const statTracksUpdated = document.getElementById('statDjTracksUpdated');

    function setStatus(text, isError) {
        statusEl.textContent = text || '';
        statusEl.classList.toggle('is-error', !!isError);
    }

    function resetProgress() {
        progressWrap.style.display = 'none';
        progressBar.classList.remove('is-indeterminate');
        progressBar.style.width = '0%';
        actions.style.display = 'none';
        runQueuedBtn.disabled = false;
        activeJobId = 0;
    }

    function showProgress() {
        progressWrap.style.display = 'block';
        progressBar.classList.remove('is-indeterminate');
        progressBar.style.width = '0%';
    }

    function isXmlFile(file) {
        if (!file) return false;
        const name = (file.name || '').toLowerCase();
        return name.endsWith('.xml');
    }

    function setResults(payload) {
        statTracks.textContent = String(payload.tracks_processed || 0);
        statNewIds.textContent = String(payload.new_identities || 0);
        statExistingIds.textContent = String(payload.existing_identities || 0);
        statTracksAdded.textContent = String(payload.dj_tracks_added || 0);
        statTracksUpdated.textContent = String(payload.dj_tracks_updated || 0);
        results.style.display = 'block';
    }

    function postForm(formData) {
        return fetch('/api/dj/import_rekordbox_xml.php', {
            method: 'POST',
            body: formData
        }).then(async function (resp) {
            const rawText = await resp.text();
            let payload = null;
            try {
                payload = JSON.parse(rawText || '{}');
            } catch (e) {
                payload = null;
            }
            if (!resp.ok || !payload || payload.error) {
                let msg = payload && payload.error ? payload.error : '';
                if (!msg) {
                    const snippet = (rawText || '').replace(/\s+/g, ' ').trim().slice(0, 140);
                    if (snippet) {
                        msg = 'HTTP ' + resp.status + ': ' + snippet;
                    } else {
                        msg = 'HTTP ' + resp.status + ' upload failed.';
                    }
                }
                throw new Error(msg);
            }
            return payload;
        });
    }

    function uploadChunk(uploadId, chunkBlob, chunkIndex, totalChunks) {
        return new Promise(function (resolve, reject) {
            const formData = new FormData();
            formData.append('action', 'chunk');
            formData.append('upload_id', uploadId);
            formData.append('chunk_index', String(chunkIndex));
            formData.append('total_chunks', String(totalChunks));
            formData.append('chunk', chunkBlob, 'chunk_' + chunkIndex + '.bin');

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/api/dj/import_rekordbox_xml.php', true);

            xhr.upload.addEventListener('progress', function (event) {
                if (!event.lengthComputable) {
                    progressBar.classList.add('is-indeterminate');
                    setStatus('Uploading chunk ' + (chunkIndex + 1) + '/' + totalChunks + '...', false);
                    return;
                }

                progressBar.classList.remove('is-indeterminate');
                const chunkRatio = Math.max(0, Math.min(1, event.loaded / event.total));
                const overallRatio = (chunkIndex + chunkRatio) / totalChunks;
                const pct = Math.max(0, Math.min(100, Math.round(overallRatio * 100)));
                progressBar.style.width = pct + '%';
                setStatus('Uploading... ' + pct + '%', false);
            });

            xhr.onload = function () {
                let payload = null;
                try {
                    payload = JSON.parse(xhr.responseText || '{}');
                } catch (e) {
                    payload = null;
                }
                if (xhr.status >= 200 && xhr.status < 300 && payload && !payload.error) {
                    resolve(payload);
                    return;
                }
                reject(new Error(payload && payload.error ? payload.error : 'Chunk upload failed.'));
            };

            xhr.onerror = function () {
                reject(new Error('Network error during chunk upload.'));
            };

            xhr.send(formData);
        });
    }

    async function pollImportJob(jobId) {
        const startedAt = Date.now();
        const maxWaitMs = 45 * 60 * 1000; // 45 minutes

        while (true) {
            if ((Date.now() - startedAt) > maxWaitMs) {
                throw new Error('Import is taking longer than expected. Check again in a few minutes.');
            }

            const resp = await fetch('/api/dj/import_rekordbox_xml_status.php?job_id=' + encodeURIComponent(jobId), {
                method: 'GET',
                cache: 'no-store'
            });

            const text = await resp.text();
            let payload = null;
            try {
                payload = JSON.parse(text || '{}');
            } catch (e) {
                payload = null;
            }

            if (!resp.ok || !payload) {
                const snippet = (text || '').replace(/\s+/g, ' ').trim().slice(0, 140);
                throw new Error(snippet ? ('Status check failed: ' + snippet) : ('Status check failed (HTTP ' + resp.status + ').'));
            }

            const status = String(payload.status || '');
            if (status === 'done') {
                progressBar.classList.remove('is-indeterminate');
                progressBar.style.width = '100%';
                setStatus('Import complete.', false);
                actions.style.display = 'none';
                setResults(payload);
                return;
            }

            if (status === 'failed') {
                const err = payload.error_message ? String(payload.error_message) : 'Import failed.';
                throw new Error(err);
            }

            if (status === 'processing') {
                actions.style.display = 'none';
                setStatus('Processing library... this can take several minutes for large XML files.', false);
            } else {
                actions.style.display = 'block';
                setStatus('Queued for processing...', false);
            }

            await new Promise(function (resolve) {
                setTimeout(resolve, 2500);
            });
        }
    }

    async function uploadFile(file) {
        if (!isXmlFile(file)) {
            setStatus('Please select a valid .xml file.', true);
            return;
        }

        const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
        results.style.display = 'none';
        showProgress();
        setStatus('Preparing upload...', false);

        try {
            const startFd = new FormData();
            startFd.append('action', 'start');
            startFd.append('file_name', file.name);
            startFd.append('file_size', String(file.size));
            startFd.append('total_chunks', String(totalChunks));

            const started = await postForm(startFd);
            const uploadId = started.upload_id;
            if (!uploadId) {
                throw new Error('Failed to initialize upload session.');
            }

            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end = Math.min(file.size, start + CHUNK_SIZE);
                const chunk = file.slice(start, end);
                await uploadChunk(uploadId, chunk, i, totalChunks);
            }

            progressBar.classList.add('is-indeterminate');
            setStatus('Processing library...', false);

            const finishFd = new FormData();
            finishFd.append('action', 'finish');
            finishFd.append('upload_id', uploadId);

            const payload = await postForm(finishFd);
            const jobId = Number(payload.job_id || 0);
            if (jobId > 0) {
                activeJobId = jobId;
                await pollImportJob(jobId);
            } else {
                progressBar.classList.remove('is-indeterminate');
                progressBar.style.width = '100%';
                setStatus('Import complete.', false);
                setResults(payload);
            }
        } catch (err) {
            const msg = err && err.message ? err.message : 'Upload failed. Please try again.';
            setStatus(msg, true);
            resetProgress();
        }
    }

    dropzone.addEventListener('click', function () {
        input.click();
    });

    dropzone.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            input.click();
        }
    });

    input.addEventListener('change', function () {
        const file = input.files && input.files[0] ? input.files[0] : null;
        if (file) uploadFile(file);
    });

    runQueuedBtn.addEventListener('click', async function () {
        if (!activeJobId) {
            return;
        }
        runQueuedBtn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('job_id', String(activeJobId));
            const payload = await fetch('/api/dj/import_rekordbox_xml_run.php', {
                method: 'POST',
                body: fd
            }).then(async function (resp) {
                const text = await resp.text();
                let json = null;
                try { json = JSON.parse(text || '{}'); } catch (e) { json = null; }
                if (!resp.ok || !json || json.error) {
                    throw new Error((json && json.error) ? json.error : ('HTTP ' + resp.status + ' manual trigger failed.'));
                }
                return json;
            });
            setStatus(payload.message || 'Manual processing started.', false);
        } catch (err) {
            setStatus(err && err.message ? err.message : 'Manual processing failed.', true);
        } finally {
            runQueuedBtn.disabled = false;
        }
    });

    ['dragenter', 'dragover'].forEach(function (evtName) {
        dropzone.addEventListener(evtName, function (event) {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.add('is-hover');
        });
    });

    ['dragleave', 'drop'].forEach(function (evtName) {
        dropzone.addEventListener(evtName, function (event) {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.remove('is-hover');
        });
    });

    dropzone.addEventListener('drop', function (event) {
        const dt = event.dataTransfer;
        const file = dt && dt.files && dt.files[0] ? dt.files[0] : null;
        if (file) uploadFile(file);
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
