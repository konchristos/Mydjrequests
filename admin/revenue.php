<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Platform Revenue';
$pageBodyClass = 'admin-page';
$db = db();

$dateFrom = trim((string)($_GET['from'] ?? date('Y-m-d', strtotime('-30 days'))));
$dateTo = trim((string)($_GET['to'] ?? date('Y-m-d')));
$djId = (int)($_GET['dj_id'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}
$fromTs = $dateFrom . ' 00:00:00';
$toTs = $dateTo . ' 23:59:59';

$tableExists = false;
try {
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'stripe_payment_ledger'
    ");
    $stmt->execute();
    $tableExists = ((int)$stmt->fetchColumn() > 0);
} catch (Throwable $e) {
    $tableExists = false;
}

$rows = [];
$summary = [
    'gross' => 0.0,
    'platform_fee' => 0.0,
    'stripe_fee' => 0.0,
    'net_to_dj' => 0.0,
];

if ($tableExists) {
    $djFilter = '';
    $params = [$fromTs, $toTs];
    if ($djId > 0) {
        $djFilter = ' AND l.dj_user_id = ?';
        $params[] = $djId;
    }

    $sql = "
        SELECT
            l.dj_user_id,
            COALESCE(NULLIF(TRIM(u.dj_name), ''), NULLIF(TRIM(u.name), ''), u.email, CONCAT('User #', l.dj_user_id)) AS dj_name,
            UPPER(COALESCE(l.currency, 'AUD')) AS currency,
            SUM(l.gross_amount_cents) / 100 AS gross_amount,
            SUM(l.platform_fee_cents) / 100 AS platform_fee_amount,
            SUM(l.stripe_fee_cents) / 100 AS stripe_fee_amount,
            SUM(l.net_to_dj_cents) / 100 AS net_to_dj_amount
        FROM stripe_payment_ledger l
        LEFT JOIN users u ON u.id = l.dj_user_id
        WHERE l.occurred_at BETWEEN ? AND ?
          {$djFilter}
        GROUP BY l.dj_user_id, dj_name, currency
        ORDER BY gross_amount DESC, l.dj_user_id ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $summary['gross'] += (float)($r['gross_amount'] ?? 0);
        $summary['platform_fee'] += (float)($r['platform_fee_amount'] ?? 0);
        $summary['stripe_fee'] += (float)($r['stripe_fee_amount'] ?? 0);
        $summary['net_to_dj'] += (float)($r['net_to_dj_amount'] ?? 0);
    }
}

include APP_ROOT . '/dj/layout.php';
?>
<style>
.rev-card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; margin-bottom:16px; }
.rev-grid { display:grid; grid-template-columns:repeat(4,minmax(140px,1fr)); gap:10px; }
.rev-kpi { background:#181823; border:1px solid #2a2a3f; border-radius:10px; padding:12px; }
.rev-kpi .label { color:#aeb3c0; font-size:12px; }
.rev-kpi .value { color:#fff; font-size:22px; font-weight:700; margin-top:4px; }
.rev-table { width:100%; border-collapse:collapse; }
.rev-table th, .rev-table td { text-align:left; border-bottom:1px solid #232337; padding:8px 6px; }
.rev-table th { color:#c4c8d4; font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
.rev-note { color:#aeb3c0; font-size:13px; margin-top:8px; }
</style>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
    <h1>Platform Revenue</h1>

    <div class="rev-card">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <label>From<br><input type="date" name="from" value="<?php echo e($dateFrom); ?>" style="background:#0f1117;color:#fff;border:1px solid #2a2a3f;border-radius:8px;padding:8px 10px;"></label>
            <label>To<br><input type="date" name="to" value="<?php echo e($dateTo); ?>" style="background:#0f1117;color:#fff;border:1px solid #2a2a3f;border-radius:8px;padding:8px 10px;"></label>
            <label>DJ ID (optional)<br><input type="number" min="0" name="dj_id" value="<?php echo (int)$djId; ?>" style="background:#0f1117;color:#fff;border:1px solid #2a2a3f;border-radius:8px;padding:8px 10px;width:140px;"></label>
            <button type="submit" style="background:#ff2fd2;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;">Apply</button>
        </form>
    </div>

    <?php if (!$tableExists): ?>
        <div class="rev-card">
            <p>Stripe ledger table does not exist yet. It will be created automatically when the webhook receives the next event.</p>
        </div>
    <?php else: ?>
        <div class="rev-card">
            <div class="rev-grid">
                <div class="rev-kpi"><div class="label">Gross</div><div class="value"><?php echo number_format($summary['gross'], 2); ?></div></div>
                <div class="rev-kpi"><div class="label">Platform Fee</div><div class="value"><?php echo number_format($summary['platform_fee'], 2); ?></div></div>
                <div class="rev-kpi"><div class="label">Stripe Fee</div><div class="value"><?php echo number_format($summary['stripe_fee'], 2); ?></div></div>
                <div class="rev-kpi"><div class="label">Net to DJs</div><div class="value"><?php echo number_format($summary['net_to_dj'], 2); ?></div></div>
            </div>
            <div class="rev-note">Includes dispute adjustments from webhook entries.</div>
        </div>

        <div class="rev-card">
            <table class="rev-table">
                <thead>
                <tr>
                    <th>DJ</th>
                    <th>DJ ID</th>
                    <th>Currency</th>
                    <th>Gross</th>
                    <th>Platform Fee</th>
                    <th>Stripe Fee</th>
                    <th>Net to DJ</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7">No ledger rows for this range.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo e((string)$row['dj_name']); ?></td>
                            <td><?php echo (int)$row['dj_user_id']; ?></td>
                            <td><?php echo e((string)$row['currency']); ?></td>
                            <td><?php echo number_format((float)$row['gross_amount'], 2); ?></td>
                            <td><?php echo number_format((float)$row['platform_fee_amount'], 2); ?></td>
                            <td><?php echo number_format((float)$row['stripe_fee_amount'], 2); ?></td>
                            <td><?php echo number_format((float)$row['net_to_dj_amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
