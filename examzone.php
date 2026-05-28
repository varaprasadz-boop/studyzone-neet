<?php
/* ============================================================
   Phase 4 — Exam Zone.
   Admin: create tests + see them with summaries / attempt counts.
   Student: attempt / reattempt tests, see past results.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'examzone'; $PAGE = 'Exam Zone';
require __DIR__.'/includes/header.php';
$admin = is_admin();
$uid   = current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
    require_csrf();
    if (($_POST['action'] ?? '') === 'delete_test') {
        $tid = (int)($_POST['test'] ?? 0);
        db()->prepare("DELETE FROM test_questions WHERE test_id=?")->execute([$tid]);
        $attempts = qa("SELECT id FROM attempts WHERE test_id=?", [$tid]);
        foreach ($attempts as $a) db()->prepare("DELETE FROM attempt_answers WHERE attempt_id=?")->execute([$a['id']]);
        db()->prepare("DELETE FROM attempts WHERE test_id=?")->execute([$tid]);
        db()->prepare("DELETE FROM tests WHERE id=?")->execute([$tid]);
        flash('Test deleted.');
    }
    redirect('examzone.php');
}

$tests = qa("SELECT * FROM tests ORDER BY id DESC");
?>
<div class="phead"><h1>📝 Exam Zone</h1>
<p><?php echo $admin?'Generate tests from the published question bank.':'Attempt tests with NEET marking (+4 / −1 / 0). Options reshuffle every attempt.'; ?></p></div>
<?php echo flash_render(); ?>

<?php if ($admin): ?>
  <div class="toolbar"><a class="btn" href="test_new.php">+ Generate new test</a></div>
<?php endif; ?>

<?php if (!$tests): ?>
  <div class="note"><?php echo $admin?'No tests yet. Generate one from the question bank.':'No tests available yet — your tutor will add them.'; ?></div>
<?php endif; ?>

<div class="list">
<?php foreach ($tests as $t):
  $nq = qcount("SELECT COUNT(*) FROM test_questions WHERE test_id=?", [$t['id']]);
  $maxScore = $nq * NEET_CORRECT;
  if ($admin) {
      $nAtt = qcount("SELECT COUNT(*) FROM attempts WHERE test_id=? AND status='completed'", [$t['id']]);
  } else {
      $myAtt = qa("SELECT * FROM attempts WHERE test_id=? AND student_id=? AND status='completed' ORDER BY id DESC", [$t['id'], $uid]);
      $best  = q1("SELECT MAX(score) AS b FROM attempts WHERE test_id=? AND student_id=? AND status='completed'", [$t['id'], $uid]);
  }
?>
  <div class="row" style="cursor:default;display:block">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div>
        <h3><?php echo e($t['name']); ?></h3>
        <p><?php echo e($t['summary'] ?: ($nq.' questions')); ?><?php echo $t['duration_min']?' · '.$t['duration_min'].' min':''; ?> · max <?php echo $maxScore; ?></p>
      </div>
      <div class="btnrow" style="margin:0">
        <?php if ($admin): ?>
          <span class="pill"><?php echo $nAtt; ?> attempt<?php echo $nAtt==1?'':'s'; ?></span>
          <a class="btn sm ghost" href="test_attempt.php?test=<?php echo $t['id']; ?>&fresh=1">Preview</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this test and its attempts?')"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete_test"><input type="hidden" name="test" value="<?php echo $t['id']; ?>">
            <button class="btn sm danger" type="submit">✕</button></form>
        <?php else: ?>
          <?php if (!empty($best['b']) || $best['b']==='0' || isset($myAtt[0])): ?>
            <span class="pill pub">best <?php echo (int)$best['b']; ?>/<?php echo $maxScore; ?></span>
            <a class="btn sm" href="test_attempt.php?test=<?php echo $t['id']; ?>&fresh=1">Reattempt</a>
          <?php else: ?>
            <a class="btn sm green" href="test_attempt.php?test=<?php echo $t['id']; ?>">Attempt</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$admin && !empty($myAtt)): ?>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach (array_slice($myAtt, 0, 6) as $a): ?>
          <a class="pill" href="test_result.php?attempt=<?php echo $a['id']; ?>"><?php echo (int)$a['score']; ?>/<?php echo $maxScore; ?> · <?php echo e(date('d M', strtotime($a['started_at']))); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
