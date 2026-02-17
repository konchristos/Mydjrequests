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
$defaultBroadcastTemplate = "🔊 You’re Live at {{EVENT_NAME}} with {{DJ_NAME}}\n\nUse this page to shape the vibe.\n\n• Home – Event info. Enter your name so the DJ knows who you are when you interact.\n• My Requests – Send in your songs and manage your requests.\n• All Requests – See what the crowd is requesting in real time.\n• Message – Chat directly with the DJ and receive live updates.\n• Contact – Connect and follow the DJ.\n\nDrop your requests and let’s make it a night to remember.";

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
        $defaultEventBroadcastMessage = trim((string)($_POST['default_event_broadcast_message'] ?? ''));

        $allowed = ['rekordbox', 'serato', 'traktor', 'virtualdj', 'djay', 'other'];
        if (!in_array($software, $allowed, true)) {
            $error = 'Please select your DJ software platform.';
        } elseif ($software === 'other' && $softwareOther === '') {
            $error = 'Please specify your DJ software when selecting Other.';
        } elseif ($defaultEventBroadcastEnabled === 1 && $defaultEventBroadcastMessage === '') {
            $error = 'Add a personalized event broadcast message or turn the toggle off.';
        } elseif (strlen($defaultEventBroadcastMessage) > 2000) {
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
            $stmt->execute([
                ':message' => ($defaultEventBroadcastEnabled === 1 && $defaultEventBroadcastMessage !== '') ? $defaultEventBroadcastMessage : null,
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
$defaultEventBroadcastMessage = (string)($broadcastStmt->fetchColumn() ?: '');
$defaultEventBroadcastEnabled = ($defaultEventBroadcastMessage !== '');
if ($defaultEventBroadcastMessage === '') {
    $defaultEventBroadcastMessage = $defaultBroadcastTemplate;
}

$prodEnabled = appSettingValue(
    $db,
    'patron_payments_enabled_prod',
    appSettingValue($db, 'patron_payments_enabled', '0')
) === '1';

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

            <div class="settings-row">
                <label>
                    <input class="settings-check" type="checkbox" name="default_tips_boost_enabled" value="1" <?php echo $defaultTipsBoostEnabled ? 'checked' : ''; ?>>
                    Enable tips/boosts by default when creating new events
                </label>
                <div class="settings-help">You can still override this per event.</div>
            </div>

            <div class="settings-row">
                <label>
                    <input class="settings-check" type="checkbox" name="default_event_broadcast_enabled" value="1" <?php echo $defaultEventBroadcastEnabled ? 'checked' : ''; ?>>
                    Enable personalized broadcast message by default on new events
                </label>
                <div class="settings-help">Turn off to stop auto-posting this message for new events.</div>
            </div>

            <div class="settings-row">
                <label class="settings-label" for="default_event_broadcast_message">
                    Personalized Event Broadcast Message
                </label>
                <textarea
                    class="settings-input"
                    id="default_event_broadcast_message"
                    name="default_event_broadcast_message"
                    rows="5"
                    maxlength="2000"
                    placeholder="Welcome to {{EVENT_NAME}} with {{DJ_NAME}}..."
                ><?php echo e($defaultEventBroadcastMessage); ?></textarea>
                <div class="settings-help">Variables supported: <code>{{DJ_NAME}}</code>, <code>{{EVENT_NAME}}</code>.</div>
            </div>

            <button type="submit" class="settings-btn">Save Event Defaults</button>
        </div>

        <button type="submit" class="settings-btn">Save Settings</button>
    </form>
</div>

<script>
(function () {
    var software = document.getElementById('dj_software');
    var otherRow = document.getElementById('djSoftwareOtherRow');
    var otherInput = document.getElementById('dj_software_other');
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
})();
</script>
