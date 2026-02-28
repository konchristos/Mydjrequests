<?php
// dj/dj_profile_edit.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();
$countries = require __DIR__ . '/../app/config/countries.php';

// Include navbar + layout wrapper (opens <html>, <body>, etc.)
require __DIR__ . '/layout.php';

// Logged-in DJ id
$djId = (int)($_SESSION['dj_id'] ?? 0);
if ($djId <= 0) {
    header('Location: /dj/login.php');
    exit;
}


$userModel = new User();
$user = $userModel->findById($djId);

$profileModel = new DjProfile();
$profile = $profileModel->findByUserId($djId);
$isPremiumPlan = mdjr_user_has_premium(db(), $djId);

// Auto-create blank profile if missing
if (!$profile) {
    $baseName = $_SESSION['dj_alias']
        ?: $_SESSION['dj_name']
        ?: 'dj-' . $djId;

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $baseName));
    $slug = trim($slug, '-') ?: ('dj-' . $djId);

    $profileModel->create($djId, $slug);
    $profile = $profileModel->findByUserId($djId);
}


// --------------------------------------------------
// Auto-populate profile defaults (non-destructive)
// --------------------------------------------------

// Display name
if (empty($profile['display_name'])) {
    $profile['display_name'] =
        $_SESSION['dj_name']
        ?: ($user['dj_name'] ?? $user['name'] ?? '');
}

// City (from users table)
if (empty($profile['city']) && !empty($user['city'])) {
    $profile['city'] = $user['city'];
}

// Country (from users table)
if (empty($profile['country']) && !empty($user['country_code'])) {
    $profile['country'] = $user['country_code'];
}



// Sanitiser
function h($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}


function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

$profileLogoFocusX = isset($profile['logo_focus_x']) ? max(0, min(100, (float)$profile['logo_focus_x'])) : 50.0;
$profileLogoFocusY = isset($profile['logo_focus_y']) ? max(0, min(100, (float)$profile['logo_focus_y'])) : 50.0;
$profileLogoZoomPct = isset($profile['logo_zoom_pct']) ? max(100, min(220, (int)$profile['logo_zoom_pct'])) : 100;




// Load Font Awesome (safe to include here even if layout already has it)
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">';
?>

<style>
/* Page layout */
.dj-profile-container {
    max-width: 720px;
    margin: 0 auto;
    padding: 25px;
    color: #fff;
}

.dj-profile-container h1 {
    color: #ff2fd2;
    margin-bottom: 6px;
    font-size: 30px;
}
.dj-profile-subtitle {
    color: #bbbbc7;
    font-size: 14px;
    margin-bottom: 20px;
}

/* Form card */
#djProfileForm {
    background: #161623;
    border-radius: 16px;
    padding: 20px 22px 30px;
    border: 1px solid #292933;
}

/* Section headings */
.section-head {
    margin-top: 20px;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #9f9fb5;
}

/* Inputs */
#djProfileForm label {
    display: block;
    margin-top: 14px;
    font-size: 14px;
    font-weight: 600;
}
#djProfileForm input,
#djProfileForm textarea {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid #333647;
    background: #181824;
    color: #fff;
    margin-top: 6px;
    font-size: 16px;
    box-sizing: border-box;
}
#djProfileForm textarea {
    min-height: 110px;
    resize: vertical;
}
#djProfileForm input[type="color"] {
    width: 80px;
    height: 45px;
    padding: 0;
    cursor: pointer;
}

#djProfileForm select {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid #333647;
    background: #181824;
    color: #fff;
    margin-top: 6px;
    font-size: 16px;
    box-sizing: border-box;

    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    /* dropdown arrow */
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23c9c9c9'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z' clip-rule='evenodd'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 14px;
    padding-right: 40px;
}

#djProfileForm select option {
    background: #181824;
    color: #fff;
}

/* Row layout */
.row {
    display: flex;
    gap: 14px;
}
.row > div {
    flex: 1;
}

/* Social rows with Font Awesome icons */
.social-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
}

.social-icon {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: rgba(255, 47, 210, 0.18);
    display: flex;
    align-items: center;
    justify-content: center;
}

