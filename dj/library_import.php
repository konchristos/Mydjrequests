<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'Library Import';
$pageBodyClass = 'dj-page';
include __DIR__ . '/layout.php';
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

.library-status {
    margin-top: 14px;
    min-height: 24px;
    color: #ddd;
    font-weight: 600;
}

.library-status.is-error {
    color: #ff8686;
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

    <div id="libraryDropzone" class="library-dropzone" tabindex="0" role="button" aria-label="Upload Rekordbox XML">
        <p class="library-dropzone-title">Drag Rekordbox XML here or click to upload</p>
        <p class="library-dropzone-help">XML only • large files supported (up to 500MB)</p>
    </div>

    <input id="libraryFileInput" type="file" accept=".xml,text/xml,application/xml" style="display:none;">

    <div id="libraryUploadProgress" class="library-upload-progress">
        <div id="libraryUploadProgressBar" class="library-upload-progress-bar"></div>
    </div>

    <div id="libraryStatus" class="library-status" aria-live="polite"></div>

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
        </div>
    </div>
</div>

<script>
(function () {
    const dropzone = document.getElementById('libraryDropzone');
    const input = document.getElementById('libraryFileInput');
    const statusEl = document.getElementById('libraryStatus');
    const progressWrap = document.getElementById('libraryUploadProgress');
    const progressBar = document.getElementById('libraryUploadProgressBar');
    const results = document.getElementById('libraryResults');

    const statTracks = document.getElementById('statTracksProcessed');
    const statNewIds = document.getElementById('statNewIdentities');
    const statExistingIds = document.getElementById('statExistingIdentities');
    const statTracksAdded = document.getElementById('statDjTracksAdded');

    function setStatus(text, isError) {
        statusEl.textContent = text || '';
        statusEl.classList.toggle('is-error', !!isError);
    }

    function resetProgress() {
        progressWrap.style.display = 'none';
        progressBar.style.width = '0%';
    }

    function showProgress() {
        progressWrap.style.display = 'block';
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
        results.style.display = 'block';
    }

    function uploadFile(file) {
        if (!isXmlFile(file)) {
            setStatus('Please select a valid .xml file.', true);
            return;
        }

        results.style.display = 'none';
        showProgress();
        setStatus('Uploading...', false);

        const formData = new FormData();
        formData.append('library_xml', file);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/dj/import_rekordbox_xml.php', true);

        xhr.upload.addEventListener('progress', function (event) {
            if (!event.lengthComputable) return;
            const pct = Math.max(0, Math.min(100, Math.round((event.loaded / event.total) * 100)));
            progressBar.style.width = pct + '%';
            if (pct >= 100) {
                setStatus('Processing library...', false);
            } else {
                setStatus('Uploading... ' + pct + '%', false);
            }
        });

        xhr.onload = function () {
            progressBar.style.width = '100%';
            setStatus('Processing library...', false);

            let payload = null;
            try {
                payload = JSON.parse(xhr.responseText || '{}');
            } catch (e) {
                payload = null;
            }

            if (xhr.status >= 200 && xhr.status < 300 && payload && !payload.error) {
                setStatus('Import complete.', false);
                setResults(payload);
                return;
            }

            const msg = payload && payload.error ? payload.error : 'Upload failed. Please try again.';
            setStatus(msg, true);
            resetProgress();
        };

        xhr.onerror = function () {
            setStatus('Network error during upload.', true);
            resetProgress();
        };

        xhr.send(formData);
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
