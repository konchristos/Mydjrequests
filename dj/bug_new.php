<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'Report a Bug';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = strtolower(trim($_POST['priority'] ?? 'medium'));

        $validPriorities = ['low', 'medium', 'high'];
        if (!in_array($priority, $validPriorities, true)) {
            $priority = 'medium';
        }

        if ($title === '' || $description === '') {
            $error = 'Please provide a title and description.';
        } else {
            $bugModel = new BugReport();
            $id = $bugModel->create((int)$_SESSION['dj_id'], $title, $description, $priority);

            $nid = notifications_create('bug_new', 'New Bug Report', $title, '/admin/bug_view.php?id=' . $id);
            notifications_add_admins($nid);

            // Optional screenshot upload
            if (!empty($_FILES['screenshot']['name'])) {
                $uploadError = handle_bug_upload($id, (int)$_SESSION['dj_id'], $_FILES['screenshot']);
                if ($uploadError) {
                    $error = $uploadError;
                }
            }

            if ($error === '') {
                redirect('dj/bug_view.php?id=' . $id);
                exit;
            }
        }
    }
}

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

?>

<style>
.form-card {
    background: #111116;
    border: 1px solid #1f1f29;
    border-radius: 12px;
    padding: 20px;
    max-width: 800px;
}

.form-card label {
    display: block;
    margin-bottom: 6px;
    color: #cfcfd8;
}

.form-card input,
.form-card textarea,
.form-card select {
    width: 100%;
    padding: 10px;
    border-radius: 8px;
    border: 1px solid #2a2a38;
    background: #0f0f14;
    color: #fff;
    margin-bottom: 16px;
}

.btn-primary {
    background: #ff2fd2;
    color: #fff;
    border: none;
    padding: 10px 14px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.error { color: #ff8080; }
</style>

<p style="margin:0 0 8px;"><a href="/dj/bugs.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
<h1>Report a Bug</h1>

<div class="form-card">
    <?php if ($error): ?>
        <div class="error" style="margin-bottom:10px;"><?php echo e($error); ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <label for="title">Title</label>
        <input id="title" name="title" type="text" required>

        <label for="description">Description</label>
        <textarea id="description" name="description" rows="6" required></textarea>

        <label for="priority">Priority</label>
        <select id="priority" name="priority">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
        </select>

        <label for="screenshot">Screenshot (optional, JPG/PNG/WEBP, max 5MB)</label>
        <input id="screenshot" name="screenshot" type="file" accept="image/png,image/jpeg,image/webp">

        <button class="btn-primary" type="submit">Submit Bug</button>
    </form>
</div>

<?php require __DIR__ . '/footer.php'; ?>
