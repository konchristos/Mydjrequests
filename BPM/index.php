<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    die('BPM import is not enabled for this account.');
}

$pageTitle = 'Playlist Imports';
$pageBodyClass = 'dj-page';
include APP_ROOT . '/dj/layout.php';
?>
<style>
.playlist-import-wrap { max-width: 960px; margin: 0 auto; }
.playlist-back-btn {
    display: inline-block;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid rgba(var(--brand-accent-rgb), 0.45);
    background: rgba(var(--brand-accent-rgb), 0.12);
    color: #fff;
    text-decoration: none;
    font-weight: 700;
}
.playlist-back-btn:hover {
    background: rgba(var(--brand-accent-rgb), 0.22);
    border-color: rgba(var(--brand-accent-rgb), 0.8);
}
.playlist-import-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 14px;
}
.playlist-card {
    display: block;
    background: #111116;
    border: 1px solid #23233a;
    border-radius: 12px;
    padding: 18px;
    text-decoration: none;
    color: #fff;
}
.playlist-card:hover {
    border-color: rgba(var(--brand-accent-rgb), 0.65);
    box-shadow: 0 0 0 1px rgba(var(--brand-accent-rgb), 0.28) inset;
}
.playlist-card.disabled {
    opacity: 0.6;
    pointer-events: none;
}
.playlist-card-title { font-size: 18px; font-weight: 700; margin: 0 0 6px; }
.playlist-card-sub { color: #b7b7c8; font-size: 14px; margin: 0; }
.playlist-badge {
    display: inline-block;
    margin-top: 10px;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: rgba(var(--brand-accent-rgb), 0.2);
    color: #fff;
}
.playlist-help {
    margin-top: 14px;
    color: #9ea3b8;
    font-size: 13px;
    border-top: 1px solid #23233a;
    padding-top: 12px;
}
</style>

<div class="playlist-import-wrap">
    <p style="margin:0 0 10px;"><a class="playlist-back-btn" href="/dj/dashboard.php">← Back to Dashboard</a></p>
    <h1 style="margin-top:0;">Playlist Imports</h1>
    <p style="color:#b7b7c8;">Select your DJ platform to import playlist metadata.</p>

    <div class="playlist-import-grid">
        <a class="playlist-card" href="/BPM/rekordbox.php">
            <div class="playlist-card-title"><i class="fa-solid fa-compact-disc"></i> Rekordbox</div>
            <p class="playlist-card-sub">Import from a Rekordbox playlist TXT export.</p>
            <span class="playlist-badge">Available</span>
        </a>

        <div class="playlist-card disabled">
            <div class="playlist-card-title"><i class="fa-solid fa-wave-square"></i> Serato</div>
            <p class="playlist-card-sub">Serato playlist import is in development.</p>
            <span class="playlist-badge">Coming Soon</span>
        </div>

        <div class="playlist-card disabled">
            <div class="playlist-card-title"><i class="fa-solid fa-sliders"></i> Virtual DJ</div>
            <p class="playlist-card-sub">Virtual DJ playlist import is in development.</p>
            <span class="playlist-badge">Coming Soon</span>
        </div>
    </div>

    <div class="playlist-help">
        More playlist import platforms will be added soon.
    </div>
</div>
