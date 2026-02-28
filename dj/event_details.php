<?php
//dj/event_details.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();
$db = db();


$djId = (int)$_SESSION['dj_id'];

$eventModel = new Event();
$event = null;



// Prefer UUID in URL
if (!empty($_GET['uuid'])) {
    $uuid = trim($_GET['uuid']);
    $event = $eventModel->findByUuid($uuid);
}
// Backwards compatibility: legacy numeric ID
elseif (!empty($_GET['id'])) {
    $eventId = (int) $_GET['id'];
    $event = $eventModel->findById($eventId);
}

// If still no event, bail
if (!$event) {
    redirect('dj/events.php');
}

// Load DJ record from database
$userModel = new User();
$dj = $userModel->findById($djId);

// Spotify gating (EVENT PAGE ONLY)
$spotifyAllowed   = (bool)($dj['spotify_access_enabled'] ?? false);
$spotifyConnected = false;

if ($spotifyAllowed) {
    $stmt = $db->prepare("
        SELECT expires_at
        FROM dj_spotify_accounts
        WHERE dj_id = ?
    ");
    $stmt->execute([$djId]);
    $spotifyAccount = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($spotifyAccount && !empty($spotifyAccount['expires_at'])) {
        $spotifyConnected = (strtotime($spotifyAccount['expires_at']) > time());
    }
}

$canUseSpotifyForEvent = ($spotifyAllowed && $spotifyConnected);






// Build display name: dj_name → fallback to name → fallback default
$djDisplay = $dj['dj_name'] ?: $dj['name'] ?: 'Your DJ';

// --------------------------------------------------
// Guest message preview (what patrons currently see)
// --------------------------------------------------


$eventState = $event['event_state'] ?? 'upcoming';

$guestNotice = resolveEventNotice(
    $db,
    (int)$event['id'],
    $djId,
    $eventState
);

$guestNoticeBody = null;
if ($guestNotice && !empty($guestNotice['body'])) {
    $eventName = trim((string)($event['title'] ?? ''));
    $djNameForNotice = ($djDisplay && $djDisplay !== 'Your DJ') ? $djDisplay : '';
    $guestNoticeBody = str_replace('{{DJ_NAME}}', $djNameForNotice, $guestNotice['body']);
    $guestNoticeBody = str_replace('{{EVENT_NAME}}', $eventName, $guestNoticeBody);
}

//---------------------------------------


// Ensure event belongs to logged-in DJ
if (!$event || (int)$event['user_id'] !== $djId) {
    redirect('dj/events.php');
}

// Ensure per-event tip/boost override column exists.
function ensureEventTipsBoostColumn(PDO $db): void
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'events'
          AND COLUMN_NAME = 'tips_boost_enabled'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE events ADD COLUMN tips_boost_enabled TINYINT(1) NULL DEFAULT NULL");
    }
}

ensureEventTipsBoostColumn($db);
mdjr_ensure_premium_tables($db);

// Reload event to include any newly added column.
$event = $eventModel->findById((int)$event['id']) ?: $event;

$djPlan = mdjr_get_user_plan($db, $djId);
$isPremiumPlan = ($djPlan === 'premium');

$globalPosterSettings = $isPremiumPlan ? (mdjr_get_user_qr_settings($db, $djId) ?: []) : [];
$posterOverrideRow = $isPremiumPlan ? (mdjr_get_event_poster_override($db, (int)$event['id'], $djId) ?: []) : [];
$posterOverrideSaved = ((string)($_GET['poster_override_saved'] ?? '') === '1');
$posterOverrideError = '';

if (
    $isPremiumPlan
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['save_poster_override'])
) {
    if (!verify_csrf_token()) {
        $posterOverrideError = 'Security check failed. Please refresh and try again.';
    } else {
        $orderMap = [
            'event_name' => max(1, min(4, (int)($_POST['poster_order_event_name'] ?? 2))),
            'location' => max(1, min(4, (int)($_POST['poster_order_location'] ?? 3))),
            'date' => max(1, min(4, (int)($_POST['poster_order_date'] ?? 4))),
            'dj_name' => max(1, min(4, (int)($_POST['poster_order_dj_name'] ?? 1))),
        ];
        asort($orderMap, SORT_NUMERIC);
        $order = mdjr_normalize_poster_field_order(implode(',', array_keys($orderMap)));

        mdjr_save_event_poster_override($db, (int)$event['id'], $djId, [
            'use_override' => !empty($_POST['poster_use_override']) ? 1 : 0,
            'poster_show_event_name' => !empty($_POST['poster_show_event_name']) ? 1 : 0,
            'poster_show_location' => !empty($_POST['poster_show_location']) ? 1 : 0,
            'poster_show_date' => !empty($_POST['poster_show_date']) ? 1 : 0,
            'poster_show_dj_name' => !empty($_POST['poster_show_dj_name']) ? 1 : 0,
            'poster_field_order' => $order,
            'poster_bg_path' => $posterOverrideRow['poster_bg_path'] ?? null,
        ]);

        redirect('dj/event_details.php?uuid=' . urlencode((string)$event['uuid']) . '&poster_override_saved=1#posterOverrideCard');
    }
}

if ($isPremiumPlan) {
    $posterOverrideRow = mdjr_get_event_poster_override($db, (int)$event['id'], $djId) ?: [];
}

// Global platform gate from app_settings (production patron page).
$platformTipsBoostEnabled = false;
try {
    $stmt = $db->prepare("
        SELECT `value`
        FROM app_settings
        WHERE `key` IN ('patron_payments_enabled_prod','patron_payments_enabled')
        ORDER BY FIELD(`key`, 'patron_payments_enabled_prod','patron_payments_enabled')
        LIMIT 1
    ");
    $stmt->execute();
    $platformTipsBoostEnabled = ((string)$stmt->fetchColumn() === '1');
} catch (Throwable $e) {
    $platformTipsBoostEnabled = false;
}

// DJ default preference fallback from user settings.
$djDefaultTipsBoostEnabled = false;
try {
    $stmt = $db->prepare("
        SELECT default_tips_boost_enabled
        FROM user_settings
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$djId]);
    $djDefaultTipsBoostEnabled = ((string)$stmt->fetchColumn() === '1');
} catch (Throwable $e) {
    $djDefaultTipsBoostEnabled = false;
}

$eventTipsBoostOverrideRaw = $event['tips_boost_enabled'] ?? null;
$eventTipsBoostEnabled = ($eventTipsBoostOverrideRaw === null || $eventTipsBoostOverrideRaw === '')
    ? $djDefaultTipsBoostEnabled
    : ((int)$eventTipsBoostOverrideRaw === 1);

// Format event date for poster output
$posterDate = $event['event_date'];
if ($posterDate) {
    $dt = DateTime::createFromFormat('Y-m-d', $posterDate);
    if ($dt) {
        $posterDate = $dt->format('j F Y');
    }
}

$posterDownloadUrl = url(
    'qr_poster.php'
    . '?uuid=' . urlencode((string)$event['uuid'])
    . '&dj=' . urlencode((string)$djDisplay)
    . '&title=' . urlencode((string)($event['title'] ?? ''))
    . '&location=' . urlencode((string)($event['location'] ?? ''))
    . '&date=' . urlencode((string)$posterDate)
);
$posterPreviewUrl = $posterDownloadUrl . '&t=' . time();

$globalPosterOrder = ['dj_name', 'event_name', 'location', 'date'];
$globalPosterOrderIndex = array_flip($globalPosterOrder);
$overrideOrder = $globalPosterOrder;
$overrideOrderIndex = $globalPosterOrderIndex;
$posterUseOverride = false;
$posterShowEventName = true;
$posterShowLocation = true;
$posterShowDate = true;
$posterShowDjName = true;

