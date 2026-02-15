<?php
define('MDJR_DEMO_MODE', true);

// public_html/demo/patron.php
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/bootstrap_public.php';


// -------------------------
// 1. Demo
// -------------------------

$uuid = 'demo-event';

$event = [
    'title'      => 'Sarah & Tom’s Wedding',
    'event_date' => 'Saturday 14 June 2026',
    'location'   => 'Melbourne, VIC',
    'user_id'    => 1
];

// -------------------------
// 3. Ensure guest token
// -------------------------
$guestToken = $_COOKIE['mdjr_guest'] ?? null;
if (!$guestToken) {
    $guestToken = bin2hex(random_bytes(16));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('mdjr_guest', $guestToken, time() + 86400 * 30, '/', '', $secure, true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<title><?= e($event['title']); ?> – Requests</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
/* GLOBAL */
body {
    background: radial-gradient(circle at top, #0f0f1a 0%, #050510 70%);
    color: #fff;
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display",
                 "Segoe UI", Roboto, sans-serif;
    margin: 0;
    padding: 20px;
}
.container { max-width: 480px; margin: auto; }
.card {
    background: #161623;
    border-radius: 20px;
    border: 1px solid rgba(255,255,255,0.08);
    padding: 26px;
    margin-bottom: 32px;
    box-shadow: 0 0 25px rgba(255, 47, 210, 0.15);
}

/* EVENT CARD */
.event-card {
    text-align: center;
    background: linear-gradient(135deg, #550066, #1a1a2a);
    border-radius: 22px;
    padding: 26px 22px;
    margin-bottom: 32px;
    box-shadow: 0 0 35px rgba(255,47,210,0.25);
}
.event-card h1 {
    margin: 0 0 14px;
    font-size: 26px;
    font-weight: 700;
    color: #ff2fd2;
}
.event-meta { font-size: 15px; color: #ddd; }

/* FIELD + CLEAR BUTTON */
.field-group {
    position: relative;
    width: 100%;
    box-sizing: border-box;
}

input, textarea {
    width: 100%;
    background: #0d0d12;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 12px;
    padding: 14px 40px 14px 14px;
    margin-top: 14px;
    color: #fff;
    font-size: 16px;
    box-sizing: border-box;
}

.clear-btn {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 26px;
    height: 26px;
    border-radius: 999px;
    border: none;
    background: rgba(255,255,255,0.10);
    color: #ccc;
    font-size: 18px;
    cursor: pointer;
}
.field-group.textarea-group .clear-btn { top: 22px; }

/* Buttons */
button.send-btn {
    width: 100%;
    padding: 15px;
    margin-top: 18px;
    background: linear-gradient(135deg, #ff2fd2, #ff44de);
    color: #fff;
    border: none;
    font-size: 17px;
    border-radius: 14px;
    cursor: pointer;
    font-weight: 700;
    box-shadow: 0 0 12px rgba(255,47,210,0.4);
    transition: 0.2s;
}
button.send-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 0 18px rgba(255,47,210,0.6);
}

.status {
    text-align: center;
    font-size: 14px;
    margin-top: 12px;
    color: #ccc;
}

/* Spotify Dropdown */
.song-suggestions {
    position: absolute;
    left: 0; right: 0;
    top: 100%; margin-top: 6px;
    background: #11111a;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.12);
    max-height: 260px;
    overflow-y: auto;
    box-shadow: 0 10px 25px rgba(0,0,0,0.6);
    display: none;
    z-index: 50;
}
.song-suggestion-item {
    display: flex;
    gap: 10px;
    padding: 10px 12px;
    cursor: pointer;
}
.song-suggestion-item:hover { background: rgba(255,47,210,0.15); }
.song-suggestion-cover {
    width: 40px; height: 40px; border-radius: 6px;
}
.song-suggestion-title { font-weight: 600; }
.song-suggestion-artist { color: #bbb; }
.song-suggestions-footer {
    padding: 6px 10px;
    text-align: right;
    font-size: 11px;
    color: #777;
}

/* FOOTER */
.footer-note {
    text-align: center;
    font-size: 12px;
    color: #777;
    margin-top: 35px;
}


/* ===========================================
   iOS TOGGLE SWITCH (Spotify ON/OFF)
=========================================== */
.switch {
    position: relative;
    display: inline-block;
    width: 46px;
    height: 24px;
}

.switch input { 
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0;
    right: 0; bottom: 0;
    background-color: #444;
    transition: .3s;
    border-radius: 24px;
    box-shadow: inset 0 0 5px rgba(0,0,0,0.5);
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: #fff;
    transition: .3s;
    border-radius: 50%;
}

.switch input:checked + .slider {
    background: linear-gradient(135deg, #ff2fd2, #ff44de);
}

.switch input:checked + .slider:before {
    transform: translateX(22px);
}


.social-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: #1c1c28;
    border-radius: 12px;
    color: #fff;
    text-decoration: none;
    border: 1px solid rgba(255,255,255,0.08);
    transition: 0.2s;
}

.social-btn i {
    font-size: 18px;
    color: #ff2fd2; /* Neon Pink Icon */
}

.social-btn:hover {
    background: rgba(255,47,210,0.15);
    transform: translateY(-1px);
    border-color: rgba(255,47,210,0.4);
}


/* ===========================================
   My Requests
=========================================== */


.my-request-item {
    padding: 10px 12px;
    border-radius: 12px;
    background: #11111a;
    margin-bottom: 8px;
    border: 1px solid rgba(255,255,255,0.08);
}

.my-request-title {
    font-weight: 600;
}

.my-request-artist {
    font-size: 13px;
    color: #bbb;
}

.my-request-time {
    font-size: 11px;
    color: #777;
    margin-top: 2px;
}

/* ===========================================
   All Requests
=========================================== */

.request-count {
    background: rgba(255,47,210,0.15);
    color: #ff2fd2;
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 999px;
    font-weight: 600;
}

.all-request-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border-radius: 12px;
    background: #11111a;
    margin-bottom: 8px;
    border: 1px solid rgba(255,255,255,0.08);
}


/* ===========================
   Requests List Scroll Cap
=========================== */
#myRequestsList {
    max-height: 720px;   /* ~9–10 items comfortably */
    overflow-y: auto;
    padding-right: 4px;
}

/* Subtle scrollbar styling (WebKit only, safe fallback elsewhere) */
#myRequestsList::-webkit-scrollbar {
    width: 6px;
}
#myRequestsList::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.2);
    border-radius: 6px;
}

/* ===========================
   All Requests (with album art)
=========================== */
.all-request-item {
    display: flex;
    align-items: center;
    gap: 12px;
}

.all-request-cover {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    object-fit: cover;
    background: #222;
    flex-shrink: 0;
}

.all-request-meta {
    flex: 1;
    min-width: 0;
}

.all-request-title {
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.all-request-artist {
    font-size: 13px;
    color: #bbb;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}


/* Highlight tracks I requested */
.all-request-item.is-mine {
    border: 1px solid rgba(255,47,210,0.6);
    background: linear-gradient(
        135deg,
        rgba(255,47,210,0.15),
        rgba(255,47,210,0.05)
    );
    box-shadow: 0 0 10px rgba(255,47,210,0.25);
}

/* Optional subtle badge
.all-request-item.is-mine::after {
    content: "You requested";
    font-size: 11px;
    color: #ff2fd2;
    margin-left: 6px;
    white-space: nowrap;
} */



.expand-btn {
    font-size: 18px;
    cursor: pointer;
    color: #ff2fd2;
    margin-left: 8px;
}

.variant-list {
    margin-left: 20px;
    margin-top: 6px;
}

.variant-item {
    display: flex;
    justify-content: space-between;
    padding: 6px 10px;
    font-size: 13px;
    color: #bbb;
    background: rgba(255,255,255,0.04);
    border-radius: 8px;
    margin-bottom: 4px;
}

.request-count.small {
    font-size: 11px;
    opacity: 0.8;
}

/* ===========================
   Requests Header Responsive Fix
=========================== */

.requests-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    flex-wrap: nowrap;
}

/* Mobile adjustments */
@media (max-width: 420px) {
    .requests-header {
        flex-wrap: wrap;          /* allow second row */
    }

    #requests_sort {
        max-width: 150px;         /* prevent overflow */
        font-size: 12px;
        padding: 5px 8px;
    }

    .requests-header h2 {
        font-size: 18px;
        flex: 1 0 100%;           /* force title to its own row */
    }

    .requests-header label.switch {
        margin-left: auto;        /* keep toggle aligned right */
    }
}

/* Mobile density optimisation */
@media (max-width: 480px) {
    .card {
        padding: 10px;
    }
}

@media (max-width: 480px) {
    .card h3 {
        margin-top: 5px;
        margin-bottom: 8px;
    }
}


.card {
    margin-bottom: 24px;
}

@media (max-width: 480px) {
    .card {
        margin-bottom: 14px;
        padding: 18px;
    }
}


</style>
</head>

<body>
    
    <div style="
    position: fixed;
    top: 12px;
    right: 12px;
    background: rgba(255,47,210,0.15);
    color: #ff2fd2;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    z-index: 9999;
    pointer-events: none;
">
    Demo preview
</div>


<div class="container">

    <!-- EVENT HEADER -->
    <div class="event-card">
        <h1><?= e($event['title']); ?></h1>
        <div class="event-meta">
            <div><strong>Date:</strong> <?= e($event['event_date'] ?: 'TBA'); ?></div>
            <div><strong>Location:</strong> <?= e($event['location'] ?: 'TBA'); ?></div>
        </div>
    </div>

    <!-- MOOD WIDGET -->
    <?php include __DIR__ . '/mood_widget.php'; ?>

<!-- NAME TILE -->
<div class="card name-card">
    <h3>Your Name (shown to DJ)</h3>
    <div class="field-group">
        <input type="text" id="guest_name" placeholder="Your name…">
        <button type="button" class="clear-btn" id="clear_guest_name">×</button>
    </div>
    <div style="font-size:12px;color:#888;margin-top:8px;text-align:center;">
        Saved automatically ✔
    </div>
</div>


<!-- MY REQUESTS TILE -->
<div class="card" id="myRequestsCard">
    <div class="requests-header">
        <h3 style="margin:0;">🎶 Requests</h3>
        
        <div style="display:flex;justify-content:flex-end;margin-top:6px;">
    <select id="requests_sort"
            style="
                background:#11111a;
                color:#fff;
                border:1px solid rgba(255,255,255,0.15);
                border-radius:10px;
                padding:6px 10px;
                font-size:13px;
            ">
        <option value="popularity">Sort: Popularity</option>
        <option value="last">Sort: Last Requested</option>
        <option value="title">Sort: Title</option>
        <option value="artist">Sort: Artist</option>
    </select>
</div>
        

        <label class="switch">
            <input type="checkbox" id="requests_toggle">
            <span class="slider"></span>
        </label>
    </div>

    <div style="font-size:13px;color:#aaa;margin-top:6px;">
        <span id="requests_mode_label">My Requests</span>
    </div>

    <div id="myRequestsList" style="margin-top:12px;"></div>
</div>



<!-- SONG REQUEST FORM -->
<div class="card request-card">
    <h3>Request a Song 🎵</h3>

    <form id="songForm">
        <input type="hidden" name="event_uuid" value="<?= e($uuid); ?>">
        <input type="hidden" name="patron_name" id="hidden_patron_name_song">

        <input type="hidden" name="spotify_track_id" id="spotify_track_id">
        <input type="hidden" name="spotify_track_name" id="spotify_track_name">
        <input type="hidden" name="spotify_artist_name" id="spotify_artist_name">
        <input type="hidden" name="spotify_album_art_url" id="spotify_album_art_url">
        
        
        <!-- Spotify Toggle -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;margin-bottom:5px;">
            <label id="spotify_mode_label" style="font-size:15px;color:#ccc;">
                Spotify Search
            </label>
            
            <label class="switch">
                <input type="checkbox" id="spotify_toggle" checked>
                <span class="slider"></span>
            </label>
        </div>

        <div class="field-group">
            <input id="song_title" name="song_title" type="text" placeholder="Song title…" autocomplete="off" required>
            <button type="button" id="clear_song_title" class="clear-btn">×</button>
            <div id="songSuggestions" class="song-suggestions"></div>
        </div>

<div class="field-group" id="artist_group">
    <input id="artist" name="artist" type="text" placeholder="Artist (optional)">
    <button type="button" id="clear_artist" class="clear-btn">×</button>
</div>

        <button type="submit" class="send-btn">Send Song Request</button>
        <div id="songStatus" class="status"></div>
    </form>
</div>


<!-- MESSAGE FORM -->
<div class="card message-card">
    <h3>Send DJ a Message 💬</h3>

    <form id="messageForm">
        <input type="hidden" name="event_uuid" value="<?= e($uuid); ?>">
        <input type="hidden" name="patron_name" id="hidden_patron_name_msg">

        <div class="field-group textarea-group">
            <textarea name="message" id="message" rows="3" placeholder="Type your message…"></textarea>
            <button type="button" class="clear-btn" id="clear_message">×</button>
        </div>

        <button type="submit" class="send-btn">Send Message</button>
        <div id="msgStatus" class="status"></div>
    </form>
</div>


<?php
// Load DJ Profile
require_once __DIR__ . '/../app/models/DjProfile.php';

$profileModel = new DjProfile();
$djProfile = $profileModel->findByUserId($event['user_id']);
?>

<?php if ($djProfile): ?>
<div class="card dj-profile-card">
    <h3>Your DJ's Profile</h3>

    <?php if (!empty($djProfile['display_name'])): ?>
        <div class="dj-name" style="font-size:22px;font-weight:700;margin-bottom:6px;">
            <?= e($djProfile['display_name']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($djProfile['bio'])): ?>
        <div class="dj-bio" style="font-size:14px;color:#bbb;margin-bottom:14px;">
            <?= nl2br(e($djProfile['bio'])) ?>
        </div>
    <?php endif; ?>

<?php if (!empty($djProfile['phone'])): ?>
    <div><i class="fa-solid fa-phone"></i> <?= e($djProfile['phone']) ?></div>
<?php endif; ?>

<?php if (!empty($djProfile['public_email'])): ?>
    <div><i class="fa-solid fa-envelope"></i> <?= e($djProfile['public_email']) ?></div>
<?php endif; ?>

   <div style="margin-top:14px; display:flex; flex-direction:column; gap:10px;">

    <?php if ($djProfile['social_website']): ?>
        <a class="social-btn" href="<?= e($djProfile['social_website']) ?>" target="_blank">
            <i class="fa-solid fa-globe"></i> Website
        </a>
    <?php endif; ?>

    <?php if ($djProfile['social_instagram']): ?>
        <a class="social-btn" href="<?= e($djProfile['social_instagram']) ?>" target="_blank">
            <i class="fa-brands fa-instagram"></i> Instagram
        </a>
    <?php endif; ?>

    <?php if ($djProfile['social_spotify']): ?>
        <a class="social-btn" href="<?= e($djProfile['social_spotify']) ?>" target="_blank">
            <i class="fa-brands fa-spotify"></i> Spotify
        </a>
    <?php endif; ?>

    <?php if ($djProfile['social_facebook']): ?>
        <a class="social-btn" href="<?= e($djProfile['social_facebook']) ?>" target="_blank">
            <i class="fa-brands fa-facebook"></i> Facebook
        </a>
    <?php endif; ?>

    <?php if ($djProfile['social_youtube']): ?>
        <a class="social-btn" href="<?= e($djProfile['social_youtube']) ?>" target="_blank">
            <i class="fa-brands fa-youtube"></i> YouTube
        </a>
    <?php endif; ?>

    <?php if ($djProfile['social_soundcloud']): ?>
        <a class="social-btn" href="<?= e($djProfile['social_soundcloud']) ?>" target="_blank">
            <i class="fa-brands fa-soundcloud"></i> SoundCloud
        </a>
    <?php endif; ?>

    <?php if ($djProfile['social_tiktok']): ?>
        <a class="social-btn" href="<?= e($djProfile['social_tiktok']) ?>" target="_blank">
            <i class="fa-brands fa-tiktok"></i> TikTok
        </a>
    <?php endif; ?>
</div>

    <a href="/api/public/dj_vcard.php?dj=<?= e($event['user_id']) ?>" 
       class="save-contact-btn"
       style="
           display:block;
           text-align:center;
           margin-top:20px;
           padding:12px;
           border-radius:10px;
           background:#ff2fd2;
           color:white;
           text-decoration:none;
       ">
       + Save to Contacts
    </a>
</div>
<?php endif; ?>


<div class="footer-note">
    Powered by <strong>MyDjRequests.com</strong>
</div>

</div>


<script>
const DEMO_MODE = <?= MDJR_DEMO_MODE ? 'true' : 'false' ?>;
</script>


<script>

let allRequestsCache = [];
let myRequestsCache = [];


// =============================
// DEMO DATA
// =============================
const DEMO_MY_REQUESTS = [
    {
        song_title: "Dancing Queen",
        artist: "ABBA",
        created_at: new Date(Date.now() - 1000 * 60 * 12).toISOString(),
        spotify_album_art_url: "https://i.scdn.co/image/ab67616d00001e02e1c0b17e2c09b5c2d57f0755"
        is_mine: 1
    }
];

const DEMO_ALL_REQUESTS = [
    {
        song_title: "Mr Brightside",
        artist: "The Killers",
        request_count: 4,
        last_requested_at: new Date(Date.now() - 1000 * 60 * 5).toISOString(),
        album_art: "https://i.scdn.co/image/ab67616d00001e027f7c8ad9e02e92edcfb6c74f",
        is_mine: 0
    },
    {
        song_title: "September",
        artist: "Earth, Wind & Fire",
        request_count: 3,
        last_requested_at: new Date(Date.now() - 1000 * 60 * 20).toISOString(),
        album_art: "https://i.scdn.co/image/ab67616d00001e028d1a7b5c4dfb4b3a4b4a7c1d",
        is_mine: 1
    }
];



// Used only for My Requests (client-side ownership)
let myTrackKeys = new Set();

// =============================
// GUEST NAME MEMORY SYSTEM
// =============================
const guestNameInput = document.getElementById("guest_name");
if (DEMO_MODE) {
    guestNameInput.value = "Alex";
    syncGuestName();
}

// keep synced into forms
function syncGuestName() {
    const name = guestNameInput.value.trim();
    localStorage.setItem("mdjr_guest_name", name);

    document.getElementById("hidden_patron_name_song").value = name;
    document.getElementById("hidden_patron_name_msg").value = name;
}

// 🔑 ADD THIS LINE ⬇️
guestNameInput.addEventListener("input", syncGuestName);


document.getElementById("clear_guest_name").onclick = () => {
    guestNameInput.value = "";
    localStorage.removeItem("mdjr_guest_name");
    syncGuestName();
};


// =============================
// SONG REQUEST LOGIC
// =============================
const songForm   = document.getElementById("songForm");
const songStatus = document.getElementById("songStatus");
const songInput  = document.getElementById("song_title");
const artistInput = document.getElementById("artist");
const sugBox = document.getElementById("songSuggestions");

function clearSpotify() {
    document.getElementById("spotify_track_id").value = "";
    document.getElementById("spotify_track_name").value = "";
    document.getElementById("spotify_artist_name").value = "";
    document.getElementById("spotify_album_art_url").value = "";
}

document.getElementById("clear_song_title").onclick = () => {
    songInput.value = "";
    clearSpotify();
    sugBox.style.display = "none";
};

document.getElementById("clear_artist").onclick = () => {
    artistInput.value = "";
};

songForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (DEMO_MODE) {
        songStatus.textContent = "Demo mode — requests are disabled";
        return;
    }

    syncGuestName();

    const fd = new FormData(songForm);
    const res = await fetch("/api/public/submit_song.php", { method: "POST", body: fd });
    const data = await res.json();

    if (data.success) {
        songStatus.textContent = "Song request sent! 🙌";
        songForm.reset();
        clearSpotify();
        sugBox.innerHTML = "";
        sugBox.style.display = "none";
        if (requestsToggle.checked) {
                loadAllRequests();
            } else {
                loadMyRequests();
            }
    } else {
        songStatus.textContent = data.message || "Something went wrong.";
    }
});



