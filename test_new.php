<?php
/* ============================================================
   Phase 4 — Admin: generate a test from the published question bank.
   Pick subject (+ chapters) + difficulty + count → draws matching
   published questions in random order into a new test.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'examzone'; $PAGE = 'New Test';
require_admin();

$subjOpts = [];
foreach (array_column(qa("SELECT DISTINCT name FROM subjects ORDER BY name"), 'name') as $nm) {
    $sid = resolve_subject_id($nm); if ($sid) $subjOpts[$sid] = $nm;
}
$selSubject = (int)($_GET['subject'] ?? $_POST['subject'] ?? 0);
$chapters = $selSubject ? qa("SELECT id, name FROM chapters WHERE subject_id=? ORDER BY name", [$selSubject]) : [];

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    $name   = trim($_POST['name'] ?? '');
    $count  = max(1, min(180, (int)($_POST['count'] ?? 20)));
    $dur    = max(0, (int)($_POST['duration'] ?? 0));
    $diffs  = array_values(array_intersect((array)($_POST['difficulty'] ?? []), ['easy','medium','hard']));
    $chs    = array_filter(array_map('intval', (array)($_POST['chapters'] ?? [])));

    $where = ["status='published'"]; $args = [];
    if ($selSubject) { $where[] = 'subject_id=?'; $args[] = $selSubject; }
    if ($chs)   { $where[] = 'chapter_id IN (' . implode(',', array_fill(0, count($chs), '?')) . ')'; array_push($args, ...$chs); }
    if ($diffs) { $where[] = 'difficulty IN (' . implode(',', array_fill(0, count($diffs), '?')) . ')'; array_push($args, ...$diffs); }

    $pool = qa("SELECT id FROM questions WHERE " . implode(' AND ', $where) . " ORDER BY RAND() LIMIT $count", $args);

    if ($name === '') { $err = 'Give the test a name.'; }
    elseif (!$pool)   { $err = 'No published questions match those filters. Adjust, or publish more questions first.'; }
    else {
        $picked = count($pool);
        $subjName = $selSubject ? ($subjOpts[$selSubject] ?? 'Mixed') : 'All subjects';
        $summary = "$picked Qs · $subjName" . ($diffs ? ' · ' . implode('/', $diffs) : '');
        $config  = json_encode(['subject'=>$selSubject,'chapters'=>array_values($chs),'difficulty'=>$diffs,'count'=>$count]);

        db()->prepare("INSERT INTO tests (name, config, summary, duration_min, created_by) VALUES (?,?,?,?,?)")
            ->execute([$name, $config, $summary, $dur, current_user()['id']]);
        $tid = (int)db()->lastInsertId();
        $ins = db()->prepare("INSERT INTO test_questions (test_id, question_id, sort) VALUES (?,?,?)");
        foreach ($pool as $i => $r) { $ins->execute([$tid, $r['id'], $i]); }

        flash("Test created with $picked questions.");
        redirect('examzone.php');
    }
}

// counts to help the admin size the test
$availTotal = qcount("SELECT COUNT(*) FROM questions WHERE status='published'" . ($selSubject?' AND subject_id=?':''), $selSubject?[$selSubject]:[]);

require __DIR__.'/includes/header.php';
?>
<div class="crumbs"><a href="examzone.php">Exam Zone</a> › <span>New test</span></div>
<div class="phead"><h1>＋ Generate test</h1><p>Draws random questions from the published bank. <b><?php echo $availTotal; ?></b> published question(s) available<?php echo $selSubject?' in '.e($subjOpts[$selSubject] ?? ''):''; ?>.</p></div>
<?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>

<form method="get" style="margin-bottom:4px">
  <label>Subject</label>
  <select name="subject" onchange="this.form.submit()">
    <option value="0">All subjects</option>
    <?php foreach ($subjOpts as $sid=>$nm): ?>
      <option value="<?php echo $sid; ?>" <?php echo $selSubject===$sid?'selected':''; ?>><?php echo e($nm); ?></option>
    <?php endforeach; ?>
  </select>
</form>

<form method="post">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="create">
  <input type="hidden" name="subject" value="<?php echo $selSubject; ?>">

  <div class="field"><label>Test name</label><input type="text" name="name" required placeholder="e.g. Physics — Optics Practice 1"></div>

  <?php if ($chapters): ?>
  <div class="field"><label>Chapters <span class="hint">(leave all unchecked for the whole subject)</span></label>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px">
    <?php foreach ($chapters as $c): ?>
      <label style="display:flex;align-items:center;gap:7px;font-weight:400"><input type="checkbox" name="chapters[]" value="<?php echo $c['id']; ?>"> <?php echo e($c['name']); ?></label>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="field"><label>Difficulty <span class="hint">(none = any)</span></label>
    <div class="btnrow" style="margin-top:0">
      <?php foreach (['easy','medium','hard'] as $d): ?>
        <label style="display:flex;align-items:center;gap:6px;font-weight:400"><input type="checkbox" name="difficulty[]" value="<?php echo $d; ?>"> <?php echo ucfirst($d); ?></label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="row2">
    <div class="field"><label>Number of questions</label><input type="text" name="count" value="20" inputmode="numeric"></div>
    <div class="field"><label>Duration (minutes) <span class="hint">(0 = untimed)</span></label><input type="text" name="duration" value="20" inputmode="numeric"></div>
  </div>

  <div class="btnrow"><button class="btn" type="submit">Create test →</button>
    <a class="btn ghost" href="examzone.php">Cancel</a></div>
</form>
<?php require __DIR__.'/includes/footer.php'; ?>
