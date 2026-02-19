<?php
// public_html/request/index.php    V2
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../app/bootstrap_public.php';
require_once __DIR__ . '/../app/config/stripe.php';

$ENABLE_PATRON_PAYMENTS = false;


// -------------------------
// 1. Get event UUID
// -------------------------
$uuid = $_GET['event'] ?? '';
if ($uuid === '') {
    http_response_code(400);
    exit("Invalid event link.");
}

// -------------------------
// 2. Load event
// -------------------------
$eventModel = new Event();
$event = $eventModel->findByUuid($uuid);

if (!$event) {
    http_response_code(404);
    exit("Event not found.");
}


// -------------------------
// 2.5 Resolve event notice
// -------------------------
$db = db();

// Dev request page toggle: app_settings.patron_payments_enabled_dev
// Legacy fallback: app_settings.patron_payments_enabled
try {
    $stmt = $db->prepare("
        SELECT `key`, `value`
        FROM app_settings
        WHERE `key` IN ('patron_payments_enabled_dev', 'patron_payments_enabled')
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $raw = $rows['patron_payments_enabled_dev']
        ?? $rows['patron_payments_enabled']
        ?? '0';
    $ENABLE_PATRON_PAYMENTS = ((string)$raw === '1');
} catch (Throwable $e) {
    // Leave false when app_settings is unavailable.
}

// Resolve per-event tips/boost visibility:
// global app setting (dev) AND (event override if set, otherwise DJ default).
$djDefaultTipsBoostEnabled = false;
try {
    $userSettingStmt = $db->prepare("
        SELECT default_tips_boost_enabled
        FROM user_settings
        WHERE user_id = ?
        LIMIT 1
    ");
    $userSettingStmt->execute([(int)$event['user_id']]);
    $djDefaultTipsBoostEnabled = ((string)$userSettingStmt->fetchColumn() === '1');
} catch (Throwable $e) {
    $djDefaultTipsBoostEnabled = false;
}

$eventOverrideRaw = $event['tips_boost_enabled'] ?? null;
$eventTipsBoostEnabled = $eventOverrideRaw === null || $eventOverrideRaw === ''
    ? $djDefaultTipsBoostEnabled
    : ((int)$eventOverrideRaw === 1);

$eventState = strtolower((string)($event['event_state'] ?? 'upcoming'));
$isEventLiveForPayments = ($eventState === 'live');
$ENABLE_PATRON_PAYMENTS = $ENABLE_PATRON_PAYMENTS && $eventTipsBoostEnabled && $isEventLiveForPayments;

// Load DJ (for name replacement)
$userModel = new User();
$dj = $userModel->findById($event['user_id']);

$notice = resolveEventNotice(
    $db,
    (int)$event['id'],
    (int)$event['user_id'],
    $eventState
);

$noticeTitle = $notice['title'] ?? null;
$noticeBody  = $notice['body'] ?? null;

if ($noticeBody) {
    $djName = $dj['dj_name'] ?: $dj['name'] ?: '';
    $eventName = trim((string)($event['title'] ?? ''));
    $noticeBody = str_replace('{{DJ_NAME}}', $djName, $noticeBody);
    $noticeBody = str_replace('{{EVENT_NAME}}', $eventName, $noticeBody);
}



// -------------------------
// 3. Ensure guest token
// -------------------------
$guestToken = $_COOKIE['mdjr_guest'] ?? null;
if (!$guestToken) {
    $guestToken = bin2hex(random_bytes(16));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('mdjr_guest', $guestToken, time() + 86400 * 30, '/', '', $secure, true);
}

// -------------------------
// 4. Log unique page view
// -------------------------
$stmt = $db->prepare("
    INSERT INTO event_page_views
        (event_id, guest_token, ip_address, user_agent, first_seen_at, last_seen_at)
    VALUES (?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        last_seen_at = NOW()
");

$stmt->execute([
    $event['id'],
    $guestToken,
    $_SERVER['REMOTE_ADDR'] ?? null,
    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<title><?= e($event['title']); ?> – Requests</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($ENABLE_PATRON_PAYMENTS): ?>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>


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

.clear-btn {
    display: none;
}

.message-thread {
    height: 280px;
    overflow-y: auto;
    margin-top: 12px;
    margin-bottom: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px 8px;
    border: 1px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    background: rgba(8,8,15,0.55);
    padding-right: 4px;
}

.thread-empty {
    text-align: center;
    color: #8d8d9a;
    font-size: 13px;
    padding: 10px 6px;
}

.thread-row {
    display: flex;
}

.thread-row.guest {
    justify-content: flex-end;
}

.thread-row.dj {
    justify-content: flex-start;
}

.thread-bubble {
    max-width: 74%;
    border-radius: 14px;
    padding: 4px 10px;
    border: 1px solid rgba(255,255,255,0.12);
    line-height: 1.32;
    font-size: 14px;
    white-space: pre-wrap;
    word-break: break-word;
}

.thread-row.guest .thread-bubble {
    background: linear-gradient(135deg, #2f55ff, #326fff);
    color: #fff;
}

.thread-row.dj .thread-bubble {
    background: #1b1b27;
    color: #e7e7f1;
}

.thread-row.broadcast {
    justify-content: flex-start;
}

.thread-row.broadcast .thread-bubble {
    max-width: 90%;
    background: rgba(255, 47, 210, 0.16);
    border: 1px solid rgba(255, 47, 210, 0.45);
    color: #ffd8f5;
}

.thread-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-right: 6px;
    padding: 2px 6px;
    border-radius: 999px;
    background: rgba(255, 47, 210, 0.25);
    border: 1px solid rgba(255, 47, 210, 0.5);
    color: #fff;
}

.thread-time {
    display: block;
    margin-top: 1px;
    font-size: 11px;
    opacity: 0.75;
}

.requests-stats {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:8px;
}

.my-requests-header {
    display:flex;
    flex-direction:column;
    gap:8px;
}

.my-requests-top {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:nowrap;
}

.my-requests-top h3 {
    margin:0;
}

#requests_sort_my {
    max-width: 210px;
}

.stat-pill {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 10px;
    border-radius:999px;
    font-size:12px;
    border:1px solid rgba(255,255,255,0.18);
    background:rgba(255,255,255,0.06);
    color:#e9e9f7;
}

.rank-cup {
    display:inline-flex;
    align-items:center;
    gap:4px;
    margin-bottom:4px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.03em;
    text-transform:uppercase;
    padding:3px 8px;
    border-radius:999px;
    width:fit-content;
}

.rank-cup.gold {
    color:#2b1a00;
    background:linear-gradient(135deg,#ffd86b,#ffb300);
}
.rank-cup.silver {
    color:#20212a;
    background:linear-gradient(135deg,#f0f2f7,#aeb7c8);
}
.rank-cup.bronze {
    color:#271708;
    background:linear-gradient(135deg,#ffc08a,#b87333);
}

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
#myRequestsList,
#allRequestsList {
    max-height: 720px;   /* ~9–10 items comfortably */
    overflow-y: auto;
    padding-right: 4px;
}

/* Subtle scrollbar styling (WebKit only, safe fallback elsewhere) */
#myRequestsList::-webkit-scrollbar,
#allRequestsList::-webkit-scrollbar {
    width: 6px;
}
#myRequestsList::-webkit-scrollbar-thumb,
#allRequestsList::-webkit-scrollbar-thumb {
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

    #requests_sort_my,
    #requests_sort_all {
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


/* Track Buttons */


@media (max-width: 420px) {
    .track-btn {
        padding: 9px 10px;
        font-size: 11.5px;
    }
}

.track-actions {
    display: flex;
    gap: 8px;
    margin-top: 6px;

    flex-wrap: nowrap;       /* ⬅️ DO NOT ALLOW WRAP */
    align-items: center;
}

.track-btn {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff;
    font-size: 12px;

    padding: 8px 10px;   /* ⬅️ was 6px → now taller */
    line-height: 1;      /* ⬅️ keep tight */

    border-radius: 12px; /* ⬅️ slightly rounder suits taller buttons */
    cursor: pointer;
    transition: 0.15s;
    white-space: nowrap;
}

.track-btn:hover {
    background: rgba(255,47,210,0.2);
    border-color: rgba(255,47,210,0.5);
}

.highlight-btn {
    color: #ff2fd2;
    font-weight: 600;
}


.track-btn.voted {
    background: linear-gradient(135deg,#ff2fd2,#ff44de);
    border-color: rgba(255,47,210,0.6);
    font-weight: 600;
}



/* ===========================
   Played Track Highlight
=========================== */

.all-request-item.is-played {
    border: 1px solid rgba(0, 200, 120, 0.6);
    background: linear-gradient(
        135deg,
        rgba(0, 200, 120, 0.18),
        rgba(0, 200, 120, 0.05)
    );
    box-shadow: 0 0 14px rgba(0, 200, 120, 0.35);
}

/* Optional: soften text slightly */
.all-request-item.is-played .all-request-title {
    opacity: 0.9;
}

/* Optional: small badge */
.played-badge {
    font-size: 11px;
    color: #3cffb0;
    font-weight: 600;
    margin-left: 6px;
}



/* ===========================
   MDJR Dropdown Menus
=========================== */

.mdjr-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    background:
        linear-gradient(135deg, rgba(255,47,210,0.15), rgba(255,47,210,0.05)),
        url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23ff2fd2' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>")
        no-repeat right 12px center;

    background-color: #14141f;
    background-size: auto, 14px;

    color: #fff;
    border: 1px solid rgba(255,47,210,0.35);
    border-radius: 14px;

    padding: 10px 36px 10px 14px;
    font-size: 13.5px;
    font-weight: 600;

    cursor: pointer;
    transition: all 0.15s ease;

    box-shadow:
        inset 0 0 0 rgba(0,0,0,0),
        0 0 10px rgba(255,47,210,0.15);
}

/* Hover / focus */
.mdjr-select:hover,
.mdjr-select:focus {
    border-color: rgba(255,47,210,0.7);
    box-shadow:
        0 0 14px rgba(255,47,210,0.35);
    outline: none;
}

/* Active press */
.mdjr-select:active {
    transform: translateY(1px);
}

/* Mobile comfort */
@media (max-width: 420px) {
    .mdjr-select {
        font-size: 12.5px;
        padding: 9px 34px 9px 12px;
    }
}


/* ===========================
   MDJR Glass Dropdown
=========================== */

.mdjr-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    /* Glass background */
    background:
        linear-gradient(
            135deg,
            rgba(255, 255, 255, 0.10),
            rgba(255, 255, 255, 0.03)
        ),
        url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23ff2fd2' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>")
        no-repeat right 14px center;

    background-size: auto, 14px;
    background-color: rgba(20, 20, 30, 0.55);

    /* Glass blur */
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);

    color: #fff;
    border: 1px solid rgba(255, 47, 210, 0.35);
    border-radius: 16px;

    padding: 11px 38px 11px 16px;
    font-size: 13.5px;
    font-weight: 600;

    cursor: pointer;
    transition: all 0.18s ease;

    /* Glow + depth */
    box-shadow:
        0 8px 24px rgba(0, 0, 0, 0.45),
        inset 0 0 0 rgba(255,255,255,0),
        0 0 16px rgba(255,47,210,0.18);
}

/* Hover / focus = brighter glass */
.mdjr-select:hover,
.mdjr-select:focus {
    border-color: rgba(255,47,210,0.75);
    box-shadow:
        0 10px 30px rgba(0, 0, 0, 0.55),
        0 0 22px rgba(255,47,210,0.45);
    outline: none;
}

/* Pressed feel */
.mdjr-select:active {
    transform: translateY(1px);
}

/* Mobile tuning */
@media (max-width: 420px) {
    .mdjr-select {
        font-size: 12.5px;
        padding: 10px 34px 10px 14px;
        border-radius: 14px;
    }
}


select.mdjr-menu {
    background: linear-gradient(
        135deg,
        rgba(255,47,210,0.10),
        rgba(255,47,210,0.04)
    );
    border: 1px solid rgba(255,47,210,0.35);
    color: #fff;

    border-radius: 12px;
    padding: 8px 28px 8px 12px;
    font-weight: 600;

    box-shadow:
        0 4px 14px rgba(0,0,0,0.35);
}


.event-notice.collapsed .notice-body {
    display: none;
}


/* ===========================
   STRIPE TIP BANNER (PATRON)
=========================== */

.stripe-tip-card {
    background: linear-gradient(135deg, #2b1d55, #14141f);
    border: 1px solid rgba(99, 91, 255, 0.45);
    box-shadow: 0 0 28px rgba(99, 91, 255, 0.35);
}

.stripe-tip-inner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
}

.stripe-tip-title {
    font-size: 18px;
    font-weight: 800;
    color: #c7c2ff;
}

.stripe-tip-sub {
    font-size: 14px;
    color: #ddd;
    margin-top: 4px;
}

.stripe-tip-btn {
    background: linear-gradient(135deg, #635bff, #7a72ff);
    border: none;
    color: #fff;
    font-weight: 800;
    padding: 12px 16px;
    border-radius: 14px;
    cursor: pointer;
    white-space: nowrap;
    box-shadow: 0 0 14px rgba(99, 91, 255, 0.45);
    transition: 0.15s;
}

.stripe-tip-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 0 20px rgba(99, 91, 255, 0.65);
}

.stripe-tip-footnote {
    margin-top: 10px;
    font-size: 11px;
    color: #aaa;
    text-align: center;
}


.stripe-tip-presets {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.tip-preset {
    flex: 1;
    padding: 12px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 14px;
    background: rgba(99,91,255,0.15);
    border: 1px solid rgba(99,91,255,0.4);
    color: #fff;
    cursor: pointer;
    transition: 0.15s;
}

.tip-preset:hover {
    background: rgba(99,91,255,0.35);
    transform: translateY(-1px);
}

.tip-preset.highlight {
    background: linear-gradient(135deg, #635bff, #7a72ff);
    box-shadow: 0 0 18px rgba(99,91,255,0.6);
}

.tip-preset.other {
    background: rgba(255,255,255,0.08);
    border-color: rgba(255,255,255,0.25);
}


.stripe-tip-footnote {
    opacity: 0.85;
    font-size: 12px;
}


/* ===========================
   BOOSTED BUTTON (Electric Blue)
=========================== */

.track-btn.highlight-btn.boosted {
    background: linear-gradient(135deg, #00e5ff, #3aa9ff);
    border-color: rgba(0, 229, 255, 0.9);
    color: #001018;
    font-weight: 800;
    box-shadow:
        0 0 14px rgba(0, 229, 255, 0.75),
        0 0 28px rgba(58, 169, 255, 0.55);
    cursor: default;
}

.track-btn.highlight-btn.boosted:hover {
    transform: none;
}


/* ===========================
   MDJR Sticky Nav (Chrome)
=========================== */
#mdjr-nav {
    position: sticky;
    top: 0;
    z-index: 10000;

    width: 100%;
    display: flex;
    justify-content: space-around;
    align-items: center;

    padding: 10px 0;

    background: rgba(12,12,20,0.9);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);

    border-bottom: 1px solid rgba(255,47,210,0.25);
}

#mdjr-nav button {
    background: none;
    border: none;
    color: #bbb;
    cursor: pointer;
}

#mdjr-nav i {
    font-size: 20px;
    color: #ff2fd2;
}

#mdjr-nav button.active i {
    text-shadow: 0 0 10px rgba(255,47,210,0.9);
}


#mdjr-nav button {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;

    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.2px;
}

#mdjr-nav button span {
    color: #ccc;
    transition: opacity 0.2s ease;
}

