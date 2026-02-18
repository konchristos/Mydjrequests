<?php
// dj/settings.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);

if ($djId <= 0) {
    redirect('dj/login.php');
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
                if ($defaultEventBroadcastMode === 'default') {
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
.settings-card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; margin-bottom:16px; }
.settings-card h3 { margin:0 0 14px; }
.settings-row { margin: 12px 0; }
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
    padding: 14px;
}
.event-defaults-subcard h4 {
    margin: 0 0 10px;
    font-size: 14px;
    color: #d6d7e2;
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
</style>

<div class="settings-wrap">
    <p style="margin:0 0 8px;">
        <a href="/dj/dashboard.php" style="color:#ff2fd2; text-decoration:none;">&larr; Back to Dashboard</a>
    </p>
    <h1>Settings</h1>

    <?php if ($error !== ''): ?><div class="settings-err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="settings-ok"><?php echo e($success); ?></div><?php endif; ?>

    <div class="settings-card">
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
            Your default tip/boost preference is saved below, but live visibility is controlled by this global platform setting.
        </div>
    </div>

    <form method="POST">
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
                <label><input class="settings-check" type="checkbox" name="has_spotify" value="1" <?php echo !empty($profile['has_spotify']) ? 'checked' : ''; ?>>Spotify</label>
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
                            Enable personalized broadcast message by default on new events
                        </label>
                        <div class="settings-help">Turn off to stop auto-posting this message for new events.</div>
                    </div>

                    <div class="settings-row">
                        <label class="settings-label">Broadcast Message Mode</label>
                        <label>
                            <input class="settings-check" type="radio" name="default_event_broadcast_mode" value="default" <?php echo $defaultEventBroadcastMode === 'default' ? 'checked' : ''; ?>>
                            Use MyDJRequests default message (locked)
                        </label>
                        <label style="display:block; margin-top:8px;">
                            <input class="settings-check" type="radio" name="default_event_broadcast_mode" value="custom" <?php echo $defaultEventBroadcastMode === 'custom' ? 'checked' : ''; ?>>
                            Use my custom message
                        </label>
                    </div>

                    <div class="settings-row" id="defaultBroadcastPreviewRow">
                        <label class="settings-label" for="default_event_broadcast_message_preview">
                            MyDJRequests Default Message
                        </label>
                        <textarea
                            class="settings-input"
                            id="default_event_broadcast_message_preview"
                            rows="8"
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

        <button type="submit" class="settings-btn">Save Settings</button>
    </form>

    <div class="settings-card settings-after-save-gap" id="message-statuses">
        <h3>Message Statuses</h3>
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
            defaultPreviewRow.style.display = (enabled && !customMode) ? '' : 'none';
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
