<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'My Feedback';

$djId = (int)($_SESSION['dj_id'] ?? 0);
$feedbackModel = new Feedback();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'update_feedback') {
            $feedbackId = (int)($_POST['feedback_id'] ?? 0);
            $message = trim((string)($_POST['message'] ?? ''));
            if ($feedbackId <= 0) {
                $error = 'Invalid feedback item.';
            } elseif ($message === '') {
                $error = 'Feedback message cannot be empty.';
            } elseif ($feedbackModel->updateMessageForUser($feedbackId, $djId, $message)) {
                redirect('dj/feedback.php?ok=' . urlencode('Feedback updated.'));
                exit;
            } else {
                $error = 'Could not update feedback.';
            }
        }
    }
}

$ok = trim((string)($_GET['ok'] ?? ''));
if ($ok !== '') {
    $success = $ok;
}

$items = $feedbackModel->findByUserId($djId);
$totalCount = count($items);
$recent7dCount = 0;
$latestCreatedAt = '';
$nowTs = time();
foreach ($items as $row) {
    $createdAt = (string)($row['created_at'] ?? '');
    if ($createdAt !== '') {
        $ts = strtotime($createdAt . ' UTC');
        if ($ts !== false && ($nowTs - $ts) <= 7 * 86400) {
            $recent7dCount++;
        }
        if ($latestCreatedAt === '' || strcmp($createdAt, $latestCreatedAt) > 0) {
            $latestCreatedAt = $createdAt;
        }
    }
}

require __DIR__ . '/layout.php';
?>

<style>
.feedback-wrap {
    max-width: 980px;
}

.feedback-back-link {
    color: #ff2fd2;
    text-decoration: none;
    font-weight: 600;
}

.feedback-header {
    margin-top: 8px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
}

.feedback-header h1 {
    margin: 0 0 8px;
}

.feedback-subtitle {
    margin: 0;
    color: #b8b9c9;
}

.btn-primary {
    background: linear-gradient(135deg, #ff2fd2, #ff5fe4);
    color: #fff;
    border: none;
    padding: 11px 16px;
    border-radius: 10px;
    font-weight: 700;
    text-decoration: none;
    display: inline-block;
    box-shadow: 0 8px 24px rgba(255, 47, 210, 0.25);
}

.btn-info {
    background:#2563eb;
    color:#fff;
    border:1px solid #3b82f6;
    padding:8px 12px;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
}

.btn-info:hover {
    background:#1d4ed8;
}

.btn-secondary {
    background:#222233;
    color:#fff;
    border:1px solid #31314a;
    padding:8px 12px;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
}

.stats-grid {
    margin-top: 16px;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}

.stat-tile {
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
    padding: 12px;
}

.stat-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9ea0bb;
    margin-bottom: 6px;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    line-height: 1;
}

.feedback-list {
    margin-top: 16px;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 14px;
    overflow: hidden;
    background: rgba(255,255,255,0.02);
}

.feedback-table {
    width: 100%;
    border-collapse: collapse;
}

.feedback-table thead th {
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9ea0bb;
    padding: 14px 12px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}

.feedback-table td {
    padding: 14px 12px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    vertical-align: top;
}

.feedback-table tbody tr:hover {
    background: rgba(255,255,255,0.03);
}

.feedback-table tbody tr:last-child td {
    border-bottom: none;
}

.id-chip {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    background: rgba(255,255,255,0.08);
    font-weight: 600;
}

.feedback-msg {
    white-space: pre-wrap;
    word-break: break-word;
}

.cell-label {
    display: none;
}

.empty {
    color: #aaa;
    margin-top: 12px;
    padding: 18px 16px;
}

.feedback-action-cell {
    white-space: nowrap;
}

.feedback-notice {
    margin-top: 12px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 10px;
    padding: 10px 12px;
}

.feedback-notice.error {
    border-color: rgba(199, 61, 95, 0.5);
    color: #ff9ab4;
    background: rgba(199, 61, 95, 0.12);
}

.feedback-notice.success {
    border-color: rgba(47, 191, 113, 0.5);
    color: #9ff0bf;
    background: rgba(47, 191, 113, 0.12);
}

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
    width: min(680px, 96vw);
    background: #111116;
    border: 1px solid #2a2a38;
    border-radius: 12px;
    padding: 16px;
}

.modal-card textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 10px;
    border-radius: 8px;
    border: 1px solid #2a2a38;
    background: #0f0f14;
    color: #fff;
}