#mdjr-nav button.active span {
    color: #fff;
}

.msg-tab-badge {
    display: none;
    min-width: 24px;
    height: 24px;
    border-radius: 999px;
    background: #ff2fd2;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    line-height: 24px;
    text-align: center;
    padding: 0 5px;
    box-shadow: 0 0 0 0 rgba(255, 47, 210, 0.55);
}

.msg-tab-badge.show {
    display: inline-block;
    animation: msgPulse 1.3s ease-out infinite;
}

.msg-tab-has-unread i {
    display: none;
}

@keyframes msgPulse {
    0% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(255, 47, 210, 0.55);
    }
    70% {
        transform: scale(1.08);
        box-shadow: 0 0 0 9px rgba(255, 47, 210, 0);
    }
    100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(255, 47, 210, 0);
    }
}

@media (max-width: 360px) {
    #mdjr-nav button span {
        display: none;
    }

    #mdjr-nav {
        padding: 8px 0;
    }
}

.tab-panel { display: none; }
.tab-panel.active {
    display: block;
    animation: fadeTabIn .2s ease;
}

@keyframes fadeTabIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}


</style>
</head>

<body>
    
   
   <div id="mdjr-nav">
    <button data-target="section-home">
        <i class="fa fa-house"></i>
        <span>Home</span>
    </button>

    <button data-target="section-request">
        <i class="fa fa-music"></i>
        <span>My Requests</span>
    </button>

    <button data-target="section-requests">
        <i class="fa fa-list"></i>
        <span>All Requests</span>
    </button>

    <button data-target="section-message">
        <i class="fa fa-comment"></i>
        <span id="messageUnreadBadge" class="msg-tab-badge">0</span>
        <span>Message</span>
    </button>

    <button data-target="section-contact">
        <i class="fa fa-address-card"></i>
        <span>Contact</span>
    </button>
</div> 

    
<div class="container">
    <div style="
    position: fixed;
    bottom: 12px;
    right: 12px;
    background: #4b2d7f;
    color: #fff;
    font-size: 11px;
    padding: 6px 10px;
    border-radius: 999px;
    opacity: 0.75;
    z-index: 9999;
