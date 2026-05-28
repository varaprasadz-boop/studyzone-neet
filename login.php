<?php
require_once __DIR__ . '/includes/auth.php';
if (current_user()) { header('Location: dashboard.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usr = $_POST['username'] ?? '';
    $pwd = $_POST['password'] ?? '';
    if (attempt_login($usr, $pwd)) { header('Location: dashboard.php'); exit; }
    $err = 'Wrong username or password.';
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · <?php echo APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Spline+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head><body>
<div class="login-wrap"><div class="login-card">
  <div class="kick">NEET · Private</div>
  <h1><?php echo APP_NAME; ?></h1>
  <form method="post" autocomplete="off">
    <?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>
    <label>Username</label>
    <input type="text" name="username" required autofocus>
    <label>Password</label>
    <input type="password" name="password" required>
    <button class="btn full" type="submit" style="margin-top:20px">Log in</button>
  </form>
</div></div>
</body></html>
