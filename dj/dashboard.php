<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/bootstrap.php';
// Ensure PHP uses local / configured timezone
date_default_timezone_set(date_default_timezone_get());
require_dj_login();

if (empty($_SESSION['dj_id'])) {
    exit('DJ session missing');
}

$djId = (int) $_SESSION['dj_id'];

$db = db();

// -----------------------------
// LIFETIME EVENTS COUNT
// -----------------------------
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM events
    WHERE user_id = ?
");
$stmt->execute([$djId]);
$lifetimeEvents = (int)$stmt->fetchColumn();






// -----------------------------
// REQUEST STATS (FAST ROLLUPS)
// -----------------------------
$stmt = $db->prepare("
    SELECT
        SUM(total_requests) AS lifetime_requests,
        SUM(CASE
            WHEN year = YEAR(CURRENT_DATE)
             AND month = MONTH(CURRENT_DATE)
            THEN total_requests
            ELSE 0
        END) AS this_month_requests
    FROM song_request_stats_monthly
    WHERE dj_id = ?
");
$stmt->execute([$djId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$lifetimeRequests   = (int)($stats['lifetime_requests'] ?? 0);
$thisMonthRequests  = (int)($stats['this_month_requests'] ?? 0);



// -----------------------------
// VOTE STATS (FAST ROLLUPS)
// -----------------------------
$stmt = $db->prepare("
    SELECT
        SUM(total_votes) AS lifetime_votes,
        SUM(CASE
            WHEN year = YEAR(CURRENT_DATE)
             AND month = MONTH(CURRENT_DATE)
            THEN total_votes
            ELSE 0
        END) AS this_month_votes
    FROM song_vote_stats_monthly
    WHERE dj_id = ?
");
$stmt->execute([$djId]);
$voteStats = $stmt->fetch(PDO::FETCH_ASSOC);

$lifetimeVotes  = (int)($voteStats['lifetime_votes'] ?? 0);
$thisMonthVotes = (int)($voteStats['this_month_votes'] ?? 0);


// -----------------------------
// VOTE ENGAGEMENT METRIC
// -----------------------------
$voteEngagementRate = 0;

if ($lifetimeRequests > 0) {
    $voteEngagementRate = round(
        ($lifetimeVotes / $lifetimeRequests) * 100
    );
}

// -----------------------------
// TIP STATS (LIFETIME ONLY)
// -----------------------------
$stmt = $db->prepare("
    SELECT
        currency,
        SUM(total_tips_amount) AS lifetime_amount,
        SUM(total_tips_count)  AS lifetime_count
    FROM event_tip_stats
    WHERE dj_user_id = ?
    GROUP BY currency
");
$stmt->execute([$djId]);
$tipLifetime = $stmt->fetchAll(PDO::FETCH_ASSOC);


// -----------------------------
// MONTHLY TIPS
// -----------------------------
$stmt = $db->prepare("
    SELECT
        currency,
        total_tips_amount AS month_amount,
        total_tips_count  AS month_count
    FROM event_tip_stats_monthly
    WHERE dj_user_id = ?
      AND year  = YEAR(CURRENT_DATE)
      AND month = MONTH(CURRENT_DATE)
");
$stmt->execute([$djId]);
$tipMonthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

$monthlyByCurrency = [];
foreach ($tipMonthly as $row) {
    $monthlyByCurrency[$row['currency']] = $row;
}



// -----------------------------
// BOOST STATS (LIFETIME ONLY)
// -----------------------------
$stmt = $db->prepare("
    SELECT
        currency,
        SUM(total_boosts_amount) AS lifetime_amount,
        SUM(total_boosts_count)  AS lifetime_count
    FROM event_track_boost_stats
    WHERE dj_user_id = ?
    GROUP BY currency
");
$stmt->execute([$djId]);
$boostLifetime = $stmt->fetchAll(PDO::FETCH_ASSOC);


// -----------------------------
// MONTHLY BOOSTS
// -----------------------------
$stmt = $db->prepare("
    SELECT
        currency,
        total_boosts_amount AS month_amount,
        total_boosts_count  AS month_count
    FROM event_track_boost_stats_monthly
    WHERE dj_user_id = ?
      AND year  = YEAR(CURRENT_DATE)
      AND month = MONTH(CURRENT_DATE)
");
$stmt->execute([$djId]);
$boostMonthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

$boostMonthlyByCurrency = [];
foreach ($boostMonthly as $row) {
    $boostMonthlyByCurrency[$row['currency']] = $row;
}


//--------------------------------
/* --- Spotify status --- */
$isSpotifyConnected = false;

try {
    $stmt = $db->prepare("
        SELECT expires_at
        FROM dj_spotify_accounts
        WHERE dj_id = ?
    ");
    $stmt->execute([$djId]);
    $spotifyAccount = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($spotifyAccount && !empty($spotifyAccount['expires_at'])) {
        $isSpotifyConnected = (strtotime($spotifyAccount['expires_at']) > time());
    }
} catch (Throwable $e) {
    // fail silently
}


$userModel = new User();
$user = $userModel->findById($djId);
$spotifyAccessEnabled = (int)($user['spotify_access_enabled'] ?? 0);

// -----------------------------
// STRIPE STATUS
// -----------------------------
$stmt = $db->prepare("
    SELECT stripe_connect_onboarded
    FROM users
    WHERE id = ?
");
$stmt->execute([$djId]);
$stripeOnboarded = (bool)$stmt->fetchColumn();

// -----------------------------


$eventCtrl = new EventController();
$eventsRes = $eventCtrl->listForUser($djId);
$events = $eventsRes['events'] ?? [];


$nextEvent = null;
$today = new DateTime('today');

foreach ($events as $event) {
    if (empty($event['event_date'])) {
        continue;
    }

    try {
        $eventDate = new DateTime($event['event_date']);
    } catch (Exception $e) {
        continue;
    }

    // Only consider today or future
    if ($eventDate < $today) {
        continue;
    }

    if ($nextEvent === null || $eventDate < new DateTime($nextEvent['event_date'])) {
        $nextEvent = $event;
    }
}


$requestsForNextEvent = 0;
$votesForNextEvent    = 0;

if (!empty($nextEvent['id'])) {
    // Requests
    $stmt = $db->prepare("
        SELECT total_requests
        FROM event_request_stats
        WHERE event_id = ?
    ");
    $stmt->execute([(int)$nextEvent['id']]);
    $requestsForNextEvent = (int)($stmt->fetchColumn() ?? 0);

    // Votes
    $stmt = $db->prepare("
        SELECT total_votes
        FROM event_vote_stats
        WHERE event_id = ?
    ");
    $stmt->execute([(int)$nextEvent['id']]);
    $votesForNextEvent = (int)($stmt->fetchColumn() ?? 0);
}

$topRequests = [];
if (!empty($nextEvent['id'])) {
        $stmt = $db->prepare("
            SELECT 
                COALESCE(spotify_track_name, song_title) AS title,
                COALESCE(spotify_artist_name, artist) AS artist,
                COUNT(*) AS total
            FROM song_requests
            WHERE event_id = ?
            GROUP BY 
                COALESCE(spotify_track_name, song_title),
                COALESCE(spotify_artist_name, artist)
            ORDER BY total DESC, MIN(created_at) ASC
            LIMIT 3
        ");
        $stmt->execute([(int)$nextEvent['id']]);
        $topRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

$latestRequests = [];
if (!empty($nextEvent['id'])) {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(spotify_track_name, song_title) AS title,
            COALESCE(spotify_artist_name, artist) AS artist
        FROM song_requests
        WHERE event_id = ?
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$nextEvent['id']]);
    $latestRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}



function resolveEventBadges(array $event): array
{
    $state = $event['event_state'] ?? 'upcoming';

    switch ($state) {
        case 'live':
            $primary = ['label' => 'LIVE', 'class' => 'badge-live'];
            break;

        case 'ended':
            $primary = ['label' => 'ENDED', 'class' => 'badge-ended'];
            break;

        default:
            $primary = ['label' => 'UPCOMING', 'class' => 'badge-upcoming'];
    }

    $isToday = false;

    if (!empty($event['event_date'])) {
        try {
            $today = new DateTimeImmutable('today');
            $eventDay = new DateTimeImmutable($event['event_date']);

            $isToday = (
                $eventDay->format('Y-m-d') === $today->format('Y-m-d')
                && $state !== 'ended'
            );
        } catch (Exception $e) {}
    }

    return [
        'primary' => $primary,
        'today'   => $isToday
    ];
}


$today = new DateTime('today');
$dashboardEvents = [];

foreach ($events as $event) {
    if (empty($event['event_date'])) {
        continue;
    }

    try {
        $eventDate = new DateTime($event['event_date']);
        $eventDate->setTime(0, 0, 0);
    } catch (Exception $e) {
        continue;
    }

    // Only include today or future
    if ($eventDate < $today) {
        continue;
    }

    $dashboardEvents[] = $event;
}


usort($dashboardEvents, function ($a, $b) {
    return strtotime($a['event_date']) <=> strtotime($b['event_date']);
});



$pageTitle = "Dashboard";
require __DIR__ . '/layout.php';
?>

<div class="dashboard-wrap">

<style>
/* DASHBOARD STYLES */
.dashboard-header {
    background: radial-gradient(circle at top left, #550066 0%, #0d0d0f 80%);
    padding: 30px 25px;
    border-radius: 12px;
    text-align: center;
    margin-bottom: 35px;
}

.dashboard-header h1 {
    margin: 0;
    font-size: 32px;
    font-weight: 700;
    color: #ff2fd2;
}

.dashboard-header p {
    font-size: 16px;
    color: #d0d0d0;
}

/* Cards Section */
.cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 40px;
}

@media (max-width: 1024px) {
    .cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 520px) {
    .cards {
        grid-template-columns: 1fr;
    }
}

.card-box {
    background: #1a1a1f;
    border: 1px solid #292933;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    transition: 0.2s;
}

.card-box:hover {
    border-color: #ff2fd2;
}

.card-box h2 {
    margin: 0;
    font-size: 26px;
    color: #ff2fd2;
}

.card-box p {
    margin-top: 8px;
    font-size: 15px;
    color: #d0d0d0;
}

/* Create Event Button */
.create-btn {
    display: block;
    text-align: center;
    background: #ff2fd2;
    color: white;
    padding: 14px;
    border-radius: 8px;
    font-weight: 600;
    
    text-decoration: none;
    margin-bottom: 35px;
    transition: 0.25s;
}

.create-btn:hover {
    background: #ff4ae0;
}

/* Events List */
.event-list {
    background: #1a1a1f;
    border: 1px solid #292933;
    border-radius: 10px;
    padding: 20px;
}

.event-item {
    padding: 18px 0;
    border-bottom: 1px solid #292933;
}

.event-item:last-child {
    border-bottom: none;
}

.event-item h3 {
    margin: 0;
    font-size: 20px;
    color: #fff;
}

.event-item small {
    color: #b0b0b0;
}

.event-empty {
    text-align: center;
    font-size: 15px;
    color: #a0a0a0;
    padding: 20px 0;
}



/* SPOTIFY BANNER */
.spotify-banner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 20px 24px;
    border-radius: 12px;
    margin-bottom: 30px;
    border: 1px solid #2a2a33;
    background: linear-gradient(135deg, #141417, #0d0d0f);
}

.spotify-banner.connected {
    border-color: rgba(29, 185, 84, 0.4);
    box-shadow: 0 0 0 1px rgba(29, 185, 84, 0.15) inset;
}

.spotify-banner.disconnected {
    border-color: rgba(255, 47, 210, 0.4);
    box-shadow: 0 0 0 1px rgba(255, 47, 210, 0.15) inset;
}

.spotify-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.spotify-logo {
    width: 42px;
    height: 42px;
    color: #1db954;
    flex-shrink: 0;
}

.spotify-text h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #fff;
}

.spotify-text p {
    margin: 4px 0 0;
    font-size: 14px;
    color: #b0b0b0;
}

.spotify-right {
    display: flex;
    align-items: center;
    gap: 14px;
}

.spotify-btn {
    background: #1db954;
    color: #000;
    padding: 10px 18px;
    border-radius: 999px;
    font-weight: 700;
    text-decoration: none;
    transition: 0.2s;
}

.spotify-btn:hover {
    background: #1ed760;
}

.spotify-pill {
    background: rgba(29, 185, 84, 0.15);
    color: #1db954;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
}

.spotify-link {
    color: #ff2fd2;
    font-size: 13px;
    text-decoration: none;
}

.spotify-link:hover {
    text-decoration: underline;
}

/* Mobile */
@media (max-width: 640px) {
    .spotify-banner {
        flex-direction: column;
        align-items: flex-start;
    }

    .spotify-right {
        width: 100%;
        justify-content: flex-start;
    }
}

.card-highlight {
    border-color: #ff2fd2;
    box-shadow: 0 0 0 1px rgba(255,47,210,0.2) inset;
}

.card-updates {
    background: linear-gradient(135deg, #1a1a1f, #141418);
}

.card-box h2 {
    font-size: 22px;
}

.card-box small {
    display: block;
    margin-top: 6px;
    font-size: 13px;
}


.event-countdown {
    margin-top: 8px;
    font-size: 14px;
    font-weight: 600;
    color: #ff2fd2;
}

/* EVENT STATUS BADGES */
.event-badge {
    display: inline-block;
    margin-left: 8px;
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    vertical-align: middle;
}

.badge-today {
    background: rgba(29, 185, 84, 0.15);
    color: #1db954;
    border: 1px solid rgba(29, 185, 84, 0.4);
}

.badge-upcoming {
    background: rgba(255, 47, 210, 0.15);
    color: #ff2fd2;
    border: 1px solid rgba(255, 47, 210, 0.4);
}



.request-link-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 4px;
    flex-wrap: wrap;
}

.request-url {
    background: #0d0d0f;
    border: 1px solid #292933;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 13px;
    color: #d0d0d0;
}

.copy-btn {
    background: #1a1a1f;
    border: 1px solid #292933;
    color: #ff2fd2;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.copy-btn:hover {
    border-color: #ff2fd2;
    background: #1f1f25;
}

.copy-btn.copied {
    background: rgba(29, 185, 84, 0.15);
    border-color: rgba(29, 185, 84, 0.5);
    color: #1db954;
}


.create-btn {
    opacity: 0.95;
}

.create-btn:hover {
    opacity: 1;
}


.event-banner {
    display: flex;
    justify-content: space-between;
    gap: 24px;
    padding: 24px 28px;
    border-radius: 14px;
    margin-bottom: 35px;
    border: 1px solid #292933;
    background: linear-gradient(135deg, #1a1a1f, #141418);
}

.event-left h2 {
    margin: 0;
    font-size: 22px;
    color: #fff;
}

.event-meta {
    margin-top: 6px;
    font-size: 14px;
    color: #b0b0b0;
}

.event-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 12px;
    margin-top: 0px;   /* 👈 push button down */
}

.request-count .count {
    font-size: 28px;
    font-weight: 700;
    color: #ff2fd2;
}

.request-count .label {
    font-size: 13px;
    color: #b0b0b0;
}

.event-manage-btn {
    background: #292933;
    padding: 7px 14px;
    border-radius: 999px;
    color: #fff;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;          /* ⬅️ down from default */
    opacity: 0.9;             /* subtle de-emphasis */
    transition: all 0.2s ease;
}

.event-manage-btn:hover {
    background: #383844;
    opacity: 1;
}

.event-heading {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #ff2fd2; /* electric pink */
    margin-bottom: 6px;
}

@media (max-width: 768px) {
    .event-banner {
        flex-direction: column;
        align-items: flex-start;
    }

    .event-right {
        align-items: flex-start;
    }
}

.dashboard-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 20px;
}


/* Make widths/padding behave consistently */
.dashboard-wrap, 
.dashboard-wrap * {
    box-sizing: border-box;
}


.card-toggle {
    cursor: pointer;
}

.tile-list {
    margin-top: 12px;
}

.tile-list.hidden {
    display: none;
}

.tile-row {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    margin-bottom: 6px;
}

.tile-row span {
    color: #b0b0b0;
    font-size: 13px;
}

.tile-hint {
    display: block;
    margin-top: 10px;
    font-size: 12px;
    color: #777;
}


.card-wide {
    grid-column: span 2;
}


.event-context {
    margin-bottom: 40px;
    padding-bottom: 10px;
    border-bottom: 1px solid #292933;
}


/* Dashboard Create Event button – full tile width */
#createEvent,
#createEventBtn,
#createEventButton {
    width: 100%;
}

/* Or simply target the existing class */
.create-btn {
    display: block;
    width: 100%;
}

.dashboard-wrap .create-btn {
    display: block;
    width: 100%;
}

.dashboard-wrap .create-btn {
    border: none;
    outline: none;
    box-shadow: none;
}




.event-badge {
    font-size: 12px;
    padding: 5px 10px;
    border-radius: 999px;
    font-weight: 600;
    white-space: nowrap;
}

.badge-upcoming {
    background: rgba(255, 47, 210, 0.15);
    color: #ff2fd2;
}

.badge-today {
    background: rgba(29, 185, 84, 0.15);
    color: #1db954;
    border: 1px solid rgba(29, 185, 84, 0.4);
}

.badge-ended {
    background: rgba(176, 176, 176, 0.12);
    color: #b0b0b0;
    border: 1px solid rgba(176, 176, 176, 0.35);
}


/* =========================
   LIVE BADGE – PULSE
========================= */

.badge-live {
    background: linear-gradient(135deg, #ff2fd2, #ff453a);
    color: #050510;
    border: none;
    font-weight: 800;
    letter-spacing: 0.08em;

    box-shadow:
        0 0 10px rgba(255, 69, 58, 0.6),
        0 0 18px rgba(255, 47, 210, 0.45);

    animation: livePulse 1.4s ease-in-out infinite;
}

@keyframes livePulse {
    0% {
        transform: scale(1);
        box-shadow:
            0 0 8px rgba(255, 69, 58, 0.45),
            0 0 14px rgba(255, 47, 210, 0.35);
    }
    50% {
        transform: scale(1.08);
        box-shadow:
            0 0 16px rgba(255, 69, 58, 0.9),
            0 0 28px rgba(255, 47, 210, 0.75);
    }
    100% {
        transform: scale(1);
        box-shadow:
            0 0 8px rgba(255, 69, 58, 0.45),
            0 0 14px rgba(255, 47, 210, 0.35);
    }
}


/* Stripe identity polish */
.stripe-banner {
    border-color: rgba(99, 91, 255, 0.4);
}

.stripe-banner.connected {
    box-shadow: 0 0 0 1px rgba(99, 91, 255, 0.15) inset;
}

.stripe-banner .spotify-logo {
    color: #635bff;
}


.badge-requests {
    background: rgba(255, 47, 210, 0.18);
    color: #ff2fd2;
    border: 1px solid rgba(255, 47, 210, 0.55);
    font-size: 12px;
    font-weight: 700;
    padding: 5px 12px;
    border-radius: 999px;
    letter-spacing: 0.04em;
    line-height: 1; 
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
    font-weight: 700;
    padding: 5px 12px;
    border-radius: 999px;
    letter-spacing: 0.04em;
    line-height: 1; 
}

.badge-votes.zero {
    background: rgba(150, 150, 150, 0.15);
    border-color: rgba(150, 150, 150, 0.35);
    color: #888;
}


/* Optional: align nicely with title */
.event-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}



/* BOOST TILE */
.card-boosts h2 {
    color: #6ae3ff;
}

.card-boosts:hover {
    border-color: #6ae3ff;
}

.card-boosts {
    box-shadow: 0 0 0 1px rgba(106,227,255,0.15) inset;
}




</style>

<!-- DASHBOARD HEADER -->
<div class="dashboard-header">
    <h1>Welcome, <?php echo e($user['dj_name'] ?: $user['name']); ?></h1>
    <p>Your DJ control panel — manage events, requests, and more.</p>
</div>

<?php if ($spotifyAccessEnabled): ?>

    <!-- SPOTIFY STATUS BANNER -->
    <div class="spotify-banner <?php echo $isSpotifyConnected ? 'connected' : 'disconnected'; ?>">
    
        <div class="spotify-left">
            <!-- Spotify SVG -->
            <svg class="spotify-logo" viewBox="0 0 168 168" xmlns="http://www.w3.org/2000/svg">
                <path fill="currentColor" d="M84 0C37.7 0 0 37.7 0 84s37.7 84 84 84 84-37.7 84-84S130.3 0 84 0zm38.3 121.2c-1.6 2.6-5 3.4-7.6 1.8-20.8-12.7-47-15.6-77.9-8.6-3 .7-6-1.1-6.7-4.1-.7-3 1.1-6 4.1-6.7 33.8-7.7 63-4.4 86.3 10.2 2.6 1.6 3.4 5 1.8 7.4zm10.9-24.2c-2 3.3-6.4 4.3-9.7 2.3-23.8-14.6-60-18.9-87.9-10.4-3.7 1.1-7.6-1-8.7-4.7-1.1-3.7 1-7.6 4.7-8.7 31.9-9.7 71.7-5 98.9 11.7 3.3 2 4.3 6.4 2.3 9.8zm1-25.2C106.8 55.9 60.6 54.7 30.9 63c-4.3 1.3-8.8-1.2-10.1-5.5-1.3-4.3 1.2-8.8 5.5-10.1 33.9-10.2 84.3-8.5 118.8 12.2 3.9 2.3 5.2 7.4 2.9 11.2-2.3 3.9-7.4 5.2-11.2 2.9z"/>
            </svg>
    
            <div class="spotify-text">
                <?php if ($isSpotifyConnected): ?>
                    <h3>Spotify Connected</h3>
                    <p>Your account is linked and ready for live requests.</p>
                <?php else: ?>
                    <h3>Connect Spotify to unlock requests</h3>
                    <p>Enable song search, previews, and smarter request handling.</p>
                <?php endif; ?>
            </div>
        </div>
    
        <div class="spotify-right">
            <?php if ($isSpotifyConnected): ?>
                <span class="spotify-pill">Ready</span>
                <a href="/dj/connect_spotify.php" class="spotify-link">Reconnect</a>
            <?php else: ?>
                <a href="/dj/connect_spotify.php" class="spotify-btn">
                    Connect Spotify
                </a>
            <?php endif; ?>
        </div>
    
    </div>
    
<?php endif; ?>

<!-- STRIPE STATUS BANNER -->
<div class="spotify-banner stripe-banner <?php echo $stripeOnboarded ? 'connected' : 'disconnected'; ?>">

    <div class="spotify-left">
        <!-- Stripe icon -->
        <svg class="spotify-logo" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm1.26 17.58c-2.43 0-4.68-.6-6.54-1.56l.6-2.22c1.8.9 3.78 1.44 5.94 1.44 1.98 0 3.06-.78 3.06-1.98 0-1.14-.84-1.74-2.88-2.28-3-.78-4.92-1.98-4.92-4.32 0-2.52 2.1-4.38 5.46-4.38 2.22 0 3.9.48 5.22 1.08l-.66 2.16c-.96-.48-2.52-1.02-4.62-1.02-1.86 0-2.7.84-2.7 1.74 0 1.14.96 1.62 3.12 2.22 3.12.84 4.68 2.1 4.68 4.38 0 2.46-1.86 4.56-5.76 4.56z"/>
        </svg>

        <div class="spotify-text">
            <?php if ($stripeOnboarded): ?>
                <h3>Tipping enabled</h3>
                <p>Patrons can tip you securely during live events.</p>
            <?php else: ?>
                <h3>Enable tipping with Stripe</h3>
                <p>Let patrons support you with fast, secure tips.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="spotify-right">
        <?php if ($stripeOnboarded): ?>
            <span class="spotify-pill">Ready</span>
        <?php else: ?>
            <a href="/dj/stripe/start_onboarding.php" class="spotify-btn">
                Enable tipping
            </a>
        <?php endif; ?>
    </div>

</div>



<!-- ACCOUNT-LEVEL METRICS -->
<div class="cards">
    
    <?php
$tip = $tipLifetime[0] ?? null;
$currency = $tip['currency'] ?? 'AUD';

$lifetimeAmount = (float)($tip['lifetime_amount'] ?? 0);
$monthAmount    = (float)($monthlyByCurrency[$currency]['month_amount'] ?? 0);
?>

    <!-- LIFETIME EVENTS -->
    <div class="card-box">
        <h2><?php echo number_format($lifetimeEvents); ?></h2>
        <p>Lifetime Events</p>
        <small style="color:#9aa;">Across all time</small>
    </div>
    
    
        <!-- LIFETIME REQUESTS -->
        <div class="card-box">
            <h2><?php echo number_format($lifetimeRequests); ?></h2>
            <p>Lifetime Requests</p>
    
            <?php if ($thisMonthRequests > 0): ?>
                <small style="color:#1db954;">
                    +<?php echo number_format($thisMonthRequests); ?> this month
                </small>
            <?php else: ?>
                <small style="color:#9aa;">Across all events</small>
            <?php endif; ?>
        </div>
        
        
        
    <!-- LIFETIME VOTES -->
    <div class="card-box">
        <h2><?php echo number_format($lifetimeVotes); ?></h2>
        <p>Lifetime Votes</p>
    
        <?php if ($thisMonthVotes > 0): ?>
            <small style="color:#1db954;">
                +<?php echo number_format($thisMonthVotes); ?> this month
            </small>
        <?php endif; ?>
    
        <?php if ($lifetimeRequests > 0): ?>
            <small style="color:#777;">
                <?php echo $voteEngagementRate; ?>% of requests get votes
            </small>
        <?php endif; ?>
    </div>
        
    
    <!-- LIFETIME TIPS -->
    <div class="card-box">
    
    <?php if (!$stripeOnboarded): ?>
    
        <h2>💸</h2>
        <p>Enable Tips</p>
    
        <a href="/dj/stripe/start_onboarding.php"
           class="create-btn"
           style="margin-top:12px;">
            Enable tipping
        </a>
    
        <small style="color:#9aa;">
            Connect Stripe to receive tips
        </small>
    
    <?php elseif ($lifetimeAmount <= 0): ?>
    
        <h2><?php echo e($currency); ?> 0.00</h2>
        <p>💸 Lifetime Tips</p>
    
        <small style="color:#9aa;">
            No tips received yet
        </small>
    
    <?php else: ?>
    
        <h2>
            <?php echo e($currency); ?>
            <?php echo number_format($lifetimeAmount, 2); ?>
        </h2>
    
        <p>💸 Lifetime Tips</p>
    
        <?php if ($monthAmount > 0): ?>
            <small style="color:#1db954;">
                +<?php echo number_format($monthAmount, 2); ?> this month
            </small>
        <?php else: ?>
            <small style="color:#9aa;">
                No tips yet this month
            </small>
        <?php endif; ?>
    
    <?php endif; ?>
    
    <?php if ($stripeOnboarded): ?>
        <small style="color:#8e91a3;">
            Gross amount before platform fee
        </small>
    <?php endif; ?>

    </div>
    
    <!-- LIFETIME BOOSTS -->
    <?php
    $boost = $boostLifetime[0] ?? null;
    $boostCurrency = $boost['currency'] ?? 'AUD';
    
    $boostLifetimeAmount = (float)($boost['lifetime_amount'] ?? 0);
    $boostMonthAmount    = (float)($boostMonthlyByCurrency[$boostCurrency]['month_amount'] ?? 0);
    ?>

    <!-- LIFETIME BOOSTS -->
    <div class="card-box card-boosts">
    
    <?php if (!$stripeOnboarded): ?>
    
        <h2>🚀</h2>
        <p>Enable Boosts</p>
    
        <small style="color:#9aa;">
            Connect Stripe to receive boosts
        </small>
    
    <?php elseif ($boostLifetimeAmount <= 0): ?>
    
        <h2><?php echo e($boostCurrency); ?> 0.00</h2>
        <p>🚀 Lifetime Boosts</p>
    
        <small style="color:#9aa;">
            No boosts received yet
        </small>
    
    <?php else: ?>
    
        <h2>
            <?php echo e($boostCurrency); ?>
            <?php echo number_format($boostLifetimeAmount, 2); ?>
        </h2>
    
        <p>🚀 Lifetime Boosts</p>
    
        <?php if ($boostMonthAmount > 0): ?>
            <small style="color:#6ae3ff;">
                +<?php echo number_format($boostMonthAmount, 2); ?> this month
            </small>
        <?php else: ?>
            <small style="color:#9aa;">
                No boosts yet this month
            </small>
        <?php endif; ?>
    
    <?php endif; ?>
    
    <?php if ($stripeOnboarded): ?>
        <small style="color:#8e91a3;">
            Gross amount before platform fee
        </small>
    <?php endif; ?>

    </div>
    
   
  
</div>




<?php if ($nextEvent): ?>
<?php
    $badge = null;
    if (!empty($nextEvent['event_date'])) {
        $badges = resolveEventBadges($nextEvent);
    }
?>

<!-- EVENT CONTEXT -->
<div class="event-context">

    <!-- NEXT EVENT BANNER -->
    <div class="event-banner">

        <div class="event-left">

            <div class="event-heading">Next Event</div>

            <h2 class="event-title-row">

    <?php echo e($nextEvent['title']); ?>

    <!-- Event state -->
    <span class="event-badge <?php echo $badges['primary']['class']; ?>">
        <?php echo $badges['primary']['label']; ?>
    </span>

    <?php if ($badges['today']): ?>
        <span class="event-badge badge-today">TODAY</span>
    <?php endif; ?>

    <!-- Requests badge -->
    <span class="badge-requests <?php echo $requestsForNextEvent === 0 ? 'zero' : ''; ?>">
         <?php echo (int)$requestsForNextEvent; ?> Requests
    </span>

    <!-- Votes badge -->
    <span class="badge-votes <?php echo $votesForNextEvent === 0 ? 'zero' : ''; ?>">
        <?php echo (int)$votesForNextEvent; ?> Votes
    </span>

</h2>

            <div class="event-meta">
                <?php echo e($nextEvent['event_date']); ?>
                •
                <?php echo e($nextEvent['location'] ?: 'Location TBC'); ?>
            </div>

            <div
                class="event-countdown"
                data-event-date="<?php echo e($nextEvent['event_date']); ?>">
                Calculating…
            </div>
        </div>

        <div class="event-right">
            
            
            <a
                href="<?php echo e(url('dj/event_details.php?uuid=' . $nextEvent['uuid'])); ?>"
                class="event-manage-btn">
                Manage
            </a>
        </div>

    </div>

<?php endif; ?>

<!-- CREATE BUTTON -->

<button id="openCreateEvent" class="create-btn">
    Create Event
</button>


<!-- EVENTS LIST -->

<div class="event-list">
    <h2 style="margin-top:0; color:#ff2fd2;">Upcoming Events</h2>

    <?php if (!empty($dashboardEvents)): ?>
        <?php foreach ($dashboardEvents as $event): ?>
            <?php
                $badges = resolveEventBadges($event);
            ?>

            <div class="event-item">
                <h3>
                    <?php echo e($event['title']); ?>
                
                    <span class="event-badge <?php echo $badges['primary']['class']; ?>">
                        <?php echo $badges['primary']['label']; ?>
                    </span>
                
                    <?php if ($badges['today']): ?>
                        <span class="event-badge badge-today">TODAY</span>
                    <?php endif; ?>
                </h3>

                <small>
                    <?php echo e($event['event_date']); ?>
                    • Location: <?php echo e($event['location'] ?: 'N/A'); ?>
                </small>

                <br><br>
                <small>Request URL</small><br>

                <div class="request-link-wrap">
                    <code class="request-url"><?php echo url('request/' . $event['uuid']); ?></code>
                    <button class="copy-btn" type="button"
                        data-copy="<?php echo url('request/' . $event['uuid']); ?>">
                        Copy
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="event-empty">
            No upcoming events — create one to start collecting requests.
        </div>
    <?php endif; ?>
</div>


<script>
(function () {
    const el = document.querySelector('.event-countdown');
    if (!el) return;

    const dateStr = el.dataset.eventDate;
    if (!dateStr) {
        el.textContent = 'Date TBC';
        return;
    }

    const eventDate = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(eventDate)) {
        el.textContent = 'Date TBC';
        return;
    }

    function updateCountdown() {
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const eventDay = new Date(eventDate);
        eventDay.setHours(0, 0, 0, 0);

        const diffDays = Math.round((eventDay - today) / 86400000);

        if (diffDays === 0) {
            el.textContent = 'Today';
        } else if (diffDays === 1) {
            el.textContent = 'Tomorrow';
        } else if (diffDays > 1) {
            el.textContent = `In ${diffDays} days`;
        } else {
            el.textContent = '';
        }
    }

    updateCountdown();
})();
</script>

<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.copy-btn');
    if (!btn) return;

    const text = btn.dataset.copy;
    if (!text) return;

    // Clipboard API (modern)
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCopied(btn);
        });
    } else {
        // Fallback
        const input = document.createElement('input');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showCopied(btn);
    }
});

function showCopied(btn) {
    const original = btn.textContent;
    btn.textContent = 'Copied ✓';
    btn.classList.add('copied');

    setTimeout(() => {
        btn.textContent = original;
        btn.classList.remove('copied');
    }, 1500);
}
</script>




<!-- CREATE EVENT MODAL -->
<div id="createEventModal" class="modal hidden">
    <div class="modal-overlay"></div>

    <div class="modal-panel">
        <button class="modal-x" aria-label="Close">&times;</button>
        <h2>Create New Event</h2>

        <div class="form-card fancy-form">
            <?php
                $redirectTo = '/dj/events.php';
                include __DIR__ . '/partials/event_create_form.php';
            ?>
        </div>
    </div>
</div>


<script>

/* Modal logic */
const modal = document.getElementById('createEventModal');
const openBtn = document.getElementById('openCreateEvent');
const closeBtn = modal.querySelector('.modal-x');
const overlay = modal.querySelector('.modal-overlay');

openBtn.onclick = () => modal.classList.remove('hidden');
overlay.onclick = () => modal.classList.add('hidden');
closeBtn.onclick = () => modal.classList.add('hidden');

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        modal.classList.add('hidden');
    }
});  
    
