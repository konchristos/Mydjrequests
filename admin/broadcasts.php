<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Broadcasts';
$pageBodyClass = 'admin-page';

$error = '';
$success = '';

$db = db();

// Soft delete broadcast
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_broadcast'])) {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh.';
    } else {
        $deleteId = (int)($_POST['broadcast_id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $db->prepare("UPDATE notifications SET deleted_at = UTC_TIMESTAMP() WHERE id = :id");
            $stmt->execute(['id' => $deleteId]);
            $success = 'Broadcast deleted.';
        }
    }
}

// Create broadcast
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_broadcast'])) {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $url = trim($_POST['url'] ?? '');
        if ($url === '') {
            $url = '/dj/broadcasts.php';
        }

        if ($title === '' || $body === '') {
            $error = 'Title and message are required.';
        } else {
            $nid = notifications_create('broadcast', $title, $body, $url);
            notifications_add_all_users($nid);
            $success = 'Broadcast sent to all users.';
        }
    }
}

// Show recent broadcasts
$rows = $db->query("SELECT * FROM notifications WHERE type = 'broadcast' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 50")
    ->fetchAll(PDO::FETCH_ASSOC);

include APP_ROOT . '/dj/layout.php';
?>

<style>
.form-card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; max-width:900px; }
label { display:block; margin:10px 0 6px; color:#cfcfd8; }
input, textarea { width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff; }
.btn-primary { background:#ff2fd2; color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; }
.error { color:#ff8080; }
.success { color:#7be87f; }

.broadcast-table { width:100%; border-collapse:collapse; margin-top:16px; }
.broadcast-table th, .broadcast-table td { padding:10px; border-bottom:1px solid rgba(255,255,255,0.08); text-align:left; }
</style>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
    <h1>Broadcasts</h1>

    <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?php echo e($success); ?></div><?php endif; ?>

    <div class="form-card">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <label for="title">Title</label>
            <input id="title" name="title" type="text" required>

            <label for="body">Message</label>
            <textarea id="body" name="body" rows="4" required></textarea>

            <label for="url">Link (optional)</label>
            <input id="url" name="url" type="text" placeholder="/dj/broadcasts.php">

            <button class="btn-primary" type="submit">Send Broadcast</button>
        </form>
    </div>

    <table class="broadcast-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Message</th>
                <th>Link</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>#<?php echo (int)$r['id']; ?></td>
                <td><?php echo e($r['title']); ?></td>
                <td><?php echo e($r['body']); ?></td>
                <td>
                    <?php if (!empty($r['url'])): ?>
                        <a href="<?php echo e($r['url']); ?>" style="color:#ff2fd2; text-decoration:none;" target="_blank">Open</a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td><span class="js-local-time" data-utc="<?php echo e($r['created_at']); ?>"></span></td>
                <td>
                    <a href="/admin/broadcast_edit.php?id=<?php echo (int)$r['id']; ?>" style="color:#ff2fd2; text-decoration:none; margin-right:8px;">Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this broadcast?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="delete_broadcast" value="1">
                        <input type="hidden" name="broadcast_id" value="<?php echo (int)$r['id']; ?>">
                        <button type="submit" style="background:none;border:none;color:#ff4ae0;cursor:pointer;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
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
