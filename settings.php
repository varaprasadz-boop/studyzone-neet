<?php
/* ============================================================
   Admin — App settings.
   Phase 2: toggle public sign-up (default off / invite-only).
   POST handled before header.php per the page-lifecycle rule.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/mail.php';
$ACTIVE = ''; $PAGE = 'Settings';
require_permission('users.manage');
ensure_mail_tables();
ensure_phase2_m2();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $next = !empty($_POST['signup_open']) ? '1' : '0';
    $prev = setting_get('signup_open', '0');
    if ($prev !== $next) {
        setting_set('signup_open', $next);
        audit('settings.update', 'settings', null, ['signup_open' => $next]);
    }
    flash('Settings saved.');
    redirect('settings.php');
}

$signupOpen = setting_get('signup_open', '0') === '1';
$mailFromSet = defined('MAIL_FROM') && MAIL_FROM !== '';
$captchaSet  = defined('HCAPTCHA_SITE_KEY') && HCAPTCHA_SITE_KEY !== '';

require __DIR__.'/includes/header.php';
?>
<div class="phead"><h1><?php echo icon('settings','lg'); ?> Settings</h1>
  <p>App-wide toggles. More options arrive with later phases.</p></div>
<?php echo flash_render(); ?>

<form method="post" class="qcard">
  <?php echo csrf_field(); ?>
  <h3 style="font-family:var(--disp);margin-bottom:6px">Public sign-up</h3>
  <p class="hint" style="margin:0 0 12px">When off, <code>register.php</code> shows an "invite-only" notice. When on, anyone can sign up at <a href="register.php" target="_blank"><?php echo e(app_url('register.php')); ?></a>.</p>
  <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--panel2);border:1px solid var(--line);border-radius:var(--r-2)">
    <input type="checkbox" name="signup_open" value="1" <?php echo $signupOpen?'checked':''; ?>>
    <span><b>Allow public sign-up</b>
      <?php if (!$signupOpen): ?><span class="pill draft" style="margin-left:6px">off</span><?php else: ?><span class="pill pub" style="margin-left:6px">on</span><?php endif; ?>
    </span>
  </label>
  <?php if (!$mailFromSet): ?>
    <div class="note" style="margin-top:10px;border-color:var(--amber);color:var(--amber)">⚠ <b>MAIL_FROM is empty</b> — outbound email is in <b>log-only</b> mode (mail_log table). Set <code>MAIL_FROM</code> in <code>includes/config.php</code> to actually send. Sign-up will still work for testing but the verification email won't reach the user.</div>
  <?php endif; ?>
  <?php if (!$captchaSet): ?>
    <div class="note" style="margin-top:10px">hCaptcha is disabled (no <code>HCAPTCHA_SITE_KEY</code>). Public sign-up will work without it, but add keys before launching publicly to deter abuse.</div>
  <?php endif; ?>
  <div class="btnrow" style="margin-top:14px"><button class="btn" type="submit">Save</button>
    <a class="btn ghost" href="dashboard.php">Back</a></div>
</form>

<div class="sect" style="margin-top:26px"><h2 style="font-size:1rem;font-weight:600">Status</h2></div>
<div class="tbl-wrap"><table class="tbl">
  <tr><th>Capability</th><th>State</th></tr>
  <tr><td>Public sign-up</td><td><?php echo $signupOpen ? '<span class="pill pub">on</span>' : '<span class="pill draft">invite-only</span>'; ?></td></tr>
  <tr><td>Email sending</td><td><?php echo $mailFromSet ? '<span class="pill pub">live (' . e(MAIL_FROM) . ')</span>' : '<span class="pill draft">log-only</span>'; ?></td></tr>
  <tr><td>hCaptcha</td><td><?php echo $captchaSet ? '<span class="pill pub">configured</span>' : '<span class="pill">off</span>'; ?></td></tr>
  <tr><td>Tenancy</td><td><span class="pill">single tenant (id=1)</span></td></tr>
</table></div>
<?php require __DIR__.'/includes/footer.php'; ?>