document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.getElementById('event_date');

    if (!dateInput) return;

    dateInput.addEventListener('click', () => {
        if (dateInput.showPicker) {
            dateInput.showPicker();
        } else {
            dateInput.focus();
        }
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.getElementById('event_date');
    if (!dateInput || dateInput.value) return;

    const today = new Date();
    const localDate = today.toISOString().split('T')[0];
    dateInput.value = localDate;
});

</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('createEventModal');
    if (!modal) return;

    const form = modal.querySelector('form');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(form);

        try {
            await fetch('/dj/events.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const redirectTo = formData.get('redirect_to') || '/dj/events.php';
            window.location.href = redirectTo;

        } catch (err) {
            alert('Failed to create event. Please try again.');
            console.error(err);
        }
    });
});
</script>



<style>
    
/* =========================================================
   FANCY CREATE EVENT FORM
========================================================= */

.fancy-form form {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

.fancy-form .input-wrap,
.fancy-form .input-group {
    position: relative;
}

.fancy-form label {
    display: block;
    margin-bottom: 8px;
    font-size: 16px;
    font-weight: 600;
    color: #ff2fd2;
    letter-spacing: 0.2px;
}

.fancy-form .icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 18px;
    opacity: 0.85;
    pointer-events: none;
}

.fancy-form input {
    width: 100%;
    padding: 14px 16px 14px 48px;
    background: #0f0f12;
    border: 1px solid #292933;
    border-radius: 8px;
    color: #fff;
    font-size: 16px;
}

