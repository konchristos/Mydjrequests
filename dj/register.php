<?php
require_once __DIR__ . '/../app/bootstrap.php';
$countries = require __DIR__ . '/../app/config/countries.php';

function inferCountryFromLocale(): ?string {
    if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return null;
    }

    if (preg_match('/([a-z]{2})-([A-Z]{2})/', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $m)) {
        return $m[2]; // AU, US, GB, etc.
    }

    return null;
}

$defaultCountry = inferCountryFromLocale();
$selectedCountry =
    $_POST['country']
    ?? $defaultCountry
    ?? '';


$errors = '';
$sent   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $errors = 'Invalid CSRF token.';
    } else {
        $pw  = $_POST['password'] ?? '';
        $pw2 = $_POST['password_confirm'] ?? '';

        if ($pw === '' || $pw2 === '') {
            $errors = 'Please enter and confirm your password.';
        } elseif ($pw !== $pw2) {
            $errors = 'Passwords do not match.';
        } elseif (($_POST['dj_software'] ?? '') === 'other' && trim((string)($_POST['dj_software_other'] ?? '')) === '') {
            $errors = 'Please specify your DJ software when selecting Other.';
        } else {
            $auth = new AuthController();
            $res  = $auth->register($_POST);

            if ($res['success']) {
                $sent = true; // no auto-login
            } else {
                $errors = $res['message'] ?? 'Registration failed.';
            }
        }
    }
}
?>

<?php
$pageTitle = "DJ Registration";
require __DIR__ . '/auth_layout.php';
?>

<style>
.card input,
.card select {
  width: 100%;
  display: block;
  box-sizing: border-box;
  height: 44px;
  padding: 10px 12px;
  border-radius: 8px;
  background: #0f1115;
  border: 1px solid rgba(255,255,255,0.10);
  color: #e5e7eb;
  outline: none;
}

.card select {
  cursor: pointer;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23c9c9c9'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z' clip-rule='evenodd'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 14px;
  padding-right: 38px;
}

.form-section {
  margin-top: 22px;
  margin-bottom: 10px;
  font-size: 13px;
  font-weight: 600;
  color: #9ca3af;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.form-section::after {
  content: '';
  display: block;
  margin-top: 6px;
  height: 1px;
  background: linear-gradient(
    to right,
    rgba(255,47,210,0.4),
    rgba(255,47,210,0.05)
  );
}
</style>

<div style="text-align:center;margin-bottom:20px;">
    <a href="<?php echo mdjr_url('/'); ?>">
        <img
            src="/assets/logo/MYDJRequests_Logo-white.png"
            alt="MyDJRequests"
            style="height:40px;width:auto;opacity:0.95;"
        >
    </a>
</div>

<h1>DJ Registration</h1>

<div class="card">

<?php if ($errors): ?>
    <p style="color:#ff4ae0;"><?php echo e($errors); ?></p>
<?php endif; ?>

<?php if ($sent && !$errors): ?>

    <p style="color:#6ee7b7;">✅ Your account has been created. One last step!</p>
    <p>Please check your email and click the verification link to activate your account.</p>

    <p style="margin-top:10px;font-size:14px;color:#888;">
        Didn’t receive the email?
        <a href="#" style="color:#ff2fd2;">Resend verification</a>
    </p>

    <p style="margin-top:14px;">
        <a href="<?php echo mdjr_url('dj/login.php'); ?>">Go to Login</a>
    </p>

<?php else: ?>

<form method="post">
<?php echo csrf_field(); ?>

<input type="hidden" name="browser_timezone" id="browser_timezone">

<!-- IDENTITY -->
<div class="form-section">Your details</div>

<label>Full Name *</label>
<input type="text" name="name" required value="<?php echo e($_POST['name'] ?? ''); ?>">

<label>DJ Name (public display)</label>
<input type="text" name="dj_name" placeholder="e.g. DJ Kon C" value="<?php echo e($_POST['dj_name'] ?? ''); ?>">

<!-- LOCATION -->
<div class="form-section">Location</div>

<label>Country *</label>
<select name="country" required>
  <option value="">Select country</option>
  <?php foreach ($countries as $code => $label): ?>
    <option
  value="<?php echo $code; ?>"
  <?php if ($selectedCountry === $code) echo 'selected'; ?>
>
      <?php echo $label; ?>
    </option>
  <?php endforeach; ?>