if ($isPremiumPlan) {
    $globalPosterOrder = mdjr_parse_poster_field_order((string)($globalPosterSettings['poster_field_order'] ?? 'dj_name,event_name,location,date'));
    $globalPosterOrderIndex = array_flip($globalPosterOrder);
    $overrideOrder = mdjr_parse_poster_field_order((string)($posterOverrideRow['poster_field_order'] ?? implode(',', $globalPosterOrder)));
    $overrideOrderIndex = array_flip($overrideOrder);
    $posterUseOverride = !empty($posterOverrideRow['use_override']);
    $posterShowEventName = array_key_exists('poster_show_event_name', $posterOverrideRow)
        ? !empty($posterOverrideRow['poster_show_event_name'])
        : (!isset($globalPosterSettings['poster_show_event_name']) || !empty($globalPosterSettings['poster_show_event_name']));
    $posterShowLocation = array_key_exists('poster_show_location', $posterOverrideRow)
        ? !empty($posterOverrideRow['poster_show_location'])
        : (!isset($globalPosterSettings['poster_show_location']) || !empty($globalPosterSettings['poster_show_location']));
    $posterShowDate = array_key_exists('poster_show_date', $posterOverrideRow)
        ? !empty($posterOverrideRow['poster_show_date'])
        : (!isset($globalPosterSettings['poster_show_date']) || !empty($globalPosterSettings['poster_show_date']));
    $posterShowDjName = array_key_exists('poster_show_dj_name', $posterOverrideRow)
        ? !empty($posterOverrideRow['poster_show_dj_name'])
        : (!isset($globalPosterSettings['poster_show_dj_name']) || !empty($globalPosterSettings['poster_show_dj_name']));
}

$isLiveEvent = false;
if (!empty($event['event_date'])) {
    $today = date('Y-m-d');
    $isLiveEvent = ($event['event_state'] === 'live');
}

$pageTitle = "Event Details";
require __DIR__ . '/layout.php';