function normalizeTitle(title) {
    return (title || "")
        .toLowerCase()
        .replace(/\s*\(.*?\)/g, "")     // remove (Extended Mix)
        .replace(/\s*-.*$/g, "")        // remove - Live / - Remix
        .replace(/\s+/g, " ")
        .trim();
}


// =============================
// MESSAGE FORM LOGIC
// =============================
const messageForm = document.getElementById("messageForm");
const msgStatus   = document.getElementById("msgStatus");

document.getElementById("clear_message").onclick = () =>
    document.getElementById("message").value = "";

messageForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (DEMO_MODE) {
        msgStatus.textContent = "Demo mode — messages are disabled";
        return;
    }

    syncGuestName();

    const fd = new FormData(messageForm);
    const res = await fetch("/api/public/submit_message.php", { method: "POST", body: fd });
    const data = await res.json();

    if (data.success) {
        msgStatus.textContent = "Message sent! 💬";
        messageForm.reset();
    } else {
        msgStatus.textContent = data.message || "Something went wrong.";
    }
});


// =============================
// SPOTIFY AUTOCOMPLETE
// =============================
let timer = null;
songInput.addEventListener("input", () => {
    if (DEMO_MODE) {
        if (songInput.value.length > 1) {
            sugBox.innerHTML = `
                <div style="padding:10px;color:#888;font-size:13px;">
                    Spotify search disabled in demo
                </div>`;
            sugBox.style.display = "block";
        } else {
            sugBox.style.display = "none";
        }
        return; // 🔑 THIS LINE
    }

    const q = songInput.value.trim();
    const spotifyOn = document.getElementById("spotify_toggle").checked;

    // If Spotify is OFF → disable autocomplete fully
    if (!spotifyOn) {
        clearSpotify();
        sugBox.style.display = "none";
        return;
    }

    clearSpotify();

    if (q.length < 2) {
        sugBox.style.display = "none";
        return;
    }

    if (timer) clearTimeout(timer);

    timer = setTimeout(async () => {
        const res = await fetch("/api/public/spotify_search.php?q=" + encodeURIComponent(q));
        const data = await res.json();

        if (!data.ok || !data.tracks.length) {
            sugBox.style.display = "none";
            return;
        }

        sugBox.innerHTML = "";
        data.tracks.forEach(track => {
            const el = document.createElement("div");
            el.className = "song-suggestion-item";
            el.innerHTML = `
                <img src="${track.albumArt}" class="song-suggestion-cover">
                <div class="song-suggestion-text">
                    <div class="song-suggestion-title">${track.title}</div>
                    <div class="song-suggestion-artist">${track.artist}</div>
                </div>`;
            el.onclick = () => {
                songInput.value = track.title;
                artistInput.value = track.artist;
                document.getElementById("spotify_track_id").value = track.id;
                document.getElementById("spotify_track_name").value = track.title;
                document.getElementById("spotify_artist_name").value = track.artist;
                document.getElementById("spotify_album_art_url").value = track.albumArt;
                sugBox.style.display = "none";
            };
            sugBox.appendChild(el);
        });

        const footer = document.createElement("div");
        footer.className = "song-suggestions-footer";
        footer.textContent = "Powered by Spotify";
        sugBox.appendChild(footer);

        sugBox.style.display = "block";
    }, 350);
});

