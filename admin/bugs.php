<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Bug Reports';
$pageBodyClass = 'admin-page';

$bugModel = new BugReport();
$bugs = $bugModel->findAll();

include APP_ROOT . '/dj/layout.php';
?>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
<h1>Bug Reports</h1>

    <div class="admin-report">
        <div class="admin-filters" style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
            <input type="text" id="bugSearch" placeholder="Search title, user, email" style="padding: 8px; min-width: 260px;">
            <select id="statusFilter" style="padding: 8px;">
                <option value="">All statuses</option>
                <option value="open">Open</option>
                <option value="in_progress">In progress</option>
                <option value="resolved">Resolved</option>
                <option value="closed">Closed</option>
            </select>
        </div>

        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Title</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bugs as $b): ?>
                <?php $rowClass = ($b['status'] === 'resolved') ? 'admin-bug-resolved' : ''; ?>
                <tr class="<?php echo $rowClass; ?>" data-status="<?php echo e($b['status']); ?>" data-title="<?php echo e(strtolower($b['title'])); ?>" data-user="<?php echo e(strtolower($b['email'] ?? '')); ?>">
                    <td>#<?php echo (int)$b['id']; ?></td>
                    <td><?php echo e($b['email'] ?? ''); ?></td>
                    <td>
                        <a href="/admin/bug_view.php?id=<?php echo (int)$b['id']; ?>" style="color:#ff2fd2; text-decoration:none;">
                            <?php echo e($b['title']); ?>
                        </a>
                    </td>
                    <td><?php echo e(ucfirst($b['priority'])); ?></td>
                    <td><?php echo e(str_replace('_',' ', ucfirst($b['status']))); ?></td>
                    <td><span class="js-local-time" data-utc="<?php echo e($b['updated_at']); ?>"></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>




<script>
function formatLocalDateTime(ts) {
  if (!ts) return "";
  const d = new Date(ts.replace(" ", "T") + "Z");
  const date = d.toLocaleDateString([], { weekday: "short", day: "numeric", month: "short" });
  const time = d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  return `${date}, ${time}`;
}

function applyFilters() {
  const search = (document.getElementById('bugSearch').value || '').toLowerCase();
  const status = document.getElementById('statusFilter').value;
  document.querySelectorAll('.admin-table tbody tr').forEach(row => {
    const title = row.dataset.title || '';
    const user = row.dataset.user || '';
    const rowStatus = row.dataset.status || '';
    const matchesSearch = !search || title.includes(search) || user.includes(search);
    const matchesStatus = !status || rowStatus === status;
    row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
  });
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-local-time').forEach(el => {
    const ts = el.dataset.utc || '';
    el.textContent = formatLocalDateTime(ts);
  });

  const searchInput = document.getElementById('bugSearch');
  const statusFilter = document.getElementById('statusFilter');
  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);
});
</script>
