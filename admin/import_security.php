<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/helpers/rekordbox_import_security.php';
require_admin();

$pageTitle = 'Import Security';
$pageBodyClass = 'admin-page';

$db = db();
$error = '';
$entries = [];
$summary = [
    'total' => 0,
    'by_category' => [],
    'recent_errors' => 0,
];
$jobCounts = [
    'queued' => 0,
    'processing' => 0,
    'failed' => 0,
    'done' => 0,
];
$recentJobs = [];
$logPath = mdjr_rekordbox_log_path('security.log');

try {
    $entries = mdjr_rekordbox_log_entries(200);
    $summary = mdjr_rekordbox_log_summary(24, 500);

    $stmt = $db->query("
        SELECT status, COUNT(*) AS c
        FROM dj_library_import_jobs
        GROUP BY status
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)($row['status'] ?? '');
        if (array_key_exists($status, $jobCounts)) {
            $jobCounts[$status] = (int)($row['c'] ?? 0);
        }
    }

    $jobsStmt = $db->query("
        SELECT id, dj_id, status, stage, stage_message, error_message, created_at, started_at, finished_at
        FROM dj_library_import_jobs
        ORDER BY id DESC
        LIMIT 25
    ");
    $recentJobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = 'Failed to load import security review: ' . $e->getMessage();
}

include APP_ROOT . '/dj/layout.php';
?>
<style>
.import-sec-card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; max-width:1120px; margin-bottom:16px; }
.import-sec-grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); margin-top:12px; }
.import-sec-stat { border:1px solid #26263a; border-radius:10px; background:#151520; padding:14px; }
.import-sec-stat-label { color:#b7b7c8; font-size:13px; margin:0 0 6px; }
.import-sec-stat-value { color:#fff; font-size:28px; font-weight:800; margin:0; }
.import-sec-help { color:#b7b7c8; font-size:14px; margin:8px 0 0; }
.import-sec-table-wrap { overflow:auto; margin-top:14px; }
.import-sec-table { width:100%; border-collapse:collapse; min-width:980px; }
.import-sec-table th, .import-sec-table td { padding:10px 12px; border-bottom:1px solid #242438; text-align:left; vertical-align:top; }
.import-sec-table th { color:#aeb0c7; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; }
.import-sec-pill { display:inline-flex; align-items:center; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; border:1px solid #30304a; background:#171727; color:#f0f0ff; }
.import-sec-pill.upload_rejected, .import-sec-pill.upload_error, .import-sec-pill.worker_failure { color:#ffb0b0; border-color:#7f3030; background:rgba(180,60,60,0.16); }
.import-sec-pill.worker_deferred { color:#ffe2a3; border-color:#826126; background:rgba(180,130,40,0.16); }
.import-sec-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px; color:#dfe6ff; white-space:pre-wrap; word-break:break-word; }
.import-sec-muted { color:#9ea1bc; }
.error { color:#ff8080; margin-bottom:10px; }
</style>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
    <h1>Import Security Review</h1>

    <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>

    <div class="import-sec-card">
        <h3 style="margin-top:0;">Overview</h3>
        <div class="import-sec-grid">
            <div class="import-sec-stat">
                <p class="import-sec-stat-label">Recent log entries</p>
                <p class="import-sec-stat-value"><?php echo (int)$summary['total']; ?></p>
            </div>
            <div class="import-sec-stat">
                <p class="import-sec-stat-label">Last 24h events</p>
                <p class="import-sec-stat-value"><?php echo (int)$summary['recent_errors']; ?></p>
            </div>
            <div class="import-sec-stat">
                <p class="import-sec-stat-label">Queued imports</p>
                <p class="import-sec-stat-value"><?php echo (int)$jobCounts['queued']; ?></p>
            </div>
            <div class="import-sec-stat">
                <p class="import-sec-stat-label">Processing imports</p>
                <p class="import-sec-stat-value"><?php echo (int)$jobCounts['processing']; ?></p>
            </div>
            <div class="import-sec-stat">
                <p class="import-sec-stat-label">Failed imports</p>
                <p class="import-sec-stat-value"><?php echo (int)$jobCounts['failed']; ?></p>
            </div>
        </div>
        <p class="import-sec-help">Security log path: <code><?php echo e($logPath); ?></code></p>
    </div>

    <div class="import-sec-card">
        <h3 style="margin-top:0;">Categories</h3>
        <?php if (empty($summary['by_category'])): ?>
            <p class="import-sec-help">No security events logged yet.</p>
        <?php else: ?>
            <div class="import-sec-grid">
                <?php foreach ($summary['by_category'] as $category => $count): ?>
                    <div class="import-sec-stat">
                        <p class="import-sec-stat-label"><?php echo e($category); ?></p>
                        <p class="import-sec-stat-value"><?php echo (int)$count; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="import-sec-card">
        <h3 style="margin-top:0;">Recent Import Jobs</h3>
        <div class="import-sec-table-wrap">
            <table class="import-sec-table">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>DJ</th>
                        <th>Status</th>
                        <th>Stage</th>
                        <th>Created</th>
                        <th>Started</th>
                        <th>Finished</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentJobs as $row): ?>
                        <tr>
                            <td>#<?php echo (int)($row['id'] ?? 0); ?></td>
                            <td><?php echo (int)($row['dj_id'] ?? 0); ?></td>
                            <td><?php echo e((string)($row['status'] ?? '')); ?></td>
                            <td>
                                <span class="import-sec-pill"><?php echo e((string)($row['stage'] ?? '')); ?></span>
                                <?php if (!empty($row['stage_message'])): ?>
                                    <div class="import-sec-muted" style="margin-top:4px;"><?php echo e((string)$row['stage_message']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e((string)($row['created_at'] ?? '—')); ?></td>
                            <td><?php echo e((string)($row['started_at'] ?? '—')); ?></td>
                            <td><?php echo e((string)($row['finished_at'] ?? '—')); ?></td>
                            <td><?php echo e(trim((string)($row['error_message'] ?? '')) !== '' ? (string)$row['error_message'] : '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="import-sec-card">
        <h3 style="margin-top:0;">Recent Security Log Entries</h3>
        <div class="import-sec-table-wrap">
            <table class="import-sec-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Category</th>
                        <th>Message</th>
                        <th>Context</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="4" class="import-sec-muted">No security log entries yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?php echo e((string)($entry['ts'] ?? '')); ?></td>
                                <td><span class="import-sec-pill <?php echo e((string)($entry['category'] ?? '')); ?>"><?php echo e((string)($entry['category'] ?? 'unknown')); ?></span></td>
                                <td><?php echo e((string)($entry['message'] ?? '')); ?></td>
                                <td class="import-sec-code"><?php echo e(json_encode($entry['context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
