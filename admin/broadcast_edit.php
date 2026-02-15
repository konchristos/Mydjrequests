<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Edit Broadcast';
$pageBodyClass = 'admin-page';

$id = (int)($_GET['id'] ?? 0);
$db = db();
$stmt = $db->prepare("SELECT * FROM notifications WHERE id = :id AND type = 'broadcast' AND deleted_at IS NULL LIMIT 1");
$stmt->execute(['id' => $id]);
$broadcast = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$broadcast) {
    http_response_code(404);
    exit('Broadcast not found');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $url = trim($_POST['url'] ?? '');
        if ($url === '') { $url = '/dj/broadcasts.php'; }

        if ($title === '' || $body === '') {
            $error = 'Title and message are required.';
        } else {
            $update = $db->prepare("UPDATE notifications SET title = :title, body = :body, url = :url WHERE id = :id");
            $update->execute([
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'id' => $id,
            ]);
            $success = 'Broadcast updated.';
            $broadcast = array_merge($broadcast, ['title' => $title, 'body' => $body, 'url' => $url]);
            header('Location: /admin/broadcasts.php?updated=1');
            exit;
        }
    }
}

include APP_ROOT . '/dj/layout.php';
?>

<style>
.edit-card {
    background:#111116;
    border:1px solid #1f1f29;
    border-radius:12px;
    padding:20px;
    max-width:900px;
}
.edit-card label {
    display:block;
    margin:10px 0 6px;
    color:#cfcfd8;
}
.edit-card input,
.edit-card textarea {
    width:100%;
    padding:10px;
    border-radius:8px;
    border:1px solid #2a2a38;
    background:#0f0f14;
    color:#fff;
}
.edit-card textarea {
    min-height:120px;
}
.edit-card .btn-primary {
    margin-top:12px;
}
</style>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/broadcasts.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
    <h1>Edit Broadcast</h1>

    <?php if ($error): ?><div class="error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?php echo e($success); ?></div><?php endif; ?>

    <div class="edit-card">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <label for="title">Title</label>
            <input id="title" name="title" type="text" value="<?php echo e($broadcast['title']); ?>" required>

            <label for="body">Message</label>
            <textarea id="body" name="body" rows="4" required><?php echo e($broadcast['body']); ?></textarea>

            <label for="url">Link (optional)</label>
            <input id="url" name="url" type="text" value="<?php echo e($broadcast['url']); ?>">

            <button class="btn-primary" type="submit">Save Changes</button>
        </form>
    </div>
</div>
