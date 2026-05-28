<?php
/* ============================================================
   Phase 3 — Admin: edit a single question, or add one manually.
   ?id=Q       edit existing
   ?paper=P    add a manual question to paper P
   (no args)   add a standalone manual question
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'questionbank'; $PAGE = 'Edit Question';
require_admin();

$id      = (int)($_GET['id'] ?? 0);
$paperId = (int)($_GET['paper'] ?? 0);
$q = $id ? q1("SELECT * FROM questions WHERE id=?", [$id]) : null;
if ($id && !$q) { require __DIR__.'/includes/header.php'; echo '<div class="note">Question not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }
if ($q) $paperId = (int)$q['paper_id'];

// canonical subject options (one per subject name)
$subjOpts = [];
foreach (array_column(qa("SELECT DISTINCT name FROM subjects ORDER BY name"), 'name') as $nm) {
    $sid = resolve_subject_id($nm);
    if ($sid) $subjOpts[$sid] = $nm;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $qtype = ($_POST['qtype'] ?? 'mcq') === 'numeric' ? 'numeric' : 'mcq';
    $stem  = trim($_POST['stem'] ?? '');
    $opts  = [];
    for ($i = 0; $i < 4; $i++) { $opts[] = trim($_POST['opt'.$i] ?? ''); }
    $opts  = array_values(array_filter($opts, fn($o) => $o !== ''));
    $ci    = isset($_POST['correct_index']) && $_POST['correct_index'] !== '' ? (int)$_POST['correct_index'] : null;
    $cv    = trim($_POST['correct_value'] ?? '');
    $diff  = in_array(($_POST['difficulty'] ?? ''), ['easy','medium','hard'], true) ? $_POST['difficulty'] : 'medium';
    $expl  = trim($_POST['explanation'] ?? '');
    $status= ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $subjId= (int)($_POST['subject_id'] ?? 0) ?: null;
    $chap  = trim($_POST['chapter'] ?? '');
    $chapId= ($subjId && $chap !== '') ? resolve_chapter_id($subjId, $chap) : null;

    if ($stem === '') { $err = 'Question text is required.'; }
    elseif ($qtype === 'mcq' && count($opts) < 2) { $err = 'Give at least two options for an MCQ.'; }
    elseif ($qtype === 'mcq' && ($ci === null || $ci < 0 || $ci >= count($opts))) { $err = 'Mark which option is correct.'; }
    elseif ($qtype === 'numeric' && $cv === '') { $err = 'Give the numeric answer.'; }

    if (!$err) {
        $optsJson = $qtype === 'mcq' ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
        if ($q) {
            db()->prepare("UPDATE questions SET subject_id=?, chapter_id=?, qtype=?, stem=?, options=?,
                           correct_index=?, correct_value=?, explanation=?, difficulty=?, status=? WHERE id=?")
                ->execute([$subjId, $chapId, $qtype, $stem, $optsJson,
                           ($qtype==='mcq'?$ci:null), ($qtype==='numeric'?$cv:null), $expl, $diff, $status, $id]);
            flash('Question updated.');
        } else {
            db()->prepare("INSERT INTO questions (paper_id, subject_id, chapter_id, qtype, stem, options,
                           correct_index, correct_value, explanation, difficulty, source, status)
                           VALUES (?,?,?,?,?,?,?,?,?,?, 'manual', ?)")
                ->execute([($paperId ?: null), $subjId, $chapId, $qtype, $stem, $optsJson,
                           ($qtype==='mcq'?$ci:null), ($qtype==='numeric'?$cv:null), $expl, $diff, $status]);
            flash('Question added.');
        }
        redirect($paperId ? 'paper_review.php?paper='.$paperId : 'questionbank.php');
    }
}

// current values
$cur = [
    'qtype' => $q['qtype'] ?? 'mcq',
    'stem'  => $q['stem'] ?? '',
    'opts'  => $q && $q['options'] ? json_decode($q['options'], true) : ['','','',''],
    'ci'    => $q['correct_index'] ?? null,
    'cv'    => $q['correct_value'] ?? '',
    'diff'  => $q['difficulty'] ?? 'medium',
    'expl'  => $q['explanation'] ?? '',
    'status'=> $q['status'] ?? 'draft',
    'subject_id' => $q['subject_id'] ?? 0,
    'chapter' => $q ? name_of('chapters', (int)$q['chapter_id']) : '',
];
$cur['opts'] = array_pad(array_slice((array)$cur['opts'], 0, 4), 4, '');

require __DIR__.'/includes/header.php';
?>
<div class="crumbs"><a href="questionbank.php">Question Bank</a> <?php if($paperId):?>› <a href="paper_review.php?paper=<?php echo $paperId;?>">Review</a><?php endif;?> › <span><?php echo $q?'Edit':'New'; ?></span></div>
<div class="phead"><h1><?php echo $q?'✏ Edit question':'＋ New question'; ?></h1></div>
<?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>

<form method="post" id="qform">
  <?php echo csrf_field(); ?>
  <div class="row2">
    <div class="field"><label>Subject</label>
      <select name="subject_id">
        <option value="">— unclassified —</option>
        <?php foreach ($subjOpts as $sid=>$nm): ?>
          <option value="<?php echo $sid; ?>" <?php echo ((int)$cur['subject_id']===$sid)?'selected':''; ?>><?php echo e($nm); ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label>Chapter</label><input type="text" name="chapter" value="<?php echo e($cur['chapter']); ?>" placeholder="e.g. Ray Optics"></div>
  </div>
  <div class="row2">
    <div class="field"><label>Type</label>
      <select name="qtype" id="qtype">
        <option value="mcq" <?php echo $cur['qtype']==='mcq'?'selected':''; ?>>MCQ (4 options)</option>
        <option value="numeric" <?php echo $cur['qtype']==='numeric'?'selected':''; ?>>Numeric / integer</option>
      </select></div>
    <div class="field"><label>Difficulty</label>
      <select name="difficulty">
        <?php foreach (['easy','medium','hard'] as $d): ?>
          <option value="<?php echo $d; ?>" <?php echo $cur['diff']===$d?'selected':''; ?>><?php echo ucfirst($d); ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <div class="field"><label>Question</label><textarea name="stem" rows="3" required><?php echo e($cur['stem']); ?></textarea></div>

  <div id="mcqBox" class="field">
    <label>Options <span class="hint">(select the correct one)</span></label>
    <?php for ($i=0;$i<4;$i++): ?>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <input type="radio" name="correct_index" value="<?php echo $i; ?>" <?php echo ((int)$cur['ci']===$i)?'checked':''; ?>>
        <input type="text" name="opt<?php echo $i; ?>" value="<?php echo e($cur['opts'][$i] ?? ''); ?>" placeholder="Option <?php echo chr(65+$i); ?>">
      </div>
    <?php endfor; ?>
  </div>

  <div id="numBox" class="field" style="display:none">
    <label>Numeric answer</label><input type="text" name="correct_value" value="<?php echo e($cur['cv']); ?>" placeholder="e.g. 9.8">
  </div>

  <div class="field"><label>Explanation <span class="hint">(shown after answering)</span></label><textarea name="explanation" rows="2"><?php echo e($cur['expl']); ?></textarea></div>
  <div class="field"><label>Status</label>
    <select name="status">
      <option value="draft" <?php echo $cur['status']==='draft'?'selected':''; ?>>Draft (hidden from student)</option>
      <option value="published" <?php echo $cur['status']==='published'?'selected':''; ?>>Published (visible)</option>
    </select></div>
  <div class="btnrow"><button class="btn" type="submit">Save</button>
    <a class="btn ghost" href="<?php echo $paperId?'paper_review.php?paper='.$paperId:'questionbank.php'; ?>">Cancel</a></div>
</form>
<script>
(function(){
  var t=document.getElementById('qtype'),m=document.getElementById('mcqBox'),n=document.getElementById('numBox');
  function sync(){var num=t.value==='numeric';m.style.display=num?'none':'block';n.style.display=num?'block':'none';}
  t.onchange=sync;sync();
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