document.addEventListener("click", (e) => {
    if (!sugBox.contains(e.target) && e.target !== songInput) {
        sugBox.style.display = "none";
    }
});


// When Spotify Search is turned off, clear suggestions + hidden fields
document.getElementById("spotify_toggle").addEventListener("change", () => {
    const spotifyOn = document.getElementById("spotify_toggle").checked;

    if (!spotifyOn) {
        clearSpotify();
        sugBox.innerHTML = "";
        sugBox.style.display = "none";
    }
});


const spotifyToggle = document.getElementById("spotify_toggle");
const spotifyLabel  = document.getElementById("spotify_mode_label");

spotifyToggle.addEventListener("change", () => {
    const isOn = spotifyToggle.checked;

    // Update label text
    spotifyLabel.textContent = isOn ? "Spotify Search" : "Manual Entry";

    // Clear autocomplete when off
    if (!isOn) {
        clearSpotify();
        sugBox.innerHTML = "";
        sugBox.style.display = "none";
    }
});


// =============================
// SPOTIFY TOGGLE → ARTIST VISIBILITY
// =============================
const artistGroup = document.getElementById("artist_group");

function updateArtistVisibility() {
    const spotifyOn = spotifyToggle.checked;

    if (spotifyOn) {
        // Hide artist when Spotify is active
        artistGroup.style.display = "none";
        artistInput.value = "";
    } else {
        // Show artist for manual entry
        artistGroup.style.display = "block";
    }
}

