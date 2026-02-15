<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'User Events';
$pageBodyClass = 'admin-page';

$db = db();

// -------------------------
// Load all users
// -------------------------
$stmt = $db->query("
    SELECT
        id,
        COALESCE(NULLIF(dj_name, ''), name) AS display_name,
        email
    FROM users
    ORDER BY display_name ASC
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------
// Handle selected user
// -------------------------
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$events = [];
$totalEvents = 0;
$selectedUser = null;

if ($selectedUserId > 0) {

    // fetch user (for heading)
    $stmt = $db->prepare("
        SELECT
            id,
            COALESCE(NULLIF(dj_name, ''), name) AS display_name,
            email
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$selectedUserId]);
    $selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedUser) {
        // fetch events
        $stmt = $db->prepare("
            SELECT
                e.id,
                e.uuid,
                e.title,
                e.location,
                e.event_date,
                e.event_state,
                e.is_active,
                e.allow_tipping,
                e.created_at,
        
                COALESCE(rs.total_requests, 0) AS total_requests,
                COALESCE(vs.total_votes, 0)    AS total_votes
        
            FROM events e
            LEFT JOIN event_request_stats rs ON rs.event_id = e.id
            LEFT JOIN event_vote_stats vs    ON vs.event_id = e.id
        
            WHERE e.user_id = ?
            ORDER BY e.event_date DESC, e.created_at DESC
        ");
        $stmt->execute([$selectedUserId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalEvents = count($events);
    }
}
?>

<?php include APP_ROOT . '/dj/layout.php'; ?>

<style>
    
  .admin-badge {
    display: inline-block;
    margin-left: 6px;
    padding: 2px 8px;
    font-size: 11px;
    border-radius: 12px;
    font-weight: 600;
    vertical-align: middle;
}

.admin-badge-requests {
    background: rgba(255,255,255,0.12);
    color: #ddd;
}

.admin-badge-votes {
    background: rgba(255,47,210,0.25);
    color: #ffb6ea;
} 


.admin-wrap {
    padding-top: 24px;
}

.admin-header {
    display:flex;
    justify-content:flex-end;
    margin-bottom:16px;
}

.admin-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
    padding-right: 16px; /* 👈 prevents clipping on the right */
}

.admin-topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.admin-back-btn {
    background: #292933;
    border: 1px solid #3a3a46;
    padding: 8px 14px;
    border-radius: 8px;
    color: #cfcfd8;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}



</style>


<div class="admin-wrap" style="padding-top: 24px;">
    
<div class="admin-topbar">

    <!-- LEFT: Search + Dropdown -->
    <form method="get" id="userSelectForm" class="admin-topbar-left">

        <!-- Search -->
        <input
            type="text"
            id="userSearch"
            placeholder="Search DJs…"
            style="
                padding:8px 10px;
                min-width:220px;
                background:#0c0c11;
                border:1px solid #292933;
                color:#ccc;
                border-radius:6px;
                font-size:14px;
            "
        >

        <!-- Dropdown -->
        <select
            name="user_id"
            onchange="this.form.submit()"
            style="
                padding:8px;
                min-width:260px;
                background:#0c0c11;
                border:1px solid #292933;
                color:#ccc;
                border-radius:6px;
                font-size:14px;
            "
        >
            <option value="">Select a user…</option>

            <?php foreach ($users as $u): ?>
                <option
                    value="<?php echo (int)$u['id']; ?>"
                    data-search="<?php echo strtolower($u['display_name'] . ' ' . $u['email']); ?>"
                    <?php if ($selectedUserId === (int)$u['id']) echo 'selected'; ?>
                >
                    <?php echo e($u['display_name']); ?> (<?php echo e($u['email']); ?>)
                </option>
            <?php endforeach; ?>
        </select>

    </form>

    <!-- RIGHT: Back button -->
    <a href="/admin/dashboard.php" class="admin-back-btn">
        ← Back to Dashboard
    </a>

</div>

</form>

        <?php if ($selectedUser): ?>

            <h2 style="margin-top: 10px;">
                <?php echo e($selectedUser['display_name']); ?>
                <small style="color:#888;">
                    — <?php echo e($totalEvents); ?> event<?php echo $totalEvents === 1 ? '' : 's'; ?>
                </small>
            </h2>

            <?php if ($totalEvents === 0): ?>

                <p style="margin-top: 20px; color:#aaa;">
                    No events found for this user.
                </p>

            <?php else: ?>

                <table class="admin-table" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Location</th>
                            <th>Event Date</th>
                            <th>Status</th>
                            <th>Active</th>
                            <th>Tipping</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $e): ?>
                            <tr style="cursor:pointer;"
                                onclick="window.location='/admin/event_detail.php?id=<?php echo (int)$e['id']; ?>'">
                                <td><?php echo (int)$e['id']; ?></td>
                                
                                <td>
    <strong><?php echo e($e['title']); ?></strong>

    <?php if ($e['total_requests'] > 0): ?>
        <span class="admin-badge admin-badge-requests">
            <?php echo (int)$e['total_requests']; ?> req
        </span>
    <?php endif; ?>

    <?php if ($e['total_votes'] > 0): ?>
        <span class="admin-badge admin-badge-votes">
            <?php echo (int)$e['total_votes']; ?> votes
        </span>
    <?php endif; ?>
</td>
                                
                                
                                <td><?php echo e($e['location'] ?? '—'); ?></td>
                                <td><?php echo e($e['event_date']); ?></td>
                                <td>
                                    <span class="admin-status-<?php echo e($e['event_state']); ?>">
                                        <?php echo e(ucfirst($e['event_state'])); ?>
                                    </span>
                                </td>
                                <td><?php echo $e['is_active'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $e['allow_tipping'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo e($e['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:10px; color:#777;">
                    Click an event row to drill down.
                </p>

            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>


<script>
document.getElementById('userSearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    const select = document.querySelector('#userSelectForm select');

    if (!select) return;

    Array.from(select.options).forEach(opt => {
        if (!opt.value) return; // keep placeholder visible
        const hay = opt.dataset.search || '';
        opt.hidden = !hay.includes(q);
    });
});
</script>