// ------------------------
// Event request + vote counts
// ------------------------
$stmt = $db->prepare("
    SELECT
        COALESCE(r.total_requests, 0) AS requests,
        COALESCE(v.total_votes, 0)    AS votes
    FROM events e
    LEFT JOIN event_request_stats r ON r.event_id = e.id
    LEFT JOIN event_vote_stats    v ON v.event_id = e.id
    WHERE e.id = ?
    LIMIT 1
");
$stmt->execute([(int)$event['id']]);
$counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$requestCount = (int)($counts['requests'] ?? 0);
$voteCount    = (int)($counts['votes'] ?? 0);

// ------------------------
// Recent Requests
// ------------------------

$recentRequests = [];

$stmt = $db->prepare("
    SELECT
        COALESCE(NULLIF(spotify_track_name, ''), song_title) AS title,
        COALESCE(NULLIF(spotify_artist_name, ''), artist)    AS artist,
        created_at
    FROM song_requests
    WHERE event_id = ?
      AND (
          COALESCE(NULLIF(spotify_track_name, ''), song_title) IS NOT NULL
          OR
          COALESCE(NULLIF(spotify_artist_name, ''), artist) IS NOT NULL
      )
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([(int)$event['id']]);
$recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------
// All Requests
// ------------------------

$allRequests = [];

$stmt = $db->prepare("
    SELECT
        COALESCE(NULLIF(spotify_track_name, ''), song_title) AS title,
        COALESCE(NULLIF(spotify_artist_name, ''), artist)    AS artist,
        spotify_album_art_url,
        status,
        requester_name,
        created_at
    FROM song_requests
    WHERE event_id = ?
      AND (
          COALESCE(NULLIF(spotify_track_name, ''), song_title) IS NOT NULL
          OR
          COALESCE(NULLIF(spotify_artist_name, ''), artist) IS NOT NULL
      )
    ORDER BY created_at DESC
");
$stmt->execute([(int)$event['id']]);
$allRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ------------------------
// Event Tip Summary
// ------------------------

$stmt = $db->prepare("
    SELECT
        currency,
        COUNT(*)               AS tip_count,
        SUM(amount_cents) / 100 AS total_amount
    FROM event_tips
    WHERE event_id = ?
      AND status = 'succeeded'
    GROUP BY currency
");
$stmt->execute([(int)$event['id']]);
$eventTips = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ------------------------
// Event Tip History
// ------------------------
$eventTipHistory = [];

$stmt = $db->prepare("
    SELECT
        amount_cents,
        currency,
        status,
        patron_name,
        created_at
    FROM event_tips
    WHERE event_id = ?
      AND status = 'succeeded'
    ORDER BY created_at DESC
");
$stmt->execute([(int)$event['id']]);
$eventTipHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ------------------------
// Event Boost Summary
// ------------------------
$eventBoosts = [];

$stmt = $db->prepare("
    SELECT
        currency,
        COUNT(*)                AS boost_count,
        SUM(amount_cents) / 100 AS total_amount
    FROM event_track_boosts
    WHERE event_id = ?
      AND status = 'succeeded'
    GROUP BY currency
");
$stmt->execute([(int)$event['id']]);
$eventBoosts = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ------------------------
// Event Boost History
// ------------------------
$eventBoostHistory = [];

$stmt = $db->prepare("
    SELECT
        b.id,
        b.amount_cents,
        b.currency,
        b.guest_token,
        b.patron_name,
        b.track_key,
        b.created_at,

        MAX(
            COALESCE(
                NULLIF(sr.spotify_track_name, ''),
                sr.song_title
            )
        ) AS track_title,

        MAX(
            COALESCE(
                NULLIF(sr.spotify_artist_name, ''),
                sr.artist
            )
        ) AS track_artist

    FROM event_track_boosts b

    LEFT JOIN song_requests sr
      ON sr.event_id = b.event_id
     AND (
            sr.spotify_track_id = b.track_key
         OR CONCAT(LOWER(sr.song_title), '::', LOWER(sr.artist)) = b.track_key
     )

    WHERE b.event_id = ?
      AND b.status = 'succeeded'

    GROUP BY
        b.id,
        b.amount_cents,
        b.currency,
        b.guest_token,
        b.patron_name,
        b.track_key,
        b.created_at

    ORDER BY b.created_at DESC
");


$stmt->execute([(int)$event['id']]);
$eventBoostHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);




?>

<style>
/* Event Details Page */
.event-header {
    background: radial-gradient(circle at top left, #550066 0%, #0d0d0f 80%);
    padding: 30px 25px;
    border-radius: 12px;
    margin-bottom: 35px;
}

.event-header h1 {
    margin: 0;
    font-size: 28px;
    color: #ff2fd2;
}

.event-meta {
    margin-top: 10px;
    color: #ccc;
    font-size: 15px;
}

.action-buttons {
    margin-top: 25px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.action-spacer {
    flex: 1;
}

.action-buttons a {
    background: #292933;
    padding: 10px 18px;
    border-radius: 6px;
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.action-buttons a:hover {
    background: #383844;
}

.section-card {
    background: #1a1a1f;
    border: 1px solid #292933;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 35px;
}

.section-card h2 {
    margin-top: 0;
    color: #ff2fd2;
}
.premium-badge {
  display: inline-block;
  margin-left: 8px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
  background: rgba(255,47,210,0.18);
  border: 1px solid rgba(255,47,210,0.55);
  color: #ff7de8;
  vertical-align: middle;
}

.data-row {
    margin: 8px 0;
    font-size: 14px;
    color: #ccc;
}

.data-row strong {
    color: #fff;
}

/* QR SECTION STYLING */
.qr-section {
    text-align: center;
    padding-bottom: 10px;
}

.qr-wrapper {
    position: relative;
    display: inline-block;
    padding: 18px;
    background: #0c0c11;
    border-radius: 16px;
    border: 2px solid #ff2fd2;
    box-shadow: 0 0 20px rgba(255, 47, 210, 0.4);
}

.qr-image {
    width: 240px;
    height: 240px;
    display: block;
}

.qr-glow {
    position: absolute;
    top: -15px;
    left: -15px;
    right: -15px;
    bottom: -15px;
    border-radius: 20px;
    background: radial-gradient(circle, rgba(255,47,210,0.4), transparent 70%);
    animation: pulseGlow 2.5s infinite;
    z-index: -1;
}

@keyframes pulseGlow {
    0% { opacity: 0.4; transform: scale(1); }
    50% { opacity: 0.8; transform: scale(1.05); }
    100% { opacity: 0.4; transform: scale(1); }
}

.scan-me {
    margin-top: 12px;
    font-size: 18px;
    font-weight: bold;
    color: #ff2fd2;
    animation: scanPulse 1.4s infinite;
}

@keyframes scanPulse {
    0% { transform: scale(1); opacity: .6; }
    50% { transform: scale(1.1); opacity: 1; }
    100% { transform: scale(1); opacity: .6; }
}

.dj-name {
    margin-top: 8px;
    color: #fff;
    font-size: 20px;
    font-weight: 600;
    text-shadow: 0 0 6px rgba(255,47,210,0.8);
}

/* QR TILE POSITIONING */
.qr-tile {
    position: relative;
}

.qr-top-action {
    position: absolute;
    top: 18px;
    right: 18px;
    z-index: 2;
}

.qr-primary-action {
    margin-top: 20px;
}

.qr-downloads {
    margin-top: 26px;
    padding-top: 18px;
    border-top: 1px solid #292933;
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-width: 360px;
    margin-left: auto;
    margin-right: auto;
}


/* QR split layout */
.qr-content {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 40px;
    flex-wrap: wrap;
}

/* Right-side button column */
.qr-actions-right {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 260px;
}

/* Separate downloads visually */
.qr-actions-right .downloads {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #292933;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.poster-studio-layout {
  display: grid;
  grid-template-columns: minmax(260px, 340px) minmax(280px, 1fr);
  gap: 16px;
  align-items: start;
}

.poster-preview-wrap {
  background: #10111a;
  border: 1px solid #2a2a3a;
  border-radius: 10px;
  padding: 10px;
}

.poster-preview-frame {
  width: 100%;
  max-width: 320px;
  aspect-ratio: 210 / 297;
  border: 1px solid #2b2d3b;
  border-radius: 8px;
  overflow: hidden;
  background: #ffffff;
}

.poster-preview-frame img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
  background: #fff;
}

.poster-override-form {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
  background: #11121a;
  border: 1px solid #2b2d3b;
  border-radius: 10px;
  padding: 12px;
}


/* Ghost button for top-right DJ link */
.qr-top-action a {
    background: transparent;
    border: 1px solid #3a3a46;
    padding: 10px 16px;
    border-radius: 10px;
    color: #cfcfd8;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.qr-top-action a:hover {
    background: #292933;
    border-color: #ff2fd2;
    color: #fff;
}


.copy-patron-link {
    margin-top: 8px;
    font-size: 13px;
    color: #cfcfd8;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    opacity: 0.85;
}

.copy-patron-link:hover {
    opacity: 1;
    color: #ff2fd2;
}

.poster-override-tiles {
  display: grid;
  grid-template-columns: 1fr;
  gap: 8px;
}

.poster-override-tile {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 10px;
  border: 1px solid #2b2d3b;
  border-radius: 8px;
  background: #151722;
  cursor: grab;
  user-select: none;
}

.poster-override-tile label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #dde0ea;
  font-size: 12px;
  font-weight: 600;
}

.poster-override-tile .drag-handle {
  color: #aab0bf;
}

.poster-override-tile.dragging {
  opacity: .6;
  border-color: #ff2fd2;
}


/* LIVE indicator */
.live-indicator {
    margin-left: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: #ff4d4d;
}

.live-indicator .dot {
    width: 8px;
    height: 8px;
    background: #ff4d4d;
    border-radius: 50%;
    animation: livePulse 1.4s infinite;
}

@keyframes livePulse {
    0%   { opacity: 0.4; }
    50%  { opacity: 1; }
    100% { opacity: 0.4; }
}


/* Mobile layout adjustments */
@media (max-width: 768px) {

    .qr-content {
        flex-direction: column;
        align-items: center;
    }

    .qr-actions-right {
        width: 100%;
        max-width: 360px;
        align-items: stretch;
    }

    .qr-actions-right a {
        width: 100%;
        text-align: center;
    }

    .poster-studio-layout {
        grid-template-columns: 1fr;
    }

    .poster-override-tiles {
        grid-template-columns: 1fr;
    }

    .copy-patron-link {
        justify-content: center;
    }

    /* Prevent top-right button overlapping on mobile */
    .qr-top-action {
        position: static;
        margin-bottom: 12px;
        text-align: right;
    }
}



/* =========================
   EVENT STATE ROW (DETAILS)
========================= */

.event-state-row {
  margin-top: 18px;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 14px;
}

/* Badge */
.event-state-badge {
  height: 32px;
  padding: 0 16px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.12em;

  display: inline-flex;
  align-items: center;
  justify-content: center;
}

/* UPCOMING */
.event-upcoming {
  background: rgba(255,255,255,0.12);
  color: #fff;
  border: 1px solid rgba(255,255,255,0.25);
}

/* LIVE */
.event-live {
  background: linear-gradient(135deg, #ff2fd2, #ff453a);
  color: #050510;
  box-shadow:
    0 0 12px rgba(255,69,58,0.45),
    0 0 22px rgba(255,47,210,0.35);
  animation: pulseLive 1.4s infinite;
}

/* ENDED */
.event-ended {
  background: rgba(255,255,255,0.08);
  color: #ccc;
  border: 1px solid rgba(255,255,255,0.18);
}

/* Buttons */
.event-state-btn {
  height: 32px;
  padding: 0 18px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 700;
  border: none;
  cursor: pointer;
}

.event-state-badge {
  height: 32px;
  padding: 0 16px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.12em;

  display: inline-flex;
  align-items: center;
  justify-content: center;
}

/* Button variants */
.btn-live {
  background: #2ecc71;
  color: #000;
}

.btn-end {
  background: #e74c3c;
  color: #fff;
}

.btn-reopen {
  background: #555;
  color: #fff;
}

.tips-boost-card-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}

.tips-boost-status {
  font-size: 13px;
  color: #c9cad8;
  margin-top: 4px;
}

.tips-boost-status strong {
  color: #fff;
}

.tips-boost-toggle-wrap {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.tips-boost-note {
  font-size: 11px;
  color: #9ea0af;
  white-space: nowrap;
}

.btn-tips-on {
  background: #2ecc71;
  color: #000;
}

.btn-tips-off {
  background: #6a6f7e;
  color: #fff;
}

.event-upcoming {
  background: rgba(255,255,255,0.12);
  color: #fff;
  border: 1px solid rgba(255,255,255,0.25);
}

.event-live {
  background: linear-gradient(135deg, #ff2fd2, #ff453a);
  color: #050510;
  animation: pulseLive 1.4s infinite;
}

.event-ended {
  background: rgba(255,255,255,0.08);
  color: #ccc;
  border: 1px solid rgba(255,255,255,0.18);
}




.badge-requests {
    background: rgba(255, 47, 210, 0.18);
    color: #ff2fd2;
    border: 1px solid rgba(255, 47, 210, 0.55);
    font-size: 12px;
    font-weight: 800;
    padding: 5px 11px;
    border-radius: 999px;
    letter-spacing: 0.04em;
}

.badge-requests.zero {
    background: rgba(150, 150, 150, 0.15);
    border-color: rgba(150, 150, 150, 0.35);
    color: #888;
}

.badge-votes {
    background: rgba(106, 227, 255, 0.18);
    color: #6ae3ff;
    border: 1px solid rgba(106, 227, 255, 0.55);
    font-size: 12px;
    font-weight: 800;
    padding: 5px 11px;
    border-radius: 999px;
    letter-spacing: 0.04em;
}

.badge-votes.zero {
    background: rgba(150, 150, 150, 0.15);
    border-color: rgba(150, 150, 150, 0.35);
    color: #888;
}


/* =========================
   REQUEST LIST
========================= */

.request-list {
    margin-top: 10px;
}

/* Base row */
.request-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px solid #292933;
}

.request-row:last-child {
    border-bottom: none;
}

/* Compact rows (collapsed view) */
.request-row.compact {
    padding: 6px 4px;
}

/* Text stack */
.request-meta {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.request-title {
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.request-artist {
    font-size: 13px;
    color: #aaa;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Optional album art (expanded view only) */
.request-art {
    width: 48px;
    height: 48px;
    border-radius: 6px;
    flex-shrink: 0;
}

/* Base badge */
.request-status {
    margin-left: auto;
    font-size: 11px;
    font-weight: 800;
    padding: 4px 10px;
    border-radius: 999px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border: 1px solid transparent;
}

/* UNPLAYED (NEW + ACCEPTED) */
.status-new,
.status-accepted {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border-color: rgba(255,255,255,0.35);
}

/* PLAYED */
.status-played {
    background: rgba(46, 204, 113, 0.18);
    color: #2ecc71;
    border-color: rgba(46, 204, 113, 0.45);
}

/* SKIPPED */
.status-skipped {
    background: rgba(231, 76, 60, 0.18);
    color: #e74c3c;
    border-color: rgba(231, 76, 60, 0.45);
}

/* Utility */
.hidden {
    display: none;
}




.request-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.request-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

#requestSort {
    background: #0c0c11;
    border: 1px solid #292933;
    color: #ccc;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 13px;
    cursor: pointer;
}

.toggle-btn {
    background: none;
    border: 1px solid #292933;
    padding: 6px 14px;
    border-radius: 999px;
    color: #ccc;
    font-size: 13px;
    cursor: pointer;
}

.toggle-btn:hover {
    border-color: #ff2fd2;
    color: #fff;
}

.request-export {
    margin-bottom: 14px;
    text-align: right;
}

.request-export a {
    font-size: 13px;
    color: #ff2fd2;
    text-decoration: none;
    font-weight: 600;
}

.muted {
    color: #777;
}


/* =========================
   CSV DOWNLOAD BUTTON
========================= */

.btn-csv {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    padding: 8px 16px;
    border-radius: 8px;

    background: #292933;
    border: 1px solid #3a3a46;

    color: #fff;
    font-size: 13px;
    font-weight: 700;

    text-decoration: none;
    cursor: pointer;

    transition: all 0.2s ease;
}

.btn-csv:hover {
    background: #383844;
    border-color: #ff2fd2;
    color: #ff2fd2;
}

.btn-csv:active {
    transform: translateY(1px);
}


/*QR CODE HEADER*/


.qr-header {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    margin-bottom: 18px;
}

.qr-header h2 {
    margin: 0;
    text-align: center;
    color: #ff2fd2;
}

/* Shared button look */
.qr-dev-btn,
.qr-prod-btn {
    background: transparent;
    border: 1px solid #3a3a46;
    padding: 8px 14px;
    border-radius: 10px;
    color: #cfcfd8;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

/* DEV button */
.qr-dev-btn {
    border-color: #ff453a;
    color: #ff453a;
}

.qr-dev-btn:hover {
    background: rgba(255,69,58,0.12);
}

/* PROD button */
.qr-prod-btn:hover {
    background: #292933;
    border-color: #ff2fd2;
    color: #fff;
}



.tip-row {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:10px 14px;
    border-bottom:1px solid #292933;
    font-size:14px;
}

.tip-row:last-child {
    border-bottom:none;
}


.tip-totals{
  display:flex;
  gap:48px;
  align-items:flex-start;
  flex-wrap:wrap;
}

.tip-total-block{
  display:flex;
  flex-direction:column;
  align-items:flex-start; /* keeps both columns consistent */
  min-width: 180px;       /* helps alignment */
}

.tip-total-amount{
  font-size:22px;
  font-weight:800;
  line-height:1.1;
}

.tip-total-sub{
  margin-top:6px;
  font-size:13px;
  color:#888;
  line-height:1.2;
}

.tip-total-amount.boost{
  color:#6ae3ff;
}

.tip-total-sub.boost{
  color:#8fdfff;
}


.request-artist {
    font-size: 13px;
    color: #aaa;
}

.request-meta-inline {
    margin-left: 14px;            /* 👈 space between artist & requester */
    font-size: 12px;
    color: #777;
    white-space: nowrap;
}

.requester-name {
    color: #999;
    font-weight: 600;
}

.request-meta-inline .request-time {
    margin-left: 6px;
    color: #666;
}

</style>

<!-- EVENT HEADER -->
<div class="event-header">
    <h1 style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <?php echo e($event['title']); ?>

    <span class="badge-requests <?php echo $requestCount === 0 ? 'zero' : ''; ?>">
        <?php echo $requestCount; ?> Requests
    </span>

    <span class="badge-votes <?php echo $voteCount === 0 ? 'zero' : ''; ?>">
        <?php echo $voteCount; ?> Votes
    </span>
</h1>

    <div class="event-meta">
        <strong>Date:</strong> <?php echo e($event['event_date'] ?: 'No date set'); ?><br>
        <strong>Location:</strong> <?php echo e($event['location'] ?: 'N/A'); ?><br>
        <strong>UUID:</strong> <?php echo e($event['uuid']); ?>
    </div>


    <!-- IMPORTANT: action-buttons is CLOSED properly -->
<div class="action-buttons">

    <a href="<?php echo e(url('dj/events.php')); ?>">← Back to Events</a>
    <a href="<?php echo e(url('dj/event_edit.php?uuid=' . $event['uuid'])); ?>">✏️ Edit Event</a>

    <!-- SPACER pushes state controls to the right -->
    <div class="action-spacer"></div>

    <!-- ACTION BUTTON FIRST -->
    <?php if ($event['event_state'] === 'upcoming'): ?>

        <button
          id="toggleLiveBtn"
          class="event-state-btn btn-live"
          data-event-id="<?php echo (int)$event['id']; ?>"
          data-current-state="upcoming"
        >
          Go Live
        </button>

    <?php elseif ($event['event_state'] === 'live'): ?>

        <button
          id="toggleLiveBtn"
          class="event-state-btn btn-end"
          data-event-id="<?php echo (int)$event['id']; ?>"
          data-current-state="live"
        >
          End Event
        </button>

    <?php elseif ($event['event_state'] === 'ended'): ?>

        <button
          id="revertUpcomingBtn"
          class="event-state-btn btn-reopen"
          data-event-id="<?php echo (int)$event['id']; ?>"
        >
          Reopen – Set Upcoming
        </button>

    <?php endif; ?>

    <!-- STATE BADGE LAST -->
    <span
      id="eventStateBadge"
      class="event-state-badge event-<?php echo e($event['event_state']); ?>"
    >
      <?php echo strtoupper($event['event_state']); ?>
    </span>

</div>
</div>

<?php if ($platformTipsBoostEnabled): ?>
<!-- TIPS / BOOST SETTINGS -->
<div class="section-card">
    <div class="tips-boost-card-row">
        <div style="min-width:280px;flex:1;">
            <h2 style="margin-top:0;">Tips & Boost Settings</h2>
            <p class="tips-boost-status">
                Event setting: <strong><?php echo $eventTipsBoostEnabled ? 'ON' : 'OFF'; ?></strong><br>
                Platform: <strong>ENABLED</strong>
            </p>
        </div>

        <div class="tips-boost-toggle-wrap">
            <button
              id="toggleTipsBoostBtn"
              type="button"
              class="event-state-btn <?php echo $eventTipsBoostEnabled ? 'btn-tips-on' : 'btn-tips-off'; ?>"
              data-event-id="<?php echo (int)$event['id']; ?>"
              data-current-enabled="<?php echo $eventTipsBoostEnabled ? '1' : '0'; ?>"
            >
              Tips/Boost: <?php echo $eventTipsBoostEnabled ? 'ON' : 'OFF'; ?>
            </button>
            <span class="tips-boost-note">Per-event override</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- VIEW DJ PAGE (PROMINENT) -->
<div class="section-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;">
        <div style="min-width:280px;flex:1;">
            <h2 style="margin-top:0;">View DJ Event Page</h2>
            <p style="color:#bbb;margin-bottom:0;">
                Open the DJ control page for this event.
            </p>
        </div>

        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:10px;min-width:300px;">
            <a
                href="<?php echo e(url('dj/?event=' . $event['uuid'])); ?>"
                target="_blank"
                class="qr-prod-btn"
                style="display:inline-flex;padding:12px 18px;font-size:16px;"
            >
                🎧 View DJ Event Page
                <?php if ($isLiveEvent): ?>
                    <span class="live-indicator">
                        <span class="dot"></span> LIVE
                    </span>
                <?php endif; ?>
            </a>

            <?php if (is_admin()): ?>
                <a
                    href="<?php echo e(url('dj/index.dev.php?event=' . $event['uuid'])); ?>"
                    target="_blank"
                    class="qr-dev-btn"
                    title="Development DJ Page"
                >
                    🧪 DJ Page V2
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- GUEST/PATRON LINK BLOCK -->
<div class="section-card">
    <h2>Guest/Patron Page Access Link</h2>

    <p style="color:#bbb;margin-bottom:10px;">
        Share this link with guests so they can submit song requests.
    </p>

    <div style="display:flex; align-items:center; gap:10px;">
        <code id="publicRequestLink" style="flex:1;">
            <?php echo url('r/' . $event['uuid']); ?>
        </code>

        <button
            id="copyRequestLinkBtn"
            style="
                background:#ff2fd2;
                border:none;
                padding:6px 12px;
                border-radius:6px;
                color:#fff;
                cursor:pointer;
                font-size:13px;
                font-weight:bold;
            "
        >
            📋 Copy
        </button>
    </div>

<div id="copyFeedback"
         style="font-size:12px;color:#5fdb6e;margin-top:6px;display:none;">
        Copied!
    </div>
</div>

<!-- QR CODE SECTION -->
<div class="section-card qr-section qr-tile">
    
    
    

<div class="qr-header">

    <span></span>

    <h2>Event QR Code</h2>
    <span></span>

</div>

<p style="color:#bbb; margin-bottom:25px;">
    Guests scan this to request songs instantly.
</p>

<div class="qr-content">

    <!-- LEFT: QR -->
    <div>
        <?php
            if ($isPremiumPlan) {
                $qrUrl = url('qr/premium_generate.php?uuid=' . urlencode($event['uuid']));
            } else {
                $qrUrl = url(
                    'qr_generate.php?uuid=' . urlencode($event['uuid']) .
                    '&dj=' . urlencode($djDisplay)
                );
            }
        ?>

        <div class="qr-wrapper">
            <div class="qr-glow"></div>

            <div style="font-size:20px;font-weight:bold;margin-bottom:10px;">
                MyDjRequests.com
            </div>

            <img src="<?php echo $qrUrl; ?>" class="qr-image">

            <div class="scan-me">SCAN ME</div>
            <div class="dj-name"><?php echo e($djDisplay); ?></div>
            
            
           
            
        </div>

    </div>

    <!-- RIGHT: ACTIONS -->
    <div class="qr-actions-right">

        <a href="<?php echo e(url('r/' . $event['uuid'])); ?>"
           target="_blank"
           style="
                background:linear-gradient(135deg,#ff2fd2,#ff44de);
                padding:14px 22px;
                border-radius:10px;
                color:#fff;
                font-weight:bold;
                text-decoration:none;
                text-align:center;
                font-size:16px;
           ">
            🔗 View Patron Request Page
        </a>
        
        

<?php if (is_admin()): ?>
    <div style="
        margin-top:22px;
        padding-top:16px;
        border-top:1px dashed #333;
        opacity:.85;
    ">
        <div style="
            font-size:12px;
            color:#aaa;
            margin-bottom:10px;
            text-align:center;
        ">
            Admin Preview / Comparison
        </div>

        <a href="<?php echo e(url('r2/' . $event['uuid'])); ?>"
           target="_blank"
           style="
                background:#2a2a3a;
                padding:12px 20px;
                border-radius:8px;
                color:#fff;
                font-weight:600;
                text-decoration:none;
                text-align:center;
                font-size:14px;
                display:block;
           ">
            🧪 View Patron Page (V2 Preview)
        </a>

        <div 
            class="copy-patron-link"
            onclick="copyPatronLink('<?php echo url('r2/' . $event['uuid']); ?>')"
            style="justify-content:center;"
        >
            🧪 Copy V2 preview link
        </div>
    </div>
<?php endif; ?>

        <div class="downloads">
            <a href="<?php echo e(url('qr_download.php?uuid=' . $event['uuid'])); ?>"
               style="
                    background:#292933;
                    padding:12px 20px;
                    border-radius:8px;
                    color:#fff;
                    font-weight:bold;
                    text-decoration:none;
                    text-align:center;
               ">
                ⬇️ Download OBS QR Image
            </a>

        </div>

    </div>
</div>

</div>

<div class="section-card" id="posterOverrideCard">
    <h2>A4 Poster Studio <span class="premium-badge">Premium</span></h2>
    <p style="color:#bbb;margin-bottom:16px;">
        Preview and manage printable A4 poster layout. Global settings can be overridden for this event only.
    </p>

    <div class="poster-studio-layout">
        <div class="poster-preview-wrap">
            <div style="font-size:12px;color:#aeb4c3;margin-bottom:8px;">A4 Poster Preview (this event)</div>
            <div class="poster-preview-frame">
                <img src="<?php echo e($posterPreviewUrl); ?>" alt="A4 poster preview">
            </div>
            <div style="font-size:12px;color:#aeb4c3;margin-top:8px;">
                Preview reflects current global style + this event override.
            </div>
            <a href="<?php echo e($posterDownloadUrl); ?>"
               style="
                    margin-top:10px;
                    background:#ff2fd2;
                    padding:12px 20px;
                    border-radius:8px;
                    color:#fff;
                    font-weight:bold;
                    text-decoration:none;
                    text-align:center;
                    display:block;
               ">
                🖨 Download Printable A4 Poster
            </a>
        </div>

        <?php if ($isPremiumPlan): ?>
            <div>
                <div style="font-weight:700;color:#fff;margin-bottom:6px;">Poster Override (This Event Only)</div>
                <div style="font-size:12px;color:#9aa1b3;line-height:1.45;margin-bottom:10px;">
                    Optional premium override. Leave this OFF to inherit your Global QR Style poster layout.
                </div>
                <?php if ($posterOverrideSaved): ?>
                    <div style="font-size:12px;color:#6de29d;margin-bottom:8px;">Poster override saved.</div>
                <?php endif; ?>
                <?php if ($posterOverrideError !== ''): ?>
                    <div style="font-size:12px;color:#ff9ca8;margin-bottom:8px;"><?php echo e($posterOverrideError); ?></div>
                <?php endif; ?>
                <form method="POST" id="eventPosterOverrideForm" class="poster-override-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="save_poster_override" value="1">
                    <label style="display:flex;align-items:center;gap:8px;color:#ddd;font-size:13px;">
                        <input type="checkbox" name="poster_use_override" value="1" <?php echo $posterUseOverride ? 'checked' : ''; ?>>
                        Enable event-specific poster layout
                    </label>
                    <div>
                        <div style="font-size:12px;color:#9aa1b3;margin-bottom:6px;">Drag to reorder. Check to show/hide.</div>
                        <div id="eventPosterOverrideTiles" class="poster-override-tiles">
                            <div class="poster-override-tile" data-field="event_name" draggable="true">
                                <label><input type="checkbox" name="poster_show_event_name" value="1" <?php echo $posterShowEventName ? 'checked' : ''; ?>> Event Name</label>
                                <span class="drag-handle">↕</span>
                            </div>
                            <div class="poster-override-tile" data-field="location" draggable="true">
                                <label><input type="checkbox" name="poster_show_location" value="1" <?php echo $posterShowLocation ? 'checked' : ''; ?>> Location</label>
                                <span class="drag-handle">↕</span>
                            </div>
                            <div class="poster-override-tile" data-field="date" draggable="true">
                                <label><input type="checkbox" name="poster_show_date" value="1" <?php echo $posterShowDate ? 'checked' : ''; ?>> Date</label>
                                <span class="drag-handle">↕</span>
                            </div>
                            <div class="poster-override-tile" data-field="dj_name" draggable="true">
                                <label><input type="checkbox" name="poster_show_dj_name" value="1" <?php echo $posterShowDjName ? 'checked' : ''; ?>> DJ Name</label>
                                <span class="drag-handle">↕</span>
                            </div>
                        </div>
                        <input type="hidden" name="poster_order_event_name" value="<?php echo (int)(($overrideOrderIndex['event_name'] ?? $globalPosterOrderIndex['event_name'] ?? 1) + 1); ?>">
                        <input type="hidden" name="poster_order_location" value="<?php echo (int)(($overrideOrderIndex['location'] ?? $globalPosterOrderIndex['location'] ?? 2) + 1); ?>">
                        <input type="hidden" name="poster_order_date" value="<?php echo (int)(($overrideOrderIndex['date'] ?? $globalPosterOrderIndex['date'] ?? 3) + 1); ?>">
                        <input type="hidden" name="poster_order_dj_name" value="<?php echo (int)(($overrideOrderIndex['dj_name'] ?? $globalPosterOrderIndex['dj_name'] ?? 0) + 1); ?>">
                    </div>

                    <div style="display:flex;justify-content:flex-end;">
                        <button type="submit" class="settings-btn" style="padding:8px 12px;font-size:12px;">Save Poster Override</button>
                    </div>
                </form>
                <script>
                (function () {
                    const form = document.getElementById('eventPosterOverrideForm');
                    const tilesWrap = document.getElementById('eventPosterOverrideTiles');
                    if (!form || !tilesWrap) return;
                    let dragging = null;

                    function inputNameForField(field) {
                        if (field === 'event_name') return 'poster_order_event_name';
                        if (field === 'location') return 'poster_order_location';
                        if (field === 'date') return 'poster_order_date';
                        if (field === 'dj_name') return 'poster_order_dj_name';
                        return '';
                    }

                    function syncInputsFromTiles() {
                        Array.from(tilesWrap.querySelectorAll('.poster-override-tile')).forEach((tile, idx) => {
                            const inputName = inputNameForField(tile.getAttribute('data-field') || '');
                            if (!inputName) return;
                            const hidden = form.querySelector('input[name="' + inputName + '"]');
                            if (hidden) hidden.value = String(idx + 1);
                        });
                    }

                    function reorderTilesByInputs() {
                        const tiles = Array.from(tilesWrap.querySelectorAll('.poster-override-tile'));
                        tiles.sort((a, b) => {
                            const aName = inputNameForField(a.getAttribute('data-field') || '');
                            const bName = inputNameForField(b.getAttribute('data-field') || '');
                            const aVal = parseInt(form.querySelector('input[name="' + aName + '"]')?.value || '99', 10);
                            const bVal = parseInt(form.querySelector('input[name="' + bName + '"]')?.value || '99', 10);
                            return aVal - bVal;
                        });
                        tiles.forEach((tile) => tilesWrap.appendChild(tile));
                        syncInputsFromTiles();
                    }

                    Array.from(tilesWrap.querySelectorAll('.poster-override-tile')).forEach((tile) => {
                        tile.addEventListener('dragstart', () => {
                            dragging = tile;
                            tile.classList.add('dragging');
                        });
                        tile.addEventListener('dragend', () => {
                            tile.classList.remove('dragging');
                            dragging = null;
                            syncInputsFromTiles();
                        });
                        tile.addEventListener('dragover', (e) => {
                            e.preventDefault();
                            if (!dragging || dragging === tile) return;
                            const rect = tile.getBoundingClientRect();
                            const insertAfter = (e.clientY - rect.top) > (rect.height / 2);
                            if (insertAfter) {
                                tilesWrap.insertBefore(dragging, tile.nextSibling);
                            } else {
                                tilesWrap.insertBefore(dragging, tile);
                            }
                        });
                    });

                    form.addEventListener('submit', syncInputsFromTiles);
                    reorderTilesByInputs();
                })();
                </script>
            </div>
        <?php else: ?>
            <div class="settings-help" style="line-height:1.45;">
                Poster editor/customization is available on <strong>Premium</strong>. Your <strong>A4 poster download</strong> remains available on Pro with default global layout.
            </div>
        <?php endif; ?>
    </div>
</div>





<?php if ($canUseSpotifyForEvent): ?>

<?php
// ---- Spotify Event Tools ----


// Fetch playlist (include visibility)
$stmt = $db->prepare("
    SELECT spotify_playlist_id, spotify_playlist_url, is_public
    FROM event_spotify_playlists
    WHERE event_id = ?
    LIMIT 1
");
$stmt->execute([(int)$event['id']]);
$spotifyPlaylist = $stmt->fetch(PDO::FETCH_ASSOC);

$playlistUrl  = $spotifyPlaylist['spotify_playlist_url'] ?? null;
$hasPlaylist  = !empty($spotifyPlaylist['spotify_playlist_id']);
$isPublic     = !empty($spotifyPlaylist['is_public']);
?>



<div class="section-card" id="spotifyEventTools">
    <h2>🎵 Spotify Event Playlist (Unplayed Requests)</h2>

    <p style="color:#bbb; margin-bottom:16px;">
        A live backup playlist containing Spotify-backed song requests that haven’t been played or skipped yet.
    </p>

    <?php if (!$hasPlaylist): ?>

        <button
            id="btnCreateSpotifyPlaylist"
            data-event-id="<?php echo (int)$event['id']; ?>"
            style="
                background:#1db954;
                color:#000;
                padding:12px 18px;
                border-radius:8px;
                border:none;
                font-weight:800;
                cursor:pointer;
            "
        >
            ➕ Create Spotify Playlist
        </button>

    <?php else: ?>

        <!-- BUTTON ROW -->
        <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start;">

            <!-- LEFT COLUMN -->
            <div style="display:flex; flex-direction:column;">

                <a
                    href="<?php echo e($playlistUrl); ?>"
                    target="_blank"
                    style="
                        background:#1db954;
                        color:#000;
                        padding:12px 18px;
                        border-radius:8px;
                        font-weight:800;
                        text-decoration:none;
                        display:inline-block;
                        width:fit-content;   /* 👈 THIS fixes it */
                    "
                >
                    🎧 Open Playlist on Spotify
                </a>

                <p style="font-size:13px; color:#aaa; margin-top:8px;">
                    ℹ️ Playlist visibility (Public / Private) can be changed directly in Spotify.
                </p>

            </div>

            <!-- RIGHT COLUMN -->

            <button
                id="btnSyncSpotifyPlaylist"
                data-event-id="<?php echo (int)$event['id']; ?>"
                title="Manually sync playlist with current active Spotify-backed requests."
                style="
                    background:#1f6feb;
                    color:#fff;
                    padding:12px 18px;
                    border-radius:8px;
                    border:1px solid #3b82f6;
                    cursor:pointer;
                "
            >
                🔄 Sync Now
            </button>

            
            <button
                id="btnRebuildSpotifyPlaylist"
                data-event-id="<?php echo (int)$event['id']; ?>"
                title="Rebuild playlist from current active requests. Use only if the playlist was created late or got out of sync."
                style="
                    background:#4b2d7f;
                    color:#fff;
                    padding:12px 18px;
                    border-radius:8px;
                    border:1px solid #6a4aa5;
                    cursor:pointer;
                "
            >
                ♻️ Rebuild Playlist
            </button>
        </div>


    <?php endif; ?>

</div>


<?php endif; ?>



<div class="section-card">

    <!-- HEADER -->
    <div class="request-header">
        <h2 id="requestTitle">🎶 Recent Song Requests</h2>

        <div class="request-controls">

            <!-- SORT (hidden until expanded) -->
            <select
                id="requestSort"
                class="hidden"
            >
                <option value="requested">Requested (Newest)</option>
                <option value="title">Title (A–Z)</option>
                <option value="artist">Artist (A–Z)</option>
            </select>

            <?php if (!empty($allRequests)): ?>
                <button
                    class="toggle-btn"
                    data-target="allRequests"
                >
                    View all
                </button>
            <?php endif; ?>

        </div>
    </div>

    <!-- COLLAPSED VIEW -->
    <div class="request-preview" id="requestPreview">

        <?php if (!empty($recentRequests)): ?>
            <?php foreach ($recentRequests as $r): ?>
                <div class="request-row compact">
                    <strong><?php echo e($r['title']); ?></strong>

                    <span class="request-artist-line">
                        <span class="artist-name">
                            <?php echo e($r['artist']); ?>
                        </span>

                        <!-- populated when expanded/sorted -->
                        <span
                            class="request-time hidden"
                            data-utc="<?php echo e($r['created_at']); ?>"
                        ></span>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <small class="muted">No requests yet</small>
        <?php endif; ?>

    </div>

    <!-- EXPANDED VIEW -->
    <div class="request-full hidden" id="allRequests">

        <!-- EXPORT -->
<div class="request-export">
    <a
        href="/dj/export_requests_csv.php?event_id=<?php echo (int)$event['id']; ?>"
        class="btn-csv"
    >
        ⬇ Download CSV
    </a>
</div>

<?php
$tz = new DateTimeZone(date_default_timezone_get());
?>

        <?php foreach ($allRequests as $r): ?>
        
<?php
    $dt = new DateTime($r['created_at'], new DateTimeZone('UTC'));
    $dt->setTimezone($tz);
    $localRequested = $dt->format('j M · H:i');
?>
<div
    class="request-row"
    data-title="<?php echo e(strtolower($r['title'])); ?>"
    data-artist="<?php echo e(strtolower($r['artist'])); ?>"
    data-requested="<?php echo strtotime($r['created_at']); ?>"
    data-requested-local="<?php echo e($localRequested); ?>"
>

                <?php if (!empty($r['spotify_album_art_url'])): ?>
                    <img
                        src="<?php echo e($r['spotify_album_art_url']); ?>"
                        class="request-art"
                        loading="lazy"
                    >
                <?php endif; ?>

                <div class="request-meta">
                    <strong><?php echo e($r['title']); ?></strong>
                    
<span class="request-artist">
    <?php echo e($r['artist']); ?>

    <span class="request-meta-inline">
        · Requested by
        <span class="requester-name">
            <?php echo e($r['requester_name'] ?: 'Guest'); ?>
        </span>
        <span class="request-time hidden"></span>
    </span>
</span>
                    
                </div>

                <span class="request-status status-<?php echo e($r['status']); ?>">
                    <?php echo e($r['status']); ?>
                </span>

            </div>
        <?php endforeach; ?>

    </div>

</div>


<?php
// Display timezone (server / DJ local)
$tz = new DateTimeZone(date_default_timezone_get());
// later you can replace with $dj['timezone']
?>


<!-- TIPS + BOOSTS -->
<?php if (!empty($eventTipHistory) || !empty($eventBoostHistory)): ?>
<div class="section-card">

    <!-- HEADER -->
    <div class="request-header">
        <h2 id="tipTitle">💸 Tips & ⚡ Boosts</h2>

        <div class="request-controls">
            <select
                id="tipSort"
                style="
                    background:#0c0c11;
                    border:1px solid #292933;
                    color:#ccc;
                    padding:6px 10px;
                    border-radius:8px;
                    font-size:13px;
                    cursor:pointer;
                "
            >
                <option value="recent">Recent</option>
                <option value="type">By type</option>
            </select>
        
            <button
                class="toggle-btn"
                data-target="tipHistory"
            >
                View all
            </button>
        </div>
    </div>

    <!-- TOTALS (ALWAYS VISIBLE) -->
<div class="tip-totals">

  <?php foreach ($eventTips as $tip): ?>
    <div class="tip-total-block tip-total-tips">
      <div class="tip-total-amount">
        💸 <?php echo strtoupper($tip['currency']); ?>
        <?php echo number_format($tip['total_amount'], 2); ?>
      </div>
      <div class="tip-total-sub">
        <?php echo (int)$tip['tip_count']; ?> tips received
      </div>
    </div>
  <?php endforeach; ?>

  <?php foreach ($eventBoosts as $boost): ?>
    <div class="tip-total-block tip-total-boosts">
      <div class="tip-total-amount boost">
        ⚡ <?php echo strtoupper($boost['currency']); ?>
        <?php echo number_format($boost['total_amount'], 2); ?>
      </div>
      <div class="tip-total-sub boost">
        <?php echo (int)$boost['boost_count']; ?> boosts received
      </div>
    </div>
  <?php endforeach; ?>

</div>

    <!-- EXPANDED VIEW (HIDDEN) -->
    <div
        class="tip-history hidden"
        id="tipHistory"
        style="margin-top:18px;"
    >

        <div style="
            border:1px solid #292933;
            border-radius:10px;
            overflow:hidden;
        ">

            <!-- TIP HISTORY -->
            <?php foreach ($eventTipHistory as $tip): ?>
                <div class="tip-row"
     data-type="tip"
     data-ts="<?php echo strtotime($tip['created_at']); ?>">
                    <div>
                        <strong>
                            💸 <?php echo strtoupper($tip['currency']); ?>
                            <?php echo number_format($tip['amount_cents'] / 100, 2); ?>
                        </strong>

<?php if (!empty($tip['patron_name'])): ?>
    <span class="muted">
        · (Tipped by <?php echo e($tip['patron_name']); ?>)
    </span>
<?php endif; ?>
                    </div>

                    <div class="muted">
                        <?php
                            $dt = new DateTime($tip['created_at'], new DateTimeZone('UTC'));
                            $dt->setTimezone($tz);
                            echo $dt->format('j M Y · H:i');
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- BOOST HISTORY -->
<?php foreach ($eventBoostHistory as $boost): ?>
    <div class="tip-row"
     data-type="boost"
     data-ts="<?php echo strtotime($boost['created_at']); ?>">
        <div>
            <strong style="color:#6ae3ff;">
                ⚡ <?php echo strtoupper($boost['currency']); ?>
                <?php echo number_format($boost['amount_cents'] / 100, 2); ?>
            </strong>

            <?php if (!empty($boost['track_title'])): ?>
                <span class="muted">
                    · <?php echo e($boost['track_title']); ?>
                    <?php if (!empty($boost['track_artist'])): ?>
                        — <?php echo e($boost['track_artist']); ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>

<?php if (!empty($boost['patron_name'])): ?>
    <span class="muted">
        · (Boosted by <?php echo e($boost['patron_name']); ?>)
    </span>
<?php endif; ?>
        </div>

        <div class="muted">
            <?php
                $dt = new DateTime($boost['created_at'], new DateTimeZone('UTC'));
                $dt->setTimezone($tz);
                echo $dt->format('j M Y · H:i');
            ?>
        </div>
    </div>
<?php endforeach; ?>

        </div>

    </div>

</div>
<?php endif; ?>




<!-- GUEST/PATRON AUTO MESSAGE BLOCK -->

<?php if ($guestNotice && $guestNoticeBody): ?>
<div class="section-card">
    <h2 style="display:flex;align-items:center;gap:10px;">
        👀 Guest/Patron Message Preview
        <span style="
            font-size:12px;
            padding:4px 10px;
            border-radius:999px;
            background:#292933;
            color:#ccc;
            font-weight:700;
        ">
            <?php echo strtoupper($eventState); ?>
        </span>
    </h2>

    <p style="color:#aaa;font-size:13px;margin-top:-6px;">
        This is the message guests currently see on the request page.
        It updates automatically based on the event status.
    </p>

    <div style="
        margin-top:14px;
        padding:16px;
        border-radius:10px;
        background:#0c0c11;
        border:1px solid #292933;
    ">
        <?php if (!empty($guestNotice['title'])): ?>
            <div style="
                font-weight:800;
                margin-bottom:8px;
                color:#ff2fd2;
            ">
                <?php echo e($guestNotice['title']); ?>
            </div>
        <?php endif; ?>

        <div style="
            white-space:pre-line;
            color:#ddd;
            font-size:14px;
            line-height:1.5;
        ">
            <?php echo e($guestNoticeBody); ?>
        </div>
    </div>

    <div style="
        margin-top:10px;
        font-size:12px;
        color:#777;
    ">
        🔒 This message is managed automatically by MyDJRequests.
        DJs can override it per event if needed.
    </div>
</div>
<?php endif; ?>




<style>
@keyframes pulseLive {
    0% { opacity: .4; }
    50% { opacity: 1; }
    100% { opacity: .4; }
}
</style>



<script>
const EVENT_ID = <?php echo (int)$event['id']; ?>;
const IS_LIVE  = <?php echo $isLiveEvent ? 'true' : 'false'; ?>;
</script>

<script>
if (typeof EVENT_ID === 'undefined') {
  console.error('EVENT_ID missing');
}
</script>


<script>
(function () {
    const copyBtn = document.getElementById("copyRequestLinkBtn");
    const linkEl  = document.getElementById("publicRequestLink");
    const fb      = document.getElementById("copyFeedback");

    if (!copyBtn || !linkEl || !fb) return;

    copyBtn.addEventListener("click", function () {
        const urlText = linkEl.innerText.trim();

        navigator.clipboard.writeText(urlText).then(() => {
            fb.style.display = "block";
            fb.textContent = "Copied!";

            setTimeout(() => {
                fb.style.display = "none";
            }, 1500);
        });
    });
})();
</script>

<script>
function copyPatronLink(url) {
    navigator.clipboard.writeText(url).then(() => {
        const el = event.target.closest('.copy-patron-link');
        const original = el.innerHTML;

        el.innerHTML = '✅ Link copied';
        setTimeout(() => {
            el.innerHTML = original;
        }, 1500);
    });
}
</script>

<script>
(function () {

    
    const SYNC_INTERVAL_MS = 30000; // 30 seconds

    const btnCreate = document.getElementById('btnCreateSpotifyPlaylist');
    const btnSync   = document.getElementById('btnSyncSpotifyPlaylist');

    async function createPlaylist() {
        if (!btnCreate) return;

        btnCreate.disabled = true;
        btnCreate.textContent = 'Creating…';

        try {
            const res = await fetch('/api/dj/spotify/create_event_playlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ event_id: EVENT_ID })
            });

            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Create failed');

            location.reload();
        } catch (err) {
            alert(err.message || 'Failed to create playlist');
            btnCreate.disabled = false;
            btnCreate.textContent = '➕ Create Spotify Playlist';
        }
    }

    async function syncPlaylist(manual = false) {
        if (manual && btnSync) {
            btnSync.disabled = true;
            btnSync.textContent = 'Syncing…';
        }

        try {
            const res = await fetch('/api/dj/spotify/sync_event_playlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ event_id: EVENT_ID })
            });

            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Sync failed');

            if (manual && btnSync) {
                btnSync.textContent = `✓ Added ${data.added || 0}`;
                setTimeout(() => {
                    btnSync.textContent = '🔄 Sync Now';
                    btnSync.disabled = false;
                }, 2000);
            }

        } catch (err) {
            if (manual) {
                alert(err.message || 'Sync failed');
                btnSync.disabled = false;
                btnSync.textContent = '🔄 Sync Now';
            }
        }
    }

    if (btnCreate) {
        btnCreate.addEventListener('click', createPlaylist);
    }

    if (btnSync) {
        btnSync.addEventListener('click', () => syncPlaylist(true));
    }

    // Auto-sync moved to /dj/index.php (DJ live view page).

})();
</script>

<script>
(function () {

    const rebuildBtn = document.getElementById('btnRebuildSpotifyPlaylist');
    if (!rebuildBtn) return;

    rebuildBtn.addEventListener('click', async () => {
        const confirmed = confirm(
            "This will rebuild the Spotify playlist from ACTIVE requests.\n\n" +
            "• Skipped tracks stay skipped\n" +
            "• Playlist order resets\n\n" +
            "Continue?"
        );

        if (!confirmed) return;

        rebuildBtn.disabled = true;
        rebuildBtn.textContent = 'Rebuilding…';

        try {
            const res = await fetch('/api/dj/spotify/rebuild_event_playlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    event_id: EVENT_ID
                })
            });

            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Rebuild failed');

            rebuildBtn.textContent = `✓ Rebuilt (${data.added || 0} tracks)`;
            setTimeout(() => {
                rebuildBtn.textContent = '♻️ Rebuild Playlist';
                rebuildBtn.disabled = false;
            }, 2500);

        } catch (err) {
            alert(err.message || 'Rebuild failed');
            rebuildBtn.disabled = false;
            rebuildBtn.textContent = '♻️ Rebuild Playlist';
        }
    });

})();
</script>


<script>
document.getElementById('toggleLiveBtn')?.addEventListener('click', async function () {
    const btn = this;
    const eventId = btn.dataset.eventId;
    const current = btn.dataset.currentState;

    const newState = current === 'live' ? 'ended' : 'live';

    const confirmMsg = newState === 'live'
        ? 'Go LIVE? This will end any other live event and show the live message to patrons.'
        : 'End this event? Requests will close and the end message will be shown.';

    if (!confirm(confirmMsg)) return;

    btn.disabled = true;

    try {
        const res = await fetch('/dj/api/event_state_toggle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                event_id: eventId,
                state: newState
            })
        });

        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');

        location.reload(); // simple + safe

    } catch (e) {
        alert('Could not update event state.');
        btn.disabled = false;
    }
});
</script>


<script>
document.getElementById('revertUpcomingBtn')?.addEventListener('click', async function () {

    if (!confirm(
        'Revert this event to Upcoming?\n\n' +
        'This will reopen requests and show the pre-event message to guests.'
    )) return;

    const btn = this;
    btn.disabled = true;

    try {
        const res = await fetch('/dj/api/event_state_toggle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                event_id: btn.dataset.eventId,
                state: 'upcoming'
            })
        });

        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');

        location.reload();

    } catch (e) {
        alert('Could not revert event.');
        btn.disabled = false;
    }
});
</script>

<script>
document.getElementById('toggleTipsBoostBtn')?.addEventListener('click', async function () {
    const btn = this;
    const eventId = btn.dataset.eventId;
    const current = btn.dataset.currentEnabled === '1';
    const next = current ? '0' : '1';

    if (!confirm(`Set tips/boost for this event to ${next === '1' ? 'ON' : 'OFF'}?`)) return;

    btn.disabled = true;

    try {
        const res = await fetch('/dj/api/event_tip_boost_toggle.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                event_id: eventId,
                enabled: next
            })
        });

        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');

        location.reload();
    } catch (e) {
        alert(e.message || 'Could not update tips/boost setting.');
        btn.disabled = false;
    }
});
</script>

