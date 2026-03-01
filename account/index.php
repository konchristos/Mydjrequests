<?php
//accounts/index.php
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/helpers/time_helpers.php';
require_once APP_ROOT . '/app/helpers/formatting.php';

require_dj_login();

$djId = (int)$_SESSION['dj_id'];

$userModel = new User();
$user = $userModel->findById($djId);
$subscription = null;
$alphaOpenAccess = false;
$accessLabel = 'Trial';
$statusLabel = 'Inactive';
$subscriptionsLabel = 'None';
$showSubscriptionRequired = isset($_GET['subscription_required']) && $_GET['subscription_required'] === '1';
try {
    $db = db();
    $alphaOpenAccess = mdjr_is_alpha_open_access($db);
    $stmt = $db->prepare("SELECT plan, status, renews_at FROM subscriptions WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
    $stmt->execute(['uid' => $djId]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $subscription = null;
}

if ($alphaOpenAccess) {
    $accessLabel = 'Early Access';
    $statusLabel = 'Free during Early Access';
    $subscriptionsLabel = 'Coming soon';
} else {
    $plan = strtolower((string)($subscription['plan'] ?? ($user['subscription'] ?? 'trial')));
    if ($plan === 'free') {
        $plan = 'trial';
    }
    $status = strtolower((string)($subscription['status'] ?? ($user['subscription_status'] ?? 'inactive')));
    $renewsAtRaw = (string)($subscription['renews_at'] ?? '');
    $renewsAtText = '';
    if ($renewsAtRaw !== '' && strtotime($renewsAtRaw) !== false) {
        $renewsAtText = date('d M Y', strtotime($renewsAtRaw));
    }

    if ($plan === 'premium') {
        $accessLabel = 'Premium';
    } elseif ($plan === 'pro') {
        $accessLabel = 'Pro';
    } else {
        $accessLabel = 'Trial';
    }

    if ($plan === 'trial') {
        $trialEndsRaw = (string)($user['trial_ends_at'] ?? '');
        if ($trialEndsRaw !== '' && strtotime($trialEndsRaw) !== false) {
            $trialEndsText = date('d M Y', strtotime($trialEndsRaw));
            if (strtotime($trialEndsRaw) > time()) {
                $statusLabel = "Trial active until {$trialEndsText}";
            } else {
                $statusLabel = "Trial ended on {$trialEndsText}";
            }
        } else {
            $statusLabel = 'Trial';
        }
    } elseif ($status === 'active') {
        $statusLabel = 'Active';
    } else {
        $statusLabel = $status !== '' ? ucfirst($status) : 'Inactive';
    }

    if (in_array($plan, ['pro', 'premium'], true)) {
        $subscriptionsLabel = strtoupper($plan) . ($renewsAtText !== '' ? " · Renews {$renewsAtText}" : '');
    } elseif ($plan === 'trial') {
        $subscriptionsLabel = $renewsAtText !== '' ? "Trial · Renews {$renewsAtText}" : 'Trial';
    } else {
        $subscriptionsLabel = 'No active subscription';
    }
}

$trustedDevices = $userModel->getTrustedDevices($djId);
$recentLogins = $userModel->getRecentLogins($djId, 5);


$currentDeviceHash = null;

if (!empty($_COOKIE[MDJR_TRUSTED_COOKIE])) {
    $currentDeviceHash = mdjr_trusted_token_hash($_COOKIE[MDJR_TRUSTED_COOKIE]);
}

$pageTitle = "Account";
require_once __DIR__ . '/../dj/layout.php';
?>


<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Account Settings | MyDJRequests</title>

<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="/assets/css/account.css">

</head>


<style>
  /* =========================
   TRUSTED DEVICES
========================= */

.trusted-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}

.trusted-table th {
  text-align: left;
  color: #aaa;
  font-weight: 500;
  padding-bottom: 6px;
}

.trusted-table td {
  padding: 10px 0;
  border-top: 1px solid rgba(255,255,255,0.06);
}

.trusted-table .actions {
  text-align: right;
}

.btn-link-danger {
  background: none;
  border: none;
  padding: 0;
  color: #ff4ae0;
  cursor: pointer;
  font-size: 13px;
}

.btn-link-danger:hover {
  text-decoration: underline;
}

.btn-danger-outline {
  background: transparent;
  border: 1px solid rgba(255,74,224,0.5);
  color: #ff4ae0;
  padding: 8px 14px;
  border-radius: 8px;
  cursor: pointer;
}

.btn-danger-outline:hover {
  background: rgba(255,74,224,0.08);
}  
    
    
    
</style>


<body>


<main class="account-container">

  <!-- =========================
       PAGE HEADER
  ========================== -->
  <header class="account-header">
    <h1>Account</h1>
    <p class="muted">
      Manage login, security, and account-level settings.
    </p>
  </header>

  <!-- =========================
       ACCOUNT OVERVIEW
  ========================== -->
  <section class="card">
    <h2>Account Overview</h2>
    <?php if ($showSubscriptionRequired): ?>
      <p class="muted" style="color:#ffb3b3;margin-top:0;">
        Your access is currently limited. Activate or renew a subscription to unlock DJ tools.
      </p>
    <?php endif; ?>

    <div class="kv">
      <span>Email</span>
      <strong><?= htmlspecialchars($user['email']) ?></strong>
    </div>

    <div class="kv">
      <span>Account ID</span>
      <code><?= htmlspecialchars($user['uuid']) ?></code>
    </div>

    <div class="kv">
      <span>Created</span>
      <strong><?= date('d M Y', strtotime($user['created_at'])) ?></strong>
    </div>


    <div class="kv">
      <span>Access</span>
      <strong><?= e($accessLabel) ?></strong>
    </div>

    <div class="kv">
      <span>Status</span>
      <strong><?= e($statusLabel) ?></strong>
    </div>

    <div class="kv">
      <span>Subscriptions</span>
      <strong><?= e($subscriptionsLabel) ?></strong>
    </div>

  </section>

  <!-- =========================
       SECURITY
  ========================== -->
  <section class="card">
    <h2>Security</h2>

    <!-- Change Password -->
    <div class="security-block">
      <h3>Change Password</h3>

      <form id="change-password-form">
          <?php echo csrf_field(); ?>
        <label>
          Current password
          <input type="password" name="current_password" required>
        </label>

        <label>
          New password
          <input type="password" name="new_password" required minlength="8">
        </label>

        <label>
          Confirm new password
          <input type="password" name="confirm_password" required minlength="8">
        </label>

        <button type="submit" class="btn-primary">
          Update password
        </button>

        <p class="form-note">
          Changing your password will sign you out of other devices.
        </p>
      </form>
    </div>
  </section>

 <!-- =========================
     TRUSTED DEVICES
========================== -->
<section class="card">
  <h2>Trusted Devices</h2>

  <p class="muted" style="margin-bottom:12px;">
    Devices you trust can sign in without a verification code for up to 30 days.
  </p>

  <?php if (empty($trustedDevices)): ?>
    <p class="muted">No trusted devices.</p>
  <?php else: ?>
    <table class="trusted-table">
      <thead>
        <tr>
          <th>Device</th>
          <th>IP</th>
          <th>Trusted on</th>
          <th>Last used</th>
          <th>Expires</th>
          <th></th>
        </tr>
      </thead>
      <tbody>

<?php foreach ($trustedDevices as $device): ?>
<?php
$isCurrent =
    $currentDeviceHash !== null &&
    hash_equals($device['device_hash'], $currentDeviceHash);
?>
<tr>
  <td>
    <strong><?= htmlspecialchars($device['device_label'] ?? 'Unknown device') ?></strong>
    <?php if ($isCurrent): ?>
      <span class="device-current">• This device</span>
    <?php endif; ?>
  </td>

<td class="muted">
  <?php
    $flag = mdjr_country_flag($device['country_code'] ?? null);
  ?>
  <?php if ($flag): ?>
    <span style="margin-right:6px; font-size:16px;">
      <?= $flag ?>
    </span>
  <?php endif; ?>
  <?= htmlspecialchars($device['ip_address'] ?? '—') ?>
</td>

  <td><?= date('d M Y', strtotime($device['created_at'])) ?></td>


    <td>
      <?= !empty($device['last_used_at'])
          ? mdjr_time_ago($device['last_used_at'])
          : '—'
      ?>
    </td>

  <td><?= date('d M Y', strtotime($device['expires_at'])) ?></td>

  <td class="actions">
    <?php if (!$isCurrent): ?>
      <form method="post" action="<?= mdjr_url('account/revoke_device.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="device_id" value="<?= (int)$device['id'] ?>">
        <button type="submit" class="btn-link-danger">
          Remove
        </button>
      </form>
    <?php else: ?>
      <span class="muted" style="font-size:12px;">Active</span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>

      </tbody>
    </table>

    <form method="post"
          action="<?= mdjr_url('account/revoke_all_devices.php') ?>"
          style="margin-top:14px;">
      <?= csrf_field() ?>
      <button class="btn-danger-outline">
        Revoke all trusted devices
      </button>
    </form>
  <?php endif; ?>
</section>

 <!-- =========================
     RECENT LOGINS
========================== -->
<section class="card">
  <h2>Recent Logins</h2>

  <?php if (empty($recentLogins)): ?>
    <p class="muted">No login history yet.</p>
  <?php else: ?>
    <table class="trusted-table">
      <thead>
        <tr>
          <th>Device</th>
          <th>IP</th>
          <th>When</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentLogins as $login): ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($login['device_label']) ?></strong>
            <?php if ($login['trusted_device']): ?>
              <span class="muted">• Trusted</span>
            <?php endif; ?>
          </td>

          <td class="muted">
            <?= mdjr_country_flag($login['country_code'] ?? null) ?>
            <?= htmlspecialchars($login['ip_address'] ?? '—') ?>
          </td>

          <td><?= mdjr_time_ago($login['created_at']) ?></td>

          <td></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>



  <!-- =========================
       RECOVERY CODES
  ========================== -->
  <section class="card">
    <h2>Recovery Codes</h2>

    <p class="muted">
      Recovery codes let you access your account if you lose email access. Each code can be used once.
    </p>

    <form method="post" action="/account/recovery_codes.php" style="margin-top:10px;">
      <?= csrf_field() ?>
      <button class="btn-secondary">
        Generate recovery codes
      </button>
      <p class="form-note">
        Generating new codes will invalidate previous ones.
      </p>
    </form>

    <?php if (!empty($_SESSION['recovery_codes'])): ?>
      <div class="security-toast" style="margin-top:12px;">
        <strong>Save these codes now.</strong> This is the only time they will be shown.
      </div>
      <button type="button" id="copy-recovery" class="btn-secondary" style="margin-top:10px;">Copy codes</button>
      <ul style="margin-top:10px; columns:2;">
        <?php foreach ($_SESSION['recovery_codes'] as $code): ?>
          <li style="font-family:monospace; margin-bottom:6px;"><?= e($code) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php unset($_SESSION['recovery_codes']); ?>
    <?php endif; ?>
  </section>


</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('change-password-form');
  if (!form) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = form.querySelector('button');
    btn.disabled = true;
    btn.textContent = 'Updating…';

    const formData = new FormData(form);

    try {
      const res = await fetch('/account/change_password.php', {
        method: 'POST',
        body: formData
      });

      const data = await res.json();

      if (!data.ok) {
        alert(data.error || 'Failed to update password');
        btn.disabled = false;
        btn.textContent = 'Update password';
        return;
      }

      alert('Password updated successfully.');
      form.reset();

    } catch (err) {
      alert('Network error. Please try again.');
    }

    btn.disabled = false;
    btn.textContent = 'Update password';
  });
});
</script>


</body>
</html>