.fancy-form input::placeholder {
    color: #777;
}

.fancy-form input:focus {
    border-color: #ff2fd2;
    box-shadow: 0 0 0 1px rgba(255,47,210,0.6);
    outline: none;
}

.fancy-form .input-wrap:focus-within .icon {
    opacity: 1;
    filter: drop-shadow(0 0 4px rgba(255,47,210,0.6));
}

/* Date input */
.date-input {
    color-scheme: dark;
}

.date-input::-webkit-calendar-picker-indicator {
    filter: invert(1);
}    
    
    
    
 .modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
}

.modal.hidden {
    display: none;
}

.modal-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.75);
}

.modal-panel {
    position: relative;
    max-width: 640px;
    margin: 10vh auto;
    background: linear-gradient(180deg, #1c1c22, #141419);
    padding: 36px 40px;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.6);
}

.modal-panel h2 {
    margin: 0 0 30px;
    font-size: 28px;
    color: #ff2fd2;
}

.modal-x {
    position: absolute;
    top: 18px;
    right: 18px;
    background: none;
    border: none;
    color: #aaa;
    font-size: 28px;
    cursor: pointer;
}

.modal-x:hover {
    color: #ff2fd2;
}


.modal-panel .create-btn {
    margin-top: 8px;
    width: 100%;
}

