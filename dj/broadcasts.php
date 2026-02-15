<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'Broadcasts';

$db = db();
$stmt = $db->prepare("SELECT * FROM notifications WHERE type = 'broadcast' ORDER BY created_at DESC LIMIT 200");
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/layout.php';
?>

<style>
.broadcast-list { margin-top: 10px; }
.broadcast-item { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
.broadcast-title { font-weight: 600; color: #fff; }
.broadcast-body { color: #aaa; margin-top: 4px; }
.broadcast-meta { color: #888; font-size: 12px; margin-top: 6px; }
</style>

<p style="margin:0 0 8px;"><a href="/dj/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
<h1>Broadcasts</h1>

<div class="broadcast-list">
<?php if (empty($items)): ?>
    <p class="muted">No broadcasts yet.</p>
<?php else: ?>
    <?php foreach ($items as $n): ?>
        <div class="broadcast-item">
            <div class="broadcast-title"><?php echo e($n['title']); ?></div>
            <?php if (!empty($n['body'])): ?>
                <div class="broadcast-body"><?php echo e($n['body']); ?></div>
            <?php endif; ?>
            <div class="broadcast-meta">
                <span class="js-local-time" data-utc="<?php echo e($n['created_at']); ?>"></span>
            </div>
        </div>
    <?php endforeach; ?>
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
