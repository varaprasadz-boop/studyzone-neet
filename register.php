<?php
/* ============================================================
   Phase 2 M2 — Public registration.
   Behind setting 'signup_open' (default '0' = invite-only). When
   off, the page shows a "currently invite-only" notice. When on:
     - validate, captcha, rate-limit (≤3 / IP / hour)
     - create user with status='pending'
     - if under 18: email guardian a double-opt-in link (DPDP)
     - else: email the user a verification link
   Self-signups land on the only seeded plan ('free').
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/captcha.php';
ensure_mail_tables();
ensure_phase2_m2();

if (current_user()) { header('Location: dashboard.php'); exit; }

$signupOpen = setting_get('signup_open', '0') === '1';
$err = ''; $done = false; $guardianMode = false;
$values = ['email' => '', 'name' => '', 'dob' => '', 'class_id' => 0, 'guardian_email' => '', 'subs' => []];

if ($signupOpen && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $values['email']          = strtolower(trim($_POST['email'] ?? ''));
    $values['name']           = trim($_POST['name'] ?? '');
    $values['dob']            = trim($_POST['dob'] ?? '');
    $values['class_id']       = (int)($_POST['class_id'] ?? 0);
    $values['guardian_email'] = strtolower(trim($_POST['guardian_email'] ?? ''));
    $values['subs']           = array_map('intval', (array)($_POST['scope_subject'] ?? []));
    $pw     = $_POST['password'] ?? '';
    $cf     = $_POST['confirm'] ?? '';
    $terms  = !empty($_POST['terms']);
    $hToken = $_POST['h-captcha-response'] ?? '';

    $hits = qcount("SELECT COUNT(*) FROM signup_attempts WHERE ip=? AND created_at > (NOW() - INTERVAL 1 HOUR)", [$ip]);
    if ($hits >= 3) $err = 'Too many signups from this network — try again later.';
    elseif (!$terms)                                                $err = 'You must accept the terms to continue.';
    elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL))   $err = 'Enter a valid email.';
    elseif ($values['name'] === '')                                 $err = 'Enter your name.';
    elseif (strlen($pw) < 8)                                        $err = 'Password must be at least 8 characters.';
    elseif ($pw !== $cf)                                            $err = 'Passwords do not match.';
    elseif (!$values['dob'] || strtotime($values['dob']) === false) $err = 'Enter a valid date of birth.';
    elseif (!$values['class_id'])                                   $err = 'Pick a class.';
    elseif (!captcha_verify($hToken, $ip))                          $err = 'Captcha verification failed.';

    if (!$err) {
        $age = (int) ((time() - strtotime($values['dob'])) / (365.25 * 86400));
        $guardianMode = ($age < 18);
        if ($guardianMode) {
            if (!filter_var($values['guardian_email'], FILTER_VALIDATE_EMAIL))      $err = 'A guardian email is required for under-18 sign-ups.';
            elseif (strcasecmp($values['guardian_email'], $values['email']) === 0)  $err = 'The guardian email must differ from your own.';
        }
        if ($age < 6 || $age > 120) $err = $err ?: 'Please re-check your date of birth.';
    }

    if (!$err && q1("SELECT id FROM users WHERE email=?", [$values['email']])) {
        $err = 'An account with that email already exists.';
    }

    // record the attempt regardless (rate-limit input)
    db()->prepare("INSERT INTO signup_attempts (ip, email) VALUES (?,?)")
        ->execute([$ip, $values['email']]);

    if (!$err) {
        // derive a unique username from the local part of the email
        $base = preg_replace('/[^a-z0-9._-]/i', '', strstr($values['email'], '@', true)) ?: 'user';
        $username = $base; $i = 1;
        while (q1("SELECT id FROM users WHERE username=?", [$username])) { $username = $base . $i++; }

        db()->beginTransaction();
        try {
            db()->prepare("INSERT INTO users (role, username, password_hash, name, email, status, dob, must_change_password)
                           VALUES ('student',?,?,?,?, 'pending', ?, 0)")
                ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $values['name'], $values['email'], $values['dob']]);
            $uid = (int)db()->lastInsertId();
            db()->prepare("INSERT IGNORE INTO user_roles (user_id, role_id)
                           SELECT ?, id FROM roles WHERE code='student'")->execute([$uid]);
            db()->prepare("INSERT INTO user_scopes (user_id, scope_type, scope_id) VALUES (?,'class',?)")
                ->execute([$uid, $values['class_id']]);
            $insS = db()->prepare("INSERT INTO user_scopes (user_id, scope_type, scope_id) VALUES (?,'subject',?)");
            foreach ($values['subs'] as $sid) {
                if ($sid > 0) $insS->execute([$uid, $sid]);   // skip 0/negatives from a crafted POST
            }

            $kind = $guardianMode ? 'guardian' : 'signup';
            $tok  = gen_token();
            db()->prepare("INSERT INTO email_verifications (user_id, token, kind, expires_at, meta_json)
                           VALUES (?,?,?, NOW() + INTERVAL 7 DAY, ?)")
                ->execute([$uid, $tok, $kind, $guardianMode ? json_encode(['guardian_email' => $values['guardian_email']]) : null]);
            db()->commit();

            $link = app_url('verify.php?token=' . $tok);
            if ($guardianMode) {
                $html = render_html_email('Please approve a sign-up',
                    '<p>Hi,</p>'
                  . '<p><b>' . htmlspecialchars($values['name']) . '</b> (' . htmlspecialchars($values['email'])
                  . ') wants to sign up for ' . APP_NAME . '. Because they\'re under 18, India\'s DPDP rules require your consent first.</p>'
                  . '<p>Click the button to approve. The link works for 7 days. If you didn\'t expect this, ignore the email.</p>',
                    ['I approve this sign-up', $link]);
                send_email($values['guardian_email'], 'Approve ' . APP_NAME . ' sign-up for ' . $values['name'],
                    $html, "Approve here: $link", $uid);
            } else {
                $html = render_html_email('Confirm your email',
                    '<p>Hi ' . htmlspecialchars($values['name']) . ',</p>'
                  . '<p>Thanks for signing up for ' . APP_NAME . '. Click the button to confirm your email — the link works for 7 days.</p>',
                    ['Confirm email', $link]);
                send_email($values['email'], 'Confirm your ' . APP_NAME . ' email',
                    $html, "Confirm: $link", $uid);
            }
            audit('user.register', 'user', $uid, ['guardian' => $guardianMode]);
            $done = true;
        } catch (Throwable $ex) {
            db()->rollBack();
            audit('user.register.error', null, null, ['email' => $values['email'], 'err' => $ex->getMessage()]);
            $err = 'Could not create your account right now. Please try again in a few minutes.';
        }
    }
}

$allClasses = qa("SELECT * FROM classes ORDER BY sort");
$rowsS = qa("SELECT s.*, c.name AS class_name, y.sort AS ysort
             FROM subjects s JOIN classes c ON c.id=s.class_id JOIN syllabi y ON y.id=s.syllabus_id
             ORDER BY c.sort, y.sort, s.sort, s.id");
$seen = []; $allSubjects = [];
foreach ($rowsS as $r) { $k = $r['class_id'] . '|' . $r['name']; if (!isset($seen[$k])) { $seen[$k] = true; $allSubjects[] = $r; } }
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign up · <?php echo APP_NAME; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Spline+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/app.css">
</head><body>
<div class="login-shell">
  <main class="login-side" style="grid-column:1/-1;padding:30px 16px;align-items:flex-start">
    <div class="login-card" style="max-width:520px">
      <div class="kick">Sign up</div>
      <h1>Create your account</h1>

      <?php if (!$signupOpen): ?>
        <div class="note empty">
          <b>Sign-ups are invite-only right now</b>
          Ask your tutor for an invite — once you've got an account, sign in with the credentials they send you.
        </div>
        <a class="btn full" href="login.php">Go to sign in</a>
      <?php elseif ($done): ?>
        <div class="ok-msg">
          <?php if ($guardianMode): ?>
            <b>Almost there.</b> We've emailed your guardian (<?php echo e($values['guardian_email']); ?>) a consent link.
            Once they click it your account becomes active.
          <?php else: ?>
            <b>Almost there.</b> We've emailed <?php echo e($values['email']); ?> a confirmation link.
            Click it (check the spam folder!) and then sign in.
          <?php endif; ?>
        </div>
        <a class="btn full ghost" href="login.php" style="margin-top:14px">Back to sign in</a>
      <?php else: ?>
        <form method="post" autocomplete="off">
          <?php echo csrf_field(); ?>
          <?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>

          <label>Your name</label>
          <input type="text" name="name" value="<?php echo e($values['name']); ?>" required>

          <label>Email</label>
          <input type="email" name="email" value="<?php echo e($values['email']); ?>" required>

          <div class="row2">
            <div class="field"><label>Password</label><input type="password" name="password" required minlength="8"></div>
            <div class="field"><label>Confirm</label><input type="password" name="confirm" required minlength="8"></div>
          </div>

          <label>Date of birth</label>
          <input type="date" name="dob" value="<?php echo e($values['dob']); ?>" required>
          <div class="hint">Under 18? We'll email your guardian a one-click consent link before activating the account.</div>

          <label>Guardian's email <span class="hint">(required if you're under 18)</span></label>
          <input type="email" name="guardian_email" value="<?php echo e($values['guardian_email']); ?>">

          <label>Class</label>
          <div style="display:flex;gap:12px;flex-wrap:wrap">
            <?php foreach ($allClasses as $c): ?>
              <label style="display:flex;align-items:center;gap:6px">
                <input type="radio" name="class_id" value="<?php echo (int)$c['id']; ?>" <?php echo (int)$values['class_id']===(int)$c['id']?'checked':''; ?> required>
                <?php echo e($c['name']); ?>
              </label>
            <?php endforeach; ?>
          </div>

          <label style="margin-top:12px">Subjects <span class="hint">(pick the ones you want — leave all unchecked for everything)</span></label>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:6px">
            <?php foreach ($allSubjects as $s): ?>
              <label style="display:flex;align-items:center;gap:6px">
                <input type="checkbox" name="scope_subject[]" value="<?php echo (int)$s['id']; ?>" <?php echo in_array((int)$s['id'], $values['subs'], true)?'checked':''; ?>>
                <span><?php echo $s['icon'].' '.e($s['name']); ?> <span class="hint">(<?php echo e($s['class_name']); ?>)</span></span>
              </label>
            <?php endforeach; ?>
          </div>

          <label style="display:flex;align-items:flex-start;gap:8px;margin-top:14px;font-size:.85rem;color:var(--muted)">
            <input type="checkbox" name="terms" required style="margin-top:3px">
            <span>I agree to the Terms and the Privacy notice, and (for under-18s) confirm a guardian's consent.</span>
          </label>

          <?php $w = captcha_widget(); if ($w): ?><div style="margin:12px 0"><?php echo $w; ?></div><?php endif; ?>

          <button class="btn full" type="submit" style="margin-top:16px">Create account</button>
        </form>
        <div class="form-foot">Already have an account? <a href="login.php">Sign in</a></div>
      <?php endif; ?>
    </div>
  </main>
</div>
</body></html>
