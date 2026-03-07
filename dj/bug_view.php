<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'Bug Details';

$djId = (int)($_SESSION['dj_id'] ?? 0);
$bugId = (int)($_GET['id'] ?? 0);
$bugModel = new BugReport();
$bug = $bugModel->findById($bugId);

if (!$bug || (int)$bug['user_id'] !== $djId) {
    http_response_code(404);
    exit('Bug report not found');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? 'add_comment');

        if ($action === 'update_title') {
            $newTitle = trim((string)($_POST['title'] ?? ''));
            $titleLen = function_exists('mb_strlen') ? mb_strlen($newTitle) : strlen($newTitle);
            if ($newTitle === '') {
                $error = 'Title cannot be empty.';
            } elseif ($titleLen > 255) {
                $error = 'Title is too long (max 255 characters).';
            } elseif ($bugModel->updateTitleForUser($bugId, $djId, $newTitle)) {
                $success = 'Bug title updated.';
            } else {
                $error = 'Could not update title.';
            }
        } elseif ($action === 'update_description') {
            $newDescription = trim((string)($_POST['description'] ?? ''));
            if ($newDescription === '') {
                $error = 'Description cannot be empty.';
            } elseif ($bugModel->updateDescriptionForUser($bugId, $djId, $newDescription)) {
                $success = 'Bug description updated.';
            } else {
                $error = 'Could not update description.';
            }
        } elseif ($action === 'update_comment') {
            $commentId = (int)($_POST['comment_id'] ?? 0);
            $newComment = trim((string)($_POST['comment'] ?? ''));
            if ($commentId <= 0) {
                $error = 'Invalid comment.';
            } elseif ($newComment === '') {
                $error = 'Comment cannot be empty.';
            } elseif ($bugModel->updateCommentForUser($commentId, $bugId, $djId, $newComment)) {
                $success = 'Comment updated.';
            } else {
                $error = 'Could not update comment. You can only edit your own comments.';
            }
        } elseif ($action === 'delete_comment') {
            $commentId = (int)($_POST['comment_id'] ?? 0);
            if ($commentId <= 0) {
                $error = 'Invalid comment.';
            } elseif ($bugModel->deleteCommentForUser($commentId, $bugId, $djId)) {
                $success = 'Comment deleted.';
            } else {
                $error = 'Could not delete comment. You can only delete your own comments.';
            }
        } else {
            $comment = trim((string)($_POST['comment'] ?? ''));
            if ($comment === '') {
                $error = 'Please enter a comment.';
            } else {
                $bugModel->addComment($bugId, $djId, $comment, false);

                $nid = notifications_create('bug_comment', 'New Bug Comment', 'Comment on bug #' . $bugId, '/admin/bug_view.php?id=' . $bugId);
                notifications_add_admins($nid);

                if (!empty($_FILES['screenshot']['name'])) {
                    $uploadError = handle_bug_upload($bugId, $djId, $_FILES['screenshot']);
                    if ($uploadError) {
                        $error = $uploadError;
                    }
                }

                if ($error === '') {
                    $success = 'Comment added.';
                }
            }
        }

        if ($error === '' && $success !== '') {
            redirect('dj/bug_view.php?id=' . $bugId . '&ok=' . urlencode($success));
            exit;
        }
    }
}

$ok = trim((string)($_GET['ok'] ?? ''));
if ($ok !== '') {
    $success = $ok;
}

$bug = $bugModel->findById($bugId);
$comments = $bugModel->getComments($bugId);

