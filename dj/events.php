<?php
require_once __DIR__ . '/../app/bootstrap.php';
date_default_timezone_set(date_default_timezone_get());
require_dj_login();

$db = db();
$eventCtrl = new EventController();
$djId = (int)$_SESSION['dj_id'];

$errors = '';
$today  = new DateTimeImmutable('today');

/* ------------------------
   Handle Create Event POST
------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $errors = 'Invalid CSRF token.';
    } else {
        $res = $eventCtrl->createForUser($djId, $_POST);
        if ($res['success']) {
            $redirect = $_POST['redirect_to'] ?? '/dj/events.php';
            header('Location: ' . $redirect);
            exit;
        }
        $errors = $res['message'] ?? 'Failed to create event.';
    }
}

/* ------------------------
   Load events
------------------------- */
$events = $eventCtrl->listForUser($djId)['events'] ?? [];

$eventStats = [];
$eventIds = array_column($events, 'id');

if ($eventIds) {
    $in = implode(',', array_fill(0, count($eventIds), '?'));
    $stmt = $db->prepare("
        SELECT event_id, total_requests
        FROM event_request_stats
        WHERE event_id IN ($in)
    ");
    $stmt->execute($eventIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventStats[(int)$row['event_id']] = (int)$row['total_requests'];
    }
}


$eventVoteStats = [];

if ($eventIds) {
    $in = implode(',', array_fill(0, count($eventIds), '?'));
    $stmt = $db->prepare("
        SELECT event_id, total_votes
        FROM event_vote_stats
        WHERE event_id IN ($in)
    ");
    $stmt->execute($eventIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventVoteStats[(int)$row['event_id']] = (int)$row['total_votes'];
    }
}


$upcomingEvents = [];
$pastEvents = [];

foreach ($events as $event) {
    if (empty($event['event_date'])) {
        $upcomingEvents[] = $event;
        continue;
    }

    $eventDay = new DateTimeImmutable($event['event_date']);
    ($eventDay < $today) ? $pastEvents[] = $event : $upcomingEvents[] = $event;
}

usort($upcomingEvents, fn($a,$b) => strcmp($b['event_date'],$a['event_date']));
usort($pastEvents, fn($a,$b) => strcmp($b['event_date'],$a['event_date']));

$pageTitle = 'Manage Events';
require __DIR__ . '/layout.php';
?>



<style>
/* =========================================================
   EVENTS PAGE – BASE
========================================================= */

h1, h2 {
    color: #ff2fd2;
    margin-bottom: 25px;
}

button {
    background: #ff2fd2;
    color: #fff;
    padding: 12px 16px;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    cursor: pointer;
}

button:hover {
    background: #ff4ae0;
}


.events-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 20px;
}

.events-header h1 {
    margin: 0;
}

/* =========================================================
   EVENT GRID / CARDS
========================================================= */

.event-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 25px;
}

.event-grid-full {
    grid-template-columns: 1fr;
}

.event-card {
    position: relative;
    background: #1a1a1f;
    border: 1px solid #292933;
    padding: 20px;
    padding-top: 16px;
    border-radius: 12px;
    transition: 0.2s;
}

.event-card:hover {
    border-color: #ff2fd2;
}

.event-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.event-title {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    flex: 1;
    min-width: 0;
}

.event-meta strong {
    color: #fff;
}

/* =========================================================
   BADGES
========================================================= */

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

.badge-requests {
    background: rgba(255, 47, 210, 0.18);
    color: #ff2fd2;
    border: 1px solid rgba(255, 47, 210, 0.45);
    font-size: 11px;
    font-weight: 700;
    padding: 4px 9px;
    border-radius: 999px;
}

.badge-requests.zero {
    background: rgba(150, 150, 150, 0.15);
    border-color: rgba(150, 150, 150, 0.35);
    color: #888;
}


.badge-votes {
    background: rgba(106, 227, 255, 0.18);   /* electric blue glow */
    color: #6ae3ff;
    border: 1px solid rgba(106, 227, 255, 0.55);
    font-size: 11px;
    font-weight: 700;
    padding: 4px 9px;
    border-radius: 999px;
    letter-spacing: 0.04em;
}

.badge-votes.zero {
    background: rgba(150, 150, 150, 0.15);
    border-color: rgba(150, 150, 150, 0.35);
    color: #888;
}


/* =========================================================
   REQUEST LINK + COPY
========================================================= */

.request-link {
    margin-top: 12px;
    font-size: 13px;
    color: #ff2fd2;
    display: block;
}