@media (max-width: 820px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .feedback-list {
        background: transparent;
        border: none;
    }

    .feedback-table thead {
        display: none;
    }

    .feedback-table,
    .feedback-table tbody,
    .feedback-table tr,
    .feedback-table td {
        display: block;
        width: 100%;
    }

    .feedback-table tr {
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 12px;
        padding: 10px;
        margin-bottom: 10px;
        background: rgba(255,255,255,0.02);
    }

    .feedback-table td {
        border-bottom: none;
        padding: 6px 4px;
    }

    .cell-label {
        display: inline-block;
        color: #8f91ab;
        min-width: 84px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .feedback-action-cell {
        margin-top: 4px;
    }
}
</style>

<div class="feedback-wrap">
<p style="margin:0 0 8px;"><a class="feedback-back-link" href="/dj/dashboard.php">← Back</a></p>

<div class="feedback-header">
    <div>
        <h1>My Feedback</h1>
        <p class="feedback-subtitle">Review everything you’ve submitted and add new feedback at any time.</p>
    </div>
    <a class="btn-primary" href="/feedback.php">Submit Feedback</a>
</div>

<?php if ($success): ?>
    <div class="feedback-notice success"><?php echo e($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="feedback-notice error"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-label">Total Feedback</div>
        <div class="stat-value"><?php echo (int)$totalCount; ?></div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Last 7 Days</div>
        <div class="stat-value"><?php echo (int)$recent7dCount; ?></div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Latest</div>
        <div class="stat-value" style="font-size:15px; line-height:1.3;">
            <?php if ($latestCreatedAt !== ''): ?>
                <span class="js-local-time" data-utc="<?php echo e($latestCreatedAt); ?>"></span>
            <?php else: ?>
                -
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="feedback-list">
    <?php if (empty($items)): ?>
        <div class="empty">No feedback submitted yet.</div>
    <?php else: ?>
        <table class="feedback-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Message</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $f): ?>
                    <tr>
                        <td><span class="cell-label">ID</span><span class="id-chip">#<?php echo (int)$f['id']; ?></span></td>
                        <td><span class="cell-label">Message</span><div class="feedback-msg"><?php echo e($f['message']); ?></div></td>
                        <td><span class="cell-label">Submitted</span><span class="js-local-time" data-utc="<?php echo e($f['created_at']); ?>"></span></td>
                        <td class="feedback-action-cell">
                            <span class="cell-label">Actions</span>
                            <button
                                type="button"
                                class="btn-info js-edit-feedback"
                                data-feedback-id="<?php echo (int)$f['id']; ?>"
                            >
                                Edit
                            </button>
                            <textarea id="edit-feedback-source-<?php echo (int)$f['id']; ?>" hidden><?php echo e($f['message']); ?></textarea>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</div>

<div id="editFeedbackModal" class="modal-backdrop" hidden>
    <div class="modal-card">
        <h3 style="margin:0 0 10px;">Edit Feedback</h3>
        <form method="POST" id="editFeedbackForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_feedback">
            <input type="hidden" name="feedback_id" id="edit_feedback_id" value="">
            <textarea name="message" id="edit_feedback_text" rows="7" required></textarea>
            <div style="margin-top:10px; display:flex; gap:8px; justify-content:flex-end;">
                <button type="button" class="btn-secondary" id="editFeedbackCancel">Cancel</button>
                <button type="submit" class="btn-info">Save</button>
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

  const editModal = document.getElementById('editFeedbackModal');
  const editIdInput = document.getElementById('edit_feedback_id');
  const editTextInput = document.getElementById('edit_feedback_text');
  const cancelBtn = document.getElementById('editFeedbackCancel');

  const closeModal = () => {
    if (!editModal) return;
    editModal.hidden = true;
  };

  document.querySelectorAll('.js-edit-feedback').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!editModal || !editIdInput || !editTextInput) return;
      const id = btn.getAttribute('data-feedback-id') || '';
      const src = document.getElementById('edit-feedback-source-' + id);
      editIdInput.value = id;
      editTextInput.value = src ? src.value : '';
      editModal.hidden = false;
      editTextInput.focus();
      editTextInput.setSelectionRange(editTextInput.value.length, editTextInput.value.length);
    });
  });

  if (cancelBtn) {
    cancelBtn.addEventListener('click', closeModal);
  }

  if (editModal) {
    editModal.addEventListener('click', (event) => {
      if (event.target === editModal) closeModal();
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal();
  });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
