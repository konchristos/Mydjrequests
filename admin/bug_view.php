<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Bug Details';
$pageBodyClass = 'admin-page';

$bugId = (int)($_GET['id'] ?? 0);
$bugModel = new BugReport();
$bug = $bugModel->findById($bugId);

if (!$bug) {
    http_response_code(404);
    exit('Bug report not found');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        if (isset($_POST['update_status'])) {
            $status = strtolower(trim($_POST['status'] ?? 'open'));
            $priority = strtolower(trim($_POST['priority'] ?? 'medium'));

            $validStatus = ['open', 'in_progress', 'resolved', 'closed'];
            $validPriority = ['low', 'medium', 'high'];

            if (!in_array($status, $validStatus, true)) {
                $status = 'open';
            }
            if (!in_array($priority, $validPriority, true)) {
                $priority = 'medium';
            }

            $bugModel->updateStatusPriority($bugId, $status, $priority);

            $nid = notifications_create('bug_update', 'Bug Updated', 'Status/priority updated for bug #' . $bugId, '/dj/bug_view.php?id=' . $bugId);
            notifications_add_recipients($nid, [(int)$bug['user_id']]);
            $success = 'Updated status/priority.';
        }

        if (isset($_POST['add_comment'])) {
            $comment = trim($_POST['comment'] ?? '');
            if ($comment !== '') {
                $bugModel->addComment($bugId, (int)$_SESSION['dj_id'], $comment, true);

                $nid = notifications_create('bug_comment', 'Admin replied', 'Update on your bug #' . $bugId, '/dj/bug_view.php?id=' . $bugId);
                notifications_add_recipients($nid, [(int)$bug['user_id']]);

                if (!empty($_FILES['screenshot']['name'])) {
                    $uploadError = handle_bug_upload($bugId, (int)$_SESSION['dj_id'], $_FILES['screenshot']);
                    if ($uploadError) {
                        $error = $uploadError;
                    }
                }

                if ($error === '') {
                    $success = 'Comment added.';
                }
            } else {
                $error = 'Comment cannot be empty.';
            }
        }

        $bug = $bugModel->findById($bugId);
    }
}

$comments = $bugModel->getComments($bugId);

include APP_ROOT . '/dj/layout.php';
?>

<?php


function detect_upload_mime(string $tmpPath): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmpPath) ?: '';
            finfo_close($finfo);
            return $mime;
        }
    }

    if (function_exists('getimagesize')) {
        $info = @getimagesize($tmpPath);
        if (!empty($info['mime'])) {
            return (string)$info['mime'];
        }
    }

    return '';
}

function handle_bug_upload(int $bugId, int $userId, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Upload failed. Please try again.';
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return 'File too large. Max 5MB.';
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = detect_upload_mime($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return 'Invalid file type. Only JPG, PNG, or WEBP allowed.';
    }

    $dir = APP_ROOT . '/uploads/bug_screenshots';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $ext = $allowed[$mime];
    $filename = 'bug_' . $bugId . '_' . time() . '.' . $ext;
    $dest = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return 'Upload failed. Please try again.';
    }

    $db = db();
    $stmt = $db->prepare("
        INSERT INTO bug_attachments (bug_id, user_id, file_path, created_at)
        VALUES (:bid, :uid, :path, UTC_TIMESTAMP())
    ");
    $stmt->execute([
        'bid' => $bugId,
        'uid' => $userId,
        'path' => '/uploads/bug_screenshots/' . $filename,
    ]);

    return '';
}

