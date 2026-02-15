<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'My Bug Reports';

$bugModel = new BugReport();
$bugs = $bugModel->findByUserId((int)$_SESSION['dj_id']);

require __DIR__ . '/layout.php';
?>

<style>
.bug-list {
    margin-top: 10px;
}

.bug-table {
    width: 100%;
    border-collapse: collapse;
}

.bug-table th,
.bug-table td {
    padding: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    text-align: left;
}

.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

.badge-low { background: rgba(76,175,80,0.2); color: #7be87f; }
.badge-medium { background: rgba(255,193,7,0.2); color: #ffd25f; }
.badge-high { background: rgba(244,67,54,0.2); color: #ff8c8c; }

.badge-open { background: rgba(0, 150, 255, 0.2); color: #7cc7ff; }
.badge-in_progress { background: rgba(255, 160, 0, 0.2); color: #ffcf7a; }
.badge-resolved { background: rgba(76, 175, 80, 0.2); color: #7be87f; }
.badge-closed { background: rgba(120,120,120,0.2); color: #bbb; }

.btn-primary {
    background: #ff2fd2;
    color: #fff;
    border: none;
    padding: 10px 14px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
}

.empty {
    color: #aaa;
    margin-top: 12px;
}
</style>

<p style="margin:0 0 8px;"><a href="/dj/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
<h1>My Bug Reports</h1>
<p class="muted">Report issues you find. You can track status and comment updates here.</p>

<div style="margin: 16px 0;">
    <a class="btn-primary" href="/dj/bug_new.php">Report a Bug</a>
</div>

<div class="bug-list">
<?php if (empty($bugs)): ?>
    <div class="empty">No bug reports yet.</div>
<?php else: ?>
    <table class="bug-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($bugs as $b): ?>
            <tr>
                <td>#<?php echo (int)$b['id']; ?></td>
                <td><a href="/dj/bug_view.php?id=<?php echo (int)$b['id']; ?>" style="color:#ff2fd2; text-decoration:none;">
                    <?php echo e($b['title']); ?>
                </a></td>
                <td><span class="badge badge-<?php echo e($b['priority']); ?>"><?php echo e(ucfirst($b['priority'])); ?></span></td>
                <td><span class="badge badge-<?php echo e($b['status']); ?>"><?php echo e(str_replace('_',' ', ucfirst($b['status']))); ?></span></td>
                <td><span class="js-local-time" data-utc="<?php echo e($b['updated_at']); ?>"></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>


<script>
function formatLocalDateTime(ts) {
  if (!ts) return "";
  const d = new Date(ts.replace(" ", "T") + "Z");
  const date = d.toLocaleDateString([], { weekday: "short", day: "numeric", month: "short" });
  const time = d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  return `${date}, ${time}`;
}
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-local-time').forEach(el => {
    const ts = el.dataset.utc || '';
    el.textContent = formatLocalDateTime(ts);
  });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
