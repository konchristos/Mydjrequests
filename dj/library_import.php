<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/helpers/dj_stale_matches.php';
require_dj_login();

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    exit('Premium feature only.');
}
$djId = (int)($_SESSION['dj_id'] ?? 0);
ensureDjLibraryStatsTable($db);
if (function_exists('djPlaylistPreferencesEnsureSchema')) {
    djPlaylistPreferencesEnsureSchema($db);
}

$preferredSaveSuccess = '';
$preferredSaveError = '';
$staleMatchRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_preferred_playlists') {
    if (!verify_csrf_token()) {
        $preferredSaveError = 'Invalid request token. Please refresh and try again.';
    } else {
        $selected = $_POST['preferred_playlist_ids'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }

        $selectedIds = [];
        foreach ($selected as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $selectedIds[$n] = true;
            }
        }
        $selectedIds = array_values(array_keys($selectedIds));

        try {
            $allowed = [];
            if (!empty($selectedIds)) {
                $in = implode(',', array_fill(0, count($selectedIds), '?'));
                $sql = "
                    SELECT p.id
                    FROM dj_playlists p
                    INNER JOIN (
                        SELECT playlist_id
                        FROM dj_playlist_tracks
                        GROUP BY playlist_id
                    ) t ON t.playlist_id = p.id
                    WHERE p.dj_id = ?
                      AND p.id IN ($in)
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute(array_merge([$djId], $selectedIds));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $n = (int)$id;
                    if ($n > 0) {
                        $allowed[$n] = true;
                    }
                }
            }

            $db->beginTransaction();
            $del = $db->prepare("DELETE FROM dj_preferred_playlists WHERE dj_id = ?");
            $del->execute([$djId]);

            if (!empty($allowed)) {
                $ins = $db->prepare("
                    INSERT INTO dj_preferred_playlists (dj_id, playlist_id, created_at)
                    VALUES (?, ?, NOW())
                ");
                foreach (array_keys($allowed) as $playlistId) {
                    $ins->execute([$djId, (int)$playlistId]);
                }
            }

            $db->commit();
            $preferredSaveSuccess = 'Preferred playlists updated.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $preferredSaveError = 'Failed to save preferred playlists.';
        }
    }
}

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
        mdjrEnsureDjTrackAvailabilityColumns($db);
        $countStmt = $db->prepare("SELECT COUNT(*) FROM dj_tracks WHERE dj_id = ? AND COALESCE(is_available, 1) = 1");
        $countStmt->execute([$djId]);
        $libraryTrackCount = max(0, (int)$countStmt->fetchColumn());
    }
} catch (Throwable $e) {
    $libraryTrackCount = 0;
    $lastImportedAt = null;
    $lastImportSource = 'rekordbox_xml';
}

try {
    $staleMatchRows = mdjrLoadStaleGlobalMatches($db, $djId);
} catch (Throwable $e) {
    $staleMatchRows = [];
}

$lastImportedDisplay = 'Never';
$lastImportedIsoUtc = '';
if (!empty($lastImportedAt)) {
    try {
        $dtUtc = new DateTime((string)$lastImportedAt, new DateTimeZone('UTC'));
        $lastImportedIsoUtc = $dtUtc->format(DateTime::ATOM);
        $lastImportedDisplay = $dtUtc->format('j M Y, g:i a');
    } catch (Throwable $e) {
        $lastImportedDisplay = (string)$lastImportedAt;
    }
}