">
    Patron Page · V1.4 Preview
</div>

    <div class="tab-panel active" id="section-home">
    <!-- EVENT HEADER -->
    <div class="event-card">
        <h1><?= e($event['title']); ?></h1>
        <div class="event-meta">
            <div><strong>Date:</strong> <?= e($event['event_date'] ?: 'TBA'); ?></div>
            <div><strong>Location:</strong> <?= e($event['location'] ?: 'TBA'); ?></div>
        </div>
    </div>
    
    
    <!-- STATUS MESSASGE -->
    
 <div id="noticeWrapper"></div>   


<?php if ($ENABLE_PATRON_PAYMENTS): ?>
<!-- SUPPORT THE DJ -->
<div class="card stripe-tip-card">
    <div class="stripe-tip-inner">
        <div class="stripe-tip-text">
            <div class="stripe-tip-title">💖 Support the DJ</div>
            <div class="stripe-tip-sub">
                Enjoying the music? You can tip your DJ to show some love.
            </div>
        </div>

        <div class="stripe-tip-presets">
            <button class="tip-preset" data-amount="500">💖 $5</button>
            <button class="tip-preset" data-amount="1000">🔥 $10</button>
            <button class="tip-preset" data-amount="2000">🚀 $20</button>
            <button class="tip-preset other">Other</button>
        </div>
    </div>

    <div class="stripe-tip-footnote">
        <small>
            Tips are voluntary, non-refundable, and do not guarantee a song will be played.
        </small>
        <br>
        <span>Secure payments powered by Stripe</span>
    </div>
    
   
</div>

<!-- MY SUPPORT CONFIRMATION (hidden by default) -->
<div id="mySupportTile"
     class="card"
     style="
        display:none;
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(99,91,255,0.25);
        box-shadow: 0 0 14px rgba(99,91,255,0.15);
        cursor: pointer;
        padding: 14px 18px;
     ">

    <div style="display:flex; align-items:center; justify-content:space-between;">
        <div style="font-size:13px; color:#bbb;">
            💜 Your support so far
        </div>

        <div id="mySupportAmount"
             style="
                font-size:16px;
                font-weight:800;
                color:#c7c2ff;
             ">
            $0
        </div>
    </div>

    <div style="margin-top:4px; font-size:11px; color:#777;">
        Thank you for supporting the DJ
    </div>
</div>
<?php endif; ?>


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
    </div>


<!-- SONG REQUEST FORM -->
<div class="tab-panel" id="section-request">
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
                Make a Song Request
            </label>
            
<select id="request_mode"
        style="
            background:#11111a;
            color:#fff;
            border:1px solid rgba(255,255,255,0.15);
            border-radius:10px;
            padding:6px 10px;
            font-size:14px;
        ">
    <option value="spotify" selected>Spotify Search</option>
    <option value="manual">Manual Entry</option>
</select>
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

<div class="card" style="margin-top:16px;">
    <div class="my-requests-header">
        <div class="my-requests-top">
            <h3>🎵 My Requests</h3>
            <select id="requests_sort_my"
                    style="
                        background:#11111a;
                        color:#fff;
                        border:1px solid rgba(255,255,255,0.15);
                        border-radius:10px;
                        padding:6px 10px;
                        font-size:13px;
                    ">
                <option value="last">Sort: Last Requested</option>
                <option value="title">Sort: Title</option>
                <option value="artist">Sort: Artist</option>
            </select>
        </div>

        <div id="myRequestsStats" class="requests-stats"></div>
    </div>

    <div id="myRequestsList" style="margin-top:12px;"></div>
</div>
</div>



<!-- MY REQUESTS TILE -->
<div class="tab-panel" id="section-requests">
<div class="card">
    <div class="requests-header">
        <h3 style="margin:0;">🎶 All Requests</h3>

        <div style="display:flex; gap:8px; align-items:center; margin-top:6px;">
            <select id="requests_sort_all"
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
    </div>

    <div id="allRequestsList" style="margin-top:12px;"></div>
</div>
</div>



<!-- MESSAGE FORM -->
<div class="tab-panel" id="section-message">
<div class="card message-card">
    <h3>Send DJ a Message 💬</h3>
    <div id="messageThread" class="message-thread"></div>

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
</div>


<?php
// Load DJ Profile
require_once __DIR__ . '/../app/models/DjProfile.php';

$profileModel = new DjProfile();
$djProfile = $profileModel->findByUserId($event['user_id']);
?>

<div class="tab-panel" id="section-contact">
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

    <a href="/api/public/dj_vcard.php?dj=<?= e($event['user_id']) ?>&event=<?= e($uuid) ?>"
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
       Save to Contacts
    </a>
</div>
<?php else: ?>
<div class="card dj-profile-card">
    <h3>Your DJ's Profile</h3>
    <div style="color:#bbb;font-size:14px;">
        DJ profile details are not available for this event yet.
    </div>
</div>
<?php endif; ?>
</div>


<div class="footer-note">
    Powered by <strong>MyDjRequests.com</strong>
</div>

</div>

<script>
const EVENT_UUID = "<?= e($uuid); ?>";
const EVENT_TITLE = <?= json_encode($event['title']); ?>;
const STRIPE_PUBLISHABLE_KEY = "<?= e(STRIPE_PUBLISHABLE_KEY); ?>";
const ENABLE_PATRON_PAYMENTS = <?= $ENABLE_PATRON_PAYMENTS ? 'true' : 'false'; ?>;
const CAN_REQUEST_SONGS = <?= in_array($eventState, ['upcoming', 'live'], true) ? 'true' : 'false'; ?>;
let currentActiveTab = "section-home";
const MESSAGE_SEEN_KEY = "mdjr_message_seen_<?= e($uuid); ?>";
const messageUnreadBadgeEl = document.getElementById("messageUnreadBadge");
const messageTabBtnEl = document.querySelector('#mdjr-nav button[data-target="section-message"]');
let messageThreadRowsCache = [];
let messageSeenAnchor = null;
let messageSeenLoaded = false;
let messageReadTimer = null;


/* =========================
   MY SUPPORT CONFIRMATION
========================= */
async function loadMySupportTile() {
    if (!ENABLE_PATRON_PAYMENTS) return;
    try {
        const res = await fetch(
            "/api/public/get_my_support.php?event_uuid=" + encodeURIComponent(EVENT_UUID)
        );
        const data = await res.json();

        if (!data.ok || !data.total_cents || data.total_cents <= 0) {
            return; // nothing to show
        }

        const tile   = document.getElementById("mySupportTile");
        const amount = document.getElementById("mySupportAmount");

        if (!tile || !amount) return;

        amount.textContent = `$${(data.total_cents / 100).toFixed(2)}`;
        tile.style.display = "block";

        // Future: drill-down modal
        tile.addEventListener("click", () => {
            console.log("Support breakdown:", data.items);
        });

    } catch (err) {
        console.warn("Support tile load failed", err);
    }
}

/* =========================
   PAGE INIT
========================= */
document.addEventListener("DOMContentLoaded", () => {
    loadMySupportTile();
});
</script>


<script>

let allRequestsCache = [];
let myRequestsCache = [];
let myTrackKeys = new Set();

// 🔒 FETCH LOCKS
let requestsFetchInProgress = false;
let moodFetchInProgress = false;



// =============================
// CLEAR BUTTON
// =============================

function initClearButton(inputId, btnId) {
    const input = document.getElementById(inputId);
    const btn   = document.getElementById(btnId);

    if (!input || !btn) return;

    const toggle = () => {
        btn.style.display = input.value.trim() ? "block" : "none";
    };

    // 🔑 run immediately (handles localStorage-filled values)
    toggle();

    input.addEventListener("input", toggle);

    btn.addEventListener("click", () => {
        input.value = "";
        toggle();
        input.dispatchEvent(new Event("input"));
    });
}


// =============================
// GUEST NAME MEMORY SYSTEM
// =============================
const guestNameInput = document.getElementById("guest_name");
guestNameInput.value = localStorage.getItem("mdjr_guest_name") || "";

 // ✅ NOW wire clear buttons
    initClearButton("guest_name", "clear_guest_name");
    initClearButton("song_title", "clear_song_title");
    initClearButton("artist", "clear_artist");
    initClearButton("message", "clear_message");

// keep synced into forms
function syncGuestName() {
    const name = guestNameInput.value.trim();
    localStorage.setItem("mdjr_guest_name", name);

    document.getElementById("hidden_patron_name_song").value = name;
    document.getElementById("hidden_patron_name_msg").value = name;
}

// 🔑 ADD THIS LINE ⬇️
guestNameInput.addEventListener("input", syncGuestName);



