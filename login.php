<?php
require_once __DIR__ . '/includes/lib.php';
if (current_user()) { header('Location: dashboard.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usr = $_POST['username'] ?? '';
    $pwd = $_POST['password'] ?? '';
    if (attempt_login($usr, $pwd)) {
        audit('login.success', 'user', current_user()['id']);
        if (!empty($_SESSION['user']['must_change_password'])) {
            flash('Please set a new password to continue.');
            header('Location: account.php'); exit;
        }
        header('Location: dashboard.php'); exit;
    }
    audit('login.fail', null, null, ['username' => trim($usr)]);
    $err = 'Wrong username or password, or the account is disabled.';
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?php echo APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Spline+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/app.css">
</head><body>
<div class="login-shell">
  <aside class="login-hero">
    <div class="kick">NEET · Private study</div>
    <h1>Study smart.<br>Practice deep.<br>Improve daily.</h1>
    <?php $svg = @file_get_contents(__DIR__ . '/assets/illus/login.svg'); if ($svg) echo $svg; ?>
    <p>Your tutor's private learning workspace — chapter notes, the past-paper bank, full mocks and reports, in one place.</p>
    <div class="foot">© <?php echo date('Y'); ?> · <?php echo APP_NAME; ?></div>
  </aside>
  <main class="login-side">
    <div class="login-card">
      <div class="kick">Sign in</div>
      <h1>Welcome back</h1>
      <form method="post" autocomplete="off">
        <?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>
        <?php if (!empty($_SESSION['flash'])) echo flash_render(); ?>
        <label>Username</label>
        <input type="text" name="username" required autofocus autocapitalize="none" autocorrect="off">
        <label>Password</label>
        <input type="password" name="password" required>
        <button class="btn full" type="submit" style="margin-top:20px">Sign in</button>
      </form>
      <div class="form-foot">Trouble signing in? Ask your tutor.</div>
    </div>
  </main>
</div>
</body></html>
