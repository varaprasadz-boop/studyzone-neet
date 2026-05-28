<?php
/* ============================================================
   Phase 4 — Student attempts a test.
   - questions & options reshuffled per attempt (stored, reload-safe)
   - optional timer (auto-submits on expiry)
   - NEET marking applied on submit (+4 / −1 / 0)
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'examzone'; $PAGE = 'Attempt';
require_login();
$uid = current_user()['id'];

$testId = (int)($_GET['test'] ?? $_POST['test'] ?? 0);
$test = q1("SELECT * FROM tests WHERE id=?", [$testId]);
if (!$test) { require __DIR__.'/includes/header.php'; echo '<div class="note">Test not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }

/* -------- submit -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    require_csrf();
    $attemptId = (int)($_POST['attempt'] ?? 0);
    $att = q1("SELECT * FROM attempts WHERE id=? AND student_id=? AND test_id=?", [$attemptId, $uid, $testId]);
    if (!$att || $att['status'] === 'completed') { redirect('test_result.php?attempt=' . $attemptId); }

    $rows = qa("SELECT aa.*, q.qtype, q.options, q.correct_index, q.correct_value
                FROM attempt_answers aa JOIN questions q ON q.id=aa.question_id
                WHERE aa.attempt_id=?", [$attemptId]);
    $ansIn = $_POST['ans'] ?? [];   // answerId => displayed index
    $numIn = $_POST['num'] ?? [];   // answerId => typed value
    $correct = $wrong = $skipped = 0;
    $upd = db()->prepare("UPDATE attempt_answers SET given_index=?, given_value=?, is_correct=? WHERE id=?");

    foreach ($rows as $r) {
        $aid = $r['id'];
        $isCorrect = 0; $givenIdx = null; $givenVal = null;
        if ($r['qtype'] === 'mcq') {
            $shuf = json_decode($r['shuffled_options'] ?: '[]', true);
            if (isset($ansIn[$aid]) && $ansIn[$aid] !== '') {
                $givenIdx = (int)$ansIn[$aid];
                $origIdx  = $shuf[$givenIdx] ?? -1;
                $isCorrect = ($origIdx === (int)$r['correct_index']) ? 1 : 0;
                $isCorrect ? $correct++ : $wrong++;
            } else { $skipped++; }
        } else {
            $v = trim((string)($numIn[$aid] ?? ''));
            if ($v !== '') {
                $givenVal = $v;
                $exp = trim((string)$r['correct_value']);
                $match = (strcasecmp($v, $exp) === 0) ||
                         (is_numeric($v) && is_numeric($exp) && abs((float)$v - (float)$exp) < 1e-6);
                $isCorrect = $match ? 1 : 0;
                $isCorrect ? $correct++ : $wrong++;
            } else { $skipped++; }
        }
        $upd->execute([$givenIdx, $givenVal, $isCorrect, $aid]);
    }

    $score = $correct * NEET_CORRECT + $wrong * NEET_WRONG + $skipped * NEET_SKIP;
    db()->prepare("UPDATE attempts SET ended_at=NOW(), correct_count=?, wrong_count=?, skipped_count=?, score=?, status='completed' WHERE id=?")
        ->execute([$correct, $wrong, $skipped, $score, $attemptId]);
    redirect('test_result.php?attempt=' . $attemptId);
}

/* -------- find or create the attempt -------- */
$fresh = isset($_GET['fresh']);
$attempt = null;
if (!$fresh) {
    $attempt = q1("SELECT * FROM attempts WHERE test_id=? AND student_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1", [$testId, $uid]);
}
if (!$attempt) {
    $tq = qa("SELECT q.* FROM test_questions tq JOIN questions q ON q.id=tq.question_id WHERE tq.test_id=? ORDER BY tq.sort", [$testId]);
    if (!$tq) { require __DIR__.'/includes/header.php'; echo '<div class="note">This test has no questions.</div>'; require __DIR__.'/includes/footer.php'; exit; }
    shuffle($tq);
    db()->prepare("INSERT INTO attempts (test_id, student_id, total, status) VALUES (?,?,?, 'in_progress')")
        ->execute([$testId, $uid, count($tq)]);
    $attemptId = (int)db()->lastInsertId();
    $insA = db()->prepare("INSERT INTO attempt_answers (attempt_id, question_id, shuffled_options) VALUES (?,?,?)");
    foreach ($tq as $qq) {
        $shuf = [];
        if ($qq['qtype'] === 'mcq') {
            $opts = json_decode($qq['options'] ?: '[]', true);
            $shuf = range(0, max(0, count($opts) - 1));
            shuffle($shuf);
        }
        $insA->execute([$attemptId, $qq['id'], json_encode($shuf)]);
    }
    $attempt = q1("SELECT * FROM attempts WHERE id=?", [$attemptId]);
}
$attemptId = (int)$attempt['id'];

