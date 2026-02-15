<?php
// dj/preview.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

// Include navbar + layout wrapper (opens <html>, <body>, etc.)
require __DIR__ . '/layout.php';

$djId = (int)($_SESSION['dj_id'] ?? 0);
if ($djId <= 0) {
    header('Location: /dj/login.php');
    exit;
}

$profileModel = new DjProfile();
$profile = $profileModel->findByUserId($djId);

echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">';
?>

<style>
.preview-container {
    max-width: 480px;
    margin: 30px auto 40px;
    padding: 0 20px 40px;
    color: #fff;
}

/* Banner */
.preview-banner {
    background: linear-gradient(135deg, #ff2fd2, #ff44de);
    color: #fff;
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 14px;
    margin-bottom: 18px;
    text-align: center;
}

/* Card (same feel as patron page) */
.preview-card {
    background: #161623;
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.08);
    padding: 24px 22px 26px;
    box-shadow: 0 0 25px rgba(255, 47, 210, 0.15);
}

.preview-card h2 {
    margin: 0 0 14px;
    font-size: 24px;
    font-weight: 700;
}

.dj-name {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 6px;
}

.dj-bio {
    font-size: 14px;
    color: #bbb;
    margin-bottom: 14px;
}

/* Contact lines */
.preview-contact-line {
    font-size: 14px;
    margin-bottom: 4px;
}
.preview-contact-line i {
    margin-right: 6px;
    color: #ff2fd2;
}

/* Social buttons (match patron card) */
.social-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: #1c1c28;
    border-radius: 12px;
    color: #fff;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,0.08);
    transition: 0.2s;
    margin-top: 8px;
    font-size: 14px;
}
.social-btn i {
    font-size: 18px;
    color: #ff2fd2;
}
.social-btn:hover {
    background: rgba(255,47,210,0.15);
    transform: translateY(-1px);
    border-color: rgba(255,47,210,0.4);
}

/* Save to contacts button */
.save-contact-btn {
    display: block;
    text-align: center;
    margin-top: 20px;
    padding: 12px;
    border-radius: 10px;
    background: #ff2fd2;
    color: white;
    text-decoration: none;
    font-weight: 600;
}
.save-contact-btn:hover {
    background: #ff4ae0;
}
</style>

<div class="preview-container">
    <div class="preview-banner">
        This is how your public profile appears to guests.
    </div>

    <div class="preview-card">
        <h2>Your DJ's Profile</h2>

        <?php if ($profile): ?>

            <?php if (!empty($profile['display_name'])): ?>
                <div class="dj-name">
                    <?= htmlspecialchars($profile['display_name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($profile['bio'])): ?>
                <div class="dj-bio">
                    <?= nl2br(htmlspecialchars($profile['bio'], ENT_QUOTES, 'UTF-8')) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($profile['phone'])): ?>
                <div class="preview-contact-line">
                    <i class="fa-solid fa-phone"></i>
                    <?= htmlspecialchars($profile['phone'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($profile['public_email'])): ?>
                <div class="preview-contact-line">
                    <i class="fa-solid fa-envelope"></i>
                    <?= htmlspecialchars($profile['public_email'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div style="margin-top:14px; display:flex; flex-direction:column; gap:6px;">

                <?php if (!empty($profile['social_website'])): ?>
                    <a class="social-btn" href="<?= htmlspecialchars($profile['social_website'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                        <i class="fa-solid fa-globe"></i> Website
                    </a>
                <?php endif; ?>

                <?php if (!empty($profile['social_instagram'])): ?>
                    <a class="social-btn" href="<?= htmlspecialchars($profile['social_instagram'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                        <i class="fa-brands fa-instagram"></i> Instagram
                    </a>
                <?php endif; ?>

                <?php if (!empty($profile['social_spotify'])): ?>
                    <a class="social-btn" href="<?= htmlspecialchars($profile['social_spotify'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                        <i class="fa-brands fa-spotify"></i> Spotify
                    </a>
                <?php endif; ?>

                <?php if (!empty($profile['social_facebook'])): ?>
                    <a class="social-btn" href="<?= htmlspecialchars($profile['social_facebook'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                        <i class="fa-brands fa-facebook"></i> Facebook
                    </a>
                <?php endif; ?>

                <?php if (!empty($profile['social_youtube'])): ?>
                    <a class="social-btn" href="<?= htmlspecialchars($profile['social_youtube'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                        <i class="fa-brands fa-youtube"></i> YouTube
                    </a>
                <?php endif; ?>

                <?php if (!empty($profile['social_soundcloud'])): ?>
                    <a class="social-btn" href="<?= htmlspecialchars($profile['social_soundcloud'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                        <i class="fa-brands fa-soundcloud"></i> SoundCloud
                    </a>
                <?php endif; ?>

                <?php if (!empty($profile['social_tiktok'])): ?>
                    <a class="social-btn" href="<?= htmlspecialchars($profile['social_tiktok'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                        <i class="fa-brands fa-tiktok"></i> TikTok
                    </a>
                <?php endif; ?>

            </div>

            <a href="/api/public/dj_vcard.php?dj=<?= (int)$djId; ?>"
               class="save-contact-btn">
                + Save to Contacts
            </a>

        <?php else: ?>

            <p>No profile information saved yet. Go back to the Profile editor and add your details.</p>

        <?php endif; ?>
    </div>
    
        <!-- Back Button -->
    <div style="text-align:center; margin-top:25px;">
        <a href="<?php echo url('dj/dj_profile_edit.php'); ?>"
           style="
                display:inline-block;
                padding:14px 28px;
                background:#292933;
                color:#fff;
                text-decoration:none;
                border-radius:12px;
                font-size:16px;
                font-weight:600;
                transition:0.2s;
           ">
            ← Back to Profile Editor
        </a>
    </div>
    
</div>