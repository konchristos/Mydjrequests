<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Admin Dashboard';
$pageBodyClass = 'admin-page';

$db = db();
$error = '';
$success = '';

function adminEnsureSettingsTable(PDO $db): void
{
    $db->exec("\n        CREATE TABLE IF NOT EXISTS app_settings (\n          `key` VARCHAR(100) PRIMARY KEY,\n          `value` VARCHAR(255) NOT NULL,\n          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n        )\n    ");
}

function adminGetSetting(PDO $db, string $key, string $default): string
{
    $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : (string)$val;
}

function adminSetSetting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare("\n        INSERT INTO app_settings (`key`, `value`)\n        VALUES (?, ?)\n        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)\n    ");
    $stmt->execute([$key, $value]);
}

adminEnsureSettingsTable($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $value = (string)($_POST['value'] ?? '0');
        $value = $value === '1' ? '1' : '0';

        if ($action === 'set_tipping_prod') {
            adminSetSetting($db, 'patron_payments_enabled_prod', $value);
            adminSetSetting($db, 'patron_payments_enabled', $value); // legacy compatibility
            $success = $value === '1'
                ? 'Production tipping/boosting enabled (/request/index.php).'
                : 'Production tipping/boosting disabled (/request/index.php).';
        }

        if ($action === 'set_tipping_dev') {
            adminSetSetting($db, 'patron_payments_enabled_dev', $value);
            $success = $value === '1'
                ? 'Dev tipping/boosting enabled (/request_v2/index.php).'
                : 'Dev tipping/boosting disabled (/request_v2/index.php).';
        }

        if ($action === 'set_platform_fee_bps') {
            $rawBps = isset($_POST['platform_fee_bps']) ? (int)$_POST['platform_fee_bps'] : 0;
            $bps = max(0, min(10000, $rawBps));
            adminSetSetting($db, 'platform_fee_bps', (string)$bps);
            $success = 'Platform fee saved at ' . number_format($bps / 100, 2) . '%.';
        }

        if ($action === 'set_alpha_open_access') {
            adminSetSetting($db, 'alpha_open_access', $value);
            $success = $value === '1'
                ? 'Alpha open access enabled. All users receive premium feature access for testing.'
                : 'Alpha open access disabled. Standard trial/subscription gates are now active.';
        }

        if ($action === 'end_alpha_to_trial') {
            $configPath = APP_ROOT . '/app/config/subscriptions.php';
            $config = file_exists($configPath) ? require $configPath : [];
            $trialDays = max(1, (int)($config['trial_days'] ?? 30));
            $trialEndsAt = gmdate('Y-m-d H:i:s', time() + ($trialDays * 86400));

            $nonPaidWhere = "
                NOT EXISTS (
                    SELECT 1
                    FROM subscriptions s
                    WHERE s.user_id = users.id
                      AND s.id = (
                          SELECT MAX(s2.id)
                          FROM subscriptions s2
                          WHERE s2.user_id = users.id
                      )
                      AND LOWER(COALESCE(s.plan, '')) IN ('pro', 'premium')
                      AND LOWER(COALESCE(s.status, '')) = 'active'
                      AND (s.renews_at IS NULL OR s.renews_at > UTC_TIMESTAMP())
                )
            ";

            try {
                $db->beginTransaction();

                adminSetSetting($db, 'alpha_open_access', '0');

                $updateUsers = $db->prepare("
                    UPDATE users
                    SET subscription = 'trial',
                        subscription_status = 'trial',
                        trial_ends_at = :trial_ends_at
                    WHERE {$nonPaidWhere}
                ");
                $updateUsers->execute(['trial_ends_at' => $trialEndsAt]);
                $affectedUsers = (int)$updateUsers->rowCount();

                $insertSubs = $db->prepare("
                    INSERT INTO subscriptions (user_id, plan, status, renews_at, created_at)
                    SELECT users.id, 'trial', 'active', :trial_ends_at, UTC_TIMESTAMP()
                    FROM users
                    WHERE {$nonPaidWhere}
                ");
                $insertSubs->execute(['trial_ends_at' => $trialEndsAt]);

                $db->commit();
                $success = "Alpha ended. {$affectedUsers} users were moved to trial until " . gmdate('d M Y', strtotime($trialEndsAt)) . ' (UTC).';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Failed to end alpha and convert users to trial: ' . $e->getMessage();
            }
        }
    }
}

$tippingEnabledProd = adminGetSetting($db, 'patron_payments_enabled_prod', adminGetSetting($db, 'patron_payments_enabled', '0')) === '1';
$tippingEnabledDev = adminGetSetting($db, 'patron_payments_enabled_dev', adminGetSetting($db, 'patron_payments_enabled', '0')) === '1';
$alphaOpenAccess = adminGetSetting($db, 'alpha_open_access', '0') === '1';
$platformFeeBps = max(0, min(10000, (int)adminGetSetting($db, 'platform_fee_bps', (string)(defined('STRIPE_PLATFORM_FEE_BPS') ? (int)STRIPE_PLATFORM_FEE_BPS : 0))));

$adminId = (int)($_SESSION['dj_id'] ?? 0);
$seenKeyNotify = 'admin_seen_notify_signups_' . $adminId;
$seenKeyBugs = 'admin_seen_bug_reports_' . $adminId;
$seenKeyFeedback = 'admin_seen_feedback_' . $adminId;

$seenNotify = adminGetSetting($db, $seenKeyNotify, '1970-01-01 00:00:00');
$seenBugs = adminGetSetting($db, $seenKeyBugs, '1970-01-01 00:00:00');
$seenFeedback = adminGetSetting($db, $seenKeyFeedback, '1970-01-01 00:00:00');

$usersTotal = 0;
$eventsTotal = 0;
$notifyNew = 0;
$bugsNew = 0;
$feedbackNew = 0;

try {
    $usersTotal = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (Throwable $e) {
    $usersTotal = 0;
}

try {
    $eventsTotal = (int)$db->query("SELECT COUNT(*) FROM events")->fetchColumn();
} catch (Throwable $e) {
    $eventsTotal = 0;
}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notify_signups WHERE created_at > ?");
    $stmt->execute([$seenNotify]);
    $notifyNew = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $notifyNew = 0;
}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM bug_reports WHERE created_at > ?");
    $stmt->execute([$seenBugs]);
    $bugsNew = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $bugsNew = 0;
}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM feedback WHERE created_at > ?");
    $stmt->execute([$seenFeedback]);
    $feedbackNew = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $feedbackNew = 0;
}

