<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Admin Dashboard';
$pageBodyClass = 'admin-page';   // ðŸ‘ˆ EXPLICITLY opt into admin layout

$enrichmentPending = 0;
try {
    $stmt = db()->query("SELECT COUNT(*) FROM track_enrichment_queue WHERE status IN ('pending', 'processing')");
    $enrichmentPending = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $enrichmentPending = 0;
}

include APP_ROOT . '/dj/layout.php';
?>


<div class="admin-wrap">
    <h1>Admin Dashboard</h1>

    <div class="admin-dashboard">

        <a href="/admin/notify_signups.php" class="admin-card">
            <h3>Notify Signups</h3>
            <p>All interested reigtrations on Coming Soon page</p>
        </a>
        
        
        <a href="/admin/users.php" class="admin-card">
            <h3>All Users</h3>
            <p>View all DJs and accounts</p>
        </a>

        <a href="/admin/get_events.php" class="admin-card">
            <h3>User Events</h3>
            <p>View all DJs and thier events</p>
        </a>

        <a href="/admin/bugs.php" class="admin-card">
            <h3>Bug Reports</h3>
            <p>All reported bugs and status updates</p>
        </a>

        <a href="/admin/feedback.php" class="admin-card">
            <h3>Feedback</h3>
            <p>Public feedback submissions</p>
        </a>

        <a href="/admin/broadcasts.php" class="admin-card">
            <h3>Broadcasts</h3>
            <p>Send announcements to all users</p>
        </a>

        <a href="/admin/performance.php" class="admin-card">
            <h3>Performance</h3>
            <p>Queue pending: <?php echo (int)$enrichmentPending; ?> · toggles and indexes</p>
        </a>

    </div>
</div>