$playlistRows = [];
$preferredSet = [];
try {
    $playlistStmt = $db->prepare("
        SELECT
            p.id,
            p.name,
            p.parent_playlist_id,
            COUNT(t.dj_track_id) AS track_count
        FROM dj_playlists p
        LEFT JOIN dj_playlist_tracks t
            ON t.playlist_id = p.id
        WHERE p.dj_id = ?
        GROUP BY p.id, p.name, p.parent_playlist_id
        HAVING track_count > 0
        ORDER BY p.name ASC
    ");
    $playlistStmt->execute([$djId]);
    $playlistRows = $playlistStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $preferredStmt = $db->prepare("
        SELECT playlist_id
        FROM dj_preferred_playlists
        WHERE dj_id = ?
    ");
    $preferredStmt->execute([$djId]);
    foreach ($preferredStmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $n = (int)$pid;
        if ($n > 0) {
            $preferredSet[$n] = true;
        }
    }
} catch (Throwable $e) {
    $playlistRows = [];
    $preferredSet = [];
}

$importHistoryRows = [];
try {
    $historyStmt = $db->prepare("
        SELECT
            id,
            status,
            stage,
            stage_message,
            upload_bytes,
            stored_bytes,
            tracks_processed,
            dj_tracks_added,
            dj_tracks_updated,
            error_message,
            created_at,
            started_at,
            finished_at,
            tracks_started_at,
            tracks_finished_at,
            playlists_started_at,
            playlists_finished_at,
            finalizing_started_at,
            finalizing_finished_at
        FROM dj_library_import_jobs
        WHERE dj_id = ?
        ORDER BY id DESC
        LIMIT 12
    ");
    $historyStmt->execute([$djId]);
    $importHistoryRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $importHistoryRows = [];
}

$activeImportJob = null;
foreach ($importHistoryRows as $row) {
    $rowStatus = trim((string)($row['status'] ?? ''));
    if ($rowStatus === 'queued' || $rowStatus === 'processing') {
        $activeImportJob = [
            'id' => (int)($row['id'] ?? 0),
            'status' => $rowStatus,
            'stage' => trim((string)($row['stage'] ?? '')),
            'stage_message' => trim((string)($row['stage_message'] ?? '')),
            'upload_bytes' => isset($row['upload_bytes']) ? (int)$row['upload_bytes'] : 0,
            'stored_bytes' => isset($row['stored_bytes']) ? (int)$row['stored_bytes'] : 0,
            'tracks_processed' => (int)($row['tracks_processed'] ?? 0),
            'new_identities' => 0,
            'existing_identities' => 0,
            'dj_tracks_added' => (int)($row['dj_tracks_added'] ?? 0),
            'dj_tracks_updated' => (int)($row['dj_tracks_updated'] ?? 0),
            'error_message' => trim((string)($row['error_message'] ?? '')),
            'started_at' => (string)($row['started_at'] ?? ''),
            'finished_at' => (string)($row['finished_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
        break;
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

function formatBytesHuman(?int $bytes): string
{
    $n = (int)$bytes;
    if ($n <= 0) {
        return '—';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float)$n;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : 2) . ' ' . $units[$i];
}

function formatElapsedRange(?string $start, ?string $end): string
{
    if (!$start) {
        return '—';
    }
    $startTs = parseUtcToTs($start);
    if ($startTs <= 0) {
        return '—';
    }
    $endTs = $end ? parseUtcToTs($end) : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
    if ($endTs <= 0) {
        $endTs = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
    }
    $seconds = max(0, $endTs - $startTs);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%dh %02dm %02ds', $h, $m, $s);
    }
    if ($m > 0) {
        return sprintf('%dm %02ds', $m, $s);
    }
    return sprintf('%ds', $s);
}

function stageElapsedRange(?string $start, ?string $end): string
{
    $start = trim((string)$start);
    if ($start === '') {
        return '';
    }
    return formatElapsedRange($start, $end);
}

function stageBreakdownDisplay(string $tracks, string $playlists, string $finalizing): string
{
    $parts = [];
    if ($tracks !== '') {
        $parts[] = 'Tracks ' . $tracks;
    }
    if ($playlists !== '') {
        $parts[] = 'Playlists ' . $playlists;
    }
    if ($finalizing !== '') {
        $parts[] = 'Finalize ' . $finalizing;
    }
    return implode(' • ', $parts);
}

function parseUtcToTs(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    try {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$dt) {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
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

.library-dropzone.is-disabled {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none;
}

.library-dropzone.is-disabled:hover {
    border-color: rgba(var(--brand-accent-rgb), 0.55);
    background: rgba(var(--brand-accent-rgb), 0.08);
    transform: none;
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

.library-status-meta {
    margin-top: 8px;
    color: #a8a8bd;
    font-size: 13px;
    min-height: 18px;
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

.library-history {
    margin-top: 22px;
    border: 1px solid #2a2a3f;
    border-radius: 12px;
    background: #111116;
    padding: 14px;
}

.library-history h3 {
    margin: 0 0 10px;
    font-size: 18px;
}

.library-history-table-wrap {
    overflow-x: auto;
}

.library-history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.library-history-table th,
.library-history-table td {
    border-bottom: 1px solid #25253a;
    padding: 8px 10px;
    text-align: left;
    vertical-align: top;
    white-space: nowrap;
}

.library-history-table th {
    color: #b7b7c8;
    font-weight: 700;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.library-history-table tr:last-child td {
    border-bottom: none;
}

.library-stage-pill {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid #2f2f4a;
    background: #171727;
    color: #e2e2f2;
    font-size: 11px;
    font-weight: 700;
}

@media (max-width: 640px) {
    .library-stats {
        grid-template-columns: 1fr;
    }
}

.playlist-pref-panel {
    margin: 0 0 20px;
    border: 1px solid #2a2a3f;
    border-radius: 12px;
    background: #111116;
    padding: 14px;
}

.playlist-pref-header {
    margin: 0 0 10px;
    font-size: 18px;
    font-weight: 700;
}

.playlist-pref-sub {
    margin: 0 0 14px;
    color: #b7b7c8;
    font-size: 13px;
}

.playlist-pref-list {
    max-height: 280px;
    overflow: auto;
    border: 1px solid #25253a;
    border-radius: 10px;
    background: #0e0e16;
}

.playlist-pref-tools {
    display: flex;
    gap: 8px;
    margin: 0 0 10px;
}

.playlist-pref-search {
    flex: 1 1 auto;
    min-width: 220px;
    border: 1px solid #2b2b42;
    background: #0b0b13;
    color: #fff;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 14px;
}

.playlist-pref-search-btn,
.playlist-pref-clear-btn {
    border: 1px solid #2b2b42;
    background: #161625;
    color: #fff;
    border-radius: 10px;
    padding: 10px 12px;
    font-weight: 700;
    cursor: pointer;
}

.playlist-pref-search-btn:hover,
.playlist-pref-clear-btn:hover {
    background: #1f1f31;
}

.playlist-pref-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 12px;
    border-bottom: 1px solid #212133;
}

.playlist-pref-item:last-child {
    border-bottom: none;
}

.playlist-pref-name {
    margin: 0;
    font-weight: 600;
}

.playlist-pref-meta {
    margin: 4px 0 0;
    font-size: 12px;
    color: #9f9fb5;
}

.playlist-pref-actions {
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.playlist-pref-selected {
    margin: 0 0 12px;
    border: 1px solid #25253a;
    border-radius: 10px;
    background: #0e0e16;
    padding: 10px 12px;
}

.playlist-pref-selected-title {
    margin: 0 0 8px;
    color: #cfcfe3;
    font-size: 13px;
    font-weight: 700;
}

.playlist-pref-selected-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.playlist-pref-chip {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    border: 1px solid rgba(var(--brand-accent-rgb), 0.45);
    background: rgba(var(--brand-accent-rgb), 0.14);
    padding: 3px 9px;
    font-size: 12px;
    color: #f6e5ff;
}

.playlist-pref-empty {
    margin: 0;
    color: #9f9fb5;
    font-size: 12px;
}

.playlist-pref-save {
    border: none;
    border-radius: 10px;
    padding: 10px 16px;
    font-weight: 700;
    color: #fff;
    background: linear-gradient(90deg, var(--brand-accent) 0%, var(--brand-accent-strong) 100%);
    cursor: pointer;
}

.playlist-pref-msg {
    font-size: 13px;
    color: #9fe8b2;
}

.playlist-pref-msg.is-error {
    color: #ff9b9b;
}

.library-secondary-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 14px;
    margin: 0 0 20px;
}

.library-stale-panel {
    border: 1px solid #2a2a3f;
    border-radius: 12px;
    background: #111116;
    padding: 14px;
}

.library-stale-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
}

.library-stale-title {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}

.library-stale-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-radius: 999px;
    padding: 4px 10px;
    border: 1px solid rgba(255, 111, 111, 0.35);
    background: rgba(255, 111, 111, 0.08);
    color: #ffb2b2;
    font-size: 12px;
    font-weight: 700;
}

.library-stale-sub {
    margin: 0;
    color: #b7b7c8;
    font-size: 13px;
}

.library-stale-actions {
    margin-top: 12px;
}

.library-live-note {
    margin: 10px 0 0;
    color: #9e9eb3;
    font-size: 12px;
}

.library-stale-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid rgba(var(--brand-accent-rgb), 0.45);
    background: rgba(var(--brand-accent-rgb), 0.12);
    color: #fff;
    text-decoration: none;
    border-radius: 10px;
    padding: 10px 14px;
    font-weight: 700;
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
            <p id="libraryTrackCountValue" class="library-overview-value"><?php echo (int)$libraryTrackCount; ?></p>
        </div>
        <div class="library-overview-card">
            <p class="library-overview-label">Last Import</p>
            <p
                id="lastImportValue"
                class="library-overview-value js-local-datetime"
                data-utc="<?php echo e($lastImportedIsoUtc); ?>"
                style="font-size:18px;"
            ><?php echo e($lastImportedDisplay); ?></p>
            <p id="lastImportSourceValue" class="library-overview-label" style="margin-top:6px;">Source: <?php echo e($lastImportSource !== '' ? $lastImportSource : 'rekordbox_xml'); ?></p>
        </div>
    </div>

    <div class="library-reimport-help">
        Re-import workflow:
        <ul>
            <li>Export your latest Rekordbox library and upload it here.</li>
            <li>For large libraries, zip the XML first to make the upload much faster.</li>
            <li>If you move tracks to a new folder or drive, importing again will refresh their saved file paths.</li>
            <li>If tracks are missing from your latest export, they will be treated as unavailable until they appear again in a later import.</li>
            <li>Re-import any time after library changes to keep your tracks, playlists, and availability up to date.</li>
        </ul>
    </div>

    <div class="library-secondary-grid">
        <div class="library-stale-panel">
            <div class="library-stale-head">
                <h2 class="library-stale-title">Stale Matched Tracks</h2>
                <span id="libraryStaleCountValue" class="library-stale-count"><?php echo count($staleMatchRows); ?> pending</span>
            </div>
            <p class="library-stale-sub">
                Review saved manual matches whose previously linked local file is no longer available in the latest import.
            </p>
            <div class="library-stale-actions">
                <a class="library-stale-link" href="<?php echo e(url('dj/stale_matches.php')); ?>">Review stale matches</a>
            </div>
            <p id="libraryOverviewRefreshNote" class="library-live-note">Live data. Waiting for next refresh…</p>
        </div>
    </div>

    <div class="playlist-pref-panel">
        <h2 class="playlist-pref-header">Preferred Playlists</h2>
        <p class="playlist-pref-sub">
            Select exact playlists to treat as preferred. No folder inheritance is applied.
            A track is preferred if it belongs to any selected playlist.
        </p>

        <?php if (empty($playlistRows)): ?>
            <p class="playlist-pref-sub" style="margin:0;">No imported playlists found yet. Import Rekordbox XML first.</p>
        <?php else: ?>
            <?php
            $playlistMap = [];
            foreach ($playlistRows as $row) {
                $playlistMap[(int)$row['id']] = $row;
            }
            $breadcrumbFor = static function (array $row, array $map): string {
                $parts = [];
                $guard = 0;
                $parentId = (int)($row['parent_playlist_id'] ?? 0);
                while ($parentId > 0 && isset($map[$parentId]) && $guard < 20) {
                    $parent = $map[$parentId];
                    array_unshift($parts, (string)($parent['name'] ?? ''));
                    $parentId = (int)($parent['parent_playlist_id'] ?? 0);
                    $guard++;
                }
                return implode(' / ', array_filter($parts));
            };
            ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_preferred_playlists">
                <div class="playlist-pref-selected" id="preferredSelectedPanel">
                    <p class="playlist-pref-selected-title">Selected preferred playlists: <span id="preferredSelectedCount">0</span></p>
                    <div class="playlist-pref-selected-list" id="preferredSelectedList"></div>
                    <p class="playlist-pref-empty" id="preferredSelectedEmpty">No playlists selected.</p>
                </div>
                <div class="playlist-pref-tools">
                    <input
                        id="preferredPlaylistSearch"
                        class="playlist-pref-search"
                        type="text"
                        placeholder="Search playlists..."
                        autocomplete="off"
                    >
                    <button id="preferredPlaylistSearchBtn" class="playlist-pref-search-btn" type="button">Search</button>
                    <button id="preferredPlaylistClearBtn" class="playlist-pref-clear-btn" type="button">Clear</button>
                </div>
                <div class="playlist-pref-list">
                    <?php foreach ($playlistRows as $row): ?>
                        <?php
                        $pid = (int)($row['id'] ?? 0);
                        $name = (string)($row['name'] ?? 'Untitled');
                        $trackCount = (int)($row['track_count'] ?? 0);
                        $crumb = $breadcrumbFor($row, $playlistMap);
                        $checked = isset($preferredSet[$pid]);
                        ?>
                        <label
                            class="playlist-pref-item"
                            data-playlist-name="<?php echo e(mb_strtolower($name, 'UTF-8')); ?>"
                            data-playlist-crumb="<?php echo e(mb_strtolower($crumb, 'UTF-8')); ?>"
                        >
                            <input
                                type="checkbox"
                                name="preferred_playlist_ids[]"
                                value="<?php echo $pid; ?>"
                                <?php echo $checked ? 'checked' : ''; ?>
                            >
                            <div>
                                <p class="playlist-pref-name"><?php echo e($name); ?></p>
                                <p class="playlist-pref-meta">
                                    <?php if ($crumb !== ''): ?>
                                        <?php echo e($crumb); ?> •
                                    <?php endif; ?>
                                    <?php echo (int)$trackCount; ?> tracks
                                </p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="playlist-pref-actions">
                    <button type="submit" class="playlist-pref-save">Save Preferred Playlists</button>
                    <?php if ($preferredSaveSuccess !== ''): ?>
                        <span class="playlist-pref-msg"><?php echo e($preferredSaveSuccess); ?></span>
                    <?php elseif ($preferredSaveError !== ''): ?>
                        <span class="playlist-pref-msg is-error"><?php echo e($preferredSaveError); ?></span>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div id="libraryDropzone" class="library-dropzone" tabindex="0" role="button" aria-label="Upload Rekordbox XML">
        <p class="library-dropzone-title">Drag Rekordbox XML or ZIP here or click to upload</p>
        <p class="library-dropzone-help">XML or ZIP • large files supported (up to 500MB upload)</p>
    </div>

    <input id="libraryFileInput" type="file" accept=".xml,.zip,text/xml,application/xml,application/zip" style="display:none;">

    <div id="libraryUploadProgress" class="library-upload-progress">
        <div id="libraryUploadProgressBar" class="library-upload-progress-bar"></div>
    </div>

    <div id="libraryStatus" class="library-status" aria-live="polite"></div>
    <div id="libraryStatusMeta" class="library-status-meta" aria-live="polite"></div>
    <div id="libraryActions" class="library-actions">
        <button id="runQueuedImportBtn" type="button" class="library-run-btn">Process Queued Import Now</button>
        <button id="clearUploadStateBtn" type="button" class="library-run-btn" style="display:none;">Reset Upload State</button>
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

    <div class="library-history">
        <h3>Recent Import History</h3>
        <p id="libraryHistoryEmpty" class="library-overview-label" style="margin:0;<?php echo empty($importHistoryRows) ? '' : 'display:none;'; ?>">No imports yet.</p>
        <div id="libraryHistoryTableWrap" class="library-history-table-wrap" style="<?php echo empty($importHistoryRows) ? 'display:none;' : ''; ?>">
                <table class="library-history-table" id="libraryHistoryTable">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th>Stage</th>
                            <th>Elapsed</th>
                            <th>Upload</th>
                            <th>Stored</th>
                            <th>Tracks</th>
                            <th>Added</th>
                            <th>Updated</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody id="libraryHistoryBody">
                        <?php foreach ($importHistoryRows as $row): ?>
                            <?php
                            $created = (string)($row['created_at'] ?? '');
                            $createdIsoUtc = '';
                            if ($created !== '') {
                                try {
                                    $createdIsoUtc = (new DateTime($created, new DateTimeZone('UTC')))->format(DateTime::ATOM);
                                } catch (Throwable $e) {
                                    $createdIsoUtc = '';
                                }
                            }
                            $createdDisplay = $created !== '' ? date('d M Y, H:i:s', strtotime($created)) : '—';
                            $status = (string)($row['status'] ?? 'queued');
                            $stage = trim((string)($row['stage'] ?? ''));
                            $stageMessage = trim((string)($row['stage_message'] ?? ''));
                            $elapsed = formatElapsedRange((string)($row['started_at'] ?? ''), (string)($row['finished_at'] ?? ''));
                            $tracksSeconds = stageElapsedRange((string)($row['tracks_started_at'] ?? ''), (string)($row['tracks_finished_at'] ?? ''));
                            $playlistsSeconds = stageElapsedRange((string)($row['playlists_started_at'] ?? ''), (string)($row['playlists_finished_at'] ?? ''));
                            $finalizingSeconds = stageElapsedRange((string)($row['finalizing_started_at'] ?? ''), (string)($row['finalizing_finished_at'] ?? ''));
                            ?>
                            <tr data-job-id="<?php echo (int)($row['id'] ?? 0); ?>">
                                <td>#<?php echo (int)($row['id'] ?? 0); ?></td>
                                <td class="js-local-datetime" data-utc="<?php echo e($createdIsoUtc); ?>"><?php echo e($createdDisplay); ?></td>
                                <td><?php echo e($status); ?></td>
                                <td>
                                    <span class="library-stage-pill"><?php echo e($stage !== '' ? $stage : 'queued'); ?></span>
                                    <?php if ($stageMessage !== ''): ?>
                                        <div class="library-overview-label" style="margin-top:4px;"><?php echo e($stageMessage); ?></div>
                                    <?php endif; ?>
                                    <?php if ($tracksSeconds !== '' || $playlistsSeconds !== '' || $finalizingSeconds !== ''): ?>
                                        <div class="library-overview-label" style="margin-top:4px;">
                                            <?php echo e(stageBreakdownDisplay($tracksSeconds, $playlistsSeconds, $finalizingSeconds)); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($elapsed); ?></td>
                                <td><?php echo e(formatBytesHuman(isset($row['upload_bytes']) ? (int)$row['upload_bytes'] : null)); ?></td>
                                <td><?php echo e(formatBytesHuman(isset($row['stored_bytes']) ? (int)$row['stored_bytes'] : null)); ?></td>
                                <td><?php echo (int)($row['tracks_processed'] ?? 0); ?></td>
                                <td><?php echo (int)($row['dj_tracks_added'] ?? 0); ?></td>
                                <td><?php echo (int)($row['dj_tracks_updated'] ?? 0); ?></td>
                                <td>
                                    <?php
                                    $err = trim((string)($row['error_message'] ?? ''));
                                    echo e($err !== '' ? mb_strimwidth($err, 0, 80, '…', 'UTF-8') : '—');
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    </div>
</div>

<script>
(function () {
    const CHUNK_SIZE = 8 * 1024 * 1024; // 8MB
    const initialActiveJob = <?php echo json_encode($activeImportJob, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const dropzone = document.getElementById('libraryDropzone');
    const input = document.getElementById('libraryFileInput');
    const statusEl = document.getElementById('libraryStatus');
    const statusMetaEl = document.getElementById('libraryStatusMeta');
    const historyBody = document.getElementById('libraryHistoryBody');
    const historyEmpty = document.getElementById('libraryHistoryEmpty');
    const historyWrap = document.getElementById('libraryHistoryTableWrap');
    const libraryTrackCountValue = document.getElementById('libraryTrackCountValue');
    const lastImportValue = document.getElementById('lastImportValue');
    const lastImportSourceValue = document.getElementById('lastImportSourceValue');
    const libraryStaleCountValue = document.getElementById('libraryStaleCountValue');
    const libraryOverviewRefreshNote = document.getElementById('libraryOverviewRefreshNote');
    const progressWrap = document.getElementById('libraryUploadProgress');
    const progressBar = document.getElementById('libraryUploadProgressBar');
    const results = document.getElementById('libraryResults');
    const actions = document.getElementById('libraryActions');
    const runQueuedBtn = document.getElementById('runQueuedImportBtn');
    const clearUploadStateBtn = document.getElementById('clearUploadStateBtn');
    const pendingStateKey = 'mdjrImportPendingState:<?php echo (int)$djId; ?>';
    let activeJobId = 0;
    let uploadLocked = false;

    const statTracks = document.getElementById('statTracksProcessed');
    const statNewIds = document.getElementById('statNewIdentities');
    const statExistingIds = document.getElementById('statExistingIdentities');
    const statTracksAdded = document.getElementById('statDjTracksAdded');
    const statTracksUpdated = document.getElementById('statDjTracksUpdated');

    function readPendingState() {
        try {
            const raw = window.localStorage.getItem(pendingStateKey);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function writePendingState(patch) {
        const current = readPendingState() || {};
        const next = Object.assign({}, current, patch, { updated_at: Date.now() });
        try {
            window.localStorage.setItem(pendingStateKey, JSON.stringify(next));
        } catch (e) {
            // ignore storage failures
        }
        return next;
    }

    function clearPendingState() {
        try {
            window.localStorage.removeItem(pendingStateKey);
        } catch (e) {
            // ignore storage failures
        }
    }

    function setActionMode(mode) {
        if (!actions) return;
        if (runQueuedBtn) {
            runQueuedBtn.style.display = mode === 'queued' ? 'inline-flex' : 'none';
        }
        if (clearUploadStateBtn) {
            clearUploadStateBtn.style.display = mode === 'pending' ? 'inline-flex' : 'none';
        }
        actions.style.display = mode === 'none' ? 'none' : 'block';
    }

    function setStatus(text, isError) {
        statusEl.textContent = text || '';
        statusEl.classList.toggle('is-error', !!isError);
    }

    function setStatusMeta(text) {
        if (!statusMetaEl) return;
        statusMetaEl.textContent = text || '';
    }

    function formatBytes(bytes) {
        const n = Number(bytes || 0);
        if (!n || n <= 0) return '—';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let v = n;
        let i = 0;
        while (v >= 1024 && i < units.length - 1) {
            v /= 1024;
            i++;
        }
        return (i === 0 ? String(Math.round(v)) : v.toFixed(2)) + ' ' + units[i];
    }

    function formatElapsed(seconds) {
        const n = Math.max(0, Number(seconds || 0));
        const h = Math.floor(n / 3600);
        const m = Math.floor((n % 3600) / 60);
        const s = Math.floor(n % 60);
        if (h > 0) return h + 'h ' + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 's';
        if (m > 0) return m + 'm ' + String(s).padStart(2, '0') + 's';
        return s + 's';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char] || char;
        });
    }

    function formatStageBreakdown(item) {
        const parts = [];
        if (item && item.tracks_seconds != null) parts.push('Tracks ' + formatElapsed(item.tracks_seconds));
        if (item && item.playlists_seconds != null) parts.push('Playlists ' + formatElapsed(item.playlists_seconds));
        if (item && item.finalizing_seconds != null) parts.push('Finalize ' + formatElapsed(item.finalizing_seconds));
        return parts.join(' • ');
    }

    function renderHistoryRow(item) {
        const breakdown = formatStageBreakdown(item);
        const stageMessageHtml = item.stage_message ? ('<div class="library-overview-label" style="margin-top:4px;">' + escapeHtml(item.stage_message) + '</div>') : '';
        const breakdownHtml = breakdown ? ('<div class="library-overview-label" style="margin-top:4px;">' + escapeHtml(breakdown) + '</div>') : '';
        return [
            '<tr data-job-id="' + Number(item.id || 0) + '">',
                '<td>#' + Number(item.id || 0) + '</td>',
                '<td class="js-local-datetime" data-utc="' + escapeHtml(item.created_at_iso || '') + '">' + escapeHtml(item.created_at_display || '—') + '</td>',
                '<td>' + escapeHtml(item.status || 'queued') + '</td>',
                '<td>',
                    '<span class="library-stage-pill">' + escapeHtml(item.stage || 'queued') + '</span>',
                    stageMessageHtml,
                    breakdownHtml,
                '</td>',
                '<td>' + escapeHtml(item.elapsed_display || '0s') + '</td>',
                '<td>' + escapeHtml(item.upload_display || '—') + '</td>',
                '<td>' + escapeHtml(item.stored_display || '—') + '</td>',
                '<td>' + Number(item.tracks_processed || 0) + '</td>',
                '<td>' + Number(item.dj_tracks_added || 0) + '</td>',
                '<td>' + Number(item.dj_tracks_updated || 0) + '</td>',
                '<td>' + escapeHtml(item.error_display || '—') + '</td>',
            '</tr>'
        ].join('');
    }

    function updateOverview(payload) {
        if (!payload || typeof payload !== 'object') return;
        if (libraryTrackCountValue) {
            libraryTrackCountValue.textContent = String(Number(payload.track_count || 0));
        }
        if (lastImportValue) {
            const isoUtc = String(payload.last_imported_iso_utc || '').trim();
            lastImportValue.setAttribute('data-utc', isoUtc);
            lastImportValue.textContent = payload.last_imported_display ? String(payload.last_imported_display) : 'Never';
        }
        if (lastImportSourceValue) {
            const source = String(payload.last_import_source || 'rekordbox_xml').trim() || 'rekordbox_xml';
            lastImportSourceValue.textContent = 'Source: ' + source;
        }
        if (libraryStaleCountValue) {
            const staleCount = Math.max(0, Number(payload.stale_count || 0));
            libraryStaleCountValue.textContent = staleCount + ' pending';
        }
        if (libraryOverviewRefreshNote) {
            try {
                libraryOverviewRefreshNote.textContent = 'Live data. Refreshed ' + new Date().toLocaleTimeString(undefined, {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            } catch (e) {
                libraryOverviewRefreshNote.textContent = 'Live data. Refreshed just now.';
            }
        }
        renderLocalDatetimes();
    }

    async function refreshLibraryOverview() {
        const resp = await fetch('/api/dj/library_import_overview.php', {
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
        if (!resp.ok || !payload || payload.error) {
            throw new Error(payload && payload.error ? payload.error : 'Failed to refresh library overview.');
        }
        updateOverview(payload);
        return payload;
    }

    async function refreshHistory(jobIdToPin) {
        if (!historyBody || !historyWrap || !historyEmpty) return;
        const resp = await fetch('/api/dj/import_rekordbox_xml_history.php', {
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
        if (!resp.ok || !payload || !Array.isArray(payload.items)) {
            throw new Error('Failed to refresh import history.');
        }
        const items = payload.items.slice();
        if (jobIdToPin) {
            items.sort(function (a, b) {
                if (Number(a.id || 0) === Number(jobIdToPin)) return -1;
                if (Number(b.id || 0) === Number(jobIdToPin)) return 1;
                return Number(b.id || 0) - Number(a.id || 0);
            });
        }
        if (!items.length) {
            historyWrap.style.display = 'none';
            historyEmpty.style.display = '';
            historyBody.innerHTML = '';
            return;
        }
        historyWrap.style.display = '';
        historyEmpty.style.display = 'none';
        historyBody.innerHTML = items.map(renderHistoryRow).join('');
        renderLocalDatetimes();
    }

    async function getServerActiveJob() {
        const resp = await fetch('/api/dj/import_rekordbox_xml_history.php', {
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
        if (!resp.ok || !payload || !Array.isArray(payload.items)) {
            throw new Error('Unable to confirm current import state.');
        }
        return payload.items.find(function (item) {
            return isImportActiveStatus(String(item.status || ''));
        }) || null;
    }

    function resetProgress() {
        progressWrap.style.display = 'none';
        progressBar.classList.remove('is-indeterminate');
        progressBar.style.width = '0%';
        setActionMode('none');
        setStatusMeta('');
        runQueuedBtn.disabled = false;
        activeJobId = 0;
        uploadLocked = false;
        clearPendingState();
        dropzone.classList.remove('is-disabled');
        dropzone.setAttribute('tabindex', '0');
        input.disabled = false;
    }

    function showProgress() {
        progressWrap.style.display = 'block';
        progressBar.classList.remove('is-indeterminate');
        progressBar.style.width = '0%';
        uploadLocked = true;
        dropzone.classList.add('is-disabled');
        dropzone.setAttribute('tabindex', '-1');
        input.disabled = true;
    }

    function isImportActiveStatus(status) {
        return status === 'queued' || status === 'processing';
    }

    function renderActiveJobState(payload) {
        const status = String(payload.status || '');
        const stage = String(payload.stage || '');
        const stageMessage = String(payload.stage_message || '');
        const elapsed = formatElapsed(payload.elapsed_seconds || 0);
        const uploadSize = formatBytes(payload.upload_bytes || 0);
        const storedSize = formatBytes(payload.stored_bytes || 0);
        const metaParts = [];
        if (stage) metaParts.push('Stage: ' + stage.replace(/_/g, ' '));
        if (stageMessage) metaParts.push(stageMessage);
        metaParts.push('Elapsed: ' + elapsed);
        metaParts.push('Upload: ' + uploadSize);
        metaParts.push('Stored: ' + storedSize);
        setStatusMeta(metaParts.join(' • '));
        showProgress();
        progressBar.classList.add('is-indeterminate');
        clearPendingState();
        if (status === 'processing') {
            setActionMode('none');
            setStatus(stageMessage || 'Processing library... this can take several minutes for large XML files.', false);
        } else {
            setActionMode('queued');
            setStatus(stageMessage || 'Queued for processing...', false);
        }
        refreshHistory(payload.job_id || activeJobId).catch(function () {});
        refreshLibraryOverview().catch(function () {});
    }

    function isLibraryFile(file) {
        if (!file) return false;
        const name = (file.name || '').toLowerCase();
        return name.endsWith('.xml') || name.endsWith('.zip');
    }

    function setResults(payload) {
        statTracks.textContent = String(payload.tracks_processed || 0);
        statNewIds.textContent = String(payload.new_identities || 0);
        statExistingIds.textContent = String(payload.existing_identities || 0);
        statTracksAdded.textContent = String(payload.dj_tracks_added || 0);
        statTracksUpdated.textContent = String(payload.dj_tracks_updated || 0);
        results.style.display = 'block';
    }

    function renderLocalDatetimes() {
        const nodes = Array.from(document.querySelectorAll('.js-local-datetime[data-utc]'));
        if (!nodes.length) return;
        nodes.forEach(function (node) {
            const raw = String(node.getAttribute('data-utc') || '').trim();
            if (!raw) return;
            const dt = new Date(raw);
            if (Number.isNaN(dt.getTime())) return;
            try {
                node.textContent = dt.toLocaleString(undefined, {
                    year: 'numeric',
                    month: 'short',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                });
            } catch (e) {
                // Keep server-rendered fallback text.
            }
        });
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
                    writePendingState({ phase: 'uploading', message: 'Uploading chunk ' + (chunkIndex + 1) + '/' + totalChunks + '...' });
                    setStatus('Uploading chunk ' + (chunkIndex + 1) + '/' + totalChunks + '...', false);
                    return;
                }

                progressBar.classList.remove('is-indeterminate');
                const chunkRatio = Math.max(0, Math.min(1, event.loaded / event.total));
                const overallRatio = (chunkIndex + chunkRatio) / totalChunks;
                const pct = Math.max(0, Math.min(100, Math.round(overallRatio * 100)));
                progressBar.style.width = pct + '%';
                writePendingState({ phase: 'uploading', progress_pct: pct, message: 'Uploading... ' + pct + '%' });
                setStatus('Uploading... ' + pct + '%', false);
                setStatusMeta('');
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
            renderActiveJobState(payload);
            refreshLibraryOverview().catch(function () {});
            if (status === 'done') {
                progressBar.classList.remove('is-indeterminate');
                progressBar.style.width = '100%';
                setStatus('Import complete.', false);
                actions.style.display = 'none';
                setResults(payload);
                dropzone.classList.remove('is-disabled');
                dropzone.setAttribute('tabindex', '0');
                input.disabled = false;
                refreshLibraryOverview().catch(function () {});
                return;
            }

            if (status === 'failed') {
                const err = payload.error_message ? String(payload.error_message) : 'Import failed.';
                throw new Error(err);
            }

            await new Promise(function (resolve) {
                setTimeout(resolve, 2500);
            });
        }
    }

    async function uploadFile(file) {
        if (activeJobId > 0 || uploadLocked) {
            if (activeJobId <= 0 && uploadLocked) {
                try {
                    const liveJob = await getServerActiveJob();
                    if (!liveJob) {
                        resetProgress();
                    } else {
                        activeJobId = Number(liveJob.id || 0);
                        renderActiveJobState(liveJob);
                    }
                } catch (err) {
                    const msg = err && err.message ? err.message : 'Unable to confirm current import state.';
                    setStatus(msg, true);
                    return;
                }
            }
            if (activeJobId > 0 || uploadLocked) {
                setStatus('An import is already in progress. Please wait for it to finish.', true);
                return;
            }
        }
        if (!isLibraryFile(file)) {
            setStatus('Please select a valid .xml or .zip file.', true);
            return;
        }

        const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
        results.style.display = 'none';
        showProgress();
        setActionMode('pending');
        writePendingState({
            phase: 'starting',
            file_name: file.name,
            file_size: file.size,
            message: 'Preparing upload...'
        });
        setStatus('Preparing upload...', false);
        setStatusMeta('');

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
            writePendingState({
                phase: 'uploading',
                upload_id: uploadId,
                total_chunks: totalChunks,
                message: 'Uploading file...'
            });

            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end = Math.min(file.size, start + CHUNK_SIZE);
                const chunk = file.slice(start, end);
                await uploadChunk(uploadId, chunk, i, totalChunks);
            }

            progressBar.classList.add('is-indeterminate');
            setActionMode('pending');
            writePendingState({
                phase: 'validating',
                upload_id: uploadId,
                message: 'Validating upload and creating import job...'
            });
            setStatus('Validating upload and creating import job...', false);
            setStatusMeta('This step happens before the import job appears in history.');

            const finishFd = new FormData();
            finishFd.append('action', 'finish');
            finishFd.append('upload_id', uploadId);

            const payload = await postForm(finishFd);
            const jobId = Number(payload.job_id || 0);
            if (jobId > 0) {
                activeJobId = jobId;
                writePendingState({
                    phase: 'queued',
                    job_id: jobId,
                    message: 'Queued for processing...'
                });
                await refreshHistory(jobId);
                await pollImportJob(jobId);
            } else {
                progressBar.classList.remove('is-indeterminate');
                progressBar.style.width = '100%';
                clearPendingState();
                setStatus('Import complete.', false);
                setStatusMeta('');
                setResults(payload);
                await refreshHistory();
                await refreshLibraryOverview();
            }
        } catch (err) {
            const msg = err && err.message ? err.message : 'Upload failed. Please try again.';
            setStatus(msg, true);
            resetProgress();
        }
    }

    dropzone.addEventListener('click', function () {
        if (input.disabled) return;
        input.click();
    });

    dropzone.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (input.disabled) return;
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

    if (clearUploadStateBtn) {
        clearUploadStateBtn.addEventListener('click', function () {
            resetProgress();
            setStatus('Upload state cleared. You can start a new import.', false);
        });
    }

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
        if (input.disabled) {
            setStatus('An import is already in progress. Please wait for it to finish.', true);
            return;
        }
        const dt = event.dataTransfer;
        const file = dt && dt.files && dt.files[0] ? dt.files[0] : null;
        if (file) uploadFile(file);
    });

    renderLocalDatetimes();
    refreshHistory().catch(function () {});
    refreshLibraryOverview().catch(function () {});
    window.setInterval(function () {
        refreshLibraryOverview().catch(function () {});
    }, 10000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshLibraryOverview().catch(function () {});
            refreshHistory(activeJobId || 0).catch(function () {});
        }
    });
    window.addEventListener('focus', function () {
        refreshLibraryOverview().catch(function () {});
        refreshHistory(activeJobId || 0).catch(function () {});
    });
    window.addEventListener('pageshow', function () {
        refreshLibraryOverview().catch(function () {});
        refreshHistory(activeJobId || 0).catch(function () {});
    });
    if (initialActiveJob && Number(initialActiveJob.id || 0) > 0 && isImportActiveStatus(String(initialActiveJob.status || ''))) {
        activeJobId = Number(initialActiveJob.id || 0);
        renderActiveJobState(initialActiveJob);
        pollImportJob(activeJobId).catch(function (err) {
            const msg = err && err.message ? err.message : 'Import status check failed.';
            setStatus(msg, true);
        });
    } else {
        const pendingState = readPendingState();
        const pendingAgeMs = pendingState && pendingState.updated_at ? (Date.now() - Number(pendingState.updated_at || 0)) : Number.POSITIVE_INFINITY;
        if (pendingState && pendingAgeMs >= 0 && pendingAgeMs <= (30 * 60 * 1000)) {
            showProgress();
            setActionMode('pending');
            progressBar.classList.add('is-indeterminate');
            setStatus(String(pendingState.message || 'Upload or validation is still in progress...'), false);
            setStatusMeta('A previous upload may still be validating or creating an import job. If nothing appears in Recent Import History after a minute, click Reset Upload State.');
        } else if (pendingState) {
            clearPendingState();
        }
    }
})();

(function () {
    const searchInput = document.getElementById('preferredPlaylistSearch');
    const searchBtn = document.getElementById('preferredPlaylistSearchBtn');
    const clearBtn = document.getElementById('preferredPlaylistClearBtn');
    const items = Array.from(document.querySelectorAll('.playlist-pref-item'));
    const selectedCount = document.getElementById('preferredSelectedCount');
    const selectedList = document.getElementById('preferredSelectedList');
    const selectedEmpty = document.getElementById('preferredSelectedEmpty');

    if (!items.length || !selectedCount || !selectedList || !selectedEmpty) {
        return;
    }

    function applyFilter(query) {
        const q = String(query || '').trim().toLowerCase();
        items.forEach(function (item) {
            if (q === '') {
                item.style.display = '';
                return;
            }
            const name = String(item.getAttribute('data-playlist-name') || '');
            const crumb = String(item.getAttribute('data-playlist-crumb') || '');
            const visible = name.includes(q) || crumb.includes(q);
            item.style.display = visible ? '' : 'none';
        });
    }

    function updateSelectedSummary() {
        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function (c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[c] || c;
            });
        }

        const checked = items
            .map(function (item) {
                const cb = item.querySelector('input[type=\"checkbox\"]');
                if (!cb || !cb.checked) return null;
                const nameEl = item.querySelector('.playlist-pref-name');
                return nameEl ? nameEl.textContent.trim() : null;
            })
            .filter(Boolean);

        selectedCount.textContent = String(checked.length);
        selectedList.innerHTML = checked.map(function (name) {
            return '<span class=\"playlist-pref-chip\">' + escapeHtml(name) + '</span>';
        }).join('');
        selectedEmpty.style.display = checked.length ? 'none' : '';
    }

    items.forEach(function (item) {
        const cb = item.querySelector('input[type=\"checkbox\"]');
        if (cb) {
            cb.addEventListener('change', updateSelectedSummary);
        }
    });

    if (searchBtn && searchInput) {
        searchBtn.addEventListener('click', function () {
            applyFilter(searchInput.value);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyFilter(searchInput.value);
            }
        });
        searchInput.addEventListener('input', function () {
            if (searchInput.value.trim() === '') {
                applyFilter('');
            }
        });
    }

    if (clearBtn && searchInput) {
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            applyFilter('');
            searchInput.focus();
        });
    }

    updateSelectedSummary();
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
