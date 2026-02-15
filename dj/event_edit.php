<?php
//dj/event_edit.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$djId = (int)$_SESSION['dj_id'];



$eventModel = new Event();
$event = null;
$eventId = 0;

// Prefer UUID
if (!empty($_GET['uuid'])) {
    $uuid = trim($_GET['uuid']);
    $event = $eventModel->findByUuid($uuid);
    if ($event) {
        $eventId = (int)$event['id'];
    }
}
// Fallback: legacy numeric ID
elseif (!empty($_GET['id'])) {
    $eventId = (int)$_GET['id'];
    $event = $eventModel->findById($eventId);
}

if (!$event) {
    redirect('dj/events.php');
}




// permission check
if (!$event || (int)$event['user_id'] !== $djId) {
    redirect('dj/events.php');
}

$eventCtrl = new EventController();
$errors = '';
$success = '';

// Pre-fill DATE value properly for <input type="date">
$eventDateFormatted = '';
if (!empty($event['event_date'])) {
    $ts = strtotime($event['event_date']);
    $eventDateFormatted = date('Y-m-d', $ts);
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {

    if (!verify_csrf_token()) {
        $errors = "Invalid CSRF token.";
    } else {
        $res = $eventCtrl->editForUser($djId, $eventId, $_POST);

        if ($res['success']) {

            // 🔄 Rename Spotify playlist (if it exists)
            require_once APP_ROOT . '/app/lib/spotify_playlist.php';
            renameEventSpotifyPlaylist(db(), $djId, $eventId);

            redirect("dj/event_details.php?uuid=" . $event['uuid'] . "&updated=1");

        } else {
            $errors = $res['message'] ?? 'Update failed.';
        }
    }
}

$pageTitle = "Edit Event";
require __DIR__ . '/layout.php';
?>

<a href="<?php echo e(url('dj/event_details.php?uuid=' . $event['uuid'])); ?>" class="back-btn">
    ← Back to Event
</a>


<div class="card fancy-form">

<h1>Edit Event</h1>



    <?php if ($errors): ?>
        <p style="color:#ff4ae0; font-weight:600;"><?php echo e($errors); ?></p>
    <?php endif; ?>

    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">

        <!-- Event Title -->
        <div class="input-group">
            <span class="icon">🎵</span>
            
            <input type="text" name="title" id="title"
                   value="<?php echo e($event['title']); ?>" required>
            <label for="title">Event Title</label>
        </div>

        <!-- Location -->
        <div class="input-group">
            <span class="icon">📍</span>
            <input type="text" name="location" id="location"
                   value="<?php echo e($event['location']); ?>">
            <label for="location">Location</label>
        </div>

        <!-- Event Date -->
<div class="input-group date-group">
    <input
        type="date"
        name="event_date"
        id="event_date"
        value="<?php echo e($eventDateFormatted); ?>" required>
        <label for="title">Event Date</label>
    
    <span class="icon">📅</span>
</div>

        <button type="submit" class="btn-primary">💾 Save Changes</button>

    </form>
</div>

<!-- DELETE EVENT SECTION -->
<div class="card danger-zone" style="border-color:#552;">
    <h2 style="color:#ff4ae0;">Danger Zone</h2>
    <p>This will permanently delete this event and all associated requests.</p>

    <button id="openDeleteModal" style="
        background:#ff3c3c;
        padding:10px 20px;
        border:none;
        border-radius:6px;
        color:#fff;
        cursor:pointer;
        font-size:16px;">
        🗑️ Delete Event
    </button>
</div>


<!-- DELETE CONFIRMATION MODAL -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box">
        <h2>Delete Event?</h2>
        <p>This action <strong>cannot</strong> be undone.</p>

        <form id="deleteForm" method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
            <input type="hidden" name="action" value="delete">

            <button type="button" class="cancel" id="cancelModal">Cancel</button>
            <button type="submit" class="confirm">Delete Event</button>
        </form>
    </div>
</div>


<style>

/* Back Button */
.back-btn {
    display: inline-block;
    background: #292933;
    color: #fff;
    padding: 10px 16px;
    border-radius: 6px;
    margin-bottom: 25px;
    font-size: 14px;
    text-decoration: none;
}

.back-btn:hover {
    background: #383844;
}

/* Fancy Floating Form (same as create page) */
.fancy-form form {
    display: flex;
    flex-direction: column;
    gap: 40px;
}

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
}

.fancy-form input[type="date"]::-webkit-calendar-picker-indicator {
    filter: invert(1) brightness(1.8);
    opacity: 1;
}

.fancy-form input:focus {
    border-color: #ff2fd2;
    box-shadow: 0 0 6px rgba(255, 47, 210, 0.6);
    outline: none;
}

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