.social-icon i {
    font-size: 18px;
    color: #ff2fd2;
}

.social-input-wrap {
    position: relative;
    flex: 1;
}

.social-input-wrap input {
    padding-right: 40px; /* room for clear button */
}

.social-clear-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
    height: 22px;
    border-radius: 999px;
    border: none;
    background: rgba(255, 255, 255, 0.1);
    color: #ccc;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Small help text */
.small-help {
    font-size: 12px;
    color: #9a9ab0;
    margin-top: 6px;
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
.dj-logo-preview {
    margin-top: 10px;
    display: inline-block;
    border: 1px solid #333647;
    border-radius: 12px;
    background: #11121a;
    padding: 6px;
}
.dj-logo-preview img {
    width: 92px;
    height: 92px;
    object-fit: cover;
    border-radius: 8px;
    display: block;
}
.image-crop-preview {
    margin-top: 10px;
    width: 100%;
    max-width: 360px;
    aspect-ratio: 16 / 10;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #333647;
    background: #10111a;
    cursor: grab;
    touch-action: none;
}
.image-crop-preview.dragging { cursor: grabbing; }
.image-crop-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transform-origin: center;
    display: block;
}
.range-row {
    display: grid;
    grid-template-columns: 1fr 68px;
    gap: 10px;
    align-items: center;
    margin-top: 10px;
}
.range-value {
    text-align: right;
    color: #c8c9d8;
    font-size: 12px;
}

/* BIG Back + Preview + Save buttons bar */
.action-bar {
    display: flex;
    justify-content: space-between; /* spreads buttons evenly */
    width: 100%;
    margin-top: 30px;
    align-items: center;
}

/* Back button */
.back-btn {
    background: #292933;
    padding: 14px 26px;
    border-radius: 10px;
    color: #fff;
    text-decoration: none;
    font-size: 16px;
    font-weight: 600;
    transition: 0.2s;
}
.back-btn:hover {
    background: #3a3a45;
}


/* Save button */
.save-btn {
    background: #ff2fd2;
    padding: 14px 30px;
    border-radius: 10px;
    border: none;
    color: white;
    cursor: pointer;
    font-size: 16px;
    font-weight: 600;
    box-shadow: 0 0 14px rgba(255, 47, 210, 0.55);
    transition: 0.2s;
}
.save-btn:hover {
    background: #ff4ae0;
}



/* Status message */
#status {
    margin-top: 15px;
    text-align: center;
    font-size: 14px;
    color: #ccc;
}


.visibility-box {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #181824;
    border: 1px solid #333647;
    border-radius: 12px;
    padding: 14px 16px;
    margin-top: 10px;
}

.visibility-text p {
    margin: 4px 0 0;
}

/* Toggle */
.toggle-switch {
    position: relative;
    width: 52px;
    height: 28px;
}

.toggle-switch input {
    display: none;
}

.slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background: #333;
    border-radius: 999px;
    transition: 0.25s;
}

.slider::before {
    content: "";
    position: absolute;
    height: 22px;
    width: 22px;
    left: 3px;
    top: 3px;
    background: white;
    border-radius: 50%;
    transition: 0.25s;
}

.toggle-switch input:checked + .slider {
    background: #ff2fd2;
}

.toggle-switch input:checked + .slider::before {
    transform: translateX(24px);
}

