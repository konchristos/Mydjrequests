<?php
require_once __DIR__ . '/../app/bootstrap.php';
// Ensure PHP uses local / configured timezone
date_default_timezone_set(date_default_timezone_get());
require_dj_login();
$db = db();

$errors = '';
$success = '';

$eventCtrl = new EventController();
$djId = (int)$_SESSION['dj_id'];


$today = new DateTimeImmutable('today');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf_token()) {
        $errors = 'Invalid CSRF token.';
    } else {
        $res = $eventCtrl->createForUser($djId, $_POST);

        if ($res['success']) {
            $success = 'Event created successfully.';
        } else {
            $errors = $res['message'] ?? 'Failed to create event.';
        }
    }
}

$eventsRes = $eventCtrl->listForUser($djId);
$events = $eventsRes['events'] ?? [];


$badgeLabel = 'Upcoming';
$badgeClass = 'badge-upcoming';

$today = new DateTime('today');

if (!empty($event['event_date'])) {
    try {
        $eventDay = new DateTime($event['event_date']);
        $eventDay->setTime(0, 0, 0);

        if ($eventDay < $today) {
            $badgeLabel = 'Ended';
            $badgeClass = 'badge-ended';
        } elseif ($eventDay == $today) {
            $badgeLabel = 'Today';
            $badgeClass = 'badge-today';
        } else {
            $badgeLabel = 'Upcoming';
            $badgeClass = 'badge-upcoming';
        }
    } catch (Exception $e) {
        // Keep defaults
    }
}


$upcomingEvents = [];
$pastEvents = [];

// ------------------------------------
// Load per-event request stats
// ------------------------------------
$eventStats = [];

$eventIds = array_column($events, 'id');

if (!empty($eventIds)) {
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $stmt = $db->prepare("
        SELECT event_id, total_requests
        FROM event_request_stats
        WHERE event_id IN ($placeholders)
    ");
    $stmt->execute($eventIds);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventStats[(int)$row['event_id']] = (int)$row['total_requests'];
    }
}


foreach ($events as $event) {

    if (empty($event['event_date'])) {
        $upcomingEvents[] = $event;
        continue;
    }

    $eventDay = new DateTimeImmutable($event['event_date']);

    if ($eventDay < $today) {
        $pastEvents[] = $event;
    } else {
        $upcomingEvents[] = $event;
    }
}

// Sort both groups by date DESC
$sortByDateDesc = function ($a, $b) {
    return strcmp($b['event_date'] ?? '', $a['event_date'] ?? '');
};

usort($upcomingEvents, $sortByDateDesc);
usort($pastEvents, $sortByDateDesc);



$pageTitle = "Manage Events";
require __DIR__ . '/layout.php';
?>

<style>
/* EVENTS PAGE STYLES */

h1, h2 {
    color: #ff2fd2;
    margin-bottom: 25px;
}

/* FORM CARD */
.form-card {
    background: #1a1a1f;
    border: 1px solid #292933;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 40px;
}

.form-card label {
    font-size: 14px;
    color: #ccc;
}

.form-card input {
    margin-top: 5px;
    margin-bottom: 18px;
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

/* EVENT LIST */
.event-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 25px;
}

.event-card {
    background: #1a1a1f;
    border: 1px solid #292933;
    padding: 20px;
    border-radius: 12px;
    transition: 0.2s;
}

.event-card:hover {
    border-color: #ff2fd2;
}

.event-title {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;

    /* 👇 critical */
    flex: 1;
    min-width: 0;
}

/*.event-meta {
    margin-top: 12px;
    font-size: 14px;
    color: #b0b0b0;
}*/

.event-meta strong {
    color: #fff;
}

.request-link {
    margin-top: 12px;
    font-size: 13px;
    color: #ff2fd2;
    display: block;
}

.manage-btn {
    margin-top: 18px;
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


/* Card header row */
.event-card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

/* Status badges */
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


.badge-ended {
    background: rgba(176, 176, 176, 0.15);
    color: #b0b0b0;
}

/* Request URL + copy */
.request-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 14px;
}

