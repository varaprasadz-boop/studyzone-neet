<?php
/* ============================================================
   Phase 2 — Set a new password using a single-use reset token
   emailed by forgot.php. Token is valid for 1 hour and consumed
   on success.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/mail.php';
ensure_mail_tables();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$err = ''; $done = false;

$row = $token !== '' ? q1(
    "SELECT pr.*, u.username, u.email, u.name
     FROM password_resets pr JOIN users u ON u.id = pr.user_id
     WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
     LIMIT 1", [$token]) : null;

if (!$row) { $err = 'This reset link is invalid or has expired. Request a new one.'; }

if (!$err && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = $_POST['new'] ?? '';
    $cf  = $_POST['confirm'] ?? '';
    if (strlen($new) < 5) $err = 'Password must be at least 5 characters.';
    elseif ($new !== $cf) $err = 'Passwords do not match.';
    else {
        db()->beginTransaction();
        try {
            db()->prepare("UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$row['user_id']]);
            db()->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=?")
                ->execute([(int)$row['id']]);
            // for good measure, invalidate any other open reset tokens for this user
            db()->prepare("UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL AND id<>?")
                ->execute([(int)$row['user_id'], (int)$row['id']]);
            db()->commit();
            audit('password.reset_done', 'user', (int)$row['user_id']);
            $done = true;
        } catch (Throwable $ex) {
            db()->rollBack();
            $err = 'Could not save: ' . $ex->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set new password · <?php echo APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Spline+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/app.css">
</head><body>
<div class="login-shell">
  <main class="login-side" style="grid-column:1/-1">
    <div class="login-card">
      <div class="kick">Reset</div>
      <h1>Set a new password</h1>
      <?php if ($done): ?>
        <div class="ok-msg">Password updated. You can sign in now.</div>
        <a class="btn full" href="login.php" style="margin-top:14px">Go to sign in</a>
      <?php elseif (!$row): ?>
        <div class="err"><?php echo e($err); ?></div>
        <div class="form-foot"><a href="forgot.php">Request a new link →</a></div>
      <?php else: ?>
        <p style="color:var(--muted);font-size:.92rem;margin-bottom:14px">Setting a new password for <b><?php echo e($row['username']); ?></b>.</p>
        <form method="post" autocomplete="off">
          <?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>
          <input type="hidden" name="token" value="<?php echo e($token); ?>">
          <label>New password</label><input type="password" name="new" required autofocus>
          <label>Confirm new password</label><input type="password" name="confirm" required>
          <button class="btn full" type="submit" style="margin-top:18px">Save</button>
        </form>
      <?php endif; ?>
    </div>
  </main>
</div>
</body></html>