$qs = qa("SELECT aa.id AS aid, aa.shuffled_options, q.id AS qid, q.qtype, q.stem, q.options, q.image_ref, q.paper_id
          FROM attempt_answers aa JOIN questions q ON q.id=aa.question_id
          WHERE aa.attempt_id=? ORDER BY aa.id", [$attemptId]);

$dur = (int)$test['duration_min'];
$remain = $dur > 0 ? max(0, $dur * 60 - (time() - strtotime($attempt['started_at']))) : 0;

require __DIR__.'/includes/header.php';
?>
<div class="examtop">
  <div><b><?php echo e($test['name']); ?></b><div class="hint"><?php echo count($qs); ?> questions · +4 / −1 / 0</div></div>
  <span class="spacer"></span>
  <?php if ($dur > 0): ?><div class="timer" id="timer" data-remain="<?php echo $remain; ?>">--:--</div><?php endif; ?>
  <button class="btn" type="button" id="submitTop">Submit</button>
</div>

<div class="qgrid" id="qgrid" style="margin-bottom:16px">
  <?php foreach ($qs as $i=>$q): ?><button type="button" class="qdot" data-i="<?php echo $i; ?>"><?php echo $i+1; ?></button><?php endforeach; ?>
</div>

<form method="post" id="examForm">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="submit">
  <input type="hidden" name="test" value="<?php echo $testId; ?>">
  <input type="hidden" name="attempt" value="<?php echo $attemptId; ?>">

  <?php foreach ($qs as $i=>$q):
    $opts = json_decode($q['options'] ?: '[]', true);
    $shuf = json_decode($q['shuffled_options'] ?: '[]', true);
  ?>
    <div class="qcard qblock" id="q<?php echo $i; ?>" data-i="<?php echo $i; ?>">
      <div class="qno">Question <?php echo $i+1; ?> of <?php echo count($qs); ?></div>
      <div class="qtext"><?php echo e($q['stem']); ?></div>
      <?php if ($q['image_ref'] && $q['paper_id']): ?>
        <div class="hint">Refer to the page image: <a href="api/file.php?paper=<?php echo (int)$q['paper_id']; ?>&f=<?php echo e(basename($q['image_ref'])); ?>" target="_blank">open</a></div>
      <?php endif; ?>
      <?php if ($q['qtype']==='mcq'): foreach ($shuf as $pos=>$orig): ?>
        <label class="opt optbtn">
          <input type="radio" name="ans[<?php echo $q['aid']; ?>]" value="<?php echo $pos; ?>" style="margin-right:8px" class="ansradio" data-qi="<?php echo $i; ?>">
          <?php echo e($opts[$orig] ?? ''); ?>
        </label>
      <?php endforeach; else: ?>
        <input type="text" name="num[<?php echo $q['aid']; ?>]" class="ansnum" data-qi="<?php echo $i; ?>" placeholder="Type your answer" inputmode="decimal">
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="btnrow"><button class="btn green" type="submit" id="submitBtn">Submit test</button></div>
</form>

<script>
(function(){
  var form=document.getElementById('examForm');
  /* answered-state dots */
  function mark(i){var d=document.querySelector('.qdot[data-i="'+i+'"]');if(d)d.classList.add('answered');}
  document.querySelectorAll('.ansradio').forEach(function(r){r.addEventListener('change',function(){mark(r.getAttribute('data-qi'));});});
  document.querySelectorAll('.ansnum').forEach(function(n){n.addEventListener('input',function(){if(n.value.trim())mark(n.getAttribute('data-qi'));else{var d=document.querySelector('.qdot[data-i="'+n.getAttribute('data-qi')+'"]');if(d)d.classList.remove('answered');}});});
  document.querySelectorAll('.qdot').forEach(function(d){d.onclick=function(){var el=document.getElementById('q'+d.getAttribute('data-i'));if(el)el.scrollIntoView({behavior:'smooth',block:'center'});};});

  document.getElementById('submitTop').onclick=function(){ if(confirm('Submit the test now?')) form.submit(); };
  document.getElementById('submitBtn').addEventListener('click',function(e){ if(!confirm('Submit the test now?')){e.preventDefault();} });

  /* timer */
  var t=document.getElementById('timer');
  if(t){
    var remain=parseInt(t.getAttribute('data-remain'),10)||0;
    function tick(){
      var m=Math.floor(remain/60),s=remain%60;
      t.textContent=(m<10?'0':'')+m+':'+(s<10?'0':'')+s;
      if(remain<=30)t.classList.add('low');
      if(remain<=0){t.textContent='00:00';form.submit();return;}
      remain--;setTimeout(tick,1000);
    }
    tick();
  }
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
