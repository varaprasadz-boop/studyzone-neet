<?php
/* ============================================================
   Admin self-diagnostic. Confirms the server, database, uploads
   folder and Anthropic API are all set up correctly — the buildable
   substitute for "click through it on Hostinger".
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/ai.php';
$ACTIVE = ''; $PAGE = 'Self-test';
require_admin();

$checks = [];
function chk(&$c, $label, $state, $detail = '') { $c[] = ['label' => $label, 'state' => $state, 'detail' => $detail]; }

// PHP
chk($checks, 'PHP version', version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'fail', PHP_VERSION);
foreach (['pdo_mysql','curl','mbstring','json'] as $ext) {
    chk($checks, "Extension: $ext", extension_loaded($ext) ? 'ok' : 'fail', extension_loaded($ext) ? 'loaded' : 'missing');
}

// DB + tables
$expected = ['users','settings','classes','syllabi','subjects','chapters','topics','study_content',
             'papers','questions','tests','test_questions','attempts','attempt_answers',
             'activity_log','study_sessions','flashcard_reviews'];
try {
    $have = array_map('strtolower', array_column(
        qa("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()"), 't'));
    chk($checks, 'Database connection', 'ok', 'connected');
    $missing = array_diff($expected, $have);
    chk($checks, 'Tables present', $missing ? 'fail' : 'ok',
        $missing ? ('missing: ' . implode(', ', $missing) . ' — re-run install.php') : (count($expected) . ' tables OK'));
} catch (Throwable $ex) {
    chk($checks, 'Database connection', 'fail', $ex->getMessage());
}

// uploads writable
$updir = __DIR__ . '/uploads';
chk($checks, 'uploads/ writable', (is_dir($updir) && is_writable($updir)) ? 'ok' : 'warn',
    is_dir($updir) ? (is_writable($updir) ? 'writable' : 'NOT writable — chmod 775') : 'folder missing — will be created on first paper');

// API key
chk($checks, 'Anthropic API key', ai_enabled() ? 'ok' : 'warn',
    ai_enabled() ? ('set · model ' . (defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : '?')) : 'not set — AI generation/extraction disabled');

// security reminders
if (file_exists(__DIR__ . '/install.php')) chk($checks, 'install.php removed', 'warn', 'still present — delete it after setup');
foreach ([['appa','appa@123'],['son','son@123']] as $d) {
    $row = q1("SELECT password_hash FROM users WHERE username=?", [$d[0]]);
    if ($row && password_verify($d[1], $row['password_hash'])) {
        chk($checks, "Password changed: {$d[0]}", 'warn', 'still the default — change it in Account');
    }
}

// optional live API ping
$ping = null;
if (isset($_GET['ping']) && ai_enabled()) {
    $r = ai_call([['role' => 'user', 'content' => 'Reply with the single word: OK']], '', 16);
    $ping = $r['ok'] ? ['ok', 'Claude replied: ' . trim($r['text'])] : ['fail', $r['error']];
    chk($checks, 'Live API call', $ping[0], $ping[1]);
}

require __DIR__.'/includes/header.php';
$icon = ['ok' => '✓', 'warn' => '⚠', 'fail' => '✕'];
$col  = ['ok' => 'var(--green)', 'warn' => 'var(--amber)', 'fail' => 'var(--red)'];
?>
<div class="phead"><h1>🩺 Self-test</h1><p>Environment, database and API checks.</p></div>
<div class="tbl-wrap"><table class="tbl">
  <tr><th></th><th>Check</th><th>Detail</th></tr>
  <?php foreach ($checks as $c): ?>
    <tr>
      <td style="color:<?php echo $col[$c['state']]; ?>;font-weight:700;font-size:1.1rem"><?php echo $icon[$c['state']]; ?></td>
      <td><?php echo e($c['label']); ?></td>
      <td class="hint" style="margin:0"><?php echo e($c['detail']); ?></td>
    </tr>
  <?php endforeach; ?>
</table></div>
<div class="btnrow" style="margin-top:14px">
  <?php if (ai_enabled()): ?><a class="btn" href="selftest.php?ping=1">Run live API ping</a><?php endif; ?>
  <a class="btn ghost" href="dashboard.php">Back</a>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
