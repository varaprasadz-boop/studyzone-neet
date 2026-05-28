<?php
/* ============================================================
   Phase 3 — Question Bank.
   Admin: manage papers (add / review / publish) + browse.
   Student: browse published questions by paper or subject/chapter,
            each with the correct answer and explanation.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'questionbank'; $PAGE = 'Question Bank';
require __DIR__.'/includes/header.php';
$admin = is_admin();

$fSubject = (int)($_GET['subject'] ?? 0);
$fChapter = (int)($_GET['chapter'] ?? 0);
$fPaper   = (int)($_GET['paper'] ?? 0);
$fQ       = trim($_GET['q'] ?? '');

// canonical subjects for the filter
$subjOpts = [];
foreach (array_column(qa("SELECT DISTINCT name FROM subjects ORDER BY name"), 'name') as $nm) {
    $sid = resolve_subject_id($nm); if ($sid) $subjOpts[$sid] = $nm;
}
$chapters = $fSubject ? qa("SELECT id, name FROM chapters WHERE subject_id=? ORDER BY name", [$fSubject]) : [];
$papers   = qa("SELECT * FROM papers ORDER BY exam_date DESC, id DESC");
?>
<div class="phead"><h1>🗂️ Question Bank</h1>
<p><?php echo $admin?'Upload papers, extract questions, publish for the student.':'Past-paper questions with answers & explanations.'; ?></p></div>
<?php echo flash_render(); ?>

<?php if ($admin): ?>
  <div class="toolbar">
    <a class="btn" href="paper_new.php">+ Add new paper (upload images)</a>
    <a class="btn ghost" href="question_edit.php">+ Add question manually</a>
  </div>
  <?php if ($papers): ?>
  <div class="sect"><h2>Papers</h2></div>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>Exam</th><th>Date</th><th>Published</th><th>Draft</th><th></th></tr>
    <?php foreach ($papers as $p):
      $pub = qcount("SELECT COUNT(*) FROM questions WHERE paper_id=? AND status='published'", [$p['id']]);
      $drf = qcount("SELECT COUNT(*) FROM questions WHERE paper_id=? AND status='draft'", [$p['id']]); ?>
      <tr>
        <td><a href="paper_review.php?paper=<?php echo $p['id']; ?>"><?php echo e($p['exam_name']); ?></a><?php echo $p['note']?'<br><span class="hint">'.e($p['note']).'</span>':''; ?></td>
        <td><?php echo e(date('d M Y', strtotime($p['exam_date']))); ?></td>
        <td><?php echo $pub; ?></td>
        <td><?php echo $drf? '<span class="pill draft">'.$drf.'</span>':'0'; ?></td>
        <td><a href="paper_review.php?paper=<?php echo $p['id']; ?>">Review →</a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php else: ?>
    <div class="note">No papers yet. Add one to start building the bank.</div>
  <?php endif; ?>
<?php endif; ?>

<div class="sect"><h2>Browse questions</h2><p>Filter by subject, chapter or paper.</p></div>
<form method="get" class="toolbar">
  <select name="subject" onchange="this.form.submit()">
    <option value="0">All subjects</option>
    <?php foreach ($subjOpts as $sid=>$nm): ?>
      <option value="<?php echo $sid; ?>" <?php echo $fSubject===$sid?'selected':''; ?>><?php echo e($nm); ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($chapters): ?>
  <select name="chapter" onchange="this.form.submit()">
    <option value="0">All chapters</option>
    <?php foreach ($chapters as $c): ?>
      <option value="<?php echo $c['id']; ?>" <?php echo $fChapter===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="paper" onchange="this.form.submit()">
    <option value="0">All papers</option>
    <?php foreach ($papers as $p): ?>
      <option value="<?php echo $p['id']; ?>" <?php echo $fPaper===(int)$p['id']?'selected':''; ?>><?php echo e($p['exam_name']); ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="q" value="<?php echo e($fQ); ?>" placeholder="Search text…" style="max-width:200px">
  <button class="btn sm" type="submit">Search</button>
  <?php if ($fSubject||$fChapter||$fPaper||$fQ!==''): ?><a class="btn ghost sm" href="questionbank.php">Reset</a><?php endif; ?>
</form>

<?php
$where = ["q.status='published'"]; $args = [];
if ($fSubject) { $where[] = 'q.subject_id=?'; $args[] = $fSubject; }
if ($fChapter) { $where[] = 'q.chapter_id=?'; $args[] = $fChapter; }
if ($fPaper)   { $where[] = 'q.paper_id=?';   $args[] = $fPaper; }
if ($fQ!=='')  { $where[] = 'q.stem LIKE ?';  $args[] = '%' . $fQ . '%'; }
$sql = "SELECT q.*, ch.name AS chapter, s.name AS subject, p.exam_name
        FROM questions q
        LEFT JOIN chapters ch ON ch.id=q.chapter_id
        LEFT JOIN subjects s ON s.id=q.subject_id
        LEFT JOIN papers p ON p.id=q.paper_id
        WHERE " . implode(' AND ', $where) . " ORDER BY q.id DESC";
$rows = qa($sql, $args);
?>
<div class="hint" style="margin-bottom:10px"><?php echo count($rows); ?> published question(s)</div>
<?php if (!$rows): ?>
  <div class="note">No published questions match. <?php echo $admin?'Publish some from a paper review.':'Check back soon.'; ?></div>
<?php endif; ?>

<div class="list">
<?php foreach ($rows as $i=>$q):
  $opts = $q['options'] ? json_decode($q['options'], true) : [];
?>
  <div class="qcard" style="margin-bottom:12px">
    <div class="qno"><?php echo e($q['subject'] ?: 'General'); ?><?php echo $q['chapter']?' · '.e($q['chapter']):''; ?><?php echo $q['exam_name']?' · '.e($q['exam_name']):''; ?></div>
    <div class="qtext" style="font-size:1.02rem"><?php echo e($q['stem']); ?></div>
    <?php echo question_image_html($q['paper_id'], $q['image_ref']); ?>
    <?php if ($q['qtype']==='mcq'): foreach ($opts as $oi=>$o): ?>
      <div class="opt ans-opt" data-correct="<?php echo ((int)$q['correct_index']===$oi)?1:0; ?>" style="cursor:default"><?php echo e($o); ?></div>
    <?php endforeach; else: ?>
      <div class="opt ans-num" data-answer="<?php echo e($q['correct_value']); ?>" style="cursor:default">Numeric answer hidden</div>
    <?php endif; ?>
    <?php if ($q['image_ref'] && $admin): ?><div class="hint">⚠ diagram-based</div><?php endif; ?>
    <div class="btnrow"><button class="btn sm ghost reveal" type="button">Show answer</button></div>
    <div class="ex" style="display:none"><?php echo $q['explanation']? e($q['explanation']) : 'No explanation provided.'; ?></div>
  </div>
<?php endforeach; ?>
</div>

<script>
document.querySelectorAll('.qcard').forEach(function(card){
  var btn=card.querySelector('.reveal'); if(!btn)return;
  btn.onclick=function(){
    card.querySelectorAll('.ans-opt').forEach(function(o){ if(o.getAttribute('data-correct')==='1') o.classList.add('correct'); });
    var num=card.querySelector('.ans-num'); if(num){num.classList.add('correct');num.textContent='Answer: '+(num.getAttribute('data-answer')||'—');}
    var ex=card.querySelector('.ex'); if(ex)ex.style.display='block';
    btn.style.display='none';
  };
});
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
