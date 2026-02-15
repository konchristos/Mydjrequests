<?php
require_once __DIR__ . '/../app/bootstrap.php';

$errors = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $errors = 'Invalid CSRF token.';
    } else {
        $auth = new AuthController();
        $res  = $auth->login($_POST);

        if (!empty($res['recovery'])) {
            redirect('account/update_email.php');
        } elseif ($res['success']) {
            redirect('dj/dashboard.php');
        } else {
            $errors = $res['message'] ?? 'Recovery failed.';
        }
    }
}

$pageTitle = 'Account Recovery';
require __DIR__ . '/auth_layout.php';
?>

<h1>Account Recovery</h1>

<div class="card">
    <?php if ($errors): ?>
        <p style="color:#ff4ae0;"><?= e($errors); ?></p>
    <?php endif; ?>

    <form method="post" style="margin-top:14px;">
        <?php echo csrf_field(); ?>

        <label>Email:</label>
        <input type="email" name="email" required>

        <label>Recovery code:</label>
        <input type="text" name="recovery_code" required>

        <button type="submit">Recover account</button>
    </form>

    <p style="margin-top:10px;">
        <a href="<?php echo mdjr_url('dj/login.php'); ?>">← Back to Login</a>
    </p>
</div>

<?php require __DIR__ . '/auth_footer.php'; ?>