$enrichmentPending = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM track_enrichment_queue WHERE status IN ('pending', 'processing')");
    $enrichmentPending = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $enrichmentPending = 0;
}

include APP_ROOT . '/dj/layout.php';
?>

<style>
.admin-section { margin-top: 20px; }
.admin-section h2 { margin: 0 0 12px; font-size: 22px; }
.admin-status-pill { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; margin-left:8px; }
.admin-status-pill.on { background:#173f1f; color:#7be87f; border:1px solid #256a33; }
.admin-status-pill.off { background:#3b1818; color:#ff9f9f; border:1px solid #7f2626; }
.admin-toggle-row { display:flex; gap:10px; margin-top:10px; flex-wrap: wrap; }
.admin-toggle-btn { background:#ff2fd2; color:#fff; border:none; border-radius:8px; padding:10px 12px; cursor:pointer; font-weight:700; }
.admin-toggle-btn.secondary { background:#25253a; }
.admin-note { color:#b8b8c8; font-size:13px; margin-top:10px; }
.admin-section-copy { color:#b8b8c8; margin: 0 0 12px; font-size:14px; }
.error { color:#ff8080; margin: 8px 0 12px; }
.success { color:#7be87f; margin: 8px 0 12px; }
.admin-controls-row { margin-top:16px; }
.admin-count { color:#ff2fd2; font-weight:700; }
.admin-new-badge {
    display:inline-block;
    margin-left:8px;
    padding:2px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    color:#fff;
    background:#ff2fd2;
}
.admin-new-badge.pulse {
    animation: adminPulse 1.2s ease-in-out infinite;
}
@keyframes adminPulse {
    0% { box-shadow: 0 0 0 0 rgba(255,47,210,0.65); }
    70% { box-shadow: 0 0 0 10px rgba(255,47,210,0); }
    100% { box-shadow: 0 0 0 0 rgba(255,47,210,0); }
}
</style>

<div class="admin-wrap">
    <h1>Admin Dashboard</h1>

    <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?php echo e($success); ?></div><?php endif; ?>

    <div class="admin-section">
        <h2>Users & Events</h2>
        <p class="admin-section-copy">Account management and event oversight.</p>
        <div class="admin-dashboard">
            <a href="/admin/users.php" class="admin-card">
                <h3>All Users</h3>
                <p>View all DJs and accounts · <span class="admin-count"><?php echo (int)$usersTotal; ?></span></p>
            </a>

            <a href="/admin/get_events.php" class="admin-card">
                <h3>User Events</h3>
                <p>View all DJs and their events · <span class="admin-count"><?php echo (int)$eventsTotal; ?></span></p>
            </a>

            <a href="/admin/notify_signups.php" class="admin-card">
                <h3>
                    Notify Signups
                    <?php if ($notifyNew > 0): ?>
                        <span class="admin-new-badge pulse">+<?php echo (int)$notifyNew; ?> new</span>
                    <?php endif; ?>
                </h3>
                <p>Leads from the Coming Soon page</p>
            </a>
        </div>
    </div>

    <div class="admin-section">
        <h2>Support & Moderation</h2>
        <p class="admin-section-copy">Incoming reports and user-facing quality signals.</p>
        <div class="admin-dashboard">
            <a href="/admin/bugs.php" class="admin-card">
                <h3>
                    Bug Reports
                    <?php if ($bugsNew > 0): ?>
                        <span class="admin-new-badge pulse">+<?php echo (int)$bugsNew; ?> new</span>
                    <?php endif; ?>
                </h3>
                <p>All reported bugs and status updates</p>
            </a>

            <a href="/admin/feedback.php" class="admin-card">
                <h3>
                    Feedback
                    <?php if ($feedbackNew > 0): ?>
                        <span class="admin-new-badge pulse">+<?php echo (int)$feedbackNew; ?> new</span>
                    <?php endif; ?>
                </h3>
                <p>Public feedback submissions</p>
            </a>
        </div>
    </div>

    <div class="admin-section">
        <h2>Communications</h2>
        <p class="admin-section-copy">Platform-wide announcements and broadcast management.</p>
        <div class="admin-dashboard">
            <a href="/admin/broadcasts.php" class="admin-card">
                <h3>Broadcasts</h3>
                <p>Send announcements to all users</p>
            </a>
        </div>
    </div>

    <div class="admin-section">
        <h2>Platform Controls</h2>
        <p class="admin-section-copy">Performance and monetization switches.</p>

        <div class="admin-dashboard">
            <a href="/admin/schema_audit.php" class="admin-card">
                <h3>Schema Audit</h3>
                <p>Compare live DB tables/columns against code references</p>
            </a>

            <a href="/admin/revenue.php" class="admin-card">
                <h3>Platform Revenue</h3>
                <p>Gross, platform fees, Stripe fees, DJ net payouts</p>
            </a>

            <a href="/admin/performance.php" class="admin-card">
                <h3>Performance</h3>
                <p>Queue pending: <?php echo (int)$enrichmentPending; ?> · toggles and indexes</p>
            </a>
        </div>

        <div class="admin-dashboard admin-controls-row">
            <div class="admin-card" style="cursor:default;">
                <h3>
                    Tipping & Boosts (Production)
                    <span class="admin-status-pill <?php echo $tippingEnabledProd ? 'on' : 'off'; ?>"><?php echo $tippingEnabledProd ? 'Enabled' : 'Disabled'; ?></span>
                </h3>
                <p>Controls <code>/request/index.php</code>.</p>
                <div class="admin-toggle-row">
                    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="set_tipping_prod"><input type="hidden" name="value" value="1"><button type="submit" class="admin-toggle-btn">Enable</button></form>
                    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="set_tipping_prod"><input type="hidden" name="value" value="0"><button type="submit" class="admin-toggle-btn secondary">Disable</button></form>
                </div>
            </div>

            <div class="admin-card" style="cursor:default;">
                <h3>
                    Tipping & Boosts (Dev)
                    <span class="admin-status-pill <?php echo $tippingEnabledDev ? 'on' : 'off'; ?>"><?php echo $tippingEnabledDev ? 'Enabled' : 'Disabled'; ?></span>
                </h3>
                <p>Controls <code>/request_v2/index.php</code>.</p>
                <div class="admin-toggle-row">
                    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="set_tipping_dev"><input type="hidden" name="value" value="1"><button type="submit" class="admin-toggle-btn">Enable</button></form>
                    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="set_tipping_dev"><input type="hidden" name="value" value="0"><button type="submit" class="admin-toggle-btn secondary">Disable</button></form>
                </div>
                <div class="admin-note">Values persist in <code>app_settings</code> and stay after reload.</div>
            </div>

            <div class="admin-card" style="cursor:default;">
                <h3>Platform Fee</h3>
                <p>Applied to new tip/boost PaymentIntents via <code>application_fee_amount</code>.</p>
                <form method="POST" class="admin-toggle-row" style="align-items:center;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="set_platform_fee_bps">
                    <label style="display:flex;gap:8px;align-items:center;">
                        <span>BPS:</span>
                        <input name="platform_fee_bps" type="number" min="0" max="10000" step="1" value="<?php echo (int)$platformFeeBps; ?>" style="width:110px;background:#0e0e14;color:#fff;border:1px solid #2a2a3f;border-radius:8px;padding:8px 10px;">
                    </label>
                    <button type="submit" class="admin-toggle-btn">Save</button>
                </form>
                <div class="admin-note">Current fee: <strong><?php echo number_format($platformFeeBps / 100, 2); ?>%</strong> (100 bps = 1%).</div>
            </div>
        </div>

        <div class="admin-dashboard admin-controls-row">
            <div class="admin-card" style="cursor:default;">
                <h3>
                    Alpha Open Access
                    <span class="admin-status-pill <?php echo $alphaOpenAccess ? 'on' : 'off'; ?>"><?php echo $alphaOpenAccess ? 'Enabled' : 'Disabled'; ?></span>
                </h3>
                <p>When enabled, all DJs are treated as Premium for feature access and Account page labels show Early Access messaging.</p>
                <div class="admin-toggle-row">
                    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="set_alpha_open_access"><input type="hidden" name="value" value="1"><button type="submit" class="admin-toggle-btn">Enable</button></form>
                    <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="set_alpha_open_access"><input type="hidden" name="value" value="0"><button type="submit" class="admin-toggle-btn secondary">Disable</button></form>
                </div>
                <div class="admin-note">Use this during public alpha when subscriptions are not live yet.</div>
            </div>

            <div class="admin-card" style="cursor:default;">
                <h3>End Alpha & Convert Users to Trial</h3>
                <p>Turns OFF Alpha Open Access and moves all non-paying users to trial immediately (paid Pro/Premium users are preserved).</p>
                <div class="admin-toggle-row">
                    <form method="POST" onsubmit="return confirm('End alpha now? This will disable open access and convert non-paying users to trial.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="end_alpha_to_trial">
                        <button type="submit" class="admin-toggle-btn">Run End-Alpha Conversion</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