/* Badges */
.badge {
    display: inline-block;
    margin-top: 8px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

.badge-public {
    background: rgba(95, 219, 110, 0.15);
    color: #6ee7b7;
}

.badge-private {
    background: rgba(255, 107, 107, 0.15);
    color: #ff6b6b;
}

.profile-explainer {
    background:#1a1a2b;
    border:1px solid #2d2d3a;
    border-radius:14px;
    padding:18px 20px;
    margin-bottom:24px;
    font-size:14px;
    color:#cfcfe5;
}

.profile-explainer p {
    margin:10px 0;
    line-height:1.5;
}

.profile-explainer .note {
    font-size:13px;
    color:#9a9ab0;
}


/* Preview button base */
.preview-btn {
  background: #292933;
  padding: 14px 26px;
  border-radius: 10px;
  text-decoration: none;
  font-size: 16px;
  font-weight: 600;
  transition: all 0.2s ease;
}

/* Public = green */
.preview-btn.public {
  color: #6ee7b7;
  border: 1px solid rgba(110,231,183,0.4);
}

/* Private = red */
.preview-btn.private {
  color: #ff6b6b;
  border: 1px solid rgba(255,107,107,0.4);
}




</style>

<div class="dj-profile-container">

<h1>Edit Your DJ Profile</h1>

<div class="profile-explainer">
  <p>
    <strong>🎉 Event Patron Page</strong><br>
    The details you enter here are shown on your <strong>event request pages</strong>.
    Patrons can view your info and save your contact details to their phone.
  </p>

  <p>
    <strong>🌍 Public DJ Profile</strong><br>
    When enabled, this information can also appear on your
    <strong>public DJ profile</strong>, allowing people to discover and contact you.
  </p>

  <p class="note">
    🔒 <strong>Public visibility is OFF by default.</strong><br>
    Nothing is published publicly unless you choose to turn it on.
  </p>
</div>

    <form id="djProfileForm">

        <!-- BASIC INFO -->
        <div class="section-head">Basic Info</div>

        <label>DJ Display Name</label>
        <input type="text" name="display_name" value="<?= h($profile['display_name']); ?>">

        <div class="row">
            <div>
                <label>Public Email</label>
                <input type="email" name="public_email" value="<?= h($profile['public_email']); ?>">
            </div>
            <div>
                <label>Phone</label>
                <input type="text" name="phone" value="<?= h($profile['phone']); ?>">
            </div>
        </div>

        <div class="row">
            <div>
                <label>City</label>
                <input type="text" name="city" value="<?= h($profile['city']); ?>">
            </div>
            <div>
<label>Country</label>
<select name="country">
  <option value="">Select country</option>

  <?php foreach ($countries as $code => $label): ?>
    <option value="<?= h($code); ?>"
      <?= ($profile['country'] === $code ? 'selected' : ''); ?>>
      <?= h($label); ?>
    </option>
  <?php endforeach; ?>
</select>
            </div>
        </div>

        <label>Short Bio</label>
        <textarea name="bio" id="bioField"><?= h($profile['bio']); ?></textarea>


        <label>Logo URL (optional)</label>
        <input type="text" name="logo_url" id="logo_url" value="<?= h($profile['logo_url']); ?>">
        <p class="small-help">
            Use a square PNG/JPG. This may be shown on your public profile and event pages.
        </p>
        <?php if (!empty($profile['logo_url'])): ?>
            <div class="dj-logo-preview">
                <img src="<?= h($profile['logo_url']); ?>" alt="Current DJ image" loading="lazy" referrerpolicy="no-referrer">
            </div>
        <?php endif; ?>

        <label>
            Upload DJ Contact Image
            <span class="premium-badge">Premium</span>
        </label>
        <?php if ($isPremiumPlan): ?>
            <input type="file" name="logo_file" id="logo_file" accept="image/png,image/jpeg,image/webp">
            <p class="small-help">
                PNG/JPG/WEBP up to 2MB. Uploading replaces your current image and updates Logo URL automatically.
            </p>
            <input type="hidden" id="logo_focus_x" name="logo_focus_x" value="<?= (int)$profileLogoFocusX ?>">
            <input type="hidden" id="logo_focus_y" name="logo_focus_y" value="<?= (int)$profileLogoFocusY ?>">
            <p class="small-help">Drag preview image to set focus position.</p>

            <div class="range-row">
                <label for="logo_zoom_pct" style="margin:0;">Image Zoom</label>
                <span id="logo_zoom_pct_val" class="range-value"><?= (int)$profileLogoZoomPct ?>%</span>
            </div>
            <input type="range" id="logo_zoom_pct" name="logo_zoom_pct" min="100" max="220" step="1" value="<?= (int)$profileLogoZoomPct ?>">
            <p class="small-help">Adjust crop framing for large photos. This controls Contact-tab image display.</p>
            <div class="image-crop-preview" id="logoCropPreviewWrap">
                <img
                    id="logoCropPreviewImage"
                    src="<?= h($profile['logo_url'] ?: '/assets/logo/mydjrequests.svg'); ?>"
                    alt="Image crop preview"
                    style="object-position: <?= (int)$profileLogoFocusX ?>% <?= (int)$profileLogoFocusY ?>%; transform: scale(<?= number_format($profileLogoZoomPct / 100, 2, '.', '') ?>);"
                >
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-weight:500;">
                <input
                    type="checkbox"
                    name="show_logo_public_profile"
                    value="1"
                    style="width:auto;margin-top:0;"
                    <?= !isset($profile['show_logo_public_profile']) || (int)$profile['show_logo_public_profile'] === 1 ? 'checked' : ''; ?>
                >
                Show DJ image on Public Profile (under location, above bio)
            </label>
            <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:500;">
                <input type="checkbox" name="remove_logo_image" value="1" style="width:auto;margin-top:0;">
                Remove current DJ contact image
            </label>
        <?php else: ?>
            <p class="small-help">
                Image upload is available on Premium. Pro users can still use Logo URL.
            </p>
        <?php endif; ?>

        <!-- SOCIAL LINKS -->
        <div class="section-head">Links & Social</div>

<p class="small-help">
  Any social links you add here will be shown on your
  <strong>event request pages</strong> and your
  <strong>public DJ profile</strong> (when visibility is enabled).
  <br>
  Leave a field blank to keep it hidden.
</p>

        <!-- Website -->
        <div class="social-row">
            <div class="social-icon">
                <i class="fa-solid fa-globe"></i>
            </div>
            <div class="social-input-wrap">
                <input
                    type="text"
                    id="social_website"
                    name="social_website"
                    placeholder="Website"
                    value="<?= h($profile['social_website']); ?>"
                >
                <button type="button" class="social-clear-btn" data-target="social_website">×</button>
            </div>
        </div>

        <!-- Spotify -->
        <div class="social-row">
            <div class="social-icon">
                <i class="fa-brands fa-spotify"></i>
            </div>
            <div class="social-input-wrap">
                <input
                    type="text"
                    id="social_spotify"
                    name="social_spotify"
                    placeholder="Spotify profile or playlist"
                    value="<?= h($profile['social_spotify']); ?>"
                >
                <button type="button" class="social-clear-btn" data-target="social_spotify">×</button>
            </div>
        </div>

        <!-- Instagram -->
        <div class="social-row">
            <div class="social-icon">
                <i class="fa-brands fa-instagram"></i>
            </div>
            <div class="social-input-wrap">
                <input
                    type="text"
                    id="social_instagram"
                    name="social_instagram"
                    placeholder="Instagram"
                    value="<?= h($profile['social_instagram']); ?>"
                >
                <button type="button" class="social-clear-btn" data-target="social_instagram">×</button>
            </div>
        </div>

        <!-- Facebook -->
        <div class="social-row">
            <div class="social-icon">
                <i class="fa-brands fa-facebook"></i>
            </div>
            <div class="social-input-wrap">
                <input
                    type="text"
                    id="social_facebook"
                    name="social_facebook"
                    placeholder="Facebook"
                    value="<?= h($profile['social_facebook']); ?>"
                >
                <button type="button" class="social-clear-btn" data-target="social_facebook">×</button>
            </div>
        </div>

        <!-- TikTok -->
        <div class="social-row">
            <div class="social-icon">
                <i class="fa-brands fa-tiktok"></i>
            </div>
            <div class="social-input-wrap">
                <input
                    type="text"
                    id="social_tiktok"
                    name="social_tiktok"
                    placeholder="TikTok"
                    value="<?= h($profile['social_tiktok']); ?>"
                >
                <button type="button" class="social-clear-btn" data-target="social_tiktok">×</button>
            </div>
        </div>

        <!-- YouTube -->
        <div class="social-row">
            <div class="social-icon">
                <i class="fa-brands fa-youtube"></i>
            </div>
            <div class="social-input-wrap">
                <input
                    type="text"
                    id="social_youtube"
                    name="social_youtube"
                    placeholder="YouTube"
                    value="<?= h($profile['social_youtube']); ?>"
                >
                <button type="button" class="social-clear-btn" data-target="social_youtube">×</button>
            </div>
        </div>

        <!-- SoundCloud -->
        <div class="social-row">
            <div class="social-icon">
                <i class="fa-brands fa-soundcloud"></i>
            </div>
            <div class="social-input-wrap">
                <input
                    type="text"
                    id="social_soundcloud"
                    name="social_soundcloud"
                    placeholder="SoundCloud"
                    value="<?= h($profile['social_soundcloud']); ?>"
                >
                <button type="button" class="social-clear-btn" data-target="social_soundcloud">×</button>
            </div>
        </div>
        
        <!-- VISIBILITY -->
        <div class="section-head">Visibility</div>

<div class="visibility-box">
  <div class="visibility-text">
    <strong>Public DJ Directory</strong>
    <p class="small-help">
      Your profile is always shown on your event and request pages.
      Turning this off will hide your profile from the public DJ directory.
    </p>
  </div>

  <label class="toggle-switch">
<input
  type="checkbox"
  name="is_public"
  value="1"
  data-original="<?= (int)$profile['is_public']; ?>"
  <?= ((int)$profile['is_public'] === 1 ? 'checked' : ''); ?>
>
    <span class="slider"></span>
  </label>
</div>

<div id="visibility-badge">
  <?php if ((int)$profile['is_public'] === 1): ?>
    <span class="badge badge-public">Visible in Directory</span>
  <?php else: ?>
    <span class="badge badge-private">Hidden from Directory</span>
  <?php endif; ?>
</div>

<div id="visibility-intent" class="small-help" style="display:none;"></div>
        
        

<!-- APPEARANCE / URL -->
<div class="section-head">Appearance & Public Link</div>

<label>Theme Accent Colour</label>
<input type="color" name="theme_color" value="<?= h($profile['theme_color']); ?>">

<label>Public Page Slug</label>
<input
    type="text"
    id="page_slug"
    name="page_slug"
    value="<?= h($profile['page_slug']); ?>"
>

<div id="slug-status" class="small-help"></div>

<?php
$isPublic = ((int)$profile['is_public'] === 1);
$profileUrl = "https://mydjrequests.com/dj/" . h($profile['page_slug']);
?>

<p class="small-help">
  Public link:
  <a
    id="publicProfileLink"
    href="<?= $isPublic ? $profileUrl : '#' ?>"
    target="<?= $isPublic ? '_blank' : '' ?>"
    style="
      color: <?= $isPublic ? '#6ee7b7' : '#ff6b6b' ?>;
      text-decoration: <?= $isPublic ? 'none' : 'line-through' ?>;
      cursor: <?= $isPublic ? 'pointer' : 'not-allowed' ?>;
    "
    <?= $isPublic ? '' : 'onclick="return false;"' ?>
  >
    <?= $profileUrl ?>
  </a>
</p>


        <!-- BUTTON BAR -->
        <div class="action-bar">
<a href="/dj/preview.php" class="preview-btn"
   style="
       background:#292933;
       padding:14px 26px;
       border-radius:10px;
       color:#ff2fd2;
       text-decoration:none;
       font-size:16px;
       font-weight:600;
       border:1px solid rgba(255,47,210,0.4);
   ">
    Preview Patron Page
</a>

<a
  id="previewPublicBtn"
  data-slug="<?= h($profile['page_slug']); ?>"
  href="/dj/public_profile.php?slug=<?= urlencode($profile['page_slug']); ?>&preview=1"
  target="_blank"
>
  Preview Public DJ Profile
</a>

    <button type="submit" class="save-btn">Save Profile</button>
</div>

        <div id="status"></div>

    </form>
</div>

<script>
const form = document.getElementById('djProfileForm');
const statusEl = document.getElementById('status');

// Clear buttons for socials + logo
document.querySelectorAll('.social-clear-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-target');
        const input = document.getElementById(targetId);
        if (input) input.value = '';
    });
});