// =============================
// SONG REQUEST LOGIC
// =============================
const songForm   = document.getElementById("songForm");
const songStatus = document.getElementById("songStatus");
const songInput  = document.getElementById("song_title");
const artistInput = document.getElementById("artist");
const sugBox = document.getElementById("songSuggestions");
const songSubmitBtn = songForm ? songForm.querySelector('button[type="submit"]') : null;
const songRequestModeSelect = document.getElementById("request_mode");
const clearSongBtn = document.getElementById("clear_song_title");
const clearArtistBtn = document.getElementById("clear_artist");

function syncSongRequestAvailability() {
    const disabled = !CAN_REQUEST_SONGS;
    if (songInput) songInput.disabled = disabled;
    if (artistInput) artistInput.disabled = disabled;
    if (songSubmitBtn) songSubmitBtn.disabled = disabled;
    if (songRequestModeSelect) songRequestModeSelect.disabled = disabled;
    if (clearSongBtn) clearSongBtn.disabled = disabled;
    if (clearArtistBtn) clearArtistBtn.disabled = disabled;
    if (disabled && songStatus) {
        songStatus.textContent = "Song requests are closed because this event has ended.";
    }
}

/* =========================
   SCROLL FIX – INPUT FOCUS
========================= */
// scroll helpers
let lastScrollY = 0;
function lockScrollPosition() {
  lastScrollY = window.scrollY || window.pageYOffset;
}
function restoreScrollPosition() {
  requestAnimationFrame(() => {
    window.scrollTo({ top: lastScrollY, behavior: "instant" });
  });
}

// prevent jump on focus
songInput.addEventListener("focus", () => {
  lockScrollPosition();
  setTimeout(restoreScrollPosition, 50);
});

if (artistInput) {
  artistInput.addEventListener("focus", () => {
    lockScrollPosition();
    setTimeout(restoreScrollPosition, 50);
  });
}




function clearSpotify() {
    document.getElementById("spotify_track_id").value = "";
    document.getElementById("spotify_track_name").value = "";
    document.getElementById("spotify_artist_name").value = "";
    document.getElementById("spotify_album_art_url").value = "";
}



songForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!CAN_REQUEST_SONGS) {
        songStatus.textContent = "Song requests are closed because this event has ended.";
        return;
    }
    songStatus.textContent = "Sending…";

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
        refreshRequests();
    } else {
        songStatus.textContent = data.message || "Something went wrong.";
    }
});

syncSongRequestAvailability();



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
const messageThreadEl = document.getElementById("messageThread");

function escapeHtml(str) {
    return String(str ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function formatThreadTime(ts) {
    if (!ts) return "";
    const d = new Date(ts.replace(" ", "T") + "Z");
    return d.toLocaleString([], {
        weekday: "short",
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit"
    });
}

function isIncomingMessage(row) {
    return row && (row.sender === "dj" || row.sender === "broadcast");
}

function getMessageRank(row) {
    const created = String(row?.created_at || "");
    const ms = created ? Date.parse(created.replace(" ", "T") + "Z") : 0;
    const id = Number(row?.id || 0);
    return { ms: Number.isFinite(ms) ? ms : 0, id };
}

function compareMessageRank(a, b) {
    if (!a && !b) return 0;
    if (!a) return -1;
    if (!b) return 1;
    if (a.ms !== b.ms) return a.ms - b.ms;
    return a.id - b.id;
}

function loadMessageSeenAnchor() {
    if (messageSeenLoaded) return;
    messageSeenLoaded = true;
    try {
        const raw = localStorage.getItem(MESSAGE_SEEN_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === "object") {
                messageSeenAnchor = {
                    ms: Number(parsed.ms || 0),
                    id: Number(parsed.id || 0)
                };
            }
        }
    } catch (e) {}
}

function saveMessageSeenAnchor(anchor) {
    messageSeenAnchor = anchor;
    try {
        localStorage.setItem(MESSAGE_SEEN_KEY, JSON.stringify(anchor));
    } catch (e) {}
}

function latestIncomingAnchor(rows) {
    let latest = null;
    (rows || []).forEach(row => {
        if (!isIncomingMessage(row)) return;
        const rank = getMessageRank(row);
        if (compareMessageRank(rank, latest) > 0) {
            latest = rank;
        }
    });
    return latest;
}

function markMessagesRead() {
    const latest = latestIncomingAnchor(messageThreadRowsCache);
    if (!latest) {
        updateMessageUnreadBadge(0);
        return;
    }
    saveMessageSeenAnchor(latest);
    updateMessageUnreadBadge(0);
}

function scheduleMarkMessagesRead() {
    if (messageReadTimer) {
        clearTimeout(messageReadTimer);
        messageReadTimer = null;
    }
    messageReadTimer = setTimeout(() => {
        if (currentActiveTab === "section-message") {
            markMessagesRead();
        }
    }, 3000);
}

function cancelMarkMessagesRead() {
    if (messageReadTimer) {
        clearTimeout(messageReadTimer);
        messageReadTimer = null;
    }
}

function updateMessageUnreadBadge(count) {
    if (!messageUnreadBadgeEl) return;
    if (!count || count <= 0) {
        messageUnreadBadgeEl.textContent = "0";
        messageUnreadBadgeEl.classList.remove("show");
        if (messageTabBtnEl) {
            messageTabBtnEl.classList.remove("msg-tab-has-unread");
        }
        return;
    }
    messageUnreadBadgeEl.textContent = count > 99 ? "99+" : String(count);
    messageUnreadBadgeEl.classList.add("show");
    if (messageTabBtnEl) {
        messageTabBtnEl.classList.add("msg-tab-has-unread");
    }
}

function recomputeMessageUnread(rows) {
    loadMessageSeenAnchor();
    messageThreadRowsCache = Array.isArray(rows) ? rows : [];

    const incomingRows = messageThreadRowsCache.filter(isIncomingMessage);
    if (!incomingRows.length) {
        updateMessageUnreadBadge(0);
        return;
    }

    let unread = 0;
    if (!messageSeenAnchor) {
        // First open/reopen should show backlog as unread.
        unread = incomingRows.length;
    } else {
        unread = incomingRows.filter(row =>
            compareMessageRank(getMessageRank(row), messageSeenAnchor) > 0
        ).length;
    }

    updateMessageUnreadBadge(unread);
    if (currentActiveTab === "section-message" && unread > 0) {
        scheduleMarkMessagesRead();
    }
}

function renderMessageThread(rows) {
    if (!messageThreadEl) return;

    if (!rows || !rows.length) {
        messageThreadEl.innerHTML = `<div class="thread-empty">No messages yet.</div>`;
        return;
    }

    messageThreadEl.innerHTML = rows.map(row => {
        let sender = 'guest';
        if (row.sender === 'dj') sender = 'dj';
        if (row.sender === 'broadcast') sender = 'broadcast';

        const badge = sender === 'broadcast'
            ? '<span class="thread-badge">Broadcast</span>'
            : '';

        return `<div class="thread-row ${sender}"><div class="thread-bubble">${badge}${escapeHtml(row.body || "")}<span class="thread-time">${formatThreadTime(row.created_at)}</span></div></div>`;
    }).join("");

    messageThreadEl.scrollTop = messageThreadEl.scrollHeight;
}

async function loadMessageThread() {
    if (!messageThreadEl) return;
    try {
        const res = await fetch(
            "/api/public/get_message_thread.php?event_uuid=" + encodeURIComponent("<?= e($uuid); ?>")
        );
        const data = await res.json();

        if (!data.ok) return;

        const rows = data.rows || [];
        renderMessageThread(rows);
        recomputeMessageUnread(rows);

        const blocked = data.guest_status === "blocked";
        const textEl = document.getElementById("message");
        const sendBtn = messageForm?.querySelector('button[type="submit"]');
        if (textEl) {
            textEl.disabled = blocked;
            textEl.placeholder = blocked
                ? "Messaging is unavailable for this event."
                : "Type your message…";
        }
        if (sendBtn) {
            sendBtn.disabled = blocked;
            sendBtn.style.opacity = blocked ? "0.55" : "1";
        }
    } catch (e) {
        console.warn("Message thread load failed", e);
    }
}


messageForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    msgStatus.textContent = "Sending…";

    syncGuestName();

    const fd = new FormData(messageForm);
    const res = await fetch("/api/public/submit_message.php", { method: "POST", body: fd });
    const data = await res.json();

    if (data.success) {
        msgStatus.textContent = "Message sent! 💬";
        const textEl = document.getElementById("message");
        if (textEl) {
            textEl.value = "";
            textEl.dispatchEvent(new Event("input"));
        }
        await loadMessageThread();
    } else {
        msgStatus.textContent = data.message || "Something went wrong.";
    }
});


// =============================
// REQUEST MODE (Spotify / Manual)
// =============================
const requestModeSelect = document.getElementById("request_mode");
const spotifyLabel      = document.getElementById("spotify_mode_label");
const artistGroup       = document.getElementById("artist_group");

function isSpotifyMode() {
    return requestModeSelect.value === "spotify";
}

