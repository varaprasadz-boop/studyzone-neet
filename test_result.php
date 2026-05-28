<?php
/* ============================================================
   Phase 4 — Test result + answer key.
   Visible to the student who took it, or to an admin.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'examzone'; $PAGE = 'Result';
require_login();
$uid = current_user()['id'];

$attemptId = (int)($_GET['attempt'] ?? 0);
$att = q1("SELECT a.*, t.name AS test_name, t.id AS test_id FROM attempts a JOIN tests t ON t.id=a.test_id WHERE a.id=?", [$attemptId]);
if (!$att || (!is_admin() && (int)$att['student_id'] !== $uid)) {
    require __DIR__.'/includes/header.php'; echo '<div class="note">Result not available.</div>'; require __DIR__.'/includes/footer.php'; exit;
}
if ($att['status'] !== 'completed') { redirect('test_attempt.php?test=' . $att['test_id']); }

$total = (int)$att['total'];
$max   = $total * NEET_CORRECT;
$acc   = ($att['correct_count'] + $att['wrong_count']) > 0
       ? round($att['correct_count'] / ($att['correct_count'] + $att['wrong_count']) * 100)
       : 0;

$rows = qa("SELECT aa.*, q.qtype, q.stem, q.options, q.correct_index, q.correct_value, q.explanation
            FROM attempt_answers aa JOIN questions q ON q.id=aa.question_id
            WHERE aa.attempt_id=? ORDER BY aa.id", [$attemptId]);

require __DIR__.'/includes/header.php';
?>
<div class="crumbs"><a href="examzone.php">Exam Zone</a> › <span>Result</span></div>
<div class="phead"><h1><?php echo e($att['test_name']); ?></h1>
  <p><?php echo e(date('d M Y, H:i', strtotime($att['started_at']))); ?></p></div>

<div class="scbig"><?php echo (int)$att['score']; ?> <span style="font-size:1.2rem;color:var(--muted)">/ <?php echo $max; ?></span></div>
<div class="statcards">
  <div class="statcard"><div class="n" style="color:var(--green)"><?php echo (int)$att['correct_count']; ?></div><div class="l">Correct (+<?php echo NEET_CORRECT; ?>)</div></div>
  <div class="statcard"><div class="n" style="color:var(--red)"><?php echo (int)$att['wrong_count']; ?></div><div class="l">Wrong (<?php echo NEET_WRONG; ?>)</div></div>
  <div class="statcard"><div class="n" style="color:var(--dim)"><?php echo (int)$att['skipped_count']; ?></div><div class="l">Skipped (0)</div></div>
  <div class="statcard"><div class="n"><?php echo $acc; ?>%</div><div class="l">Accuracy</div></div>
</div>
<div class="btnrow" style="margin-bottom:18px">
  <a class="btn" href="test_attempt.php?test=<?php echo $att['test_id']; ?>&fresh=1">Reattempt (reshuffled)</a>
  <a class="btn ghost" href="examzone.php">Back to tests</a>
</div>

<div class="sect"><h2>Answer key</h2></div>
<?php foreach ($rows as $i=>$r):
  $opts = json_decode($r['options'] ?: '[]', true);
  $shuf = json_decode($r['shuffled_options'] ?: '[]', true);
  $given = $r['given_index'];          // displayed index chosen (mcq)
  if ($r['qtype'] === 'mcq') {
      $answered = $given !== null && $given !== '';
      $isCorrect = (int)$r['is_correct'] === 1;
  } else {
      $answered = trim((string)$r['given_value']) !== '';
      $isCorrect = (int)$r['is_correct'] === 1;
  }
  $cls = !$answered ? 'skip' : ($isCorrect ? 'right' : 'wrong');
?>
  <div class="keyq <?php echo $cls; ?>">
    <div class="qno">Q<?php echo $i+1; ?> · <?php echo $cls==='skip'?'skipped':($isCorrect?'correct':'wrong'); ?></div>
    <div class="qtext"><?php echo e($r['stem']); ?></div>
    <?php if ($r['qtype']==='mcq'): foreach ($shuf as $pos=>$orig):
        $isAns = ((int)$r['correct_index'] === (int)$orig);
        $isPick = ($given !== null && (int)$given === $pos);
        $oc = $isAns ? 'correct' : ($isPick ? 'wrong' : '');
    ?>
      <div class="opt <?php echo $oc; ?>" style="cursor:default"><?php echo e($opts[$orig] ?? ''); ?>
        <?php if ($isAns): ?><span class="mk">✓ correct</span><?php elseif ($isPick): ?><span class="mk">your answer</span><?php endif; ?>
      </div>
    <?php endforeach; else: ?>
      <div class="ans">Your answer: <b><?php echo e($r['given_value'] ?: '—'); ?></b></div>
      <div class="ans">Correct answer: <b><?php echo e($r['correct_value']); ?></b></div>
    <?php endif; ?>
    <?php if ($r['explanation']): ?><div class="ex"><?php echo e($r['explanation']); ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
