<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Performance Settings';
$pageBodyClass = 'admin-page';

$db = db();
$error = '';
$success = '';

function perfEnsureSettingsTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
          `key` VARCHAR(100) PRIMARY KEY,
          `value` VARCHAR(255) NOT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function perfEnsureQueueTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS track_enrichment_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            spotify_track_id VARCHAR(64) NOT NULL,
            status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL,
            next_attempt_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_track_enrichment_queue_spotify_id (spotify_track_id),
            KEY idx_track_enrichment_queue_status_next (status, next_attempt_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function perfGetSetting(PDO $db, string $key, string $default): string
{
    $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    if ($val === false) {
        $ins = $db->prepare("INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)");
        $ins->execute([$key, $default]);
        return $default;
    }
    return (string)$val;
}

function perfSetSetting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare("
        INSERT INTO app_settings (`key`, `value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    $stmt->execute([$key, $value]);
}

function perfApplyRecommendedIndexes(PDO $db): array
{
    $statements = [
        "CREATE INDEX idx_spotify_tracks_bpm_id ON spotify_tracks (bpm, id)",
        "CREATE INDEX idx_spotify_tracks_year_id ON spotify_tracks (release_year, id)",
        "CREATE INDEX idx_track_links_spotify_track_id ON track_links (spotify_track_id)",
        "CREATE INDEX idx_bpm_test_tracks_artist_title ON bpm_test_tracks (artist, title)",
        "CREATE INDEX idx_song_requests_event_spotify_created ON song_requests (event_id, spotify_track_id, created_at)",
        "CREATE INDEX idx_track_enrichment_queue_status_next ON track_enrichment_queue (status, next_attempt_at, id)",
    ];

    $applied = 0;
    $already = 0;
    $errors = [];

    foreach ($statements as $sql) {
        try {
            $db->exec($sql);
            $applied++;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate key name') !== false || strpos($msg, '1061') !== false) {
                $already++;
                continue;
            }
            $errors[] = $msg;
        }
    }

    return [$applied, $already, $errors];
}

perfEnsureSettingsTable($db);
perfEnsureQueueTable($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_toggles') {
            $backfill = isset($_POST['bpm_backfill_enabled']) ? '1' : '0';
            $requestFuzzy = isset($_POST['bpm_fuzzy_on_request_enabled']) ? '1' : '0';
            $yearFill = isset($_POST['bpm_on_request_fill_year_enabled']) ? '1' : '0';
            $queueWorker = isset($_POST['track_enrichment_worker_enabled']) ? '1' : '0';

            perfSetSetting($db, 'bpm_backfill_enabled', $backfill);
            perfSetSetting($db, 'bpm_fuzzy_on_request_enabled', $requestFuzzy);
            perfSetSetting($db, 'bpm_on_request_fill_year_enabled', $yearFill);
            perfSetSetting($db, 'track_enrichment_worker_enabled', $queueWorker);
            perfSetSetting($db, 'bpm_owner_user_id', (string)((int)($_SESSION['dj_id'] ?? 0)));

            $success = 'Performance toggles updated.';
        } elseif ($action === 'apply_indexes') {
            [$applied, $already, $errs] = perfApplyRecommendedIndexes($db);
            if (!empty($errs)) {
                $error = 'Some index operations failed: ' . implode(' | ', $errs);
            } else {
                $success = "Indexes applied: {$applied}. Already present: {$already}.";
            }
        }
    }
}

$settings = [
    'bpm_backfill_enabled' => perfGetSetting($db, 'bpm_backfill_enabled', '1'),
    'bpm_fuzzy_on_request_enabled' => perfGetSetting($db, 'bpm_fuzzy_on_request_enabled', '0'),
    'bpm_on_request_fill_year_enabled' => perfGetSetting($db, 'bpm_on_request_fill_year_enabled', '0'),
    'track_enrichment_worker_enabled' => perfGetSetting($db, 'track_enrichment_worker_enabled', '1'),
    'bpm_owner_user_id' => perfGetSetting($db, 'bpm_owner_user_id', (string)((int)($_SESSION['dj_id'] ?? 0))),
    'patron_payments_enabled_prod' => perfGetSetting($db, 'patron_payments_enabled_prod', perfGetSetting($db, 'patron_payments_enabled', '0')),
    'patron_payments_enabled_dev' => perfGetSetting($db, 'patron_payments_enabled_dev', perfGetSetting($db, 'patron_payments_enabled', '0')),
];

$queueCounts = [
    'pending' => 0,
    'processing' => 0,
    'failed' => 0,
    'done' => 0,
];

try {
    $q = $db->query("
        SELECT status, COUNT(*) AS c
        FROM track_enrichment_queue
        GROUP BY status
    ");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = (string)($r['status'] ?? '');
        if (array_key_exists($s, $queueCounts)) {
            $queueCounts[$s] = (int)$r['c'];
        }
    }
} catch (Throwable $e) {
    // Keep zeros if table/query not available.
}

include APP_ROOT . '/dj/layout.php';
?>

<style>
.perf-card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; max-width:960px; margin-bottom:16px; }
.perf-row { margin: 12px 0; }
.perf-help { color:#b7b7c8; font-size:14px; margin-top:6px; }
.perf-btn { background:#ff2fd2; color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; }
.perf-btn.secondary { background:#232337; }
.error { color:#ff8080; margin-bottom:10px; }
.success { color:#7be87f; margin-bottom:10px; }
</style>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
    <h1>Performance</h1>

    <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?php echo e($success); ?></div><?php endif; ?>

    <div class="perf-card">
        <h3 style="margin-top:0;">Enrichment Queue</h3>
        <div class="perf-row">Pending: <strong><?php echo (int)$queueCounts['pending']; ?></strong></div>
        <div class="perf-row">Processing: <strong><?php echo (int)$queueCounts['processing']; ?></strong></div>
        <div class="perf-row">Failed: <strong><?php echo (int)$queueCounts['failed']; ?></strong></div>
        <div class="perf-row">Done: <strong><?php echo (int)$queueCounts['done']; ?></strong></div>
        <div class="perf-help">Process queue with: <code>php /home/mydjrequests/public_html/app/workers/track_enrichment_queue_worker.php --limit=100</code></div>
    </div>

    <div class="perf-card">
        <h3 style="margin-top:0;">Metadata Toggles</h3>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_toggles">

            <div class="perf-row">
                <label>
                    <input type="checkbox" name="bpm_backfill_enabled" value="1" <?php echo $settings['bpm_backfill_enabled'] === '1' ? 'checked' : ''; ?>>
                    Enable background BPM/year backfill worker
                </label>
                <div class="perf-help">Controls worker runs (`bpm_cache_backfill_worker.php`).</div>
            </div>

            <div class="perf-row">
                <label>
                    <input type="checkbox" name="bpm_fuzzy_on_request_enabled" value="1" <?php echo $settings['bpm_fuzzy_on_request_enabled'] === '1' ? 'checked' : ''; ?>>
                    Enable queued fuzzy enrichment for new requests
                </label>
                <div class="perf-help">Flow: cache/link now, queue fuzzy worker later. Keep OFF to disable queue ingestion only. Existing/manual BPM display is unaffected.</div>
            </div>

            <div class="perf-row">
                <div class="perf-help">BPM/Year display owner user ID: <strong><?php echo (int)$settings['bpm_owner_user_id']; ?></strong> (set to current admin when saving toggles)</div>
            </div>

            <div class="perf-row">
                <div class="perf-help">
                    Tips/Boost platform visibility:
                    Production page: <strong><?php echo $settings['patron_payments_enabled_prod'] === '1' ? 'ENABLED' : 'DISABLED'; ?></strong>
                    · Dev page: <strong><?php echo $settings['patron_payments_enabled_dev'] === '1' ? 'ENABLED' : 'DISABLED'; ?></strong>
                </div>
            </div>

            <div class="perf-row">
                <label>
                    <input type="checkbox" name="track_enrichment_worker_enabled" value="1" <?php echo $settings['track_enrichment_worker_enabled'] === '1' ? 'checked' : ''; ?>>
                    Enable queue worker execution (cron gate)
                </label>
                <div class="perf-help">Cron can stay installed; when OFF the worker exits immediately.</div>
            </div>

            <div class="perf-row">
                <label>
                    <input type="checkbox" name="bpm_on_request_fill_year_enabled" value="1" <?php echo $settings['bpm_on_request_fill_year_enabled'] === '1' ? 'checked' : ''; ?>>
                    Allow release year write during request-time enrichment
                </label>
                <div class="perf-help">Safer to leave OFF unless you want automatic year fill.</div>
            </div>

            <button type="submit" class="perf-btn">Save Toggles</button>
        </form>
    </div>

    <div class="perf-card">
        <h3 style="margin-top:0;">Recommended Indexes</h3>
        <div class="perf-help" style="margin-bottom:12px;">
            Applies index set for request lookup, backfill scans, and link joins.
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="apply_indexes">
            <button type="submit" class="perf-btn secondary">Apply Indexes</button>
        </form>
    </div>
</div>