function updateArtistVisibility() {
    if (isSpotifyMode()) {
        // Spotify mode → artist hidden (auto-filled)
        artistGroup.style.display = "none";
        artistInput.value = "";
        spotifyLabel.textContent = "Spotify Search";
    } else {
        // Manual mode → artist visible
        artistGroup.style.display = "block";
        spotifyLabel.textContent = "Manual Entry";
    }
}

// Run on page load
updateArtistVisibility();

// Run when mode changes
requestModeSelect.addEventListener("change", () => {
    updateArtistVisibility();
    clearSpotify();
    sugBox.innerHTML = "";
    sugBox.style.display = "none";
});


// =============================
// SPOTIFY AUTOCOMPLETE
// =============================
let timer = null;

songInput.addEventListener("input", () => {
    const q = songInput.value.trim();

    // 🚫 If NOT Spotify mode → disable autocomplete fully
    if (!isSpotifyMode()) {
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
        try {
            const res = await fetch(
                "/api/public/spotify_search.php?q=" + encodeURIComponent(q)
            );
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
                    </div>
                `;

                el.onclick = () => {
                    songInput.value = track.title;
                    artistInput.value = track.artist;

                    document.getElementById("spotify_track_id").value        = track.id;
                    document.getElementById("spotify_track_name").value      = track.title;
                    document.getElementById("spotify_artist_name").value     = track.artist;
                    document.getElementById("spotify_album_art_url").value   = track.albumArt;

                    sugBox.style.display = "none";
                };

                sugBox.appendChild(el);
            });

            const footer = document.createElement("div");
            footer.className = "song-suggestions-footer";
            footer.textContent = "Powered by Spotify";
            sugBox.appendChild(footer);

            sugBox.style.display = "block";

        } catch (err) {
            console.error("Spotify search failed", err);
            sugBox.style.display = "none";
        }
    }, 350);
});

// Click outside → close suggestions
document.addEventListener("click", (e) => {
    if (!sugBox.contains(e.target) && e.target !== songInput) {
        sugBox.style.display = "none";
    }
});
// =============================
// MY REQUESTS (READ-ONLY STEP 1)
// =============================
async function loadMyRequests() {
    const list = document.getElementById("myRequestsList");
    if (!list) return;

    try {
        const res = await fetch(
            "/api/public/get_my_requests.php?event_uuid=<?= e($uuid); ?>"
        );
        const data = await res.json();

        if (!data.ok || !data.rows.length) {
            myRequestsCache = [];
            updateMyRequestStats();
            list.innerHTML = `
                <div style="color:#777;font-size:14px;text-align:center;">
                    You haven’t requested any songs yet
                </div>`;
            return;
        }

        // ✅ cache
        myRequestsCache = data.rows;
        updateMyRequestStats();
        
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
        myRequestsCache = [];
        updateMyRequestStats();
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


// =============================
// ALL REQUESTS (AGGREGATED)
// =============================
async function loadAllRequests() {
    const list = document.getElementById("allRequestsList");

    try {
        const res = await fetch(
            "/api/public/get_event_requests.php?event_uuid=<?= e($uuid); ?>"
        );
        const data = await res.json();

        if (!data.ok || !data.rows.length) {
            allRequestsCache = [];
            updateMyRequestStats();
            list.innerHTML = `
                <div style="color:#777;font-size:14px;text-align:center;">
                    No requests yet
                </div>`;
            return;
        }

        allRequestsCache = data.rows;
        updateMyRequestStats();
        renderAllRequests();

    } catch {
        allRequestsCache = [];
        updateMyRequestStats();
        list.innerHTML = `
            <div style="color:#777;font-size:14px;text-align:center;">
                Unable to load requests
            </div>`;
    }
}


function buildGroupKey(title, artist) {
    return `${normalizeTitle(title || '')}::${(artist || '').trim().toLowerCase()}`;
}

function updateMyRequestStats() {
    const statsEl = document.getElementById('myRequestsStats');
    if (!statsEl) return;

    const myRequestCount = myRequestsCache.length;

    statsEl.innerHTML = `
        <span class="stat-pill">🎵 ${myRequestCount} requests</span>
    `;
}

function renderMyRequests() {
    const list = document.getElementById("myRequestsList");
    const sortMode = document.getElementById("requests_sort_my").value;

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
        const trackKey = row.track_key || "";
        const boosted = row.has_boosted == 1;

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
                ${ENABLE_PATRON_PAYMENTS && trackKey ? `
                <div class="track-actions">
                    <button
                        type="button"
                        class="track-btn highlight-btn ${boosted ? 'boosted' : ''}"
                        data-trackkey="${trackKey}"
                        data-song="${(row.song_title || '').replace(/"/g, '&quot;')}"
                        data-artist="${(row.artist || '').replace(/"/g, '&quot;')}"
                        data-album-art="${row.spotify_album_art_url || ''}"
                        ${boosted ? 'disabled' : ''}
                    >
                        ${boosted ? '⚡ BOOSTED' : '🚀 BOOST'}
                    </button>
                </div>` : ``}
            </div>
        `;

        list.appendChild(el);
    });
}



function renderAllRequests() {
    const list = document.getElementById("allRequestsList");
    const sortMode = document.getElementById("requests_sort_all").value;

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
            vote_count: 0,
            variants: [],
            album_art: row.album_art || null,
            last_requested_at: row.last_requested_at,
            isMine: row.is_mine == 1,
            hasVoted: row.has_voted == 1,
            hasBoosted: row.has_boosted == 1   // 🔥 ADD THIS
        };
        }

    groups[baseKey].total_count += Number(row.request_count || 0);

        groups[baseKey].vote_count += Number(row.vote_count || 0);

        groups[baseKey].variants.push({
            ...row,
            isMine: row.is_mine == 1
        });

        // bubble ownership up
        if (row.is_mine == 1) {
            groups[baseKey].isMine = true;
        }
        
        // 🔥 bubble boost up
        if (row.has_boosted == 1) {
            groups[baseKey].hasBoosted = true;
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
// 1.5 CALCULATE POPULARITY
// -----------------------------------
Object.values(groups).forEach(g => {
    g.popularity_count =
        Number(g.total_count || 0) +
        Number(g.vote_count || 0);
});

const popularityRank = new Map();
Object.values(groups)
    .sort((a, b) => b.popularity_count - a.popularity_count)
    .slice(0, 3)
    .forEach((g, idx) => {
        popularityRank.set(buildGroupKey(g.base_title, g.artist), idx + 1);
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
            groupList.sort((a, b) => b.popularity_count - a.popularity_count);
    }

    // -----------------------------------
    // 3. RENDER
    // -----------------------------------
    
    
    
    list.innerHTML = "";

    groupList.forEach(group => {
        const el = document.createElement("div");
        el.className = "all-request-item";
        
        const voted = group.hasVoted === true;
        const tk = group.variants[0]?.track_key || ""; // ✅ MOVE HERE
        const boosted = group.hasBoosted == 1;

if (group.isMine) {
    el.classList.add("is-mine");
}

if (group.variants.some(v => v.is_played == 1)) {
    el.classList.add("is-played");
}

        const cover = group.album_art
            ? `<img src="${group.album_art}" class="all-request-cover" loading="lazy">`
            : `<div class="all-request-cover"></div>`;

        const expandable = group.variants.length > 1;
        const displayCount =
            group.total_count + (group.vote_count || 0);



el.innerHTML = `
    ${cover}
    <div class="all-request-meta">
    
    
        ${(() => {
            const rank = popularityRank.get(buildGroupKey(group.base_title, group.artist));
            if (rank === 1) return `<div class="rank-cup gold">🏆 1st</div>`;
            if (rank === 2) return `<div class="rank-cup silver">🥈 2nd</div>`;
            if (rank === 3) return `<div class="rank-cup bronze">🥉 3rd</div>`;
            return "";
        })()}

        <div class="all-request-title">${group.base_title}</div>
        
        
        
        ${group.artist ? `<div class="all-request-artist">${group.artist}</div>` : ""}
        <div class="my-request-time">
            Requested ${group.total_count}× • last ${timeAgo(group.last_requested_at)}
        </div>

<div class="track-actions">
${
        group.isMine
            ? ''   // 🚫 hide vote button if mine
            : `
                <button
                    type="button"
                    class="track-btn vote-btn ${voted ? 'voted' : ''}"
                    data-trackkey="${tk}"
                    data-song="${group.base_title.replace(/"/g, '&quot;')}"
                    data-artist="${(group.artist || '').replace(/"/g, '&quot;')}"
                >
                    ${voted ? "👍 Voted" : "👍 Vote"}
                </button>
              `
    }

${ENABLE_PATRON_PAYMENTS ? `
<button
    type="button"
    class="track-btn highlight-btn ${boosted ? 'boosted' : ''}"
    data-trackkey="${tk}"
    data-song="${group.base_title.replace(/"/g, '&quot;')}"
    data-artist="${(group.artist || '').replace(/"/g, '&quot;')}"
    data-album-art="${group.album_art || ''}"
    ${boosted ? 'disabled' : ''}