// Run on page load
updateArtistVisibility();

// Run when toggle changes
spotifyToggle.addEventListener("change", updateArtistVisibility);


// =============================
// MY REQUESTS (READ-ONLY STEP 1)
// =============================
async function loadMyRequests() {
    const list = document.getElementById("myRequestsList");
    if (!list) return;

    if (DEMO_MODE) {
        myRequestsCache = DEMO_MY_REQUESTS;
        renderMyRequests();
        return;
    }

    try {
        const res = await fetch(
            "/api/public/get_my_requests.php?event_uuid=<?= e($uuid); ?>"
        );
        const data = await res.json();

        if (!data.ok || !data.rows.length) {
            list.innerHTML = `
                <div style="color:#777;font-size:14px;text-align:center;">
                    You haven’t requested any songs yet
                </div>`;
            return;
        }

        // ✅ cache
        myRequestsCache = data.rows;
        
        myTrackKeys.clear();

data.rows.forEach(r => {
    if (r.spotify_track_id) {
        myTrackKeys.add("sp:" + r.spotify_track_id);
    } else {
        myTrackKeys.add(
            "txt:" +
            (r.song_title || "").toLowerCase() + "|" +
            (r.artist || "").toLowerCase()
        );
    }
});

        // ✅ render via sorter
        renderMyRequests();

    } catch {
        list.innerHTML = `
            <div style="color:#777;font-size:14px;text-align:center;">
                Unable to load requests
            </div>`;
    }
}

