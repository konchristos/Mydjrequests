<?php
// dj/settings.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

if ($djId <= 0) {
    redirect('dj/login.php');
}

$userStmt = $db->prepare("
    SELECT uuid, dj_name, name
    FROM users
    WHERE id = ?
    LIMIT 1
");
$userStmt->execute([$djId]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$djUuid = (string)($userRow['uuid'] ?? '');
$djDisplay = trim((string)($userRow['dj_name'] ?? '')) !== ''
    ? (string)$userRow['dj_name']
    : (string)($userRow['name'] ?? '');
$dynamicObsUrl = $djUuid !== '' ? url('qr/live_embed.php?dj=' . urlencode($djUuid) . '&t=init') : '';
$isAdminUser = is_admin();
$basePlan = mdjr_get_user_plan_base($db, $djId);
$activeSimulation = mdjr_get_admin_plan_simulation($db, $djId);
$effectivePlan = mdjr_get_user_plan($db, $djId);
$isPremiumPlan = ($effectivePlan === 'premium');
$dynamicLivePatronUrl = $djUuid !== '' ? url('qr/live_patron.php?dj=' . urlencode($djUuid)) : '';
mdjr_ensure_premium_tables($db);
$globalQrSettings = mdjr_get_user_qr_settings($db, $djId) ?: null;
$previewEventUuid = '';
$previewTargetUrl = 'https://mydjrequests.com';
$previewHasLiveEvent = false;
try {
    $liveEventStmt = $db->prepare("
        SELECT *
        FROM events
        WHERE user_id = ?
          AND event_state = 'live'
        ORDER BY COALESCE(state_changed_at, created_at) DESC, id DESC
        LIMIT 1
    ");
    $liveEventStmt->execute([$djId]);
    $liveEvent = $liveEventStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($liveEvent) {
        $previewHasLiveEvent = true;
        $previewEventUuid = (string)($liveEvent['uuid'] ?? '');
        $liveLink = mdjr_get_or_create_premium_link($db, $liveEvent, $djId);
        $liveSlug = trim((string)($liveLink['slug'] ?? ''));
        if ($liveSlug !== '') {
            $previewTargetUrl = url('e/' . rawurlencode($liveSlug) . '?src=qr');
        }
    }

    $previewStmt = $db->prepare("
        SELECT uuid
        FROM events
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    if ($previewEventUuid === '') {
        $previewStmt->execute([$djId]);
        $previewEventUuid = (string)($previewStmt->fetchColumn() ?: '');
    }
} catch (Throwable $e) {
    $previewEventUuid = '';
    $previewTargetUrl = 'https://mydjrequests.com';
    $previewHasLiveEvent = false;
}

// Ensure user preference table exists.
$db->exec("
    CREATE TABLE IF NOT EXISTS user_settings (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        default_tips_boost_enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ensure onboarding profile table exists (safe if already present).
$db->exec("
    CREATE TABLE IF NOT EXISTS user_onboarding_profiles (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        dj_software VARCHAR(50) NOT NULL DEFAULT '',
        dj_software_other VARCHAR(255) NULL,
        has_spotify TINYINT(1) NOT NULL DEFAULT 0,
        has_apple_music TINYINT(1) NOT NULL DEFAULT 0,
        has_beatport TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function userSettingValue(PDO $db, int $userId, string $key, string $default = '0'): string
{
    if ($key !== 'default_tips_boost_enabled') {
        return $default;
    }
    $stmt = $db->prepare("SELECT default_tips_boost_enabled FROM user_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return $default;
    }
    return ((string)$value === '1') ? '1' : '0';
}

function appSettingValue(PDO $db, string $key, string $default = '0'): string
{
    try {
        $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return $default;
        }
        return (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

$error = '';
$success = '';
$defaultBroadcastTemplate = mdjr_default_broadcast_template();
$defaultBroadcastToken = mdjr_default_broadcast_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } elseif ($isAdminUser && isset($_POST['admin_plan_simulation'])) {
        $sim = strtolower(trim((string)($_POST['admin_plan_simulation'] ?? 'auto')));
        if (!in_array($sim, ['auto', 'pro', 'premium'], true)) {
            $error = 'Invalid plan simulation option.';
        } else {
            if ($sim === 'auto') {
                mdjr_set_admin_plan_simulation($db, $djId, null, (int)$djId);
            } else {
                mdjr_set_admin_plan_simulation($db, $djId, $sim, (int)$djId);
            }
            $activeSimulation = mdjr_get_admin_plan_simulation($db, $djId);
            $effectivePlan = mdjr_get_user_plan($db, $djId);
            $isPremiumPlan = ($effectivePlan === 'premium');
            $success = 'Admin plan simulation updated.';
        }
    } else {
        $software = trim((string)($_POST['dj_software'] ?? ''));
        $softwareOther = trim((string)($_POST['dj_software_other'] ?? ''));
        $hasSpotify = isset($_POST['has_spotify']) ? 1 : 0;
        $hasAppleMusic = isset($_POST['has_apple_music']) ? 1 : 0;
        $hasBeatport = isset($_POST['has_beatport']) ? 1 : 0;
        $defaultTipsBoost = isset($_POST['default_tips_boost_enabled']) ? 1 : 0;
        $defaultEventBroadcastEnabled = isset($_POST['default_event_broadcast_enabled']) ? 1 : 0;
        $defaultEventBroadcastMode = trim((string)($_POST['default_event_broadcast_mode'] ?? 'default'));
        $defaultEventBroadcastMessage = trim((string)($_POST['default_event_broadcast_message'] ?? ''));
        if (!$isPremiumPlan) {
            // Custom broadcast defaults are Premium-only.
            $defaultEventBroadcastMode = 'default';
            $defaultEventBroadcastMessage = '';
        }

        $allowed = ['rekordbox', 'serato', 'traktor', 'virtualdj', 'djay', 'other'];
        if (!in_array($software, $allowed, true)) {
            $error = 'Please select your DJ software platform.';
        } elseif ($software === 'other' && $softwareOther === '') {
            $error = 'Please specify your DJ software when selecting Other.';
        } elseif (!in_array($defaultEventBroadcastMode, ['default', 'custom'], true)) {
            $error = 'Invalid broadcast message mode selected.';
        } elseif ($defaultEventBroadcastEnabled === 1 && $defaultEventBroadcastMode === 'custom' && $defaultEventBroadcastMessage === '') {
            $error = 'Add a personalized event broadcast message or turn the toggle off.';
        } elseif ($defaultEventBroadcastMode === 'custom' && strlen($defaultEventBroadcastMessage) > 2000) {
            $error = 'Default event broadcast message must be 2000 characters or fewer.';
        } else {
            if ($software !== 'other') {
                $softwareOther = '';
            }

            $stmt = $db->prepare("
                INSERT INTO user_onboarding_profiles
                    (user_id, dj_software, dj_software_other, has_spotify, has_apple_music, has_beatport)
                VALUES
                    (:user_id, :dj_software, :dj_software_other, :has_spotify, :has_apple_music, :has_beatport)
                ON DUPLICATE KEY UPDATE
                    dj_software = VALUES(dj_software),
                    dj_software_other = VALUES(dj_software_other),
                    has_spotify = VALUES(has_spotify),
                    has_apple_music = VALUES(has_apple_music),
                    has_beatport = VALUES(has_beatport)
            ");
            $stmt->execute([
                ':user_id' => $djId,
                ':dj_software' => $software,
                ':dj_software_other' => $softwareOther,
                ':has_spotify' => $hasSpotify,
                ':has_apple_music' => $hasAppleMusic,
                ':has_beatport' => $hasBeatport,
            ]);

            $stmt = $db->prepare("
                INSERT INTO user_settings (user_id, default_tips_boost_enabled)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE
                    default_tips_boost_enabled = VALUES(default_tips_boost_enabled)
            ");
            $stmt->execute([$djId, $defaultTipsBoost]);

            $stmt = $db->prepare("
                UPDATE users
                SET default_broadcast_message = :message
                WHERE id = :user_id
                LIMIT 1
            ");
            $messageToSave = null;
            if ($defaultEventBroadcastEnabled === 1) {
                if (!$isPremiumPlan || $defaultEventBroadcastMode === 'default') {
                    $messageToSave = $defaultBroadcastToken;
                } else {
                    $messageToSave = ($defaultEventBroadcastMessage !== '' ? $defaultEventBroadcastMessage : null);
                }
            }
            $stmt->execute([
                ':message' => $messageToSave,
                ':user_id' => $djId
            ]);

            $success = 'Settings saved.';
        }
    }
}

$profileStmt = $db->prepare("
    SELECT dj_software, dj_software_other, has_spotify, has_apple_music, has_beatport
    FROM user_onboarding_profiles
    WHERE user_id = ?
    LIMIT 1
");
$profileStmt->execute([$djId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'dj_software' => '',
    'dj_software_other' => '',
    'has_spotify' => 0,
    'has_apple_music' => 0,
    'has_beatport' => 0,
];

$defaultTipsBoostEnabled = userSettingValue($db, $djId, 'default_tips_boost_enabled', '0') === '1';
$broadcastStmt = $db->prepare("
    SELECT default_broadcast_message
    FROM users
    WHERE id = ?
    LIMIT 1
");
$broadcastStmt->execute([$djId]);
$defaultEventBroadcastRaw = (string)($broadcastStmt->fetchColumn() ?: '');
$defaultEventBroadcastEnabled = ($defaultEventBroadcastRaw !== '');
$defaultEventBroadcastMode = 'default';
$defaultEventBroadcastMessage = '';
if ($defaultEventBroadcastRaw === '') {
    $defaultEventBroadcastMessage = $defaultBroadcastTemplate;
} elseif ($defaultEventBroadcastRaw === $defaultBroadcastToken || $defaultEventBroadcastRaw === $defaultBroadcastTemplate) {
    // Treat previously saved raw-default body as "default mode" for migration.
    $defaultEventBroadcastMode = 'default';
    $defaultEventBroadcastMessage = $defaultBroadcastTemplate;
} else {
    $defaultEventBroadcastMode = 'custom';
    $defaultEventBroadcastMessage = $defaultEventBroadcastRaw;
}
if (!$isPremiumPlan) {
    $defaultEventBroadcastMode = 'default';
    $defaultEventBroadcastMessage = $defaultBroadcastTemplate;
}

$prodEnabled = appSettingValue(
    $db,
    'patron_payments_enabled_prod',
    appSettingValue($db, 'patron_payments_enabled', '0')
) === '1';

$messageStatusTemplates = [
    'pre_event'  => ['title' => '', 'body' => ''],
    'live'       => ['title' => '', 'body' => ''],
    'post_event' => ['title' => '', 'body' => ''],
];
try {
    $statusStmt = $db->prepare("
        SELECT notice_type, title, body
        FROM platform_notice_templates
        WHERE notice_type IN ('pre_event','live','post_event')
    ");
    $statusStmt->execute();
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = (string)($row['notice_type'] ?? '');
        if (!isset($messageStatusTemplates[$type])) {
            continue;
        }
        $messageStatusTemplates[$type] = [
            'title' => (string)($row['title'] ?? ''),
            'body' => (string)($row['body'] ?? ''),
        ];
    }
    $messageStatusTemplates['live']['body'] = mdjr_default_platform_live_message();
} catch (Throwable $e) {
    // Keep graceful empty defaults if template read fails.
}

$messageStatusLabels = [
    'pre_event'  => ['Upcoming', 'msg-status-label-upcoming'],
    'live'       => ['Live', 'msg-status-label-live'],
    'post_event' => ['Ended', 'msg-status-label-ended'],
];

$pageTitle = 'Settings';
require __DIR__ . '/layout.php';
?>

<style>
.settings-wrap { max-width: 980px; margin: 0 auto; }
.settings-card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:24px; margin-bottom:20px; }
.settings-card h3 { margin:0 0 16px; }
.settings-row { margin: 16px 0; }
.settings-card > .settings-row + .settings-row {
    padding-top: 14px;
    border-top: 1px solid #232331;
}
.settings-label { display:block; margin-bottom:6px; color:#cfd0da; font-weight:600; }
.settings-input, .settings-select {
    width:100%;
    box-sizing: border-box;
    border:1px solid #2a2a3a;
    border-radius:8px;
    padding:10px 12px;
    background:#0e0f17;
    color:#fff;
}
.settings-check { margin-right:8px; }
.settings-help { color:#b7b7c8; font-size:14px; margin-top:6px; }
.settings-btn { background:#ff2fd2; color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; }
.settings-ok { color:#7be87f; margin-bottom:10px; }
.settings-err { color:#ff8080; margin-bottom:10px; }
.settings-divider {
    border: 0;
    border-top: 1px solid #2a2a3a;
    margin: 18px 0;
}
.settings-section-label {
    margin: 0 0 10px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #9fa1b5;
}
.event-defaults-grid {
    display: grid;
    gap: 12px;
}
.event-defaults-subcard {
    background: #13131a;
    border: 1px solid #252531;
    border-radius: 12px;
    padding: 16px;
}
.event-defaults-subcard h4 {
    margin: 0 0 10px;
    font-size: 14px;
    color: #d6d7e2;
}
.event-defaults-subcard .settings-row + .settings-row {
    padding-top: 14px;
    border-top: 1px solid #232331;
}
.status-pill {
    display:inline-block;
    padding:4px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    margin-left:6px;
}
.status-on { background:rgba(59,214,107,0.2); color:#8affb1; border:1px solid rgba(59,214,107,0.45); }
.status-off { background:rgba(255,75,75,0.2); color:#ff9b9b; border:1px solid rgba(255,75,75,0.45); }
.msg-status-grid {
    display: grid;
    gap: 12px;
}
.msg-status-item {
    background: #13131a;
    border: 1px solid #252531;
    border-radius: 12px;
    padding: 14px;
}
.msg-status-label {
    display: inline-block;
    font-size: 12px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    margin-bottom: 10px;
}
.msg-status-label-upcoming { background: rgba(0,153,255,0.15); color: #6cc6ff; }
.msg-status-label-live { background: rgba(95,219,110,0.18); color: #5fdb6e; }
.msg-status-label-ended { background: rgba(255,87,87,0.15); color: #ff8b8b; }
.msg-status-field { margin: 8px 0; }
.msg-status-field strong { display:block; margin-bottom:6px; color:#9aa0aa; font-size:12px; }
.msg-status-box {
    background: #0f0f14;
    border: 1px solid #2b2b36;
    border-radius: 8px;
    padding: 10px 12px;
    color: #e8e8f2;
    font-size: 14px;
    white-space: pre-wrap;
}
.settings-after-save-gap {
    margin-top: 18px;
}
.settings-copy-row {
    display:flex;
    align-items:center;
    gap:10px;
}
.settings-copy-row .settings-input {
    flex:1;
    width:auto;
}
.settings-copy-feedback {
    display:none;
    font-size:12px;
    color:#5fdb6e;
    margin-top:6px;
}
.premium-badge {
    display:inline-block;
    margin-left:8px;
    padding:2px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
    background:rgba(255,47,210,0.18);
    border:1px solid rgba(255,47,210,0.55);
    color:#ff7de8;
    vertical-align:middle;
}
.premium-lock-tip {
    margin-left:6px;
    font-size:13px;
    color:#ffb3ef;
    cursor:help;
}
.admin-badge {
    display:inline-block;
    margin-left:8px;
    padding:2px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
    background:rgba(106,227,255,0.16);
    border:1px solid rgba(106,227,255,0.45);
    color:#9aeaff;
    vertical-align:middle;
}
.settings-wrap {
    display:flex;
    flex-direction:column;
}
.settings-order-admin { order: 10; }
.settings-order-profile { order: 20; }
.settings-order-platform { order: 30; }
.settings-order-dynamic { order: 40; }
.settings-order-qr { order: 50; }
.settings-order-messages { order: 60; }
#premium-qr-style .qr-tab-pane {
    gap: 14px !important;
    margin-top: 4px;
}
#premium-qr-style .preview-tab-pane {
    margin-top: 10px;
    padding-top: 12px;
    border-top: 1px solid #232331;
}
</style>

<div class="settings-wrap">
    <p style="margin:0 0 8px;">
        <a href="/dj/dashboard.php" style="color:#ff2fd2; text-decoration:none;">&larr; Back to Dashboard</a>
    </p>
    <h1>Settings</h1>

    <?php if ($error !== ''): ?><div class="settings-err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="settings-ok"><?php echo e($success); ?></div><?php endif; ?>

    <?php if ($isAdminUser): ?>
    <div class="settings-card settings-order-admin">
        <h3>Admin Plan Simulation <span class="admin-badge">Admin</span></h3>
        <div class="settings-help" style="margin-top:0;">
            Simulate <strong>Pro</strong> or <strong>Premium</strong> for your current account to preview feature locks and badges.
        </div>
        <form method="POST" style="margin-top:10px;">
            <?php echo csrf_field(); ?>
            <div class="settings-copy-row">
                <select class="settings-select" name="admin_plan_simulation" style="max-width:240px;">
                    <option value="auto" <?php echo $activeSimulation === null ? 'selected' : ''; ?>>Auto (real plan)</option>
                    <option value="pro" <?php echo $activeSimulation === 'pro' ? 'selected' : ''; ?>>Force Pro</option>
                    <option value="premium" <?php echo $activeSimulation === 'premium' ? 'selected' : ''; ?>>Force Premium</option>
                </select>
                <button type="submit" class="settings-btn">Apply</button>
            </div>
        </form>
        <div class="settings-help">
            Real plan: <strong><?php echo e(strtoupper($basePlan)); ?></strong> ·
            Effective plan: <strong><?php echo e(strtoupper($effectivePlan)); ?></strong>
        </div>
    </div>
    <?php endif; ?>

    <div class="settings-card settings-order-platform">
        <h3>Platform Status</h3>
        <div class="settings-row">
            MyDJRequests tips/boosts for patrons is currently:
            <?php if ($prodEnabled): ?>
                <span class="status-pill status-on">ENABLED</span>
            <?php else: ?>
                <span class="status-pill status-off">DISABLED</span>
            <?php endif; ?>
        </div>
        <div class="settings-help">
            Your default tip/boost preference is saved above in the Event Defaults section, but live visibility is controlled by this global platform setting managed by MyDJRequests.
        </div>
    </div>

    <div class="settings-card settings-order-dynamic" id="dynamic-qr-link">
        <h3>
            Dynamic Event Links (Set &amp; Forget)
            <span class="premium-badge">Premium</span>
            <?php if (!$isPremiumPlan): ?>
                <span class="premium-lock-tip" title="Locked for Pro. Requires Premium subscription.">🔒</span>
            <?php endif; ?>
        </h3>
        <div class="settings-help" style="margin-top:0;">
            These are global, reusable live links that always route to your current LIVE event.
        </div>

        <?php if ($djUuid !== ''): ?>
            <div class="settings-row">
                <label class="settings-label" for="dynamic_obs_url">
                    Live QR Overlay URL (OBS Browser Source)
                    <span class="premium-badge">Premium</span>
                    <?php if (!$isPremiumPlan): ?>
                        <span class="premium-lock-tip" title="Locked for Pro. Requires Premium subscription.">🔒</span>
                    <?php endif; ?>
                </label>
                <?php if ($isPremiumPlan): ?>
                    <div class="settings-copy-row">
                        <input
                            class="settings-input"
                            id="dynamic_obs_url"
                            type="text"
                            readonly
                            value="<?php echo e($dynamicObsUrl); ?>"
                        >
                        <button type="button" class="settings-btn copy-btn" data-target="dynamic_obs_url" data-feedback="dynamicObsFeedback">Copy</button>
                    </div>
                    <div id="dynamicObsFeedback" class="settings-copy-feedback">Copied for OBS.</div>
                    <div class="settings-help">
                        Use this in OBS Browser Source to display a dynamic QR that follows your current LIVE event.
                    </div>
                <?php else: ?>
                    <div class="settings-help">
                        Available on <strong>Premium</strong>. Unlock dynamic OBS overlays that auto-follow your LIVE event.
                    </div>
                <?php endif; ?>
            </div>

            <div class="settings-row">
                <label class="settings-label" for="dynamic_live_patron_url">
                    Live Patron Page URL
                    <span class="premium-badge">Premium</span>
                    <?php if (!$isPremiumPlan): ?>
                        <span class="premium-lock-tip" title="Locked for Pro. Requires Premium subscription.">🔒</span>
                    <?php endif; ?>
                </label>
                <?php if ($isPremiumPlan): ?>
                    <div class="settings-copy-row">
                        <input
                            class="settings-input"
                            id="dynamic_live_patron_url"
                            type="text"
                            readonly
                            value="<?php echo e($dynamicLivePatronUrl); ?>"
                        >
                        <button type="button" class="settings-btn copy-btn" data-target="dynamic_live_patron_url" data-feedback="dynamicLivePatronFeedback">Copy</button>
                    </div>
                    <div id="dynamicLivePatronFeedback" class="settings-copy-feedback">Copied live patron URL.</div>
                    <div class="settings-help">
                        Share one persistent URL that always routes guests to your currently LIVE event request page.
                    </div>
                <?php else: ?>
                    <div class="settings-help">
                        Available on <strong>Premium</strong>. Upgrade to share one persistent link that always routes to your current LIVE event.
                    </div>
                <?php endif; ?>
            </div>

            <div class="settings-help">
                DJ: <strong><?php echo e($djDisplay !== '' ? $djDisplay : ('User #' . $djId)); ?></strong>
            </div>
        <?php else: ?>
            <div class="settings-err" style="margin:10px 0 0;">
                Could not resolve your DJ UUID, so dynamic QR links are unavailable.
            </div>
        <?php endif; ?>
    </div>

    <div class="settings-card settings-order-qr" id="premium-qr-style">
        <h3>
            Global QR Style
            <span class="premium-badge">Premium</span>
            <?php if (!$isPremiumPlan): ?>
                <span class="premium-lock-tip" title="Locked for Pro. Requires Premium subscription.">🔒</span>
            <?php endif; ?>
        </h3>
        <?php if ($isPremiumPlan): ?>
            <div class="settings-help" style="margin-top:0;">
                This style applies to all event QR codes, downloads, and your live OBS QR overlay.
            </div>
            <form id="premiumGlobalQrForm" enctype="multipart/form-data" style="margin-top:12px;">
                <div id="qrStyleTabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                    <button type="button" class="settings-btn qr-tab-btn" data-tab="style" style="padding:8px 12px;background:#ff2fd2;">Style</button>
                    <button type="button" class="settings-btn qr-tab-btn" data-tab="color" style="padding:8px 12px;background:#2a2a3a;">Color</button>
                    <button type="button" class="settings-btn qr-tab-btn" data-tab="brand" style="padding:8px 12px;background:#2a2a3a;">Brand</button>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px;">
                    <button type="button" class="settings-btn qr-preset-btn" data-preset="neon" style="padding:7px 10px;background:#2a2a3a;">Neon</button>
                    <button type="button" class="settings-btn qr-preset-btn" data-preset="minimal" style="padding:7px 10px;background:#2a2a3a;">Minimal</button>
                    <button type="button" class="settings-btn qr-preset-btn" data-preset="mono" style="padding:7px 10px;background:#2a2a3a;">Monochrome</button>
                    <button type="button" class="settings-btn qr-preset-btn" data-preset="festival" style="padding:7px 10px;background:#2a2a3a;">Festival</button>
                </div>

                <div class="qr-tab-pane" data-pane="style" style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:10px;">
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Dot Style</span>
                        <select name="dot_style" class="settings-select">
                            <?php $dotStyleVal = (string)($globalQrSettings['dot_style'] ?? 'square'); ?>
                            <option value="square" <?php echo $dotStyleVal === 'square' ? 'selected' : ''; ?>>Square</option>
                            <option value="rounded" <?php echo $dotStyleVal === 'rounded' ? 'selected' : ''; ?>>Rounded</option>
                            <option value="circle" <?php echo $dotStyleVal === 'circle' ? 'selected' : ''; ?>>Circle</option>
                            <option value="extra-rounded" <?php echo $dotStyleVal === 'extra-rounded' ? 'selected' : ''; ?>>Extra-rounded</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Eye Outer Style</span>
                        <select name="eye_outer_style" class="settings-select">
                            <?php $eyeOuterVal = (string)($globalQrSettings['eye_outer_style'] ?? 'square'); ?>
                            <option value="square" <?php echo $eyeOuterVal === 'square' ? 'selected' : ''; ?>>Square</option>
                            <option value="rounded" <?php echo $eyeOuterVal === 'rounded' ? 'selected' : ''; ?>>Rounded</option>
                            <option value="circle" <?php echo $eyeOuterVal === 'circle' ? 'selected' : ''; ?>>Circle</option>
                            <option value="extra-rounded" <?php echo $eyeOuterVal === 'extra-rounded' ? 'selected' : ''; ?>>Extra-rounded</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Eye Inner Style</span>
                        <select name="eye_inner_style" class="settings-select">
                            <?php $eyeInnerVal = (string)($globalQrSettings['eye_inner_style'] ?? 'square'); ?>
                            <option value="square" <?php echo $eyeInnerVal === 'square' ? 'selected' : ''; ?>>Square</option>
                            <option value="rounded" <?php echo $eyeInnerVal === 'rounded' ? 'selected' : ''; ?>>Rounded</option>
                            <option value="circle" <?php echo $eyeInnerVal === 'circle' ? 'selected' : ''; ?>>Circle</option>
                            <option value="extra-rounded" <?php echo $eyeInnerVal === 'extra-rounded' ? 'selected' : ''; ?>>Extra-rounded</option>
                        </select>
                    </label>
                </div>

                <div class="qr-tab-pane" data-pane="color" style="display:none;grid-template-columns:repeat(2,minmax(220px,1fr));gap:10px;">
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Fill Mode</span>
                        <?php $fillModeVal = (string)($globalQrSettings['fill_mode'] ?? 'solid'); ?>
                        <select name="fill_mode" id="qrFillMode" class="settings-select">
                            <option value="solid" <?php echo $fillModeVal === 'solid' ? 'selected' : ''; ?>>Solid</option>
                            <option value="linear" <?php echo $fillModeVal === 'linear' ? 'selected' : ''; ?>>Linear Gradient</option>
                            <option value="radial" <?php echo $fillModeVal === 'radial' ? 'selected' : ''; ?>>Radial Gradient</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Foreground</span>
                        <input name="foreground_color" type="color" value="<?php echo e((string)($globalQrSettings['foreground_color'] ?? '#000000')); ?>" style="height:42px;">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Background</span>
                        <input name="background_color" type="color" value="<?php echo e((string)($globalQrSettings['background_color'] ?? '#ffffff')); ?>" style="height:42px;">
                    </label>
                    <label id="gradientStartWrap" style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Gradient Start</span>
                        <input name="gradient_start" type="color" value="<?php echo e((string)($globalQrSettings['gradient_start'] ?? '#000000')); ?>" style="height:42px;">
                    </label>
                    <label id="gradientEndWrap" style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Gradient End</span>
                        <input name="gradient_end" type="color" value="<?php echo e((string)($globalQrSettings['gradient_end'] ?? '#ff2fd2')); ?>" style="height:42px;">
                    </label>
                    <label id="gradientAngleWrap" style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Gradient Angle (Linear)</span>
                        <input name="gradient_angle" type="number" min="0" max="360" value="<?php echo (int)($globalQrSettings['gradient_angle'] ?? 45); ?>" class="settings-input">
                    </label>
                </div>

                <div class="qr-tab-pane" data-pane="brand" style="display:none;grid-template-columns:repeat(2,minmax(220px,1fr));gap:10px;">
                    <?php
                        $globalSizeVal = (int)($globalQrSettings['image_size'] ?? 480);
                        $obsSizeVal = (int)($globalQrSettings['obs_image_size'] ?? 600);
                        $posterSizeVal = (int)($globalQrSettings['poster_image_size'] ?? 900);
                        $mobileSizeVal = (int)($globalQrSettings['mobile_image_size'] ?? 480);
                        $obsScaleVal = (int)($globalQrSettings['obs_qr_scale_pct'] ?? 100);
                        $posterScaleVal = (int)($globalQrSettings['poster_qr_scale_pct'] ?? 48);
                        $useGlobalOutputSizing = ($obsSizeVal === $globalSizeVal && $posterSizeVal === $globalSizeVal && $mobileSizeVal === $globalSizeVal);
                    ?>
                    <input type="hidden" name="obs_image_size" value="<?php echo $obsSizeVal; ?>">
                    <input type="hidden" name="poster_image_size" value="<?php echo $posterSizeVal; ?>">
                    <input type="hidden" name="mobile_image_size" value="<?php echo $mobileSizeVal; ?>">
                    <input type="hidden" name="obs_qr_scale_pct" value="<?php echo $obsScaleVal; ?>">
                    <input type="hidden" name="poster_qr_scale_pct" value="<?php echo $posterScaleVal; ?>">
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Logo Size (%)</span>
                        <input name="logo_scale_pct" type="number" min="8" max="20" value="<?php echo (int)($globalQrSettings['logo_scale_pct'] ?? 18); ?>" class="settings-input">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Image Size</span>
                        <input name="image_size" type="number" min="220" max="1200" value="<?php echo (int)($globalQrSettings['image_size'] ?? 480); ?>" class="settings-input">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:12px;color:#aaa;">Center Logo (PNG/JPG/WEBP)</span>
                        <input name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="settings-input">
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;margin-top:12px;color:#ddd;">
                        <input type="checkbox" name="remove_logo" value="1">
                        Remove existing logo
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;margin-top:12px;color:#ddd;">
                        <input type="checkbox" name="animated_overlay" value="1" <?php echo !empty($globalQrSettings['animated_overlay']) ? 'checked' : ''; ?>>
                        Animated OBS overlay shimmer
                        <span class="premium-badge" style="font-size:10px;padding:2px 7px;">Premium</span>
                    </label>
                </div>

            <div style="margin-top:14px;">
                <div class="settings-label" style="margin-bottom:8px;">Preview Studio</div>
                <?php if ($previewEventUuid !== ''): ?>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                        <button type="button" class="settings-btn preview-tab-btn" data-preview-tab="global" style="padding:7px 11px;background:#ff2fd2;">Global</button>
                        <button type="button" class="settings-btn preview-tab-btn" data-preview-tab="obs" style="padding:7px 11px;background:#2a2a3a;">OBS</button>
                        <button type="button" class="settings-btn preview-tab-btn" data-preview-tab="poster" style="padding:7px 11px;background:#2a2a3a;">Poster</button>
                    </div>

                    <div class="preview-tab-pane" data-preview-pane="global">
                        <div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:10px;margin-bottom:10px;">
                            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;color:#ddd;grid-column:1/-1;">
                                <input type="checkbox" id="useGlobalOutputSize" name="use_global_output_size" value="1" <?php echo $useGlobalOutputSizing ? 'checked' : ''; ?>>
                                Use global size for all outputs (recommended)
                            </label>
                            <label style="display:flex;flex-direction:column;gap:6px;">
                                <span style="font-size:12px;color:#aaa;">Mobile Download Size (render px)</span>
                                <input id="mobile_image_size_proxy" type="number" min="220" max="900" class="settings-input" value="<?php echo $mobileSizeVal; ?>">
                            </label>
                            <div style="font-size:12px;color:#8f95a3;line-height:1.4;">
                                <strong style="color:#cfd3df;">How this works:</strong><br>
                                Keep this ON for simple setup. When ON, OBS/Poster/Mobile use your single Global `Image Size` from Brand tab. When OFF, each tab can use separate render size.
                            </div>
                        </div>
                        <img
                            id="globalQrPreview"
                            src="<?php echo e(url('qr/premium_generate.php?uuid=' . urlencode($previewEventUuid) . '&size=360&preview=1&preview_url=' . urlencode($previewTargetUrl))); ?>"
                            data-preview-target="<?php echo e($previewTargetUrl); ?>"
                            alt="QR preview"
                            style="width:220px;height:220px;border-radius:12px;border:1px solid #2a2a3a;background:#0f0f14;display:block;"
                        >
                        <div class="settings-help" style="margin-top:8px;">
                            <?php if ($previewHasLiveEvent): ?>
                                Preview scans to your current LIVE event.
                            <?php else: ?>
                                No LIVE event currently. Preview scans to MyDJRequests.com homepage.
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="preview-tab-pane" data-preview-pane="obs" style="display:none;">
                        <div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:10px;margin-bottom:10px;">
                            <label style="display:flex;flex-direction:column;gap:6px;">
                                <span style="font-size:12px;color:#aaa;">OBS QR Size (render px)</span>
                                <input id="obs_image_size_proxy" type="number" min="320" max="1400" class="settings-input" value="<?php echo (int)($globalQrSettings['obs_image_size'] ?? 600); ?>">
                            </label>
                            <label style="display:flex;flex-direction:column;gap:6px;">
                                <span style="font-size:12px;color:#aaa;">OBS QR Display Scale (%)</span>
                                <input id="obs_qr_scale_pct_proxy" type="number" min="70" max="115" class="settings-input" value="<?php echo (int)($globalQrSettings['obs_qr_scale_pct'] ?? 100); ?>">
                            </label>
                            <div style="font-size:12px;color:#8f95a3;line-height:1.4;grid-column:1/-1;">
                                `Render px` controls output resolution/sharpness for OBS Browser Source. `Display Scale` controls how large the QR appears inside the OBS tile. Recommended: `600-900px` render, `90-105%` display.
                            </div>
                        </div>
                        <div style="padding:8px;border:1px solid #2a2a3a;border-radius:10px;background:#10111a;display:inline-block;">
                            <div style="font-size:11px;color:#aeb4c3;margin-bottom:6px;">OBS Layout Preview</div>
                            <div style="width:120px;height:168px;border-radius:10px;background:#0b0c13;border:1px solid #2a2a3a;position:relative;overflow:hidden;">
                                <div style="position:absolute;left:0;right:0;top:0;height:24px;background:linear-gradient(90deg,rgba(255,47,210,.35),rgba(106,227,255,.35));"></div>
                                <div id="obsSizePreviewBox" style="position:absolute;left:50%;top:30px;transform:translateX(-50%);width:78%;aspect-ratio:1/1;border-radius:8px;background:#f2f2f2;border:1px solid #444;"></div>
                            </div>
                            <div id="obsSizePreviewMeta" style="font-size:10px;color:#9ba2b3;margin-top:6px;">Render 600px, Display 100%</div>
                        </div>
                    </div>

                    <div class="preview-tab-pane" data-preview-pane="poster" style="display:none;">
                        <div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:10px;margin-bottom:10px;">
                            <label style="display:flex;flex-direction:column;gap:6px;">
                                <span style="font-size:12px;color:#aaa;">Poster QR Size (render px)</span>
                                <input id="poster_image_size_proxy" type="number" min="600" max="1800" class="settings-input" value="<?php echo (int)($globalQrSettings['poster_image_size'] ?? 900); ?>">
                            </label>
                            <label style="display:flex;flex-direction:column;gap:6px;">
                                <span style="font-size:12px;color:#aaa;">Poster QR Fill Scale (%)</span>
                                <input id="poster_qr_scale_pct_proxy" type="number" min="30" max="75" class="settings-input" value="<?php echo (int)($globalQrSettings['poster_qr_scale_pct'] ?? 48); ?>">
                            </label>
                            <div style="font-size:12px;color:#8f95a3;line-height:1.4;grid-column:1/-1;">
                                `Render px` sets QR source detail used in the A4 poster export. `Fill Scale` sets how much of the poster width the QR occupies. Recommended: `900-1200px` render, `45-55%` fill.
                            </div>
                        </div>
                        <div style="padding:8px;border:1px solid #2a2a3a;border-radius:10px;background:#10111a;display:inline-block;">
                            <div style="font-size:11px;color:#aeb4c3;margin-bottom:6px;">A4 Poster QR Fill Preview</div>
                            <div style="width:120px;height:170px;border-radius:6px;background:#fff;border:1px solid #cfd3df;position:relative;overflow:hidden;">
                                <div id="posterSizePreviewBox" style="position:absolute;left:50%;top:38px;transform:translateX(-50%);width:48%;aspect-ratio:1/1;border-radius:4px;background:#dedede;border:1px solid #999;"></div>
                            </div>
                            <div id="posterSizePreviewMeta" style="font-size:10px;color:#9ba2b3;margin-top:6px;">Render 900px, Fill 48%</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="settings-help">Create at least one event to enable QR preview.</div>
                <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap;">
                <button type="submit" id="saveGlobalQrBtn" class="settings-btn">Save Global QR Style</button>
                <button type="button" id="resetGlobalQrBtn" class="settings-btn" style="background:#2a2a3a;">Reset to Default</button>
                <span id="globalQrStatus" style="font-size:12px;color:#8f95a3;"></span>
            </div>
            <?php if ($isAdminUser): ?>
                <div id="scanHealthWrap" style="margin-top:10px;padding:10px 12px;border:1px solid #2a2a3a;border-radius:10px;background:#11121a;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <strong style="color:#d9dbe6;">Scan Health <span class="admin-badge">Admin</span></strong>
                        <span id="scanHealthBadge" style="font-size:11px;padding:4px 8px;border-radius:999px;background:#1f3f28;color:#8dffb2;">Excellent</span>
                        <span id="scanHealthScore" style="font-size:12px;color:#9aa1b3;">Score: 95/100</span>
                    </div>
                    <div id="scanHealthDetail" style="font-size:12px;color:#8f95a3;margin-top:4px;">Contrast and style look scan-safe.</div>
                </div>
            <?php endif; ?>
            </form>
        <?php else: ?>
            <div class="settings-help" style="margin-top:0;">
                Available on <strong>Premium</strong>. Set one global branded QR style that applies across all events and live overlay outputs.
            </div>
        <?php endif; ?>
    </div>

    <form method="POST" class="settings-order-profile">
        <?php echo csrf_field(); ?>

        <div class="settings-card">
            <h3>DJ Software</h3>

            <div class="settings-row">
                <label class="settings-label" for="dj_software">Which DJ software platform do you use?</label>
                <select class="settings-select" id="dj_software" name="dj_software" required>
                    <option value="">Select your platform</option>
                    <option value="rekordbox" <?php echo (($profile['dj_software'] ?? '') === 'rekordbox') ? 'selected' : ''; ?>>Rekordbox</option>
                    <option value="serato" <?php echo (($profile['dj_software'] ?? '') === 'serato') ? 'selected' : ''; ?>>Serato</option>
                    <option value="traktor" <?php echo (($profile['dj_software'] ?? '') === 'traktor') ? 'selected' : ''; ?>>Traktor</option>
                    <option value="virtualdj" <?php echo (($profile['dj_software'] ?? '') === 'virtualdj') ? 'selected' : ''; ?>>VirtualDJ</option>
                    <option value="djay" <?php echo (($profile['dj_software'] ?? '') === 'djay') ? 'selected' : ''; ?>>djay / djay Pro</option>
                    <option value="other" <?php echo (($profile['dj_software'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>

            <div class="settings-row" id="djSoftwareOtherRow" style="<?php echo (($profile['dj_software'] ?? '') === 'other') ? '' : 'display:none;'; ?>">
                <label class="settings-label" for="dj_software_other">Other software</label>
                <input
                    class="settings-input"
                    type="text"
                    id="dj_software_other"
                    name="dj_software_other"
                    maxlength="255"
                    value="<?php echo e((string)($profile['dj_software_other'] ?? '')); ?>"
                    placeholder="Enter your DJ software"
                >
            </div>
        </div>

        <div class="settings-card">
            <h3>Music Subscriptions</h3>

            <div class="settings-row">
                <label><input class="settings-check" type="checkbox" name="has_spotify" value="1" <?php echo !empty($profile['has_spotify']) ? 'checked' : ''; ?>>Spotify Premium</label>
            </div>
            <div class="settings-row">
                <label><input class="settings-check" type="checkbox" name="has_apple_music" value="1" <?php echo !empty($profile['has_apple_music']) ? 'checked' : ''; ?>>Apple Music</label>
            </div>
            <div class="settings-row">
                <label><input class="settings-check" type="checkbox" name="has_beatport" value="1" <?php echo !empty($profile['has_beatport']) ? 'checked' : ''; ?>>Beatport</label>
            </div>
        </div>

        <div class="settings-card">
            <h3>Event Defaults</h3>

            <div class="event-defaults-grid">
                <div class="event-defaults-subcard">
                    <h4>Tips Defaults</h4>
                    <div class="settings-row">
                        <label>
                            <input class="settings-check" type="checkbox" name="default_tips_boost_enabled" value="1" <?php echo $defaultTipsBoostEnabled ? 'checked' : ''; ?>>
                            Enable tips/boosts by default when creating new events
                        </label>
                        <div class="settings-help">You can still override this per event.</div>
                    </div>
                </div>

                <div class="event-defaults-subcard">
                    <h4>Broadcast Defaults</h4>
                    <div class="settings-row">
                        <label>
                            <input class="settings-check" type="checkbox" name="default_event_broadcast_enabled" value="1" <?php echo $defaultEventBroadcastEnabled ? 'checked' : ''; ?>>
                            Enable broadcast message by default on new events
                        </label>
                        <div class="settings-help">Default platform message stays available to explain how requests work across event tabs. Turn off only if you want no default broadcast on new events.</div>
                    </div>

                    <div class="settings-row">
                        <label class="settings-label">Broadcast Message Mode</label>
                        <label>
                            <input class="settings-check" type="radio" name="default_event_broadcast_mode" value="default" <?php echo $defaultEventBroadcastMode === 'default' ? 'checked' : ''; ?>>
                            Use MyDJRequests default message (locked)
                        </label>
                        <label style="display:block; margin-top:8px;">
                            <input class="settings-check" type="radio" name="default_event_broadcast_mode" value="custom" <?php echo $defaultEventBroadcastMode === 'custom' ? 'checked' : ''; ?> <?php echo !$isPremiumPlan ? 'disabled' : ''; ?>>
                            Use my custom message
                            <span class="premium-badge">Premium</span>
                            <?php if (!$isPremiumPlan): ?>
                                <span class="premium-lock-tip" title="Locked for Pro. Requires Premium subscription.">🔒</span>
                            <?php endif; ?>
                        </label>
                        <?php if (!$isPremiumPlan): ?>
                            <div class="settings-help">Custom broadcast message is a Premium feature. Pro uses the default platform message.</div>
                        <?php endif; ?>
                    </div>

                    <div class="settings-row" id="defaultBroadcastPreviewRow">
                        <label class="settings-label" for="default_event_broadcast_message_preview">
                            MyDJRequests Default Message
                        </label>
                        <textarea
                            class="settings-input"
                            id="default_event_broadcast_message_preview"
                            rows="13"
                            readonly
                        ><?php echo e($defaultBroadcastTemplate); ?></textarea>
                    </div>

                    <div class="settings-row" id="customBroadcastRow">
                        <label class="settings-label" for="default_event_broadcast_message">
                            Custom Event Broadcast Message
                        </label>
                        <textarea
                            class="settings-input"
                            id="default_event_broadcast_message"
                            name="default_event_broadcast_message"
                            rows="8"
                            maxlength="2000"
                            placeholder="Welcome to {{EVENT_NAME}} with {{DJ_NAME}}..."
                        ><?php echo e($defaultEventBroadcastMode === 'custom' ? $defaultEventBroadcastMessage : ''); ?></textarea>
                        <div class="settings-help">Variables supported: <code>{{DJ_NAME}}</code>, <code>{{EVENT_NAME}}</code>.</div>
                    </div>
                </div>
            </div>

        </div>

        <button type="submit" class="settings-btn" style="margin-bottom:16px;">Save Settings</button>
    </form>

    <div class="settings-card settings-after-save-gap settings-order-messages" id="message-statuses">
        <h3>Default Platform Messages to Patron (Home Tab)</h3>
        <div class="settings-help" style="margin-top:0;">
            Read-only platform defaults shown to patrons based on event state.
        </div>
        <div class="msg-status-grid" style="margin-top:12px;">
            <?php foreach ($messageStatusLabels as $type => $meta): ?>
                <?php
                    $title = $messageStatusTemplates[$type]['title'] ?? '';
                    $body = $messageStatusTemplates[$type]['body'] ?? '';
                ?>
                <div class="msg-status-item">
                    <div class="msg-status-label <?php echo e($meta[1]); ?>"><?php echo e($meta[0]); ?></div>
                    <div class="msg-status-field">
                        <strong>Title</strong>
                        <div class="msg-status-box"><?php echo e($title !== '' ? $title : 'No default title set'); ?></div>
                    </div>
                    <div class="msg-status-field">
                        <strong>Message</strong>
                        <div class="msg-status-box"><?php echo e($body !== '' ? $body : 'No default message set'); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="settings-help">Variables supported: <code>{{DJ_NAME}}</code>, <code>{{EVENT_NAME}}</code>.</div>
    </div>
</div>

<script>
(function () {
    var software = document.getElementById('dj_software');
    var otherRow = document.getElementById('djSoftwareOtherRow');
    var otherInput = document.getElementById('dj_software_other');
    var broadcastEnabled = document.querySelector('input[name="default_event_broadcast_enabled"]');
    var modeDefault = document.querySelector('input[name="default_event_broadcast_mode"][value="default"]');
    var modeCustom = document.querySelector('input[name="default_event_broadcast_mode"][value="custom"]');
    var defaultPreviewRow = document.getElementById('defaultBroadcastPreviewRow');
    var customRow = document.getElementById('customBroadcastRow');
    var customInput = document.getElementById('default_event_broadcast_message');
    if (!software || !otherRow || !otherInput) return;

    function syncOtherField() {
        if (software.value === 'other') {
            otherRow.style.display = '';
            otherInput.required = true;
        } else {
            otherRow.style.display = 'none';
            otherInput.required = false;
            otherInput.value = '';
        }
    }

    software.addEventListener('change', syncOtherField);
    syncOtherField();

    function syncBroadcastMode() {
        var enabled = !!(broadcastEnabled && broadcastEnabled.checked);
        var customMode = !!(modeCustom && modeCustom.checked);

        if (defaultPreviewRow) {
            defaultPreviewRow.style.display = enabled ? '' : 'none';
        }
        if (customRow) {
            customRow.style.display = (enabled && customMode) ? '' : 'none';
        }
        if (customInput) {
            customInput.disabled = !(enabled && customMode);
            customInput.required = !!(enabled && customMode);
        }
    }

    if (broadcastEnabled) {
        broadcastEnabled.addEventListener('change', syncBroadcastMode);
    }
    if (modeDefault) {
        modeDefault.addEventListener('change', syncBroadcastMode);
    }
    if (modeCustom) {
        modeCustom.addEventListener('change', syncBroadcastMode);
    }
    syncBroadcastMode();
})();
</script>

<script>
(function () {
    const form = document.getElementById('premiumGlobalQrForm');
    const statusEl = document.getElementById('globalQrStatus');
    const saveBtn = document.getElementById('saveGlobalQrBtn');
    const resetBtn = document.getElementById('resetGlobalQrBtn');
    const previewImg = document.getElementById('globalQrPreview');
    const fillModeEl = document.getElementById('qrFillMode');
    const gradientStartWrap = document.getElementById('gradientStartWrap');
    const gradientEndWrap = document.getElementById('gradientEndWrap');
    const gradientAngleWrap = document.getElementById('gradientAngleWrap');
    const tabButtons = Array.from(document.querySelectorAll('.qr-tab-btn'));
    const tabPanes = Array.from(document.querySelectorAll('.qr-tab-pane'));
    const presetButtons = Array.from(document.querySelectorAll('.qr-preset-btn'));
    const scanHealthBadge = document.getElementById('scanHealthBadge');
    const scanHealthScore = document.getElementById('scanHealthScore');
    const scanHealthDetail = document.getElementById('scanHealthDetail');
    const useGlobalOutputSizeEl = document.getElementById('useGlobalOutputSize');
    const obsSizePreviewBox = document.getElementById('obsSizePreviewBox');
    const posterSizePreviewBox = document.getElementById('posterSizePreviewBox');
    const obsSizePreviewMeta = document.getElementById('obsSizePreviewMeta');
    const posterSizePreviewMeta = document.getElementById('posterSizePreviewMeta');
    const previewTabButtons = Array.from(document.querySelectorAll('.preview-tab-btn'));
    const previewTabPanes = Array.from(document.querySelectorAll('.preview-tab-pane'));
    const obsRenderProxy = document.getElementById('obs_image_size_proxy');
    const obsScaleProxy = document.getElementById('obs_qr_scale_pct_proxy');
    const posterRenderProxy = document.getElementById('poster_image_size_proxy');
    const posterScaleProxy = document.getElementById('poster_qr_scale_pct_proxy');
    const mobileRenderProxy = document.getElementById('mobile_image_size_proxy');
    if (!form || !statusEl || !saveBtn) return;

    let isContrastBlocked = false;

    function hexToRgb(hex) {
        const raw = String(hex || '').replace('#', '').trim();
        if (!/^[0-9a-fA-F]{6}$/.test(raw)) return [0, 0, 0];
        return [
            parseInt(raw.slice(0, 2), 16),
            parseInt(raw.slice(2, 4), 16),
            parseInt(raw.slice(4, 6), 16)
        ];
    }

    function relLum(rgb) {
        const f = (v) => {
            const s = v / 255;
            return s <= 0.03928 ? (s / 12.92) : Math.pow((s + 0.055) / 1.055, 2.4);
        };
        return (0.2126 * f(rgb[0])) + (0.7152 * f(rgb[1])) + (0.0722 * f(rgb[2]));
    }

    function contrastRatio(hexA, hexB) {
        const l1 = relLum(hexToRgb(hexA));
        const l2 = relLum(hexToRgb(hexB));
        const light = Math.max(l1, l2);
        const dark = Math.min(l1, l2);
        return (light + 0.05) / (dark + 0.05);
    }

    function evaluateScanHealth() {
        const fg = form.querySelector('input[name="foreground_color"]')?.value || '#000000';
        const bg = form.querySelector('input[name="background_color"]')?.value || '#ffffff';
        const fillMode = form.querySelector('select[name="fill_mode"]')?.value || 'solid';
        const gs = form.querySelector('input[name="gradient_start"]')?.value || '#000000';
        const ge = form.querySelector('input[name="gradient_end"]')?.value || '#ff2fd2';
        const logoScale = parseInt(form.querySelector('input[name="logo_scale_pct"]')?.value || '18', 10) || 18;
        const dotStyle = form.querySelector('select[name="dot_style"]')?.value || 'square';
        const cFgBg = contrastRatio(fg, bg);
        const cGsBg = contrastRatio(gs, bg);
        const cGeBg = contrastRatio(ge, bg);
        const minContrast = fillMode === 'solid' ? cFgBg : Math.min(cGsBg, cGeBg, cFgBg);

        let score = Math.round(Math.min(100, (minContrast / 7) * 100));
        if (logoScale >= 19) score -= 10;
        if (dotStyle === 'circle') score -= 8;
        if (dotStyle === 'extra-rounded') score -= 5;
        if (fillMode !== 'solid') score -= 5;
        score = Math.max(0, Math.min(100, score));

        let label = 'Excellent';
        let detail = 'Contrast and style look scan-safe.';
        let badgeBg = '#1f3f28';
        let badgeColor = '#8dffb2';
        isContrastBlocked = false;

        if (minContrast < 2.2) {
            label = 'Blocked';
            detail = 'Low contrast detected. Increase foreground/gradient contrast before saving.';
            badgeBg = '#3f1f24';
            badgeColor = '#ff9ca8';
            isContrastBlocked = true;
        } else if (score < 55) {
            label = 'Risky';
            detail = 'Likely hard to scan on some phones. Increase contrast or simplify style.';
            badgeBg = '#3f321f';
            badgeColor = '#ffd692';
        } else if (score < 75) {
            label = 'Good';
            detail = 'Generally scannable. Test with a second phone before going live.';
            badgeBg = '#24344a';
            badgeColor = '#9bc8ff';
        }

        if (scanHealthBadge) {
            scanHealthBadge.textContent = label;
            scanHealthBadge.style.background = badgeBg;
            scanHealthBadge.style.color = badgeColor;
        }
        if (scanHealthScore) {
            scanHealthScore.textContent = 'Score: ' + score + '/100';
        }
        if (scanHealthDetail) {
            scanHealthDetail.textContent = detail + ' (Min contrast ' + minContrast.toFixed(2) + ':1)';
        }

        return { score, minContrast, blocked: isContrastBlocked };
    }

    function applyPreset(name) {
        const set = (selector, value) => {
            const el = form.querySelector(selector);
            if (!el) return;
            if (el.type === 'checkbox') {
                el.checked = !!value;
            } else {
                el.value = value;
            }
        };
        if (name === 'neon') {
            set('select[name="dot_style"]', 'circle');
            set('select[name="eye_outer_style"]', 'rounded');
            set('select[name="eye_inner_style"]', 'rounded');
            set('select[name="fill_mode"]', 'linear');
            set('input[name="foreground_color"]', '#2E1FFF');
            set('input[name="background_color"]', '#F2F2F2');
            set('input[name="gradient_start"]', '#2E1FFF');
            set('input[name="gradient_end"]', '#FF43D3');
            set('input[name="gradient_angle"]', '35');
        } else if (name === 'minimal') {
            set('select[name="dot_style"]', 'square');
            set('select[name="eye_outer_style"]', 'square');
            set('select[name="eye_inner_style"]', 'square');
            set('select[name="fill_mode"]', 'solid');
            set('input[name="foreground_color"]', '#111111');
            set('input[name="background_color"]', '#FFFFFF');
            set('input[name="gradient_start"]', '#111111');
            set('input[name="gradient_end"]', '#111111');
            set('input[name="gradient_angle"]', '45');
        } else if (name === 'mono') {
            set('select[name="dot_style"]', 'rounded');
            set('select[name="eye_outer_style"]', 'square');
            set('select[name="eye_inner_style"]', 'square');
            set('select[name="fill_mode"]', 'solid');
            set('input[name="foreground_color"]', '#000000');
            set('input[name="background_color"]', '#FFFFFF');
            set('input[name="gradient_start"]', '#000000');
            set('input[name="gradient_end"]', '#000000');
            set('input[name="gradient_angle"]', '45');
        } else if (name === 'festival') {
            set('select[name="dot_style"]', 'extra-rounded');
            set('select[name="eye_outer_style"]', 'circle');
            set('select[name="eye_inner_style"]', 'rounded');
            set('select[name="fill_mode"]', 'radial');
            set('input[name="foreground_color"]', '#2417FF');
            set('input[name="background_color"]', '#F5F5F5');
            set('input[name="gradient_start"]', '#1D36FF');
            set('input[name="gradient_end"]', '#FF39C7');
            set('input[name="gradient_angle"]', '45');
        }
        syncGradientVisibility();
        evaluateScanHealth();
        updatePreview();
    }

    function setActiveTab(tabName) {
        tabButtons.forEach((btn) => {
            const isActive = btn.getAttribute('data-tab') === tabName;
            btn.style.background = isActive ? '#ff2fd2' : '#2a2a3a';
        });
        tabPanes.forEach((pane) => {
            pane.style.display = pane.getAttribute('data-pane') === tabName ? 'grid' : 'none';
        });
    }

    function syncGradientVisibility() {
        if (!fillModeEl) return;
        const mode = fillModeEl.value || 'solid';
        const showGradientFields = mode === 'linear' || mode === 'radial';
        if (gradientStartWrap) gradientStartWrap.style.display = showGradientFields ? 'flex' : 'none';
        if (gradientEndWrap) gradientEndWrap.style.display = showGradientFields ? 'flex' : 'none';
        if (gradientAngleWrap) gradientAngleWrap.style.display = mode === 'linear' ? 'flex' : 'none';
    }

    function syncOutputSizingVisibility() {
        if (!useGlobalOutputSizeEl) return;
        const useGlobal = !!useGlobalOutputSizeEl.checked;
        if (obsRenderProxy) obsRenderProxy.disabled = useGlobal;
        if (posterRenderProxy) posterRenderProxy.disabled = useGlobal;
        if (mobileRenderProxy) mobileRenderProxy.disabled = useGlobal;
        syncOutputSizePreviews();
    }

    function syncOutputSizePreviews() {
        const globalSize = parseInt(form.querySelector('input[name="image_size"]')?.value || '480', 10) || 480;
        const useGlobal = !!(useGlobalOutputSizeEl && useGlobalOutputSizeEl.checked);
        const obsRender = parseInt(form.querySelector('input[name="obs_image_size"]')?.value || String(globalSize), 10) || globalSize;
        const posterRender = parseInt(form.querySelector('input[name="poster_image_size"]')?.value || String(globalSize), 10) || globalSize;
        const obsScale = parseInt(form.querySelector('input[name="obs_qr_scale_pct"]')?.value || '100', 10) || 100;
        const posterScale = parseInt(form.querySelector('input[name="poster_qr_scale_pct"]')?.value || '48', 10) || 48;
        const obsVisualPct = Math.max(56, Math.min(94, Math.round(obsScale * 0.82)));
        const posterVisualPct = Math.max(30, Math.min(75, posterScale));
        if (obsSizePreviewBox) {
            obsSizePreviewBox.style.width = obsVisualPct + '%';
            obsSizePreviewBox.title = 'OBS display scale ' + obsVisualPct + '%';
        }
        if (posterSizePreviewBox) {
            posterSizePreviewBox.style.width = posterVisualPct + '%';
            posterSizePreviewBox.title = 'Poster fill scale ' + posterVisualPct + '%';
        }
        const obsInput = form.querySelector('input[name="obs_image_size"]');
        const posterInput = form.querySelector('input[name="poster_image_size"]');
        const mobileInput = form.querySelector('input[name="mobile_image_size"]');
        const obsScaleInput = form.querySelector('input[name="obs_qr_scale_pct"]');
        const posterScaleInput = form.querySelector('input[name="poster_qr_scale_pct"]');
        if (useGlobal) {
            if (obsInput) obsInput.value = String(globalSize);
            if (posterInput) posterInput.value = String(globalSize);
            if (mobileInput) mobileInput.value = String(globalSize);
        }
        if (obsRenderProxy && obsInput) obsRenderProxy.value = obsInput.value;
        if (posterRenderProxy && posterInput) posterRenderProxy.value = posterInput.value;
        if (mobileRenderProxy && mobileInput) mobileRenderProxy.value = mobileInput.value;
        if (obsScaleProxy && obsScaleInput) obsScaleProxy.value = obsScaleInput.value;
        if (posterScaleProxy && posterScaleInput) posterScaleProxy.value = posterScaleInput.value;
        if (obsSizePreviewMeta) {
            const renderPx = useGlobal ? globalSize : obsRender;
            obsSizePreviewMeta.textContent = 'Render ' + renderPx + 'px, Display ' + Math.max(70, Math.min(115, obsScale)) + '%';
        }
        if (posterSizePreviewMeta) {
            const renderPx = useGlobal ? globalSize : posterRender;
            posterSizePreviewMeta.textContent = 'Render ' + renderPx + 'px, Fill ' + posterVisualPct + '%';
        }
    }

    function updatePreview() {
        if (!previewImg) return;
        const fg = form.querySelector('input[name="foreground_color"]')?.value || '#000000';
        const bg = form.querySelector('input[name="background_color"]')?.value || '#ffffff';
        const logoScale = form.querySelector('input[name="logo_scale_pct"]')?.value || '18';
        const dotStyle = form.querySelector('select[name="dot_style"]')?.value || 'square';
        const eyeOuterStyle = form.querySelector('select[name="eye_outer_style"]')?.value || 'square';
        const eyeInnerStyle = form.querySelector('select[name="eye_inner_style"]')?.value || 'square';
        const fillMode = form.querySelector('select[name="fill_mode"]')?.value || 'solid';
        const gradientStart = form.querySelector('input[name="gradient_start"]')?.value || '#000000';
        const gradientEnd = form.querySelector('input[name="gradient_end"]')?.value || '#ff2fd2';
        const gradientAngle = form.querySelector('input[name="gradient_angle"]')?.value || '45';
        const url = new URL(previewImg.src, window.location.origin);
        url.searchParams.set('fg', fg);
        url.searchParams.set('bg', bg);
        url.searchParams.set('logo_scale', logoScale);
        url.searchParams.set('dot', dotStyle);
        url.searchParams.set('eyeo', eyeOuterStyle);
        url.searchParams.set('eyei', eyeInnerStyle);
        url.searchParams.set('fill', fillMode);
        url.searchParams.set('gs', gradientStart);
        url.searchParams.set('ge', gradientEnd);
        url.searchParams.set('ga', gradientAngle);
        const previewTarget = previewImg.getAttribute('data-preview-target') || 'https://mydjrequests.com';
        url.searchParams.set('preview_url', previewTarget);
        url.searchParams.set('_t', String(Date.now()));
        previewImg.src = url.toString();
        evaluateScanHealth();
        syncOutputSizePreviews();
    }

    let previewTimer = null;
    form.querySelectorAll('input[name="foreground_color"], input[name="background_color"], input[name="logo_scale_pct"], input[name="image_size"], input[name="obs_image_size"], input[name="poster_image_size"], input[name="mobile_image_size"], input[name="obs_qr_scale_pct"], input[name="poster_qr_scale_pct"], input[name="gradient_start"], input[name="gradient_end"], input[name="gradient_angle"], select[name="dot_style"], select[name="eye_outer_style"], select[name="eye_inner_style"], select[name="fill_mode"]').forEach((el) => {
        const handler = () => {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(updatePreview, 180);
        };
        el.addEventListener('input', handler);
        el.addEventListener('change', handler);
    });

    tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const tabName = btn.getAttribute('data-tab') || 'style';
            setActiveTab(tabName);
        });
    });
    previewTabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const tabName = btn.getAttribute('data-preview-tab') || 'global';
            previewTabButtons.forEach((b) => {
                b.style.background = b.getAttribute('data-preview-tab') === tabName ? '#ff2fd2' : '#2a2a3a';
            });
            previewTabPanes.forEach((pane) => {
                pane.style.display = pane.getAttribute('data-preview-pane') === tabName ? '' : 'none';
            });
        });
    });
    setActiveTab('style');
    if (previewTabButtons.length) {
        previewTabButtons[0].click();
    }
    syncGradientVisibility();
    syncOutputSizingVisibility();
    syncOutputSizePreviews();
    evaluateScanHealth();
    presetButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            applyPreset(btn.getAttribute('data-preset') || '');
        });
    });
    if (fillModeEl) fillModeEl.addEventListener('change', syncGradientVisibility);
    if (useGlobalOutputSizeEl) {
        useGlobalOutputSizeEl.addEventListener('change', syncOutputSizingVisibility);
    }
    const bindProxy = (proxyEl, targetName) => {
        if (!proxyEl) return;
        const target = form.querySelector('input[name="' + targetName + '"]');
        if (!target) return;
        const handler = () => {
            target.value = proxyEl.value;
            updatePreview();
        };
        proxyEl.addEventListener('input', handler);
        proxyEl.addEventListener('change', handler);
    };
    bindProxy(obsRenderProxy, 'obs_image_size');
    bindProxy(obsScaleProxy, 'obs_qr_scale_pct');
    bindProxy(posterRenderProxy, 'poster_image_size');
    bindProxy(posterScaleProxy, 'poster_qr_scale_pct');
    bindProxy(mobileRenderProxy, 'mobile_image_size');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const health = evaluateScanHealth();
        if (health.blocked) {
            statusEl.textContent = 'Scan safety guard blocked save: increase color contrast.';
            return;
        }
        saveBtn.disabled = true;
        statusEl.textContent = 'Saving global style...';

        try {
            const fd = new FormData(form);
            if (useGlobalOutputSizeEl && useGlobalOutputSizeEl.checked) {
                const globalSize = form.querySelector('input[name="image_size"]')?.value || '480';
                fd.set('obs_image_size', globalSize);
                fd.set('poster_image_size', globalSize);
                fd.set('mobile_image_size', globalSize);
            }
            const res = await fetch('/dj/api/premium_qr_global_settings_save.php', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Failed to save global QR style');
            const removeLogo = form.querySelector('input[name="remove_logo"]');
            if (removeLogo) removeLogo.checked = false;
            if (data && data.settings && !data.settings.logo_path) {
                const logoInput = form.querySelector('input[name="logo"]');
                if (logoInput) logoInput.value = '';
            }

            statusEl.textContent = 'Global QR style saved.';
            updatePreview();
            setTimeout(() => { statusEl.textContent = ''; }, 1800);
        } catch (err) {
            statusEl.textContent = err.message || 'Save failed.';
        } finally {
            saveBtn.disabled = false;
        }
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', async () => {
            if (!confirm('Reset global QR style to default and remove logo?')) return;
            resetBtn.disabled = true;
            statusEl.textContent = 'Resetting...';
            try {
                const fd = new FormData();
                fd.append('reset_defaults', '1');
                const res = await fetch('/dj/api/premium_qr_global_settings_save.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Reset failed');

                form.querySelector('input[name="foreground_color"]').value = '#000000';
                form.querySelector('input[name="background_color"]').value = '#ffffff';
                form.querySelector('input[name="logo_scale_pct"]').value = '18';
                form.querySelector('input[name="image_size"]').value = '480';
                form.querySelector('input[name="obs_image_size"]').value = '600';
                form.querySelector('input[name="poster_image_size"]').value = '900';
                form.querySelector('input[name="mobile_image_size"]').value = '480';
                form.querySelector('input[name="obs_qr_scale_pct"]').value = '100';
                form.querySelector('input[name="poster_qr_scale_pct"]').value = '48';
                if (useGlobalOutputSizeEl) useGlobalOutputSizeEl.checked = true;
                form.querySelector('select[name="dot_style"]').value = 'square';
                form.querySelector('select[name="eye_outer_style"]').value = 'square';
                form.querySelector('select[name="eye_inner_style"]').value = 'square';
                form.querySelector('select[name="fill_mode"]').value = 'solid';
                form.querySelector('input[name="gradient_start"]').value = '#000000';
                form.querySelector('input[name="gradient_end"]').value = '#ff2fd2';
                form.querySelector('input[name="gradient_angle"]').value = '45';
                const animatedOverlay = form.querySelector('input[name="animated_overlay"]');
                if (animatedOverlay) animatedOverlay.checked = false;
                const removeLogo = form.querySelector('input[name="remove_logo"]');
                if (removeLogo) removeLogo.checked = false;
                const logoInput = form.querySelector('input[name="logo"]');
                if (logoInput) logoInput.value = '';
                syncGradientVisibility();
                syncOutputSizingVisibility();
                setActiveTab('style');

                statusEl.textContent = 'Global QR style reset to default.';
                updatePreview();
                setTimeout(() => { statusEl.textContent = ''; }, 1800);
            } catch (err) {
                statusEl.textContent = err.message || 'Reset failed.';
            } finally {
                resetBtn.disabled = false;
            }
        });
    }
})();
</script>

<script>
(function () {
    function copyFromInput(inputId, feedbackId) {
        var input = document.getElementById(inputId);
        var feedback = document.getElementById(feedbackId);
        if (!input) return;

        navigator.clipboard.writeText(input.value || '').then(function () {
            if (!feedback) return;
            feedback.style.display = 'block';
            setTimeout(function () {
                feedback.style.display = 'none';
            }, 1500);
        });
    }

    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-target');
            var feedback = btn.getAttribute('data-feedback');
            if (!target || !feedback) return;
            copyFromInput(target, feedback);
        });
    });
})();
</script>