>
    ${boosted ? '⚡ BOOSTED' : '🚀 BOOST'}
</button>` : ``}
</div>



    </div>

    <div class="request-count">×${displayCount}</div>
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
// REQUESTS LOAD + SORT
// =============================
const requestsSortMy = document.getElementById("requests_sort_my");
const requestsSortAll = document.getElementById("requests_sort_all");

async function refreshRequests() {
    if (requestsFetchInProgress) return;
    requestsFetchInProgress = true;
    
    lockScrollPosition(); // 👈 ADD

    try {
        await Promise.all([
            loadMyRequests(),
            loadAllRequests()
        ]);
    } finally {
        requestsFetchInProgress = false;
    }
}

// Load both tabs on start.
refreshRequests();

if (requestsSortAll) {
    requestsSortAll.addEventListener("change", () => {
        renderAllRequests();
    });
}

if (requestsSortMy) {
    requestsSortMy.addEventListener("change", () => {
        renderMyRequests();
    });
}



// =============================
// VOTE
// =============================
async function voteTrack(track) {
    const fd = new FormData();

    fd.append("event_uuid", "<?= e($uuid); ?>");
    fd.append("track_key", track.track_key); // ✅ REQUIRED
    fd.append("song_title", track.song_title || "");
    fd.append("artist", track.artist || "");
    fd.append(
        "patron_name",
        localStorage.getItem("mdjr_guest_name") || ""
    );

    const res = await fetch("/api/public/vote_song.php", {
        method: "POST",
        body: fd
    });

    const data = await res.json();

    if (!data.success) {
        console.error("Vote failed:", data);
        return;
    }

    refreshRequests();
}

async function unvoteTrack(track) {
    const fd = new FormData();
    fd.append("event_uuid", "<?= e($uuid); ?>");
    fd.append("song_title", track.song_title);
    fd.append("artist", track.artist || "");
    fd.append("spotify_track_id", track.spotify_track_id || "");

    await fetch("/api/public/unvote_song.php", {
        method: "POST",
        body: fd
    });

    refreshRequests();
}


document.addEventListener("click", async (e) => {

    // 👍 Vote / Unvote
    if (e.target.classList.contains("vote-btn")) {
        e.preventDefault();
        e.stopPropagation();

        const track = {
            track_key: e.target.dataset.trackkey,
            song_title: e.target.dataset.song,
            artist: e.target.dataset.artist
        };

        if (!track.track_key) {
            console.error("Vote blocked: missing track_key", e.target);
            return;
        }

        const voted = e.target.classList.contains("voted");

        if (voted) {
            unvoteTrack(track);
        } else {
            voteTrack(track);
        }

        return;
    }

 
 /*
 
    // ✨ Highlight
// ✨ Highlight (disabled for now)
if (e.target.classList.contains("highlight-btn")) {
    e.preventDefault();
    e.stopPropagation();
    return;
}

*/



// 🚀 BOOST (open modal, no redirect)
if (e.target.classList.contains("highlight-btn")) {
  if (!ENABLE_PATRON_PAYMENTS) return;
  e.preventDefault();
  e.stopPropagation();

  const trackKey = e.target.dataset.trackkey;
  if (!trackKey) {
    console.error("BOOST blocked: missing track_key");
    return;
  }

await openBoostFlow({
  trackKey,
  title: e.target.dataset.song || "",
  artist: e.target.dataset.artist || "",
  albumArt: e.target.dataset.albumArt || ""
}, e.target);
  return;
}



});

let livePollTimer = null;
let livePollingActive = false;
let messageBadgePollTimer = null;
let messageBadgePollingActive = false;
const MESSAGE_BADGE_POLL_MS = 6000;

function getPollIntervalForTab(tabId) {
    switch (tabId) {
        case "section-message":
            return 8000;
        case "section-request":
        case "section-requests":
            return 12000;
        case "section-home":
            return 30000;
        case "section-contact":
            return 0; // no background polling needed
        default:
            return 15000;
    }
}

async function runTabPollCycle() {
    // Always keep event notice current except on Contact tab.
    if (currentActiveTab !== "section-contact" && typeof fetchEventNotice === "function") {
        fetchEventNotice();
    }

    if (currentActiveTab === "section-home") {
        loadMySupportTile();
        if (typeof window.fetchMoodStats === "function") {
            window.fetchMoodStats();
        }
    } else if (currentActiveTab === "section-request" || currentActiveTab === "section-requests") {
        refreshRequests();
    }
}

function scheduleNextTabPoll() {
    if (!livePollingActive) return;
    if (livePollTimer) {
        clearTimeout(livePollTimer);
        livePollTimer = null;
    }

    const waitMs = getPollIntervalForTab(currentActiveTab);
    if (waitMs <= 0) return;

    livePollTimer = setTimeout(async () => {
        await runTabPollCycle();
        scheduleNextTabPoll();
    }, waitMs);
}

function startLivePolling() {
    if (livePollingActive) return;
    livePollingActive = true;
    runTabPollCycle();
    scheduleNextTabPoll();
}

function stopLivePolling() {
    livePollingActive = false;
    if (livePollTimer) {
        clearTimeout(livePollTimer);
        livePollTimer = null;
    }
}

async function runMessageBadgePoll() {
    await loadMessageThread();
}

function scheduleNextMessageBadgePoll() {
    if (!messageBadgePollingActive) return;
    if (messageBadgePollTimer) {
        clearTimeout(messageBadgePollTimer);
        messageBadgePollTimer = null;
    }
    messageBadgePollTimer = setTimeout(async () => {
        await runMessageBadgePoll();
        scheduleNextMessageBadgePoll();
    }, MESSAGE_BADGE_POLL_MS);
}

function startMessageBadgePolling() {
    if (messageBadgePollingActive) return;
    messageBadgePollingActive = true;
    runMessageBadgePoll();
    scheduleNextMessageBadgePoll();
}

function stopMessageBadgePolling() {
    messageBadgePollingActive = false;
    if (messageBadgePollTimer) {
        clearTimeout(messageBadgePollTimer);
        messageBadgePollTimer = null;
    }
}

// Visibility API
document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
        startLivePolling();
        startMessageBadgePolling();
        if (currentActiveTab === "section-message") {
            scheduleMarkMessagesRead();
        }
    } else {
        stopLivePolling();
        stopMessageBadgePolling();
        cancelMarkMessagesRead();
    }
});


// Initial state
if (document.visibilityState === "visible") {
    startLivePolling();
    startMessageBadgePolling();
}


// on load
document.addEventListener("DOMContentLoaded", () => {
    // 🔔 Load notice immediately
    if (typeof fetchEventNotice === "function") {
        fetchEventNotice();
    }

    startLivePolling();
});

</script>

<script>
let lastNoticeHash = null;

async function fetchEventNotice() {
    try {
        const res = await fetch(
            "/api/public/get_event_notice.php?event_uuid=<?= e($uuid); ?>"
        );
        const data = await res.json();

        if (!data.ok || !data.notice) {
            document.getElementById("noticeWrapper").innerHTML = "";
            lastNoticeHash = null;
            return;
        }

        const hash = data.notice.type + "|" + data.notice.updated_at;
        if (hash === lastNoticeHash) return; // no change

        lastNoticeHash = hash;
        renderNotice(data.notice);

    } catch (e) {
        console.warn("Notice poll failed");
    }
}

function renderNotice(notice) {
    const wrap = document.getElementById("noticeWrapper");
    if (!wrap) return;

    wrap.innerHTML = `
        <div class="card event-notice" id="eventNotice"
             style="
                background:#0c0c11;
                border:1px solid rgba(255,47,210,0.35);
                box-shadow:0 0 20px rgba(255,47,210,0.25);
                margin-bottom:24px;
             ">

            <div style="
                display:flex;
                justify-content:space-between;
                align-items:center;
                margin-bottom:10px;
            ">
                <div style="
                    font-weight:800;
                    font-size:16px;
                    color:#ff2fd2;
                ">
                    ${notice.title || ''}
                </div>

                <button id="toggleNotice"
                        style="
                            background:none;
                            border:none;
                            color:#bbb;
                            font-size:12px;
                            cursor:pointer;
                        ">
                    Hide
                </button>
            </div>

            <div class="notice-body"
                 style="
                    font-size:14px;
                    color:#ddd;
                    line-height:1.55;
                    white-space:pre-line;
                    text-align:center;
                 ">
                ${notice.body}
            </div>
        </div>
    `;

    initNoticeBehaviour(notice.type);
}

function initNoticeBehaviour(type) {
    const notice = document.getElementById('eventNotice');
    const toggle = document.getElementById('toggleNotice');
    if (!notice || !toggle) return;

    toggle.onclick = () => {
        notice.classList.toggle('collapsed');
        toggle.textContent = notice.classList.contains('collapsed')
            ? 'Show'
            : 'Hide';
    };

    // Auto-collapse only for pre/live
    if (type !== 'post_event') {
        setTimeout(() => {
            notice.classList.add('collapsed');
            toggle.textContent = 'Show';
        }, 15000);
    }
}
</script>



<script>
document.querySelectorAll(".tip-preset").forEach(btn => {
  if (!ENABLE_PATRON_PAYMENTS) return;
  btn.addEventListener("click", async () => {
    let amount;

    document.querySelectorAll(".tip-preset")
      .forEach(b => b.classList.remove("highlight"));

    btn.classList.add("highlight");

    if (btn.classList.contains("other")) {
      const input = prompt("Enter tip amount (AUD):", "5");
      if (!input) return;
      amount = Math.round(parseFloat(input) * 100);
      if (!amount || amount < 500) {
        alert("Minimum tip is $5");
        return;
      }
    } else {
      amount = parseInt(btn.dataset.amount, 10);
    }

    await openTipFlow(amount, btn);
  });
});
</script>




<script>
/**
 * Stripe Elements modal (Payment Element)
 * - Works for both tips and boosts
 * - Calls your new endpoints to create PaymentIntents
 * - Confirms payment in-place
 */

let stripe = null;
let elements = null;
let activeClientSecret = null;
let activeContext = null; // { type: 'tip'|'boost', trackKey?, amount?, buttonEl? }

function ensureStripe() {
  if (!stripe) stripe = Stripe(STRIPE_PUBLISHABLE_KEY);
  return stripe;
}

function showPayModal({ title, subtitle }) {
  document.getElementById("stripePayTitle").textContent = title || "Secure payment";
  document.getElementById("stripePaySubtitle").textContent = subtitle || "Powered by Stripe";
  document.getElementById("stripePayMsg").textContent = "";
  document.getElementById("stripePayModal").style.display = "block";
}

function hidePayModal() {
  document.getElementById("stripePayModal").style.display = "none";

  // ✅ restore button state
  if (activeContext?.buttonEl) {
    activeContext.buttonEl.disabled = false;
    activeContext.buttonEl.textContent =
      activeContext.buttonEl.dataset._origText ||
      activeContext.buttonEl.textContent;
  }

  const pe = document.getElementById("payment-element");
  pe.innerHTML = "";
  elements = null;
  activeClientSecret = null;
  activeContext = null;
}

async function mountPaymentElement(clientSecret) {
  const s = ensureStripe();

elements = s.elements({
  clientSecret
});

  const paymentElement = elements.create("payment");
  paymentElement.mount("#payment-element");
}

function setPayMsg(msg, isError=false) {
  const el = document.getElementById("stripePayMsg");
  el.textContent = msg || "";
  el.style.color = isError ? "#ff7a7a" : "#bbb";
}

function lockPayButton(locked, label) {
  const btn = document.getElementById("stripePayConfirm");
  btn.disabled = !!locked;
  btn.textContent = label || (locked ? "Processing…" : "Pay");
  btn.style.opacity = locked ? "0.8" : "1";
  btn.style.cursor = locked ? "default" : "pointer";
}

async function createIntent(url, formData) {
  const res = await fetch(url, { method: "POST", body: formData });
  const data = await res.json();
  if (!res.ok || !data.client_secret) {
    console.log("Intent creation failed:", data);
    throw new Error(data.error || "Unable to start payment");
  }
  return data.client_secret;
}

async function openTipFlow(amountCents, buttonEl) {
  syncGuestName?.(); // keep your existing name sync

  const fd = new FormData();
  fd.append("event_uuid", EVENT_UUID);
  fd.append("amount", String(amountCents));
  fd.append("guest_name", localStorage.getItem("mdjr_guest_name") || "");

  activeContext = { type: "tip", amountCents, buttonEl };

  // UI lock on the clicked preset
  if (buttonEl) {
    buttonEl.disabled = true;
    buttonEl.dataset._origText = buttonEl.textContent;
    buttonEl.textContent = "Opening…";
  }

  try {
    const cs = await createIntent("/api/public/create_tip_intent.php", fd);
    activeClientSecret = cs;
    

    showPayModal({
      title: "💖 Support the DJ",
      subtitle: `${EVENT_TITLE} • Tip $${(amountCents/100).toFixed(2)} • Secure payment`
    });

    await mountPaymentElement(cs);

    lockPayButton(false, `Pay $${(amountCents/100).toFixed(2)}`);

  } catch (e) {
    alert("Tipping is unavailable right now.");
    console.error(e);
    if (buttonEl) {
      buttonEl.disabled = false;
      buttonEl.textContent = buttonEl.dataset._origText || buttonEl.textContent;
    }
  }
}

async function openBoostFlow(track, buttonEl) {
  syncGuestName?.();

  const fd = new FormData();
  fd.append("event_uuid", EVENT_UUID);
  fd.append("track_key", track.trackKey);
  fd.append("guest_name", localStorage.getItem("mdjr_guest_name") || "");

      activeContext = {
      type: "boost",
      trackKey: track.trackKey,
      title: track.title || "",
      artist: track.artist || "",
      albumArt: track.albumArt || "",
      buttonEl
    };

  if (buttonEl) {
    buttonEl.disabled = true;
    buttonEl.dataset._origText = buttonEl.textContent;
    buttonEl.textContent = "Opening…";
  }

  try {
    const cs = await createIntent("/api/public/create_boost_intent.php", fd);
    activeClientSecret = cs;

const label =
  track.title
    ? `${track.title}${track.artist ? " — " + track.artist : ""}`
    : "This track";

showPayModal({
  title: `🚀 Boost: ${label}`,
  subtitle: "Boost visibility • No guarantee of play"
});

    await mountPaymentElement(cs);

    lockPayButton(false, "Pay $5.00");

  } catch (e) {
    alert("Boosting is unavailable right now.");
    console.error(e);
    if (buttonEl) {
      buttonEl.disabled = false;
      buttonEl.textContent = buttonEl.dataset._origText || buttonEl.textContent;
    }
  }
}





function optimisticallyUpdateSupport(amountCents) {

  const tile = document.getElementById("mySupportTile");
  const amountEl = document.getElementById("mySupportAmount");

  if (!tile || !amountEl) return;

  const current = parseFloat(amountEl.textContent.replace("$", "")) || 0;
  const next = current + amountCents / 100;

  amountEl.textContent = `$${next.toFixed(2)}`;
  tile.style.display = "block";
}


function showTipThankYouModal() {
  const modal = document.getElementById("tipThankYouModal");
  const eventEl = document.getElementById("tipThankYouEvent");

  if (!modal) return;

  if (eventEl && EVENT_TITLE) {
    eventEl.textContent = EVENT_TITLE;
  }

  modal.style.display = "block";
}

document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("tipThankYouModal");
  const btn = document.getElementById("tipThankYouClose");

  if (!modal || !btn) return;

  btn.addEventListener("click", () => {
    modal.style.display = "none";
  });

  modal.addEventListener("click", (e) => {
    if (e.target === modal) modal.style.display = "none";
  });
});


function showBoostThankYouModal({ title = "", artist = "", albumArt = "" } = {}) {
  const modal    = document.getElementById("boostThankYouModal");
  const tile     = document.getElementById("boostedTrackTile");
  const artEl    = document.getElementById("boostedTrackArt");
  const titleEl  = document.getElementById("boostedTrackTitle");
  const artistEl = document.getElementById("boostedTrackArtist");
  const eventEl  = document.getElementById("boostThankYouEvent");

  if (!modal) return;

  // Event title
  if (eventEl && EVENT_TITLE) {
    eventEl.textContent = EVENT_TITLE;
  }

  // Track text
  titleEl.textContent  = title || "";
  artistEl.textContent = artist || "";

  // Album art
  if (albumArt) {
    artEl.src = albumArt;
    artEl.style.display = "block";
    tile.style.display = "flex";
  } else {
    tile.style.display = "none";
  }

  modal.style.display = "block";
}





async function confirmActivePayment() {
  if (!stripe || !elements || !activeClientSecret) {
    console.error("Stripe not ready");
    return;
  }

  lockPayButton(true, "Processing…");

const { error } = await stripe.confirmPayment({
  elements,
  redirect: "if_required"
});

  if (error) {
    console.error("Payment failed", error);
    alert(error.message || "Payment failed");
    lockPayButton(false, "Pay");
    return;
  }




// ✅ PAYMENT SUCCEEDED
if (activeContext?.type === "boost") {
  showBoostThankYouModal({
    title: activeContext.title || "",
    artist: activeContext.artist || "",
    albumArt: activeContext.albumArt || ""
  });

  hidePayModal();
  refreshRequests?.();
  loadMySupportTile?.();
  return;
}

if (activeContext?.type === "tip") {
  showTipThankYouModal();

  hidePayModal();
  loadMySupportTile?.();
  return;
}

// safety fallback
hidePayModal();

}

document.addEventListener("DOMContentLoaded", () => {
  const payBtn = document.getElementById("stripePayConfirm");

  if (!payBtn) {
    console.error("❌ Pay button not found");
    return;
  }

  payBtn.addEventListener("click", async (e) => {
    e.preventDefault();
    e.stopPropagation();
    confirmActivePayment();
  });
});


</script>


<!-- Stripe Payment Modal -->
<div id="stripePayModal" style="
  display:none;
  position:fixed; inset:0;
  background:rgba(0,0,0,0.7);
  z-index:10000;
  padding:18px;
">
    <div style="
      max-width:480px;
      margin:6vh auto 0;
      background:#161623;
      border-radius:18px;
      overflow-y:auto;
      max-height:85vh;
    ">
    <div style="
      display:flex; align-items:center; justify-content:space-between;
      padding:14px 16px;
      background:linear-gradient(135deg, rgba(99,91,255,0.22), rgba(255,47,210,0.12));
      border-bottom:1px solid rgba(255,255,255,0.08);
    ">
      <div>
        <div id="stripePayTitle" style="font-weight:800; font-size:15px; color:#fff;">
          Secure payment
        </div>
        <div id="stripePaySubtitle" style="font-size:12px; color:#bbb; margin-top:2px;">
          Powered by Stripe
        </div>
      </div>

      <button id="stripePayClose" type="button" style="
        background:rgba(255,255,255,0.08);
        border:1px solid rgba(255,255,255,0.12);
        color:#fff;
        border-radius:12px;
        padding:8px 10px;
        cursor:pointer;
      ">✕</button>
    </div>

    <div style="padding:16px;">
      <div id="payment-element"></div>

      <button id="stripePayConfirm" type="button" style="
        width:100%;
        margin-top:14px;
        padding:14px 16px;
        border-radius:14px;
        border:none;
        font-weight:800;
        cursor:pointer;
        background:linear-gradient(135deg, #635bff, #7a72ff);
        color:#fff;
        box-shadow:0 0 14px rgba(99,91,255,0.45);
      ">
        Pay
      </button>

      <div id="stripePayMsg" style="
        margin-top:10px;
        font-size:12px;
        color:#bbb;
        text-align:center;
        min-height:18px;
      "></div>

      <div style="margin-top:10px; font-size:11px; color:#888; text-align:center;">
        Tips/boosts are voluntary and non-refundable. No guarantee a song will be played.
      </div>
    </div>
  </div>
</div>


<!-- 💖 Tip Thank You Modal -->
<div id="tipThankYouModal" style="
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.75);
  z-index:10001;
  padding:18px;
">
  <div style="
    max-width:420px;
    margin:20vh auto 0;
    background:#161623;
    border-radius:20px;
    padding:28px 22px;
    text-align:center;
    box-shadow:0 0 40px rgba(99,91,255,0.45);
  ">
    <div style="font-size:42px; margin-bottom:10px;">💖</div>

    <div style="font-size:20px; font-weight:800; color:#c7c2ff;">
      Tip Sent!
    </div>

<div
  id="tipThankYouEvent"
  style="
    margin-top:6px;
    font-size:13px;
    color:#aaa;
  "
></div>


    <div style="
      margin-top:12px;
      font-size:14px;
      color:#ddd;
      line-height:1.55;
    ">
      Your support means a lot to the DJ.<br>
      Thank you for spreading the love 🙏
    </div>

    <div style="
      margin-top:18px;
      font-size:13px;
      color:#aaa;
    ">
      Tips are voluntary and non-refundable.
    </div>

    <button
      id="tipThankYouClose"
      style="
        margin-top:22px;
        width:100%;
        padding:12px 16px;
        border-radius:14px;
        border:none;
        font-weight:800;
        background:linear-gradient(135deg,#635bff,#7a72ff);
        color:#fff;
        cursor:pointer;
      "
    >
      Done
    </button>
  </div>
</div>



<!-- Thank you Modal -->
<div id="boostThankYouModal" style="
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.75);
  z-index:10001;
  padding:18px;
">
  <div style="
    max-width:420px;
    margin:18vh auto 0;
    background:#161623;
    border-radius:20px;
    padding:26px 22px;
    text-align:center;
    box-shadow:0 0 40px rgba(0,229,255,0.45);
  ">
    <div style="font-size:40px; margin-bottom:10px;">⚡</div>

    <div style="font-size:20px; font-weight:800; color:#00e5ff;">
      Boost Confirmed!
    </div>


<div
  id="boostThankYouEvent"
  style="
    margin-top:6px;
    font-size:13px;
    color:#9fdfff;
    opacity:0.9;
  "
></div>


<!-- 🎵 Boosted Track Tile -->
<div
  id="boostedTrackTile"
  style="
    display:none;
    align-items:center;
    gap:12px;
    margin:14px auto 6px;
    padding:10px 12px;
    border-radius:14px;
    background:rgba(255,255,255,0.05);
    max-width:360px;
    text-align:left;
  "
>
  <img
    id="boostedTrackArt"
    src=""
    alt=""
    style="
      width:56px;
      height:56px;
      border-radius:8px;
      object-fit:cover;
      background:#111;
      flex-shrink:0;
    "
  />

  <div style="min-width:0;">
    <div
      id="boostedTrackTitle"
      style="
        font-weight:700;
        font-size:14px;
        color:#fff;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
      "
    ></div>

    <div
      id="boostedTrackArtist"
      style="
        font-size:13px;
        color:#9fdfff;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
      "
    ></div>
  </div>
</div>




    <div style="
      margin-top:10px;
      font-size:14px;
      color:#ccc;
      line-height:1.5;
    ">
      Your boost increases visibility only.<br>
      It does <strong>not</strong> guarantee the song will be played.
    </div>

    <div style="
      margin-top:16px;
      font-size:13px;
      color:#9fdfff;
    ">
      Thank you for supporting the DJ 🙏
    </div>

    <!-- ✅ DONE BUTTON (NOW VISIBLE) -->
    <button
      id="boostThankYouClose"
      style="
        margin-top:22px;
        width:100%;
        padding:12px 16px;
        border-radius:14px;
        border:none;
        font-weight:800;
        background:linear-gradient(135deg,#00e5ff,#3aa9ff);
        color:#001018;
        cursor:pointer;
      "
    >
      Done
    </button>
  </div>
</div>




<script>
document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("stripePayModal");
  const closeBtn = document.getElementById("stripePayClose");

  if (!modal || !closeBtn) {
    console.warn("Stripe modal elements not found");
    return;
  }

  // ❌ Close via X
  closeBtn.addEventListener("click", () => {
    if (activeContext?.buttonEl) {
      activeContext.buttonEl.disabled = false;
      activeContext.buttonEl.textContent =
        activeContext.buttonEl.dataset._origText || activeContext.buttonEl.textContent;
    }
    hidePayModal();
  });

  // ❌ Close by clicking backdrop
  modal.addEventListener("click", (e) => {
    if (e.target === modal) {
      closeBtn.click();
    }
  });
});
</script>


<script>
document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("boostThankYouModal");
  const doneBtn = document.getElementById("boostThankYouClose");

  if (!modal || !doneBtn) return;

  // ✅ Close via Done button
  doneBtn.addEventListener("click", () => {
    modal.style.display = "none";
  });

  // ✅ Close by tapping backdrop
  modal.addEventListener("click", (e) => {
    if (e.target === modal) {
      modal.style.display = "none";
    }
  });
});
</script>


<script>
const navButtons = document.querySelectorAll("#mdjr-nav button");
const panels = document.querySelectorAll(".tab-panel");
const tabStorageKey = "mdjr_active_tab_<?= e($uuid); ?>";

function activateTab(targetId) {
    let found = false;

    panels.forEach(panel => {
        const isActive = panel.id === targetId;
        panel.classList.toggle("active", isActive);
        if (isActive) found = true;
    });

    navButtons.forEach(btn => {
        btn.classList.toggle("active", btn.dataset.target === targetId);
    });

    if (found) {
        currentActiveTab = targetId;
        try {
            sessionStorage.setItem(tabStorageKey, targetId);
        } catch (e) {}

        if (targetId === "section-message") {
            scheduleMarkMessagesRead();
        } else {
            cancelMarkMessagesRead();
        }

        // On tab change, refresh relevant data now and reschedule polling cadence.
        runTabPollCycle();
        scheduleNextTabPoll();
    }

    return found;
}

navButtons.forEach(btn => {
    btn.addEventListener("click", () => {
        const targetId = btn.dataset.target;
        if (!activateTab(targetId)) return;
        window.scrollTo({ top: 0, behavior: "smooth" });
    });
});

let initialTab = "section-home";
try {
    const savedTab = sessionStorage.getItem(tabStorageKey);
    if (savedTab) {
        initialTab = savedTab;
    }
} catch (e) {}

if (!activateTab(initialTab)) {
    activateTab("section-home");
}
</script>

</body>
</html>
