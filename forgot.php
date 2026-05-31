<?php
/* ============================================================
   Phase 2 — Forgot password (request a reset link).
   Public page (no login). Always shows the same confirmation
   message whether or not an account matched, so the form never
   reveals which emails exist.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/mail.php';
ensure_mail_tables();

if (current_user()) { header('Location: dashboard.php'); exit; }

$sent = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idin = trim($_POST['who'] ?? '');
    $ip   = $_SERVER['REMOTE_ADDR'] ?? null;

    // simple per-IP rate limit: ≤5 requests / hour
    $recent = (int)q1("SELECT COUNT(*) AS c FROM password_resets
                       WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", [$ip])['c'];
    if ($recent >= 5) {
        $err = 'Too many requests from this network — try again in an hour.';
    } elseif ($idin === '') {
        $err = 'Type your username or email.';
    } else {
        // find by username OR email; only act on active accounts with an email
        $u = q1("SELECT id, username, email, name, status FROM users
                 WHERE (username=? OR (email IS NOT NULL AND email=?))
                 LIMIT 1", [$idin, $idin]);
        if ($u && $u['status'] === 'active' && !empty($u['email'])) {
            $token = gen_token();
            db()->prepare("INSERT INTO password_resets (user_id, token, expires_at, ip)
                           VALUES (?,?, NOW() + INTERVAL 1 HOUR, ?)")
                ->execute([$u['id'], $token, $ip]);
            $link = app_url('reset.php?token=' . $token);
            $html = render_html_email(
                'Reset your password',
                '<p>Hello ' . htmlspecialchars($u['name']) . ',</p>'
              . '<p>Someone (hopefully you) asked to reset the password for <b>' . htmlspecialchars($u['username']) . '</b>.</p>'
              . '<p>The link below works for 1 hour and can be used once:</p>',
                ['Set a new password', $link]
            );
            $text = "Reset link (valid 1 hour, single use):\n$link\n";
            send_email($u['email'], 'Reset your ' . APP_NAME . ' password', $html, $text, (int)$u['id']);
            audit('password.reset_requested', 'user', (int)$u['id'], ['via' => 'email']);
        }
        $sent = true;     // same UX whether or not we actually sent
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot password · <?php echo APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Spline+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/app.css">
</head><body>
<div class="login-shell">
  <main class="login-side" style="grid-column:1/-1">
    <div class="login-card">
      <div class="kick">Reset</div>
      <h1>Forgot password?</h1>
      <?php if ($sent): ?>
        <div class="ok-msg">If an account exists for that username or email, a reset link is on its way. Check your inbox (and spam folder).</div>
        <div class="form-foot"><a href="login.php">← Back to sign in</a></div>
      <?php else: ?>
        <p style="color:var(--muted);font-size:.92rem;margin-bottom:14px">Type the username or email on your account and we'll email you a single-use link to set a new password.</p>
        <form method="post" autocomplete="off">
          <?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>
          <label>Username or email</label>
          <input type="text" name="who" required autofocus>
          <button class="btn full" type="submit" style="margin-top:18px">Send reset link</button>
        </form>
        <div class="form-foot"><a href="login.php">← Back to sign in</a></div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body></html>