<script>
document.querySelectorAll('.toggle-btn:not([data-target="tipHistory"])').forEach(btn => {
    btn.addEventListener('click', () => {

        const fullList   = document.getElementById(btn.dataset.target);
        const preview    = document.getElementById('requestPreview');
        const titleEl    = document.getElementById('requestTitle');
        const sortSelect = document.getElementById('requestSort');

        if (!fullList || !preview) return;

        const isExpanding = fullList.classList.contains('hidden');

        if (isExpanding) {
            // EXPAND
            fullList.classList.remove('hidden');
            preview.classList.add('hidden');

            titleEl.textContent = '🎶 Song Requests';
            sortSelect.classList.remove('hidden');

            // ✅ FORCE timestamps to render
            fullList.querySelectorAll('.request-row').forEach(row => {
                const timeEl = row.querySelector('.request-time');
                const local  = row.dataset.requestedLocal;

                if (timeEl && local) {
                    timeEl.textContent = ' · Requested ' + local;
                    timeEl.classList.remove('hidden');
                }
            });

            btn.textContent = 'Hide';

        } else {
            // COLLAPSE
            fullList.classList.add('hidden');
            preview.classList.remove('hidden');

            titleEl.textContent = '🎶 Recent Song Requests';
            sortSelect.classList.add('hidden');

            btn.textContent = 'View all';
        }
    });
});
</script>


