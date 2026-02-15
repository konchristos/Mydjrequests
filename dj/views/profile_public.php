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
?>


<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($profile['display_name']); ?> | MyDJRequests</title>

<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index,follow">

<style>
:root {
  --accent: <?= htmlspecialchars($profile['theme_color'] ?: '#ff2fd2'); ?>;
}

body {
  margin: 0;
  background: radial-gradient(1200px 600px at top, #1a1a2e, #0f0f1a);
  color: #fff;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
}

.container {
  max-width: 860px;
  margin: 0 auto;
  padding: 60px 24px 80px;
}

.card {
  background: #161623;
  border-radius: 22px;
  border: 1px solid #292933;
  padding: 40px 36px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.45);
}

.brand {
  text-align: center;
  margin-bottom: 26px;
}

.brand img {
  height: 36px;
  opacity: 0.95;
}

.brand img {
  filter: drop-shadow(0 2px 10px rgba(255,255,255,0.08));
}

.dj-name {
  font-size: 34px;
  font-weight: 700;
  color: var(--accent);
  text-align: center;
  margin: 10px 0 6px;
}

.location {
  text-align: center;
  color: #b8b8c8;
  font-size: 15px;
}

.bio {
  margin-top: 26px;
  font-size: 16px;
  line-height: 1.6;
  color: #e5e5f0;
  text-align: center;
}

.socials {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 34px;
}

.socials a {
  text-decoration: none;
  padding: 10px 18px;
  border-radius: 999px;
  background: rgba(255,255,255,0.06);
  border: 1px solid #2d2d3a;
  color: #fff;
  font-size: 14px;
  transition: all 0.2s ease;
}

.socials a:hover {
  background: var(--accent);
  color: #000;
}


.contact {
  margin-top: 36px;
  text-align: center;
}

.contact h3 {
  margin-bottom: 12px;
  font-size: 16px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #9a9ab0;
}

.contact a {
  color: var(--accent);
  text-decoration: none;
  font-weight: 600;
}

.contact p {
  margin: 6px 0;
  font-size: 15px;
}




.footer {
  text-align: center;
  margin-top: 46px;
  font-size: 13px;
  color: #9a9ab0;
}

.footer a {
  color: var(--accent);
  text-decoration: none;
}



</style>
</head>

<body>
    
   <?php if (!empty($isPreview)): ?>
  <div style="
    background: <?= $previewVisibilityState ? '#123a2a' : '#3a2a12'; ?>;
    color: <?= $previewVisibilityState ? '#6ee7b7' : '#facc15'; ?>;
    padding: 10px 16px;
    text-align: center;
    font-size: 14px;
    font-weight: 600;
  ">
    🔒 Preview mode —
    <?= $previewVisibilityState
        ? 'this is how your public DJ profile will appear'
        : 'this profile is not publicly visible because your Visibility toggle is OFF'
    ?>
  </div>
<?php endif; ?>

<div class="container">

  <div class="card">

    <div class="brand">
      <img
        src="/assets/logo/MYDJRequests_Logo-white.png"
        alt="MyDJRequests"
      >
    </div>

    <div class="dj-name">
      <?= htmlspecialchars($profile['display_name']); ?>
    </div>

<?php if ($profile['city'] || $profile['country']): ?>
  <p class="location">
    <?php if ($profile['city']): ?>
      <?= htmlspecialchars($profile['city']); ?>
    <?php endif; ?>

    <?php if ($profile['country'] && isset($countries[$profile['country']])): ?>
      <?= $profile['city'] ? ' · ' : ''; ?>
<?= countryFlag($profile['country']); ?>
<?= htmlspecialchars(stripFlag($countries[$profile['country']])); ?>
    <?php endif; ?>
  </p>
<?php endif; ?>

    <?php if (!empty($profile['bio'])): ?>
      <div class="bio">
        <?= nl2br(htmlspecialchars($profile['bio'])); ?>
      </div>
    <?php endif; ?>

    <?php
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
    ?>

    <?php if ($links): ?>
      <div class="socials">
        <?php foreach ($links as $label => $url): ?>
          <a href="<?= htmlspecialchars($url); ?>" target="_blank" rel="noopener">
            <?= htmlspecialchars($label); ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
  
  
  <?php
$hasEmail = !empty($profile['public_email']);
$hasPhone = !empty($profile['phone']);
?>

<?php if ($hasEmail || $hasPhone): ?>
  <div class="contact">
    <h3>Contact DJ</h3>

    <?php if ($hasEmail): ?>
      <p>
        📧
        <a href="mailto:<?= htmlspecialchars($profile['public_email']); ?>">
          <?= htmlspecialchars($profile['public_email']); ?>
        </a>
      </p>
    <?php endif; ?>

    <?php if ($hasPhone): ?>
      <p>
        📞
        <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $profile['phone'])); ?>">
          <?= htmlspecialchars($profile['phone']); ?>
        </a>
      </p>
    <?php endif; ?>
  </div>
<?php endif; ?>
  
  
  
  

  <div class="footer">
    Powered by <a href="https://mydjrequests.com" target="_blank">MyDJRequests</a>
  </div>

</div>

</body>
</html>