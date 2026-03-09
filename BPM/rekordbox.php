<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    die('BPM import is not enabled for this account.');
}

$pageTitle = 'Playlist Imports - Rekordbox';
$pageBodyClass = 'dj-page';
include APP_ROOT . '/dj/layout.php';
?>
<style>
.rekordbox-import-wrap { max-width: 760px; margin: 0 auto; }
.rekordbox-back-btn {
    display: inline-block;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid rgba(var(--brand-accent-rgb), 0.45);
    background: rgba(var(--brand-accent-rgb), 0.12);
    color: #fff;
    text-decoration: none;
    font-weight: 700;
}
.rekordbox-back-btn:hover {
    background: rgba(var(--brand-accent-rgb), 0.22);
    border-color: rgba(var(--brand-accent-rgb), 0.8);
}
.rekordbox-box { border: 1px solid #2a2a3f; border-radius: 12px; padding: 20px; background: #111116; }
.rekordbox-info { color: #b7b7c8; font-size: 14px; line-height: 1.5; }
.rekordbox-file { margin: 12px 0; color: #fff; }
.rekordbox-btn {
    display:inline-block;
    border: none;
    border-radius: 8px;
    padding: 10px 16px;
    font-weight: 700;
    background: var(--brand-accent);
    color: #fff;
    cursor: pointer;
}
.rekordbox-btn:hover {
    background: var(--brand-accent-strong);
}
</style>

<div class="rekordbox-import-wrap">
    <p style="margin:0 0 10px;"><a class="rekordbox-back-btn" href="/BPM/index.php">← Back to Platform Selection</a></p>
    <h1 style="margin-top:0;">Rekordbox Playlist Import</h1>

    <div class="rekordbox-box">
        <form method="post" action="upload.php" enctype="multipart/form-data">
            <label>
                <strong>Rekordbox Playlist TXT</strong>
            </label>

            <div class="rekordbox-file">
                <input type="file" name="xml" accept=".txt,text/plain" required>
            </div>

            <p class="rekordbox-info">
                • Export a <strong>playlist</strong> from Rekordbox as TXT (not full library)<br>
                • Recommended under <strong>10,000 tracks</strong><br>
                • Max file size: <strong>15 MB</strong>
            </p>

            <button type="submit" class="rekordbox-btn">Upload & Preview</button>
        </form>
    </div>
</div>
