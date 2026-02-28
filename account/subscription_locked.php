<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$djId = (int)($_SESSION['dj_id'] ?? 0);
$userModel = new User();
$user = $userModel->findById($djId);

$pageTitle = 'Subscription Required';
require_once __DIR__ . '/../dj/layout.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Subscription Required | MyDJRequests</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
.locked-wrap {
  max-width: 760px;
  margin: 32px auto;
  padding: 0 16px;
}
.locked-card {
  background: #121217;
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 14px;
  padding: 24px;
}
.locked-card h1 {
  margin: 0 0 10px;
  font-size: 30px;
}
.locked-card p {
  color: #c7cad1;
  line-height: 1.5;
}
.locked-meta {
  margin-top: 16px;
  padding: 14px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.03);
}
.locked-meta strong {
  color: #fff;
}
.locked-actions {
  margin-top: 20px;
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.btn-primary {
  border: 0;
  border-radius: 10px;
  padding: 10px 14px;
  background: #ff2fd2;
  color: #fff;
  font-weight: 700;
  text-decoration: none;
}
.btn-secondary {
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 10px;
  padding: 10px 14px;
  color: #fff;
  text-decoration: none;
}
</style>
</head>
<body>
<main class="locked-wrap">
  <section class="locked-card">
    <h1>Subscription Required</h1>
    <p>Your trial or subscription is no longer active, so DJ tools are currently locked.</p>

    <div class="locked-meta">
      <p style="margin:0 0 6px;"><strong>Account:</strong> <?php echo e((string)($user['email'] ?? '')); ?></p>
      <p style="margin:0;"><strong>Next step:</strong> Activate or renew a Pro/Premium subscription to restore full access.</p>
    </div>

    <div class="locked-actions">
      <a class="btn-primary" href="/account/index.php">Go to Account</a>
      <a class="btn-secondary" href="/dj/logout.php">Log out</a>
    </div>
  </section>
</main>
</body>
</html>
