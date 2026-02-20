<?php
$countries = require __DIR__ . '/../../app/config/countries.php';

function stripFlag(string $country): string {
    // Removes leading emoji flags and trims spacing
    return trim(preg_replace('/^[\x{1F1E6}-\x{1F1FF}]{2}\s*/u', '', $country));
}

function countryFlag(string $code): string {
    $code = strtoupper($code);
    return mb_chr(127397 + ord($code[0])) . mb_chr(127397 + ord($code[1]));
}

$displayName = trim((string)($profile['display_name'] ?? 'DJ Profile'));
$bio = trim((string)($profile['bio'] ?? ''));
$city = trim((string)($profile['city'] ?? ''));
$countryCode = strtoupper(trim((string)($profile['country'] ?? '')));
$countryName = ($countryCode !== '' && isset($countries[$countryCode]))
    ? stripFlag((string)$countries[$countryCode])
    : '';
$hasLocation = ($city !== '' || $countryName !== '');

$links = [
    'Website'    => $profile['social_website'] ?? null,
    'Spotify'    => $profile['social_spotify'] ?? null,
    'Instagram'  => $profile['social_instagram'] ?? null,
    'Facebook'   => $profile['social_facebook'] ?? null,
    'TikTok'     => $profile['social_tiktok'] ?? null,
    'YouTube'    => $profile['social_youtube'] ?? null,
    'SoundCloud' => $profile['social_soundcloud'] ?? null,
];
$links = array_filter($links);

$hasEmail = !empty($profile['public_email']);
$hasPhone = !empty($profile['phone']);
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($displayName); ?> | MyDJRequests</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index,follow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
  --accent: <?= htmlspecialchars($profile['theme_color'] ?: '#ff2fd2'); ?>;
  --bg-0: #0a0a13;
  --bg-1: #111226;
  --bg-2: #17172d;
  --text: #f6f6ff;
  --muted: #b5b6cb;
  --line: rgba(255, 255, 255, 0.12);
  --card-shadow: 0 24px 48px rgba(0, 0, 0, 0.45);
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
  color: var(--text);
  font-family: "Space Grotesk", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  background:
    radial-gradient(1200px 520px at 6% -10%, rgba(255, 47, 210, 0.18), transparent 55%),
    radial-gradient(900px 460px at 94% -12%, rgba(46, 210, 255, 0.14), transparent 58%),
    linear-gradient(180deg, var(--bg-1), var(--bg-0) 58%);
  min-height: 100vh;
}

.container {
  width: min(980px, 92vw);
  margin: 0 auto;
  padding: 36px 0 72px;
}

.preview-banner {
  margin: 0 0 18px;
  border-radius: 14px;
  border: 1px solid;
  padding: 12px 14px;
  text-align: center;
  font-size: 14px;
  font-weight: 600;
  backdrop-filter: blur(6px);
}

.preview-banner.public {
  background: rgba(18, 58, 42, 0.8);
  border-color: rgba(110, 231, 183, 0.35);
  color: #6ee7b7;
}

.preview-banner.private {
  background: rgba(58, 42, 18, 0.8);
  border-color: rgba(250, 204, 21, 0.35);
  color: #facc15;
}

.main-grid {
  display: grid;
  gap: 18px;
}

.card {
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
  border: 1px solid var(--line);
  border-radius: 20px;
  box-shadow: var(--card-shadow);
}

.hero {
  padding: 30px 28px 24px;
  position: relative;
  overflow: hidden;
}

.hero::after {
  content: "";
  position: absolute;
  right: -90px;
  top: -90px;
  width: 240px;
  height: 240px;
  border-radius: 50%;
  background: radial-gradient(circle at center, color-mix(in srgb, var(--accent) 30%, transparent), transparent 68%);
  pointer-events: none;
}

.hero-top {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  margin-bottom: 18px;
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #d7d8eb;
}

.brand img {
  height: 24px;
  width: auto;
  opacity: 0.92;
}

.pill {
  display: inline-flex;
  align-items: center;
  border: 1px solid color-mix(in srgb, var(--accent) 55%, white 10%);
  color: color-mix(in srgb, var(--accent) 80%, white 20%);
  border-radius: 999px;
  padding: 7px 12px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.03em;
  background: color-mix(in srgb, var(--accent) 16%, transparent);
}

.dj-name {
  margin: 0;
  font-size: clamp(30px, 4vw, 46px);
  line-height: 1.02;
  letter-spacing: -0.02em;
}

.location {
  margin-top: 10px;
  color: var(--muted);
  font-size: 15px;
}