// Save profile
form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const slugInput = document.getElementById('page_slug');

    // 🚫 BLOCK EMPTY SLUG
    if (!slugInput || slugInput.value.trim() === '') {
        statusEl.style.color = '#ff6b6b';
        statusEl.textContent = 'Please choose a public profile URL before saving.';
        slugInput.focus();
        return;
    }

    statusEl.style.color = '#ccc';
    statusEl.textContent = 'Saving…';

    const fd = new FormData(form);

    try {
        const res = await fetch('/api/dj/save_profile.php', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.success) {
            statusEl.style.color = '#5fdb6e';
            statusEl.textContent = 'Profile saved!';
        } else {
            statusEl.style.color = '#ff6b6b';
            statusEl.textContent = data.message || 'Error saving profile.';
        }

    } catch (err) {
        console.error(err);
        statusEl.style.color = '#ff6b6b';
        statusEl.textContent = 'Network error.';
    }
});

(() => {
  const logoUrlEl = document.getElementById('logo_url');
  const logoFileEl = document.getElementById('logo_file');
  const previewWrapEl = document.getElementById('logoCropPreviewWrap');
  const imgEl = document.getElementById('logoCropPreviewImage');
  const xEl = document.getElementById('logo_focus_x');
  const yEl = document.getElementById('logo_focus_y');
  const zEl = document.getElementById('logo_zoom_pct');
  const zValEl = document.getElementById('logo_zoom_pct_val');
  if (!imgEl || !xEl || !yEl || !zEl || !previewWrapEl) return;

  let dragState = null;

  const update = () => {
    const x = Math.max(0, Math.min(100, parseFloat(xEl.value || '50') || 50));
    const y = Math.max(0, Math.min(100, parseFloat(yEl.value || '50') || 50));
    const z = Math.max(100, Math.min(220, parseInt(zEl.value || '100', 10) || 100));
    imgEl.style.objectPosition = x + '% ' + y + '%';
    imgEl.style.transform = 'scale(' + (z / 100).toFixed(2) + ')';
    if (zValEl) zValEl.textContent = z + '%';
  };

  const updateFromDrag = (event) => {
    if (!dragState) return;
    const rect = previewWrapEl.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return;
    const dx = event.clientX - dragState.startX;
    const dy = event.clientY - dragState.startY;
    const nextX = Math.max(0, Math.min(100, dragState.startFocusX + ((dx / rect.width) * 100)));
    const nextY = Math.max(0, Math.min(100, dragState.startFocusY + ((dy / rect.height) * 100)));
    xEl.value = String(Math.round(nextX));
    yEl.value = String(Math.round(nextY));
    update();
  };

  previewWrapEl.addEventListener('pointerdown', (event) => {
    dragState = {
      startX: event.clientX,
      startY: event.clientY,
      startFocusX: Math.max(0, Math.min(100, parseFloat(xEl.value || '50') || 50)),
      startFocusY: Math.max(0, Math.min(100, parseFloat(yEl.value || '50') || 50)),
    };
    previewWrapEl.classList.add('dragging');
    previewWrapEl.setPointerCapture(event.pointerId);
  });
  previewWrapEl.addEventListener('pointermove', updateFromDrag);
  previewWrapEl.addEventListener('pointerup', (event) => {
    dragState = null;
    previewWrapEl.classList.remove('dragging');
    if (previewWrapEl.hasPointerCapture(event.pointerId)) {
      previewWrapEl.releasePointerCapture(event.pointerId);
    }
  });
  previewWrapEl.addEventListener('pointercancel', () => {
    dragState = null;
    previewWrapEl.classList.remove('dragging');
  });

  if (logoUrlEl) {
    logoUrlEl.addEventListener('input', () => {
      const v = (logoUrlEl.value || '').trim();
      if (v !== '') imgEl.src = v;
    });
  }
  if (logoFileEl) {
    logoFileEl.addEventListener('change', () => {
      const file = logoFileEl.files && logoFileEl.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = () => { if (reader.result) imgEl.src = String(reader.result); };
      reader.readAsDataURL(file);
    });
  }
  [xEl, yEl, zEl].forEach((el) => {
    el.addEventListener('input', update);
    el.addEventListener('change', update);
  });
  update();
})();