.modal-close {
    margin-top: 10px;
    background: transparent;
    border: none;
    color: #aaa;
    font-size: 14px;
    cursor: pointer;
}

.modal-close:hover {
    color: #fff;
}


/* Modal submit button */
.btn-submit {
    background: #ff2fd2;
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 16px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    width: 100%;
    margin-top: 14px;
    transition: background 0.2s ease, transform 0.05s ease;
}

.btn-submit:hover {
    background: #ff4ae0;
}

.btn-submit:active {
    transform: translateY(1px);
}



/* ================================
   MODAL: Create Event submit button
   (override form partial styles)
================================ */

.modal-panel form button[type="submit"],
.modal-panel form input[type="submit"] {
    display: block;
    width: 100%;
    margin-top: 16px;

    background: #ff2fd2;
    color: #fff;

    border: none;
    border-radius: 10px;

    padding: 16px;
    font-size: 16px;
    font-weight: 700;

    cursor: pointer;
    text-align: center;

    transition: background 0.2s ease, transform 0.05s ease;
}

.modal-panel form button[type="submit"]:hover,
.modal-panel form input[type="submit"]:hover {
    background: #ff4ae0;
}

.modal-panel form button[type="submit"]:active,
.modal-panel form input[type="submit"]:active {
    transform: translateY(1px);
}



/* iPad / iOS fix */
input[type="date"] {
    width: 100%;
    max-width: 100%;
    appearance: none;
    -webkit-appearance: none;
}   
    
</style>


<?php require __DIR__ . '/footer.php'; ?>