.request-url {
    flex: 1;
    font-size: 12px;
    background: #0f0f12;
    padding: 6px 8px;
    border-radius: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.copy-btn {
    background: #292933;
    border: none;
    color: #fff;
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
}

.copy-btn:hover {
    background: #383844;
}

.request-link-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
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

.manage-btn-top {
    white-space: nowrap;
    flex-shrink: 0;
}

</style>

<!-- PAGE TITLE -->
<h1>Manage Events</h1>

<!-- SUCCESS / ERROR MESSAGES -->
<?php if ($errors): ?>
    <p style="color:#ff4ae0;"><?php echo e($errors); ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p style="color:#4aff9c;"><?php echo e($success); ?></p>
<?php endif; ?>

<!-- CREATE EVENT FORM -->
<div class="form-card">
   <h2>Create New Event</h2>

<div class="card fancy-form">

    <form method="post">
        <?php echo csrf_field(); ?>

        <!-- Event Title -->
        <div class="input-group">
            <span class="icon">
                <!-- Music Note Icon -->
                🎵
            </span>
            <input type="text" name="title" id="title" required>
            <label for="title">Event Title</label>
        </div>

        <!-- Location -->
        <div class="input-group">
            <span class="icon">
                <!-- Pin Icon -->
                📍
            </span>
            <input type="text" name="location" id="location">
            <label for="location">Location</label>
        </div>

        <!-- Event Date -->
<div class="input-group">
    <span class="icon">📅</span>
    <input type="date" name="event_date" id="event_date" class="date-input" required>
    <label for="event_date">Event Date</label>
</div>

        <button type="submit" class="btn-primary">Create Event</button>
    </form>

</div>

<style>
/* Fancy Floating Form Styling */
.fancy-form form {
    display: flex;
    flex-direction: column;
    gap: 28px;
}

/* GROUP */
.fancy-form .input-group {
    position: relative;
    display: flex;
    align-items: center;
}

.fancy-form .input-group .icon {
    position: absolute;
    left: 12px;
    font-size: 20px;
    opacity: 0.8;
}

.fancy-form input {
    width: 100%;
    padding: 14px 14px 14px 45px;
    background: #0f0f12;
    border: 1px solid #292933;
    border-radius: 6px;
    color: #fff;
    font-size: 16px;
    transition: border 0.2s, box-shadow 0.2s;
}

/* FOCUS EFFECT */
.fancy-form input:focus {
    border-color: #ff2fd2;
    box-shadow: 0 0 6px rgba(255, 47, 210, 0.6);
    outline: none;
}

/* FLOATING LABEL */
.fancy-form label {
    position: absolute;
    left: 45px;
    top: 50%;
    transform: translateY(-50%);
    color: #aaa;
    font-size: 14px;
    pointer-events: none;
    transition: 0.2s ease;
}

/* When typing / focused / has value */
.fancy-form input:focus + label,
.fancy-form input:not(:placeholder-shown) + label {
    top: -8px;
    left: 40px;
    font-size: 12px;
    color: #ff2fd2;
    background: #1a1a1f;
    padding: 0 4px;
}

/* Button */
.fancy-form .btn-primary {
    background: #ff2fd2;
    padding: 12px 25px;
    border-radius: 6px;
    border: none;
    color: #fff;
    font-size: 17px;
    cursor: pointer;
}

.fancy-form .btn-primary:hover {
    background: #ff4ae0;
}


/* Fix date input on dark theme */
.date-input {
    color-scheme: dark; /* Native browsers show light icons */
}

/* Remove default Chrome calendar icon (optional) */
.date-input::-webkit-calendar-picker-indicator {
    filter: invert(1); /* Makes icon visible on dark backgrounds */
    opacity: 1;        /* Ensures icon is visible */
}

/* Floating label adjustments still work */


.search-wrap {
    margin-bottom: 25px;
}

.search-input {
    width: 100%;
    padding: 14px 16px;
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

.event-grid-full {
    grid-template-columns: 1fr;
}

.badge-ended {
    background: rgba(176, 176, 176, 0.12);
    color: #b0b0b0;
    border: 1px solid rgba(176, 176, 176, 0.35);
}

.badge-today {
    background: rgba(29, 185, 84, 0.15);
    color: #1db954;
    border: 1px solid rgba(29, 185, 84, 0.4);
}




.manage-btn-top {
    padding: 8px 14px;
    font-size: 13px;
    border-radius: 999px;
    white-space: nowrap;
}



.request-count-mini {
    font-size: 12px;
    font-weight: 600;
    color: #ff2fd2;
    white-space: nowrap;
}

.request-count-mini span {
    font-size: 11px;
    color: #999;
    margin-left: 3px;
}

.request-count-mini.zero {
    color: #777;
}

.event-card {
    position: relative;   /* anchor for absolute children */
    padding-top: 16px;    /* tighten top */
}

.event-card-header {
    display: flex;
    align-items: flex-start;   /* 👈 key fix */
    justify-content: space-between;
    gap: 16px;
}

.event-actions {
    position: absolute;
    top: 0px;
    right: 20px;

    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    line-height: 1.1;
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

</style>
</div>

<div class="search-wrap">
    <input
        type="text"
        id="eventSearch"
        placeholder="Search events by name or location…"
        class="search-input"
    >
</div>

<!-- LIST OF EVENTS -->
<h2>Upcoming Events</h2>

<?php if (!empty($upcomingEvents)): ?>
    <div class="event-grid event-grid-full" id="upcomingEvents">
        <?php foreach ($upcomingEvents as $event): ?>
        
        <?php
            $cardToday   = $today;
            $requestCount = $eventStats[(int)$event['id']] ?? 0;
            include __DIR__ . '/partials/event_card.php';
        ?>
        
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <p style="color:#aaa;">No upcoming events.</p>
<?php endif; ?>

<h2 style="margin-top:50px;">Past Events</h2>

<?php if (!empty($pastEvents)): ?>
    <div class="event-grid event-grid-full" id="pastEvents">
        <?php foreach ($pastEvents as $event): ?>
        
         <?php
        $requestCount = $eventStats[(int)$event['id']] ?? 0;
    ?>
    
            <?php include __DIR__ . '/partials/event_card.php'; ?>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <p style="color:#aaa;">No past events.</p>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>



<script>
document.addEventListener("DOMContentLoaded", function() {
    const dateInput = document.getElementById("event_date");

    // When clicking anywhere inside the input → open date picker
    dateInput.addEventListener("click", function() {
        this.showPicker?.(); // Chrome/Edge
        this.focus();        // Safari fallback
    });
});
</script>


<script>
document.getElementById('eventSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();

    document.querySelectorAll('.event-card').forEach(card => {
        const haystack = (card.dataset.search || '').toLowerCase();
        card.style.display = haystack.includes(q) ? '' : 'none';
    });
});
</script>


<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.copy-btn');
    if (!btn) return;

    const text = btn.dataset.copy;
    if (!text) return;

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCopied(btn);
        });
    } else {
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