require __DIR__ . '/layout.php';
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
.badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:600; }
.badge-low { background: rgba(76,175,80,0.2); color: #7be87f; }
.badge-medium { background: rgba(255,193,7,0.2); color: #ffd25f; }
.badge-high { background: rgba(244,67,54,0.2); color: #ff8c8c; }
.badge-open { background: rgba(0,150,255,0.2); color:#7cc7ff; }
.badge-in_progress { background: rgba(255,160,0,0.2); color:#ffcf7a; }
.badge-resolved { background: rgba(76,175,80,0.2); color:#7be87f; }
.badge-closed { background: rgba(120,120,120,0.2); color:#bbb; }

.card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; margin-bottom:16px; }

.comment { border-top: 1px solid rgba(255,255,255,0.08); padding: 10px 0; }
.comment:first-child { border-top: none; }
.comment .meta { color:#aaa; font-size:12px; margin-bottom:4px; }
.comment-admin {
    border: 1px solid rgba(var(--brand-accent-rgb), 0.35);
    background: rgba(var(--brand-accent-rgb), 0.08);
    border-radius: 10px;
    padding: 10px;
    margin: 10px 0;
}
.comment-admin .meta {
    color: #ff9cea;
    font-weight: 600;
}
.comment-actions { margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; }

textarea { width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff; }
.input { width:100%; padding:10px; border-radius:8px; border:1px solid #2a2a38; background:#0f0f14; color:#fff; }
.btn-primary { background:var(--brand-accent); color:#fff; border:none; padding:10px 14px; border-radius:8px; font-weight:600; cursor:pointer; }
.btn-secondary { background:#222233; color:#fff; border:1px solid #31314a; padding:8px 12px; border-radius:8px; font-weight:600; cursor:pointer; }
.btn-info { background:#2563eb; color:#fff; border:1px solid #3b82f6; padding:8px 12px; border-radius:8px; font-weight:600; cursor:pointer; }
.btn-info:hover { background:#1d4ed8; }
.btn-danger { background:#7e1f3f; color:#fff; border:1px solid #a42a56; padding:8px 12px; border-radius:8px; font-weight:600; cursor:pointer; }
.error { color:#ff8080; }
.success { color:#79e3a1; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    z-index: 1000;
}
.modal-backdrop[hidden] {
    display: none !important;
}

.modal-card {
    width: min(640px, 96vw);
    background: #111116;
    border: 1px solid #2a2a38;
    border-radius: 12px;
    padding: 16px;
}
.modal-card textarea,
.modal-card .input,
textarea,
.input {
    box-sizing: border-box;
    max-width: 100%;
}

.attachments-card.attachments-empty {
    padding-top: 14px;
    padding-bottom: 14px;
}

.attachments-card.attachments-empty h3 {
    margin-bottom: 8px;
}

.attachments-card.attachments-empty .muted {
    margin: 0;
}
</style>

<p style="margin:0 0 8px;"><a href="/dj/bugs.php" style="color:var(--brand-accent); text-decoration:none;">← Back</a></p>
<h1>Bug #<?php echo (int)$bug['id']; ?></h1>

<?php if (!empty($bug['parent_bug_id'])): ?>
    <div class="card" style="border-left:4px solid var(--brand-accent);">
        This bug was merged into <a href="/dj/bug_view.php?id=<?php echo (int)$bug['parent_bug_id']; ?>" style="color:var(--brand-accent);">Bug #<?php echo (int)$bug['parent_bug_id']; ?></a>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="card success" style="border-left:4px solid #2fbf71; padding:12px 16px;">
        <?php echo e($success); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="card error" style="border-left:4px solid #c73d5f; padding:12px 16px;">
        <?php echo e($error); ?>
    </div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-top:0; margin-bottom:8px;"><?php echo e($bug['title']); ?></h3>
    <form method="POST" style="margin-bottom:14px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update_title">
        <label for="bug_title_edit" class="muted" style="display:block; margin:0 0 6px;">Edit title</label>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <input id="bug_title_edit" class="input" name="title" maxlength="255" value="<?php echo e($bug['title']); ?>" style="flex:1; min-width:240px;">
            <button class="btn-secondary" type="submit">Save Title</button>
        </div>
    </form>
    <p><?php echo nl2br(e($bug['description'])); ?></p>
    <form method="POST" style="margin-top:12px; margin-bottom:12px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update_description">
        <label for="bug_description_edit" class="muted" style="display:block; margin:0 0 6px;">Edit original comment</label>
        <textarea id="bug_description_edit" name="description" rows="5" required><?php echo e($bug['description']); ?></textarea>
        <div style="margin-top:8px;">
            <button class="btn-secondary" type="submit">Save Original Comment</button>
        </div>
    </form>
    <p><strong>Bug created:</strong> <span class="js-local-time" data-utc="<?php echo e($bug['created_at']); ?>"></span> · <strong>Updated:</strong> <span class="js-local-time" data-utc="<?php echo e($bug['updated_at']); ?>"></span></p>
    <div style="margin-top:10px; display:flex; gap:10px;">
        <span class="badge badge-<?php echo e($bug['priority']); ?>"><?php echo e(ucfirst($bug['priority'])); ?></span>
        <span class="badge badge-<?php echo e($bug['status']); ?>"><?php echo e(str_replace('_',' ', ucfirst($bug['status']))); ?></span>
    </div>
</div>

<?php $attachments = get_bug_attachments($bugId); ?>
<div class="card attachments-card <?php echo empty($attachments) ? 'attachments-empty' : ''; ?>">
    <h3 style="margin-top:0;">Attachments</h3>
    <?php if (empty($attachments)): ?>
        <p class="muted">No screenshots uploaded.</p>
    <?php else: ?>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php foreach ($attachments as $a): ?>
            <div style="position:relative;">
                <a href="<?php echo e($a['file_path']); ?>" target="_blank">
                    <img src="<?php echo e($a['file_path']); ?>" alt="screenshot" style="width:140px; height:auto; border-radius:8px; border:1px solid #222;">
                </a>
                <form method="POST" action="/dj/bug_attachment_delete.php" style="position:absolute; top:6px; right:6px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="attachment_id" value="<?php echo (int)$a['id']; ?>">
                    <input type="hidden" name="bug_id" value="<?php echo (int)$bugId; ?>">
                    <button type="submit" style="background:var(--brand-accent); color:#fff; border:none; border-radius:6px; padding:2px 6px; cursor:pointer; font-size:11px;">Delete</button>
                </form>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 style="margin-top:0;">Comments</h3>

    <?php if (empty($comments)): ?>
        <p class="muted">No comments yet.</p>
    <?php else: ?>
        <?php foreach ($comments as $c): ?>
            <div class="comment <?php echo ((int)$c['is_admin'] === 1) ? 'comment-admin' : ''; ?>">
                <div class="meta">
                    <?php echo e(($c['is_admin'] ? 'Admin' : ($c['name'] ?? $c['email'] ?? 'User'))); ?> · <span class="js-local-time" data-utc="<?php echo e($c['created_at']); ?>"></span>
                </div>
                <div><?php echo nl2br(e($c['comment'])); ?></div>
                <?php if ((int)$c['is_admin'] === 0 && (int)$c['user_id'] === $djId): ?>
                    <div class="comment-actions">
                        <button
                            type="button"
                            class="btn-info js-edit-comment"
                            data-comment-id="<?php echo (int)$c['id']; ?>"
                        >
                            Edit
                        </button>
                        <textarea id="edit-source-<?php echo (int)$c['id']; ?>" hidden><?php echo e($c['comment']); ?></textarea>

                        <form method="POST" onsubmit="return confirm('Delete this comment?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_comment">
                            <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                            <button class="btn-danger" type="submit">Delete</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <form method="POST" style="margin-top:12px;" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add_comment">
        <textarea name="comment" rows="4" placeholder="Add a comment..."></textarea>
        <input name="screenshot" type="file" accept="image/png,image/jpeg,image/webp" style="margin-top:8px;">
        <div style="margin-top:10px;">
            <button class="btn-primary" type="submit">Add Comment</button>
        </div>
    </form>
</div>

<div id="editCommentModal" class="modal-backdrop" hidden>
    <div class="modal-card">
        <h3 style="margin:0 0 10px;">Edit Comment</h3>
        <form method="POST" id="editCommentForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_comment">
            <input type="hidden" name="comment_id" id="edit_comment_id" value="">
            <textarea name="comment" id="edit_comment_text" rows="6" required></textarea>
            <div style="margin-top:10px; display:flex; gap:8px; justify-content:flex-end;">
                <button type="button" class="btn-secondary" id="editCommentCancel">Cancel</button>
                <button type="submit" class="btn-info">Save Changes</button>
            </div>
        </form>
    </div>
</div>


<script>
function timeAgo(ts) {
  if (!ts) return "";
  const utc = new Date(ts.replace(" ", "T") + "Z");
  const diff = Math.floor((Date.now() - utc.getTime()) / 1000);
  if (diff < 60) return "just now";
  if (diff < 3600) return Math.floor(diff / 60) + " min ago";
  if (diff < 86400) return Math.floor(diff / 3600) + " hr ago";
  if (diff < 604800) return Math.floor(diff / 86400) + " days ago";
  return utc.toLocaleDateString();
}
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

  const editModal = document.getElementById('editCommentModal');
  const editIdInput = document.getElementById('edit_comment_id');
  const editTextInput = document.getElementById('edit_comment_text');
  const editCancelBtn = document.getElementById('editCommentCancel');

  const closeEditModal = () => {
    if (!editModal) return;
    editModal.hidden = true;
  };

  document.querySelectorAll('.js-edit-comment').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!editModal || !editIdInput || !editTextInput) return;
      const id = btn.getAttribute('data-comment-id') || '';
      const src = document.getElementById('edit-source-' + id);
      editIdInput.value = id;
      editTextInput.value = src ? src.value : '';
      editModal.hidden = false;
      editTextInput.focus();
      editTextInput.setSelectionRange(editTextInput.value.length, editTextInput.value.length);
    });
  });

  if (editCancelBtn) {
    editCancelBtn.addEventListener('click', closeEditModal);
  }

  if (editModal) {
    editModal.addEventListener('click', (event) => {
      if (event.target === editModal) closeEditModal();
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeEditModal();
  });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