.fancy-form input:focus + label,
.fancy-form input:not(:placeholder-shown) + label {
    top: -8px;
    left: 0px;
    font-size: 16px; 
    font-weight: 600;
    color: #ff2fd2;
    
    padding: 0 6px 15px;  
    
    


    letter-spacing: 0.4px;
}


/* Restore real Save button */
.fancy-form .btn-primary {
    background: #ff2fd2 !important;
    padding: 12px 25px;
    border-radius: 6px;
    border: none;
    color: #fff;
    font-size: 17px;
    font-weight: 600;
    cursor: pointer;
    width: auto !important;      /* prevent full-width white bar */
    display: inline-block;
}

.fancy-form .btn-primary:hover {
    background: #ff4ae0 !important;
}

/* Modal Overlay */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.75);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 9990;
}

.modal-overlay.show {
    display: flex;
}

/* Modal Box */
.modal-box {
    background: #1a1a1f;
    padding: 30px;
    border-radius: 8px;
    border: 1px solid #292933;
    text-align: center;
    width: 380px;
}

.modal-box h2 {
    color: #ff2fd2;
    margin-bottom: 15px;
}

.modal-box p {
    color: #ccc;
    margin-bottom: 25px;
}

.modal-box button {
    padding: 10px 20px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    margin: 0 8px;
    font-size: 14px;
}

.modal-box .cancel {
    background: #333;
    color: #fff;
}

.modal-box .cancel:hover {
    background: #444;
}

.modal-box .confirm {
    background: #ff2fd2;
    color: #fff;
}

.modal-box .confirm:hover {
    background: #ff4ae0;
}


/* === EDIT EVENT TILE === */
.card {
    background: linear-gradient(180deg, #1a1a1f, #141418);
    border: 1px solid #292933;
    border-radius: 14px;
    padding: 28px 32px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.45);
}

/* Title spacing polish */
.card h1,
.card h2 {
    margin-top: 0;
    margin-bottom: 50px; /* 👈 add breathing room below title */
}




/* Match Edit Event tile width */
.card.danger-zone {
    max-width: 760px;
    margin-top: 35px;
}



/* Keep form centered and readable */
.card.fancy-form {
    max-width: 760px;
}

/* Slight breathing room on mobile */
@media (max-width: 768px) {
    .card {
        padding: 22px 20px;
    }
}



/* Dark mode hint for browser */
input[type="date"] {
    color-scheme: dark;
}

/* Chrome / Edge calendar icon */
input[type="date"]::-webkit-calendar-picker-indicator {
    filter: invert(1) brightness(1.2);
    opacity: 0.9;
}

/* Firefox */
@supports (-moz-appearance: none) {
    input[type="date"] {
        background-color: #0f0f12;
        color: #fff;
    }
}

/* Let clicks reach the input */
.fancy-form .icon,
.fancy-form label {
    pointer-events: none;
}

.date-group {
    position: relative;
}

/* INPUT IS THE FIELD */
.date-group input[type="date"] {
    position: relative;
    z-index: 2;
    width: 100%;
    padding: 14px 48px 14px 45px; /* space for icon */
    cursor: pointer;
}

/* ICON IS VISUAL ONLY */
.date-group .icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 18px;
    opacity: 0.85;
    pointer-events: none; /* 🔑 */
    z-index: 3;
}

/* Dark calendar */
input[type="date"] {
    color-scheme: dark;
}

input[type="date"]::-webkit-calendar-picker-indicator {
    filter: invert(1) brightness(1.2);
    opacity: 0.9;
}


</style>

<script>
document.getElementById('openDeleteModal').onclick = function(e) {
    e.preventDefault();
    document.getElementById('deleteModal').classList.add('show');
};

document.getElementById('cancelModal').onclick = function(e) {
    e.preventDefault();
    document.getElementById('deleteModal').classList.remove('show');
};

document.getElementById('deleteForm').onsubmit = async function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    const res = await fetch('event_delete.php', {
        method: 'POST',
        body: formData
    });

    const json = await res.json();

    if (json.success) {
        window.location.href = 'events.php';
    } else {
        alert(json.message);
    }
};
</script>

<script>

document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.getElementById('event_date');
    if (!dateInput) return;

    dateInput.addEventListener('click', () => {
        // Only valid when triggered by the user's click on the input
        if (dateInput.showPicker) {
            try { dateInput.showPicker(); } catch (e) { /* ignore */ }
        } else {
            // Safari fallback
            dateInput.focus();
        }
    });
});
</script>
</script>


<?php require __DIR__ . '/footer.php'; ?>