</select>
<small style="display:block;margin-top:4px;color:#888;">
  Used for regional features, pricing, and currency display
</small>

<label>City (optional)</label>
<input type="text" name="city" placeholder="e.g. Melbourne" value="<?php echo e($_POST['city'] ?? ''); ?>">
<small style="display:block;margin-top:4px;color:#888;">
  Displayed on your public profile and used for DJ discovery
</small>

<!-- DJ SOFTWARE -->
<div class="form-section">DJ Setup</div>

<label>Which DJ software platform do you use? *</label>
<select name="dj_software" id="dj_software" required>
  <option value="">Select software</option>
  <option value="rekordbox" <?php echo (($_POST['dj_software'] ?? '') === 'rekordbox') ? 'selected' : ''; ?>>Rekordbox</option>
  <option value="serato" <?php echo (($_POST['dj_software'] ?? '') === 'serato') ? 'selected' : ''; ?>>Serato</option>
  <option value="traktor" <?php echo (($_POST['dj_software'] ?? '') === 'traktor') ? 'selected' : ''; ?>>Traktor</option>
  <option value="virtualdj" <?php echo (($_POST['dj_software'] ?? '') === 'virtualdj') ? 'selected' : ''; ?>>VirtualDJ</option>
  <option value="djay" <?php echo (($_POST['dj_software'] ?? '') === 'djay') ? 'selected' : ''; ?>>djay / djay Pro</option>
  <option value="other" <?php echo (($_POST['dj_software'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
</select>

<div id="djSoftwareOtherWrap" style="<?php echo (($_POST['dj_software'] ?? '') === 'other') ? '' : 'display:none;'; ?>">
  <label>Please specify (Other)</label>
  <input
    type="text"
    id="dj_software_other"
    name="dj_software_other"
    placeholder="e.g. Engine DJ, Mixxx..."
    value="<?php echo e($_POST['dj_software_other'] ?? ''); ?>"
  >
</div>

<label style="margin-top:12px;">Which premium subscriptions do you currently use?</label>
<div style="display:grid;gap:8px;margin-top:8px;">
  <label style="display:flex;align-items:center;gap:8px;margin:0;color:#cfcfd8;font-weight:400;">
    <input type="checkbox" name="sub_spotify" value="1" <?php echo !empty($_POST['sub_spotify']) ? 'checked' : ''; ?> style="width:auto;height:auto;">
    Spotify
  </label>
  <label style="display:flex;align-items:center;gap:8px;margin:0;color:#cfcfd8;font-weight:400;">
    <input type="checkbox" name="sub_apple_music" value="1" <?php echo !empty($_POST['sub_apple_music']) ? 'checked' : ''; ?> style="width:auto;height:auto;">
    Apple Music
  </label>
  <label style="display:flex;align-items:center;gap:8px;margin:0;color:#cfcfd8;font-weight:400;">
    <input type="checkbox" name="sub_beatport" value="1" <?php echo !empty($_POST['sub_beatport']) ? 'checked' : ''; ?> style="width:auto;height:auto;">
    Beatport
  </label>
</div>

<!-- ACCOUNT -->
<div class="form-section">Account access</div>

<label>Email *</label>
<input type="email" name="email" required value="<?php echo e($_POST['email'] ?? ''); ?>">

<label>Password *</label>
<input type="password" name="password" required>

<label>Confirm Password *</label>
<input type="password" name="password_confirm" required>

<button type="submit">Create Account</button>

</form>
<?php endif; ?>

</div>

<p style="text-align:center;">
    Already have an account?
    <a href="<?php echo mdjr_url('dj/login.php'); ?>">Login</a>
</p>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  var input = document.getElementById('browser_timezone');
  if (input && tz) {
    input.value = tz;
  }

  var softwareSelect = document.getElementById('dj_software');
  var otherWrap = document.getElementById('djSoftwareOtherWrap');
  var otherInput = document.getElementById('dj_software_other');
  if (softwareSelect && otherWrap && otherInput) {
    var toggleOther = function () {
      var isOther = softwareSelect.value === 'other';
      otherWrap.style.display = isOther ? '' : 'none';
      otherInput.required = isOther;
      if (!isOther) {
        otherInput.value = '';
      }
    };
    softwareSelect.addEventListener('change', toggleOther);
    toggleOther();
  }
});
</script>


<?php require __DIR__ . '/auth_footer.php'; ?>
