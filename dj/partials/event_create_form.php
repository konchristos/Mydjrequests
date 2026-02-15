<?php
// dj/partials/event_create_form.php
?>

<form method="post">
    <?php echo csrf_field(); ?>

    <input type="hidden" name="redirect_to"
           value="<?php echo e($redirectTo ?? '/dj/events.php'); ?>">

<div class="input-group">
    <label for="title">Event Title</label>
        <div class="input-wrap">
        <span class="icon">🎵</span>
        <input
            type="text"
            name="title"
            id="title"
            placeholder="e.g. Joe’s 30th Birthday"
            required
        >
    </div>
</div>

<div class="input-group">
    <label for="location">Location</label>
    <div class="input-wrap">
    <span class="icon">📍</span>
    <input
        type="text"
        name="location"
        id="location"
        placeholder="e.g. Hawthorn, VIC"
    >
    </div>
</div>

<div class="input-group">
    <label for="event_date">Event Date</label>
    <div class="input-wrap">
    <span class="icon">📅</span>
    <input
        type="date"
        name="event_date"
        id="event_date"
        class="date-input"
        required
    >
   </div> 
</div>

    <button type="submit" class="btn-primary">
        Create Event
    </button>
</form>