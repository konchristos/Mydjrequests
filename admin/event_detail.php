<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Event Detail';
$pageBodyClass = 'admin-page';

$db = db();

// -------------------------
// Input
// -------------------------
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($eventId <= 0) {
    redirect('/admin/user_events.php');
}

// -------------------------
// Load event + owner
// -------------------------
$stmt = $db->prepare("
    SELECT
        e.*,
        u.id AS user_id,
        COALESCE(NULLIF(u.dj_name, ''), u.name) AS dj_display_name,
        u.email AS dj_email
    FROM events e
    JOIN users u ON u.id = e.user_id
    WHERE e.id = ?
    LIMIT 1
");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    redirect('/admin/user_events.php');
}


// -------------------------
// Song request stats
// -------------------------
$requestStats = [
    'total'    => 0,
    'new'      => 0,
    'accepted' => 0,
];

$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'new') AS new_count,
        SUM(status = 'accepted') AS accepted_count
    FROM song_requests
    WHERE event_id = ?
");
$stmt->execute([$eventId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $requestStats['total']    = (int)$row['total'];
    $requestStats['new']      = (int)$row['new_count'];
    $requestStats['accepted'] = (int)$row['accepted_count'];
}

// -------------------------
// Song vote stats
// -------------------------
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM song_votes
    WHERE event_id = ?
");
$stmt->execute([$eventId]);
$totalVotes = (int)$stmt->fetchColumn();


// -------------------------
// Message stats
// -------------------------
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM messages
    WHERE event_id = ?
");
$stmt->execute([$eventId]);
$totalMessages = (int)$stmt->fetchColumn();


// -------------------------
// Derived values
// -------------------------
$publicUrl = 'https://mydjrequests.com/r/' . $event['uuid']; // adjust if your public route differs
?>

<?php include APP_ROOT . '/dj/layout.php'; ?>

<div class="admin-wrap">

    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h1>Event Detail</h1>
        <a href="/admin/get_events.php?user_id=<?php echo (int)$event['user_id']; ?>"
           class="admin-btn">
            ← Back to User Events
        </a>
    </div>

    <div class="admin-report">

        <!-- =========================
             Event Overview
        ========================== -->
        <h2><?php echo e($event['title']); ?></h2>

        <table class="admin-table" style="max-width: 900px;">
            <tbody>
                <tr>
                    <th>ID</th>
                    <td><?php echo (int)$event['id']; ?></td>
                </tr>
                <tr>
                    <th>UUID</th>
                    <td><code><?php echo e($event['uuid']); ?></code></td>
                </tr>
                <tr>
                    <th>DJ</th>
                    <td>
                        <?php echo e($event['dj_display_name']); ?>
                        <small style="color:#888;">
                            (<?php echo e($event['dj_email']); ?>)
                        </small>
                    </td>
                </tr>
                <tr>
                    <th>Location</th>
                    <td><?php echo e($event['location'] ?? '—'); ?></td>
                </tr>
                <tr>
                    <th>Event Date</th>
                    <td><?php echo e($event['event_date']); ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <span class="admin-status-<?php echo e($event['event_state']); ?>">
                            <?php echo e(ucfirst($event['event_state'])); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Active</th>
                    <td><?php echo $event['is_active'] ? 'Yes' : 'No'; ?></td>
                </tr>
                <tr>
                    <th>Tipping Enabled</th>
                    <td><?php echo $event['allow_tipping'] ? 'Yes' : 'No'; ?></td>
                </tr>
                <tr>
                    <th>Created</th>
                    <td><?php echo e($event['created_at']); ?></td>
                </tr>
                <tr>
                    <th>Last State Change</th>
                    <td><?php echo e($event['state_changed_at'] ?? '—'); ?></td>
                </tr>
                <tr>
                    <th>Public Page</th>
                    <td>
                        <a href="<?php echo e($publicUrl); ?>" target="_blank">
                            <?php echo e($publicUrl); ?>
                        </a>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- =========================
             Stats (Phase 2 ready)
        ========================== -->
<h3 style="margin-top: 30px;">Event Activity</h3>

<table class="admin-table" style="max-width: 700px;">
    <tbody>
        <tr>
            <th>Total Song Requests</th>
            <td><strong><?php echo $requestStats['total']; ?></strong></td>
        </tr>
        <tr>
            <th>New Requests</th>
            <td><?php echo $requestStats['new']; ?></td>
        </tr>
        <tr>
            <th>Accepted Requests</th>
            <td><?php echo $requestStats['accepted']; ?></td>
        </tr>
        <tr>
            <th>Song Votes</th>
            <td><?php echo $totalVotes; ?></td>
        </tr>
        <tr>
            <th>Messages</th>
            <td><?php echo $totalMessages; ?></td>
        </tr>
    </tbody>
</table>