// Simple time-ago helper
function timeAgo(ts) {
    if (!ts) return "";

    // Force MySQL DATETIME to be treated as UTC
    const utcDate = new Date(ts.replace(" ", "T") + "Z");
    const diff = Math.floor((Date.now() - utcDate.getTime()) / 1000);

    if (diff < 60) return "just now";
    if (diff < 3600) return Math.floor(diff / 60) + " min ago";
    if (diff < 86400) return Math.floor(diff / 3600) + " hr ago";
    return Math.floor(diff / 86400) + " days ago";
}

// Load once on page load
loadMyRequests();

// =============================
// ALL REQUESTS (AGGREGATED)
// =============================
async function loadAllRequests() {
    const list = document.getElementById("myRequestsList");

    if (DEMO_MODE) {
        allRequestsCache = DEMO_ALL_REQUESTS;
        renderAllRequests();
        return;
    }

    try {
        const res = await fetch(
            "/api/public/get_event_requests.php?event_uuid=<?= e($uuid); ?>"
        );
        const data = await res.json();

        if (!data.ok || !data.rows.length) {
            list.innerHTML = `
                <div style="color:#777;font-size:14px;text-align:center;">
                    No requests yet
                </div>`;
            return;
        }

        allRequestsCache = data.rows;
        renderAllRequests();

    } catch {
        list.innerHTML = `
            <div style="color:#777;font-size:14px;text-align:center;">
                Unable to load requests
            </div>`;
    }
}


