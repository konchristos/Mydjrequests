<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'My Feedback';

$feedbackModel = new Feedback();
$items = $feedbackModel->findByUserId((int)$_SESSION['dj_id']);

require __DIR__ . '/layout.php';
?>

<style>
.feedback-table { width:100%; border-collapse:collapse; }
.feedback-table th, .feedback-table td { padding:10px; border-bottom:1px solid rgba(255,255,255,0.08); text-align:left; }
.btn-primary { background:#ff2fd2; color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; text-decoration:none; display:inline-block; }
.empty { color:#aaa; margin-top:12px; }
</style>

<h1>My Feedback</h1>
<p class="muted">Your submitted feedback. You can submit new feedback any time.</p>

<div style="margin:16px 0;">
    <a class="btn-primary" href="/feedback.php">Submit Feedback</a>
</div>

<?php if (empty($items)): ?>
    <div class="empty">No feedback submitted yet.</div>
<?php else: ?>
    <table class="feedback-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Message</th>
                <th>Submitted</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $f): ?>
                <tr>
                    <td>#<?php echo (int)$f['id']; ?></td>
                    <td><?php echo e($f['message']); ?></td>
                    <td><span class="js-local-time" data-utc="<?php echo e($f['created_at']); ?>"></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>


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