.bio {
  margin-top: 18px;
  color: #e9e9f7;
  line-height: 1.72;
  font-size: 16px;
  max-width: 75ch;
}

.section {
  padding: 22px 24px;
}

.section h2 {
  margin: 0 0 14px;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #cfd0e5;
}

.social-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.social-link {
  display: inline-flex;
  justify-content: center;
  align-items: center;
  text-decoration: none;
  color: #f5f5ff;
  font-weight: 600;
  border-radius: 12px;
  border: 1px solid var(--line);
  padding: 11px 12px;
  background: rgba(255, 255, 255, 0.03);
  transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.social-link:hover {
  transform: translateY(-1px);
  border-color: color-mix(in srgb, var(--accent) 60%, white 10%);
  background: color-mix(in srgb, var(--accent) 20%, rgba(255, 255, 255, 0.04));
}

.contact-list {
  display: grid;
  gap: 9px;
}

.contact-row {
  display: flex;
  gap: 10px;
  align-items: center;
  color: #e9e9f8;
}

.contact-label {
  font-size: 12px;
  color: #9da0bb;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  min-width: 56px;
}

.contact-row a {
  color: color-mix(in srgb, var(--accent) 84%, white 16%);
  text-decoration: none;
  word-break: break-all;
}

.empty-note {
  margin: 0;
  color: #9ea0bc;
  font-size: 14px;
}

.footer {
  text-align: center;
  margin-top: 22px;
  font-size: 12px;
  color: #8e90aa;
}

.footer a {
  color: color-mix(in srgb, var(--accent) 80%, white 20%);
  text-decoration: none;
}

@media (max-width: 720px) {
  .container {
    width: min(680px, 94vw);
    padding-top: 18px;
  }

  .hero {
    padding: 24px 18px 20px;
  }

  .hero-top {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 14px;
  }

  .section {
    padding: 18px;
  }

  .social-grid {
    grid-template-columns: 1fr;
  }
}
</style>
</head>

<body>

<?php if (!empty($isPreview)): ?>
  <div class="container" style="padding-bottom: 0;">
    <div class="preview-banner <?= $previewVisibilityState ? 'public' : 'private'; ?>">
      Preview mode -
      <?= $previewVisibilityState
          ? 'this is how your public DJ profile will appear'
          : 'this profile is not publicly visible because your Visibility toggle is OFF';
      ?>
    </div>
  </div>
<?php endif; ?>

<div class="container">
  <div class="main-grid">
    <section class="card hero">
      <div class="hero-top">
        <div class="brand">
          <img src="/assets/logo/MYDJRequests_Logo-white.png" alt="MyDJRequests">
          <span>DJ Profile</span>
        </div>
        <div class="pill">Now Booking</div>
      </div>

      <h1 class="dj-name"><?= htmlspecialchars($displayName); ?></h1>

      <?php if ($hasLocation): ?>
        <div class="location">
          <?php if ($city !== ''): ?>
            <?= htmlspecialchars($city); ?>
          <?php endif; ?>

          <?php if ($countryName !== ''): ?>
            <?= $city !== '' ? ' · ' : ''; ?>
            <?= countryFlag($countryCode); ?> <?= htmlspecialchars($countryName); ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="bio">
        <?= $bio !== ''
            ? nl2br(htmlspecialchars($bio))
            : 'Live music specialist available for private events, venues, and celebrations.'; ?>
      </div>
    </section>

    <section class="card section">
      <h2>Connect</h2>
      <?php if ($links): ?>
        <div class="social-grid">
          <?php foreach ($links as $label => $url): ?>
            <a class="social-link" href="<?= htmlspecialchars($url); ?>" target="_blank" rel="noopener">
              <?= htmlspecialchars($label); ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="empty-note">Social links are not available yet.</p>
      <?php endif; ?>
    </section>

    <?php if ($hasEmail || $hasPhone): ?>
      <section class="card section">
        <h2>Contact</h2>
        <div class="contact-list">
          <?php if ($hasEmail): ?>
            <div class="contact-row">
              <span class="contact-label">Email</span>
              <a href="mailto:<?= htmlspecialchars($profile['public_email']); ?>">
                <?= htmlspecialchars($profile['public_email']); ?>
              </a>
            </div>
          <?php endif; ?>

          <?php if ($hasPhone): ?>
            <div class="contact-row">
              <span class="contact-label">Phone</span>
              <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $profile['phone'])); ?>">
                <?= htmlspecialchars($profile['phone']); ?>
              </a>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <div class="footer">
    Powered by <a href="https://mydjrequests.com" target="_blank" rel="noopener">MyDJRequests</a>
  </div>
</div>

</body>
</html>