function renderMyRequests() {
    const list = document.getElementById("myRequestsList");
    const sortMode = document.getElementById("requests_sort").value;

    let rows = [...myRequestsCache];

    switch (sortMode) {
        case "title":
            rows.sort((a, b) =>
                (a.song_title || "").localeCompare(b.song_title || "")
            );
            break;

        case "artist":
            rows.sort((a, b) =>
                (a.artist || "").localeCompare(b.artist || "")
            );
            break;

        case "last":
        default:
            rows.sort((a, b) =>
                new Date(b.created_at) - new Date(a.created_at)
            );
    }

    list.innerHTML = "";

    rows.forEach(row => {
        const el = document.createElement("div");
        el.className = "all-request-item is-mine";

        const cover = row.spotify_album_art_url
            ? `<img src="${row.spotify_album_art_url}" class="all-request-cover" loading="lazy">`
            : `<div class="all-request-cover"></div>`;

        el.innerHTML = `
            ${cover}
            <div class="all-request-meta">
                <div class="all-request-title">
                    ${row.song_title}
                </div>
                ${row.artist ? `
                    <div class="all-request-artist">
                        ${row.artist}
                    </div>` : ""}
                <div class="my-request-time">
                    Requested ${timeAgo(row.created_at)}
                </div>
            </div>
        `;

        list.appendChild(el);
    });
}