.request-link-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.request-url {
    flex: 1;
    font-size: 12px;
    background: #0d0d0f;
    border: 1px solid #292933;
    padding: 6px 10px;
    border-radius: 6px;
    color: #d0d0d0;
    overflow: hidden;
    text-overflow: ellipsis;
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

/* =========================================================
   MANAGE BUTTON
========================================================= */

.manage-btn {
    margin-top: 0px;
    display: inline-block;
    padding: 10px 14px;
    background: #292933;
    border-radius: 6px;
    color: #fff;
    text-decoration: none;
    transition: 0.2s;
}

.manage-btn:hover {
    background: #383844;
}

.manage-btn-top {
    padding: 8px 14px;
    font-size: 13px;
    border-radius: 999px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* =========================================================
   SEARCH
========================================================= */

.search-wrap {
    margin-bottom: 25px;
    
}

.search-input {
    width: 100%;
    padding: 14px 16px 14px 20px;  /* top | right | bottom | LEFT */
    background: #0f0f12;
    border: 1px solid #292933;
    border-radius: 8px;
    color: #fff;
    font-size: 16px;
}

.search-input:focus {
    border-color: #ff2fd2;
    box-shadow: 0 0 6px rgba(255, 47, 210, 0.4);
    outline: none;
}


.events-content {
    max-width: 900px;
    margin: 0 auto;
    padding-left: 0;
    padding-right: 0;
}


/*
.events-content {
    outline: 2px solid lime;
}
body > * {
    outline: 1px dashed red;
}
*/


/* =========================================================
   FANCY CREATE EVENT FORM
========================================================= */

.fancy-form form {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

.fancy-form .input-wrap {
    position: relative;
}


/* input group */
.fancy-form .input-group {
    position: relative;
}

/* icon */
.fancy-form .icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 18px;
    opacity: 0.85;
    pointer-events: none;
}

/* input */
.fancy-form input {
    width: 100%;
    padding: 14px 16px 14px 48px;
    background: #0f0f12;
    border: 1px solid #292933;
    border-radius: 8px;
    color: #fff;
    font-size: 16px;
}

/* focus */
.fancy-form input:focus {
    border-color: #ff2fd2;
    box-shadow: 0 0 0 1px rgba(255,47,210,0.6);
    outline: none;
}

.fancy-form label {
    display: block;
    margin-bottom: 8px;
    font-size: 16px;
    font-weight: 600;
    color: #ff2fd2;
    letter-spacing: 0.2px;
}

/* float */
.fancy-form input:focus + label,
.fancy-form input:not(:placeholder-shown) + label {
    top: -7px;
    font-size: 11px;
    color: #ff2fd2;
    background: #1a1a1f;
    padding: 0 6px;
}

.fancy-form input::placeholder {
    color: #777;
    opacity: 1;
}


.fancy-form input:focus + .icon,
.fancy-form .input-wrap:focus-within .icon {
    opacity: 1;
    filter: drop-shadow(0 0 4px rgba(255,47,210,0.6));
}


.modal-panel .btn-primary {
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

/* =========================================================
   DATE INPUT (DARK MODE FIX)
========================================================= */

.date-input {
    color-scheme: dark;
}

.date-input::-webkit-calendar-picker-indicator {
    filter: invert(1);
    opacity: 1;
}



/* =========================
   LIVE BADGE � PULSE
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


</style>


<div class="events-header">
    <h1>Manage Events</h1>

    <button id="openCreateEvent" class="btn-primary">
        Create Event
    </button>
</div>

<?php if ($errors): ?>
    <p style="color:#ff4ae0"><?= e($errors) ?></p>
<?php endif; ?>

<div class="events-content">

    <div class="search-wrap">
        <input id="eventSearch" class="search-input"
               placeholder="Search events by name or location…">
    </div>

    <h2>Upcoming Events</h2>
    <div class="event-grid event-grid-full">
        <?php foreach ($upcomingEvents as $event): ?>
            <?php
                $cardToday    = $today;
                $requestCount = $eventStats[(int)$event['id']] ?? 0;
                $voteCount    = $eventVoteStats[(int)$event['id']] ?? 0;
                include __DIR__ . '/partials/event_card.php';
                ?>
        <?php endforeach; ?>
    </div>

    <h2 style="margin-top:40px;">Past Events</h2>
    <div class="event-grid event-grid-full">
        <?php foreach ($pastEvents as $event): ?>
            <?php
                $requestCount = $eventStats[(int)$event['id']] ?? 0;
                $voteCount    = $eventVoteStats[(int)$event['id']] ?? 0;
                include __DIR__ . '/partials/event_card.php';
            ?>
        <?php endforeach; ?>
    </div>

</div> <!-- ✅ END .events-content -->

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

<?php require __DIR__ . '/footer.php'; ?>

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

/* Search */
document.getElementById('eventSearch').addEventListener('input', e => {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('.event-card').forEach(card => {
        card.style.display =
            (card.dataset.search || '').toLowerCase().includes(q)
                ? '' : 'none';
    });
});

/* Copy link */
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.copy-btn');
    if (!btn) return;

    const text = btn.dataset.copy;
    if (!text) return;

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCopied(btn);
        }).catch(() => fallbackCopy(text, btn));
    } else {
        fallbackCopy(text, btn);
    }
});

function fallbackCopy(text, btn) {
    const input = document.createElement('input');
    input.value = text;
    document.body.appendChild(input);
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);
    showCopied(btn);
}

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


<script>
document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.getElementById('event_date');

    if (!dateInput) return;

    dateInput.addEventListener('click', () => {
        // Chrome / Edge
        if (dateInput.showPicker) {
            dateInput.showPicker();
        } else {
            // Safari fallback
            dateInput.focus();
        }
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.getElementById('event_date');
    if (!dateInput || dateInput.value) return;

    // Get today's date in the user's local timezone
    const today = new Date();
    const localDate = today.toISOString().split('T')[0];

    dateInput.value = localDate;
});
</script>


<style>
/* MODAL */

*, *::before, *::after {
    box-sizing: border-box;
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
    max-width: 640px;          /* 👈 wider */
    margin: 10vh auto;
    background: linear-gradient(180deg, #1c1c22, #141419);
    padding: 36px 40px;        /* 👈 breathing room */
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.6);
}

.modal-panel h2 {
    margin: 0 0 30px;
    font-size: 28px;
    color: #ff2fd2;
    text-align: left;
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
    line-height: 1;
}

.modal-x:hover {
    color: #ff2fd2;
}


/* Fix iOS/iPad date input overflow */
input[type="date"] {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    appearance: none;
    -webkit-appearance: none;
}




</style>