function get_bug_attachments(int $bugId): array
{
    $db = db();
    $stmt = $db->prepare("SELECT * FROM bug_attachments WHERE bug_id = :bid ORDER BY created_at DESC");
    $stmt->execute(['bid' => $bugId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<style>
.card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; margin-bottom:16px; }
.badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:600; }
.badge-low { background: rgba(76,175,80,0.2); color: #7be87f; }
.badge-medium { background: rgba(255,193,7,0.2); color: #ffd25f; }
.badge-high { background: rgba(244,67,54,0.2); color: #ff8c8c; }
.badge-open { background: rgba(0,150,255,0.2); color:#7cc7ff; }
.badge-in_progress { background: rgba(255,160,0,0.2); color:#ffcf7a; }
.badge-resolved { background: rgba(76,175,80,0.2); color:#7be87f; }
.badge-closed { background: rgba(120,120,120,0.2); color:#bbb; }

.comment { border-top: 1px solid rgba(255,255,255,0.08); padding: 10px 0; }
.comment:first-child { border-top: none; }
.comment .meta { color:#aaa; font-size:12px; margin-bottom:4px; }

textarea { width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff; }
.btn-primary { background:#ff2fd2; color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; }
.error { color:#ff8080; }
.success { color:#7be87f; }
</style>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/bugs.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
<h1>Bug #<?php echo (int)$bug['id']; ?></h1>

    <?php if (!empty($bug['parent_bug_id'])): ?>
        <div class="card" style="border-left:4px solid #ff2fd2;">
            This bug was merged into <a href="/admin/bug_view.php?id=<?php echo (int)$bug['parent_bug_id']; ?>" style="color:#ff2fd2;">Bug #<?php echo (int)$bug['parent_bug_id']; ?></a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?php echo e($success); ?></div><?php endif; ?>

    <div class="card">
        <h3 style="margin-top:0;"><?php echo e($bug['title']); ?></h3>
        <p><?php echo nl2br(e($bug['description'])); ?></p>
        <p><strong>User:</strong> <?php echo e($bug['email'] ?? ''); ?></p>
        <p><strong>Bug created:</strong> <span class="js-local-time" data-utc="<?php echo e($bug['created_at']); ?>"></span> · <strong>Updated:</strong> <span class="js-local-time" data-utc="<?php echo e($bug['updated_at']); ?>"></span></p>
        <div style="margin-top:10px; display:flex; gap:10px;">
            <span class="badge badge-<?php echo e($bug['priority']); ?>"><?php echo e(ucfirst($bug['priority'])); ?></span>
            <span class="badge badge-<?php echo e($bug['status']); ?>"><?php echo e(str_replace('_',' ', ucfirst($bug['status']))); ?></span>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Attachments</h3>
        <?php $attachments = get_bug_attachments($bugId); ?>
        <?php if (empty($attachments)): ?>
            <p class="muted">No screenshots uploaded.</p>
        <?php else: ?>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <?php foreach ($attachments as $a): ?>
                <div style="position:relative;">
                <a href="<?php echo e($a['file_path']); ?>" target="_blank">
                    <img src="<?php echo e($a['file_path']); ?>" alt="screenshot" style="width:140px; height:auto; border-radius:8px; border:1px solid #222;">
                </a>
                <form method="POST" action="/admin/bug_attachment_delete.php" style="position:absolute; top:6px; right:6px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="attachment_id" value="<?php echo (int)$a['id']; ?>">
                    <input type="hidden" name="bug_id" value="<?php echo (int)$bugId; ?>">
                    <button type="submit" style="background:#ff2fd2; color:#fff; border:none; border-radius:6px; padding:2px 6px; cursor:pointer; font-size:11px;">Delete</button>
                </form>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Update Status / Priority</h3>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="update_status" value="1">

            <label style="margin-top:12px;">Merge into Bug ID (optional)</label>
            <input name="merge_into" type="number" placeholder="e.g. 123">

            <label>Status</label>
            <select name="status">
                <?php foreach (['open','in_progress','resolved','closed'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo ($bug['status'] === $s) ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_',' ', $s)); ?></option>
                <?php endforeach; ?>
            </select>

            <label style="margin-top:8px;">Priority</label>
            <select name="priority">
                <?php foreach (['low','medium','high'] as $p): ?>
                    <option value="<?php echo $p; ?>" <?php echo ($bug['priority'] === $p) ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                <?php endforeach; ?>
            </select>

            <div style="margin-top:12px;">
                <button class="btn-primary" type="submit">Save</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Comments</h3>
        <?php if (empty($comments)): ?>
            <p class="muted">No comments yet.</p>
        <?php else: ?>
            <?php foreach ($comments as $c): ?>
                <div class="comment">
                    <div class="meta">
                        <?php echo e(($c['is_admin'] ? 'Admin' : ($c['name'] ?? $c['email'] ?? 'User'))); ?> · <span class="js-local-time" data-utc="<?php echo e($c['created_at']); ?>"></span>
                    </div>
                    <div><?php echo nl2br(e($c['comment'])); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" style="margin-top:12px;" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="add_comment" value="1">
            <textarea name="comment" rows="4" placeholder="Add an admin comment..."></textarea>
            <input name="screenshot" type="file" accept="image/png,image/jpeg,image/webp" style="margin-top:8px;">
            <div style="margin-top:10px;">
                <button class="btn-primary" type="submit">Add Comment</button>
            </div>
        </form>
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