<script>
(function () {

    const sortSelect = document.getElementById('tipSort');
    const container  = document.querySelector('#tipHistory > div');

    if (!sortSelect || !container) return;

    const originalOrder = Array.from(container.children);

    function sortRows(mode) {
        let rows = Array.from(container.children);

        if (mode === 'recent') {
            rows.sort((a, b) => {
                return b.dataset.ts - a.dataset.ts;
            });
        }

        if (mode === 'type') {
            rows.sort((a, b) => {
                if (a.dataset.type === b.dataset.type) {
                    return b.dataset.ts - a.dataset.ts; // newest first within type
                }
                return a.dataset.type === 'tip' ? -1 : 1;
            });
        }

        rows.forEach(row => container.appendChild(row));
    }

    // Default = Recent
    sortRows('recent');

    sortSelect.addEventListener('change', () => {
        sortRows(sortSelect.value);
    });

})();
</script>



<script>
const sortSelect = document.getElementById('requestSort');

if (sortSelect) {
    sortSelect.addEventListener('change', () => {
        const list = document.getElementById('allRequests');
        if (!list) return;

        const rows = Array.from(list.querySelectorAll('.request-row'));
        const mode = sortSelect.value;

        rows.sort((a, b) => {
            if (mode === 'requested') {
                return b.dataset.requested - a.dataset.requested;
            }
            if (mode === 'title') {
                return a.dataset.title.localeCompare(b.dataset.title);
            }
            if (mode === 'artist') {
                return a.dataset.artist.localeCompare(b.dataset.artist);
            }
        });

        rows.forEach(row => list.appendChild(row));
    });
}
</script>

<script>
document.querySelectorAll('.toggle-btn[data-target="tipHistory"]').forEach(btn => {
    btn.addEventListener('click', () => {
        const panel = document.getElementById('tipHistory');
        if (!panel) return;

        const isHidden = panel.classList.contains('hidden');

        panel.classList.toggle('hidden');
        btn.textContent = isHidden ? 'Hide' : 'View all';
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
