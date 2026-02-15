<?php
$requestCount = isset($requestCount) ? (int)$requestCount : 0;

$state = $event['event_state'] ?? 'upcoming';

switch ($state) {
    case 'live':
        $badgeClass = 'badge-live';
        $badgeLabel = 'LIVE';
        break;

    case 'ended':
        $badgeClass = 'badge-ended';
        $badgeLabel = 'ENDED';
        break;

    case 'upcoming':
    default:
        $badgeClass = 'badge-upcoming';
        $badgeLabel = 'UPCOMING';
        break;
}


$isToday = false;

if (!empty($event['event_date'])) {
    $today = new DateTimeImmutable('today');
    $eventDay = new DateTimeImmutable($event['event_date']);

    if ($eventDay->format('Y-m-d') === $today->format('Y-m-d')) {
        $isToday = true;
    }
}




?>



<div
    class="event-card"
    data-search="<?php
        echo strtolower(
            ($event['title'] ?? '') . ' ' .
            ($event['location'] ?? '') . ' ' .
            ($event['event_date'] ?? '')
        );
    ?>"
>

    <div class="event-card-header">
<div class="event-title">
    <span class="event-title-text">
        <?php echo e($event['title']); ?>
    </span>

    <span class="event-badge <?php echo $badgeClass; ?>">
    <?php echo strtoupper($badgeLabel); ?>
</span>

<?php if ($isToday && $state !== 'ended'): ?>
    <span class="event-badge badge-today">TODAY</span>
<?php endif; ?>

<span class="event-badge badge-requests <?php echo $requestCount === 0 ? 'zero' : ''; ?>">
    <?php echo (int)$requestCount; ?> requests
</span>
<span class="badge-votes <?= $voteCount === 0 ? 'zero' : '' ?>">
    <?= $voteCount ?> votes
</span>


</div>

            <div class="event-actions">
                <a
                    href="<?php echo e(url('dj/event_details.php?uuid=' . $event['uuid'])); ?>"
                    class="manage-btn manage-btn-top">
                    Manage
                </a>
            

            </div>


    </div>

    <div class="event-meta">
        <strong>Date:</strong> <?php echo e($event['event_date'] ?: 'No date'); ?><br>
        <strong>Location:</strong> <?php echo e($event['location'] ?: 'N/A'); ?>
    </div>

    <div class="request-link-wrap">
        <code class="request-url">
            <?php echo url('r/' . $event['uuid']); ?>
        </code>
    
        <button
            class="copy-btn"
            type="button"
            data-copy="<?php echo url('r/' . $event['uuid']); ?>">
            Copy
        </button>
    </div>

    
</div>