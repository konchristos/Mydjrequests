<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Feedback';
$pageBodyClass = 'admin-page';

$feedbackModel = new Feedback();
$items = $feedbackModel->findAll();

include APP_ROOT . '/dj/layout.php';
?>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
<h1>Feedback</h1>

    <div class="admin-report">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Message</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $f): ?>
                <tr>
                    <td>#<?php echo (int)$f['id']; ?></td>
                    <td><?php echo e($f['name']); ?></td>
                    <td><?php echo e($f['email']); ?></td>
                    <td><?php echo e($f['message']); ?></td>
                    <td><span class="js-local-time" data-utc="<?php echo e($f['created_at']); ?>"></span></td>
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

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-local-time').forEach(el => {
    const ts = el.dataset.utc || '';
    el.textContent = formatLocalDateTime(ts);
  });
});
</script>
