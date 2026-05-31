<?php
/* ============================================================
   Phase 4 — Exam Zone.
   Admin: create tests (incl. full NEET mock) + see attempts.
   Student: attempt / reattempt tests, practice past mistakes,
            see past results.
   POST is handled BEFORE any output so redirects are safe.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'examzone'; $PAGE = 'Exam Zone';
require_login();
$admin = is_admin();
$uid   = current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($admin && $action === 'delete_test') {
        $tid = (int)($_POST['test'] ?? 0);
        db()->prepare("DELETE FROM test_questions WHERE test_id=?")->execute([$tid]);
        foreach (qa("SELECT id FROM attempts WHERE test_id=?", [$tid]) as $a) {
            db()->prepare("DELETE FROM attempt_answers WHERE attempt_id=?")->execute([$a['id']]);
        }
        db()->prepare("DELETE FROM attempts WHERE test_id=?")->execute([$tid]);
        db()->prepare("DELETE FROM tests WHERE id=?")->execute([$tid]);
        audit('test.delete', 'test', $tid);
        flash('Test deleted.');
        redirect('examzone.php');
    }

    if (!$admin && $action === 'practice') {
        $ids = qa("SELECT DISTINCT aa.question_id AS id
                   FROM attempt_answers aa
                   JOIN attempts a ON a.id=aa.attempt_id
                   JOIN questions q ON q.id=aa.question_id
                   WHERE a.student_id=? AND aa.is_correct=0
                     AND (aa.given_index IS NOT NULL OR (aa.given_value IS NOT NULL AND aa.given_value<>''))
                     AND q.status='published'
                   ORDER BY RAND() LIMIT 50", [$uid]);
        if (!$ids) { flash('No mistakes to practise yet — attempt a test first.', 'err'); redirect('examzone.php'); }
        $n = count($ids);
        db()->prepare("INSERT INTO tests (name, config, summary, duration_min, created_by) VALUES (?,?,?,?,?)")
            ->execute(['My mistakes — ' . date('d M'), json_encode(['kind' => 'practice']), "$n Qs · your past mistakes", 0, $uid]);
        $tid = (int)db()->lastInsertId();
        $ins = db()->prepare("INSERT INTO test_questions (test_id, question_id, sort) VALUES (?,?,?)");
        foreach ($ids as $i => $r) { $ins->execute([$tid, $r['id'], $i]); }
        redirect('test_attempt.php?test=' . $tid . '&fresh=1');
    }

    redirect('examzone.php');
}

$allTests = qa("SELECT * FROM tests ORDER BY id DESC");
$tests = [];
foreach ($allTests as $t) {
    $cfg = $t['config'] ? json_decode($t['config'], true) : [];
    if (($cfg['kind'] ?? '') === 'practice') continue;   // one-off student sets stay out of the list
    $tests[] = $t;
}

// count of practisable mistakes (student)
$mistakeCount = $admin ? 0 : qcount(
    "SELECT COUNT(DISTINCT aa.question_id)
     FROM attempt_answers aa JOIN attempts a ON a.id=aa.attempt_id JOIN questions q ON q.id=aa.question_id
     WHERE a.student_id=? AND aa.is_correct=0
       AND (aa.given_index IS NOT NULL OR (aa.given_value IS NOT NULL AND aa.given_value<>''))
       AND q.status='published'", [$uid]);

require __DIR__.'/includes/header.php';
?>
<div class="phead"><h1>📝 Exam Zone</h1>
<p><?php echo $admin?'Generate tests from the published question bank.':'Attempt tests with NEET marking (+4 / −1 / 0). Options reshuffle every attempt.'; ?></p></div>
<?php echo flash_render(); ?>

<div class="toolbar">
<?php if ($admin): ?>
  <a class="btn" href="test_new.php">+ Generate new test</a>
  <a class="btn ghost" href="test_new.php?mock=1">⚡ Full NEET mock</a>
<?php else: ?>
  <form method="post" style="display:inline"><?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="practice">
    <button class="btn green" type="submit" <?php echo $mistakeCount?'':'disabled'; ?>>🎯 Practice my mistakes (<?php echo $mistakeCount; ?>)</button>
  </form>
  <a class="btn ghost" href="study_review.php">🔁 Flashcard review</a>
<?php endif; ?>
</div>

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
          <?php if (!empty($myAtt)): ?>
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