let slugTimer;
const slugInput = document.getElementById('page_slug');
const slugStatus = document.getElementById('slug-status');

if (slugInput) {
  slugInput.addEventListener('input', () => {
    clearTimeout(slugTimer);

    const raw = slugInput.value.trim();
    if (!raw) {
      slugStatus.textContent = '';
      return;
    }

    slugTimer = setTimeout(async () => {
      try {
        const res = await fetch('/api/dj/check_page_slug.php?slug=' + encodeURIComponent(raw));
        const data = await res.json();

        if (data.available) {
          slugStatus.textContent = '✓ URL available';
          slugStatus.style.color = '#6ee7b7';
        } else {
          slugStatus.textContent = '✗ URL already taken';
          slugStatus.style.color = '#ff6b6b';
        }
      } catch {
        slugStatus.textContent = '';
      }
    }, 400);
  });
}
</script>

<script>
(() => {
  const visibilityToggle = document.querySelector('input[name="is_public"]');
  const badgeWrap = document.getElementById('visibility-badge');
  const intentMsg = document.getElementById('visibility-intent');
  const publicLink = document.getElementById('publicProfileLink');
  const previewBtn = document.getElementById('previewPublicBtn');

  if (!visibilityToggle) return;

  const original = visibilityToggle.dataset.original === '1';

  function setPreviewBtn(isOn) {
    if (!previewBtn) return;

    previewBtn.style.background = '#292933';
    previewBtn.style.padding = '14px 26px';
    previewBtn.style.borderRadius = '10px';
    previewBtn.style.textDecoration = 'none';
    previewBtn.style.fontSize = '16px';
    previewBtn.style.fontWeight = '600';

    if (isOn) {
      previewBtn.style.color = '#6ee7b7';
      previewBtn.style.border = '1px solid rgba(110,231,183,0.4)';
      previewBtn.textContent = 'Preview Public DJ Profile';
    } else {
      previewBtn.style.color = '#ff6b6b';
      previewBtn.style.border = '1px solid rgba(255,107,107,0.4)';
      previewBtn.textContent = 'Preview (Private)';
    }

    const slug = previewBtn.dataset.slug;
    previewBtn.href =
      `/dj/public_profile.php?slug=${encodeURIComponent(slug)}&preview=1&intent_public=${isOn ? 1 : 0}`;
  }

  function setPublicLink(isOn) {
    if (!publicLink) return;
    const url = publicLink.textContent.trim();

    if (isOn) {
      publicLink.href = url;
      publicLink.target = '_blank';
      publicLink.style.color = '#6ee7b7';
      publicLink.style.textDecoration = 'none';
      publicLink.style.cursor = 'pointer';
      publicLink.onclick = null;
    } else {
      publicLink.href = '#';
      publicLink.target = '';
      publicLink.style.color = '#ff6b6b';
      publicLink.style.textDecoration = 'line-through';
      publicLink.style.cursor = 'not-allowed';
      publicLink.onclick = () => false;
    }
  }

  function setBadge(isOn) {
    if (!badgeWrap) return;

    if (isOn === original) {
      badgeWrap.innerHTML = isOn
        ? '<span class="badge badge-public">Visible in Directory</span>'
        : '<span class="badge badge-private">Hidden from Directory</span>';
      return;
    }

    badgeWrap.innerHTML = isOn
      ? '<span class="badge badge-public">Will be visible after saving</span>'
      : '<span class="badge badge-private">Will be hidden after saving</span>';
  }

  function setIntent(isOn) {
    if (!intentMsg) return;

    intentMsg.style.display = 'none';
    intentMsg.textContent = '';

    if (isOn !== original) {
      intentMsg.style.display = 'block';
      intentMsg.style.color = '#facc15';
      intentMsg.textContent = 'This change will take effect when you save.';
    }
  }

  function refreshAll() {
    const isOn = visibilityToggle.checked;
    setIntent(isOn);
    setBadge(isOn);
    setPublicLink(isOn);
    setPreviewBtn(isOn);
  }

  // Initial paint (CURRENT STATE)
  refreshAll();

  // Live updates (INTENT STATE)
  visibilityToggle.addEventListener('change', refreshAll);
})();
</script>
