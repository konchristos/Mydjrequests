<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'Notifications';

$items = notifications_get_recent((int)$_SESSION['dj_id'], 200);

require __DIR__ . '/layout.php';
?>

<style>
.notif-list { margin-top: 10px; }
.notif-item { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
.notif-item.unread { background: rgba(255,47,210,0.08); }
.notif-title { font-weight: 600; color: #fff; }
.notif-body { color: #aaa; margin-top: 4px; }
.notif-meta { color: #888; font-size: 12px; margin-top: 6px; }
.btn-mark { background:#ff2fd2; color:#fff; border:none; padding:6px 10px; border-radius:6px; font-size:12px; cursor:pointer; }
</style>

<p style="margin:0 0 8px;"><a href="/dj/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
<h1>Notifications</h1>

<div class="notif-list">
<?php if (empty($items)): ?>
    <p class="muted">No unread notifications.</p>
<?php else: ?>
    <?php foreach ($items as $n): ?>
        <div class="notif-item unread">
            <div class="notif-title"><?php echo e($n['title']); ?></div>
            <?php if (!empty($n['body'])): ?>
                <div class="notif-body"><?php echo e($n['body']); ?></div>
            <?php endif; ?>
            <div class="notif-meta">
                <span class="js-local-time" data-utc="<?php echo e($n['created_at']); ?>"></span>
            </div>
            <div style="margin-top:8px; display:flex; gap:8px;">
                <?php if (!empty($n['url'])): ?>
                    <a
                        class="btn-mark"
                        href="/dj/notification_read.php?id=<?php echo (int)$n['id']; ?>&redirect=<?php echo urlencode($n['url']); ?>"
                    >
                        Open
                    </a>
                <?php endif; ?>
                <form method="GET" action="/dj/notification_read.php">
                    <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                    <input type="hidden" name="redirect" value="/dj/notifications.php">
                    <button class="btn-mark" type="submit">Dismiss</button>
                </form>
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
