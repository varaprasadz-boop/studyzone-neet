<?php
/* ============================================================
   Phase 2 — Email verification endpoint.
   Consumes an email_verifications token, marks it used and (for
   signup tokens) flips users.status from 'pending' → 'active'.
   Used by public registration (M2) and the guardian-consent flow.
   Safe to hit logged-in or not.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/mail.php';
ensure_mail_tables();

$token = trim($_GET['token'] ?? '');
$ok = false; $err = ''; $u = null;

$row = $token !== '' ? q1(
    "SELECT ev.*, u.username, u.name, u.status
     FROM email_verifications ev JOIN users u ON u.id = ev.user_id
     WHERE ev.token=? AND ev.used_at IS NULL AND ev.expires_at > NOW()
     LIMIT 1", [$token]) : null;

if (!$row) {
    $err = 'This verification link is invalid or has expired.';
} else {
    db()->beginTransaction();
    try {
        db()->prepare("UPDATE email_verifications SET used_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        if ($row['kind'] === 'signup' && $row['status'] === 'pending') {
            db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([(int)$row['user_id']]);
        }
        if ($row['kind'] === 'guardian') {
            // Guardian double-opt-in: record consent + activate the under-18 student.
            db()->prepare("INSERT INTO user_consents (user_id, kind, ip, evidence)
                           VALUES (?, 'guardian', ?, ?)")
                ->execute([(int)$row['user_id'], $_SERVER['REMOTE_ADDR'] ?? null, $row['meta_json'] ?? null]);
            if ($row['status'] === 'pending') {
                db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([(int)$row['user_id']]);
            }
        }
        db()->commit();
        audit('email.verify', 'user', (int)$row['user_id'], ['kind' => $row['kind']]);
        $ok = true; $u = $row;
    } catch (Throwable $ex) {
        db()->rollBack();
        $err = 'Could not verify: ' . $ex->getMessage();
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify · <?php echo APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Spline+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/app.css">
</head><body>
<div class="login-shell">
  <main class="login-side" style="grid-column:1/-1">
    <div class="login-card">
      <div class="kick">Verification</div>
      <?php if ($ok): ?>
        <h1>You're verified!</h1>
        <div class="ok-msg">
          <?php if ($row['kind'] === 'guardian'): ?>
            Thanks — guardian consent recorded for <b><?php echo e($u['username']); ?></b>.
          <?php else: ?>
            Email confirmed for <b><?php echo e($u['username']); ?></b>. You can sign in now.
          <?php endif; ?>
        </div>
        <a class="btn full" href="login.php" style="margin-top:14px">Go to sign in</a>
      <?php else: ?>
        <h1>Hmm…</h1>
        <div class="err"><?php echo e($err); ?></div>
        <div class="form-foot"><a href="login.php">← Back to sign in</a></div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body></html>