function renderAllRequests() {
    const list = document.getElementById("myRequestsList");
    const sortMode = document.getElementById("requests_sort").value;

    if (!allRequestsCache.length) {
        list.innerHTML = `
            <div style="color:#777;font-size:14px;text-align:center;">
                No requests yet
            </div>`;
        return;
    }

    // -----------------------------------
    // 1. GROUP TRACKS (backend-owned)
    // -----------------------------------
    const groups = {};

    allRequestsCache.forEach(row => {
        const baseKey = normalizeTitle(row.song_title);

        if (!groups[baseKey]) {
            groups[baseKey] = {
                base_title: row.song_title,
                artist: row.artist,
                total_count: 0,
                variants: [],
                album_art: row.album_art || null,
                last_requested_at: row.last_requested_at,
                isMine: row.is_mine == 1
            };
        }

        groups[baseKey].total_count += row.request_count;

        groups[baseKey].variants.push({
            ...row,
            isMine: row.is_mine == 1
        });

        // bubble ownership up
        if (row.is_mine == 1) {
            groups[baseKey].isMine = true;
        }

        // most recent request time
        if (
            !groups[baseKey].last_requested_at ||
            new Date(row.last_requested_at) > new Date(groups[baseKey].last_requested_at)
        ) {
            groups[baseKey].last_requested_at = row.last_requested_at;
        }
    });

    // -----------------------------------
    // 2. SORT GROUPS
    // -----------------------------------
    let groupList = Object.values(groups);

    switch (sortMode) {
        case "last":
            groupList.sort((a, b) =>
                new Date(b.last_requested_at) - new Date(a.last_requested_at)
            );
            break;

        case "title":
            groupList.sort((a, b) =>
                (a.base_title || "").localeCompare(b.base_title || "")
            );
            break;

        case "artist":
            groupList.sort((a, b) =>
                (a.artist || "").localeCompare(b.artist || "")
            );
            break;

        default: // popularity
            groupList.sort((a, b) => b.total_count - a.total_count);
    }

    // -----------------------------------
    // 3. RENDER
    // -----------------------------------
    list.innerHTML = "";

    groupList.forEach(group => {
        const el = document.createElement("div");
        el.className = "all-request-item";

        if (group.isMine) {
            el.classList.add("is-mine");
        }

        const cover = group.album_art
            ? `<img src="${group.album_art}" class="all-request-cover" loading="lazy">`
            : `<div class="all-request-cover"></div>`;

        const expandable = group.variants.length > 1;

        el.innerHTML = `
            ${cover}
            <div class="all-request-meta">
                <div class="all-request-title">${group.base_title}</div>
                ${group.artist ? `<div class="all-request-artist">${group.artist}</div>` : ""}
                <div class="my-request-time">
                    Requested ${group.total_count}× • last ${timeAgo(group.last_requested_at)}
                </div>
            </div>
            <div class="request-count">×${group.total_count}</div>
            ${expandable ? `<div class="expand-btn">+</div>` : ""}
        `;

        list.appendChild(el);

        // -----------------------------------
        // 4. VARIANTS
        // -----------------------------------
        if (expandable) {
            const variantsWrap = document.createElement("div");
            variantsWrap.style.display = "none";
            variantsWrap.style.marginTop = "6px";

            group.variants.forEach(v => {
                const row = document.createElement("div");
                row.className = "variant-item";

                if (v.isMine) {
                    row.style.color = "#ff2fd2";
                    row.style.fontWeight = "600";
                }

                row.innerHTML = `
                    <span>${v.song_title}${v.isMine ? " ⭐" : ""}</span>
                    <span class="request-count small">×${v.request_count}</span>
                `;

                variantsWrap.appendChild(row);
            });

            el.querySelector(".expand-btn").onclick = () => {
                const open = variantsWrap.style.display === "block";
                variantsWrap.style.display = open ? "none" : "block";
                el.querySelector(".expand-btn").textContent = open ? "+" : "–";
            };

            list.appendChild(variantsWrap);
        }
    });
}


// =============================
// REQUESTS TOGGLE
// =============================
const requestsToggle = document.getElementById("requests_toggle");
const requestsLabel  = document.getElementById("requests_mode_label");

const requestsSort = document.getElementById("requests_sort");

requestsSort.addEventListener("change", () => {
    if (requestsToggle.checked) {
        renderAllRequests();
    } else {
        renderMyRequests();
    }
});


function handleRequestsToggle() {
    if (requestsToggle.checked) {
        requestsLabel.textContent = "All Requests";
        loadAllRequests();
    } else {
        requestsLabel.textContent = "My Requests";
        loadMyRequests();
    }
}

// Initial load
handleRequestsToggle();

// Toggle listener
requestsToggle.addEventListener("change", handleRequestsToggle);


</script>

</body>
</html>