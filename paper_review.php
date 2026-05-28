<?php
/* ============================================================
   Phase 3 — Admin: review a paper's extracted questions.
   Runs AI extraction page-by-page (AJAX), then lets you edit
   classification, fix answers, delete junk, and publish.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/ai.php';
$ACTIVE = 'questionbank'; $PAGE = 'Review Paper';
require_admin();

$paperId = (int)($_GET['paper'] ?? $_POST['paper'] ?? 0);
$paper = q1("SELECT * FROM papers WHERE id=?", [$paperId]);
if (!$paper) { require __DIR__.'/includes/header.php'; echo '<div class="note">Paper not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'publish_all') {
        db()->prepare("UPDATE questions SET status='published' WHERE paper_id=? AND status='draft'")->execute([$paperId]);
        flash('All draft questions published.');
    } elseif ($a === 'unpublish_all') {
        db()->prepare("UPDATE questions SET status='draft' WHERE paper_id=?")->execute([$paperId]);
        flash('All questions moved back to draft.');
    } elseif ($a === 'delete_drafts') {
        db()->prepare("DELETE FROM questions WHERE paper_id=? AND status='draft'")->execute([$paperId]);
        flash('Draft questions cleared.');
    } elseif ($a === 'toggle_q') {
        $qid = (int)($_POST['qid'] ?? 0);
        db()->prepare("UPDATE questions SET status = IF(status='published','draft','published') WHERE id=? AND paper_id=?")->execute([$qid, $paperId]);
    } elseif ($a === 'delete_q') {
        $qid = (int)($_POST['qid'] ?? 0);
        db()->prepare("DELETE FROM questions WHERE id=? AND paper_id=?")->execute([$qid, $paperId]);
        flash('Question deleted.');
    } elseif ($a === 'delete_paper') {
        db()->prepare("DELETE FROM questions WHERE paper_id=?")->execute([$paperId]);
        db()->prepare("DELETE FROM papers WHERE id=?")->execute([$paperId]);
        $dir = __DIR__ . '/uploads/papers/' . $paperId;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
        flash('Paper deleted.');
        redirect('questionbank.php');
    }
    redirect('paper_review.php?paper=' . $paperId);
}

// page images on disk
$dir = __DIR__ . '/uploads/papers/' . $paperId;
$pages = [];
foreach (glob($dir . '/p*.*') ?: [] as $f) { $pages[] = basename($f); }
natsort($pages); $pages = array_values($pages);

$qs = qa("SELECT q.*, s.name AS subject, ch.name AS chapter
          FROM questions q
          LEFT JOIN subjects s ON s.id=q.subject_id
          LEFT JOIN chapters ch ON ch.id=q.chapter_id
          WHERE q.paper_id=? ORDER BY q.id", [$paperId]);
$nDraft = 0; $nPub = 0;
foreach ($qs as $r) { $r['status'] === 'published' ? $nPub++ : $nDraft++; }

require __DIR__.'/includes/header.php';
?>
<div class="crumbs"><a href="questionbank.php">Question Bank</a> › <span>Review</span></div>
<div class="phead"><h1><?php echo e($paper['exam_name']); ?></h1>
  <p><?php echo e(date('d M Y', strtotime($paper['exam_date']))); ?> · <?php echo count($pages); ?> pages · <?php echo count($qs); ?> questions (<?php echo $nPub; ?> published, <?php echo $nDraft; ?> draft)</p></div>
<?php echo flash_render(); ?>

<div class="sect"><h2>Pages</h2><p>Extraction reads each page and files questions as drafts. Re-extracting a page replaces its drafts.</p></div>
<div class="toolbar">
  <button class="btn green" type="button" id="extractAll" <?php echo ai_enabled()?'':'disabled'; ?>>⚡ Extract all pages</button>
  <a class="btn ghost sm" href="question_edit.php?paper=<?php echo $paperId; ?>">+ Add question manually</a>
</div>
<?php if (!ai_enabled()): ?><div class="note" style="margin-bottom:12px">AI extraction is off (no API key). Add questions manually instead.</div><?php endif; ?>
<div class="progress" id="prog" style="display:none"><i></i></div>
<div class="thumbs" id="pageStrip">
  <?php foreach ($pages as $pg): ?>
    <div style="text-align:center">
      <a href="api/file.php?paper=<?php echo $paperId; ?>&f=<?php echo e($pg); ?>" target="_blank">
        <img class="thumb" src="api/file.php?paper=<?php echo $paperId; ?>&f=<?php echo e($pg); ?>" alt="<?php echo e($pg); ?>"></a>
      <div class="status pill" data-page="<?php echo e($pg); ?>" style="margin-top:4px"><?php echo e($pg); ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="sect"><h2>Questions</h2></div>
<div class="toolbar">
  <form method="post" onsubmit="return confirm('Publish all draft questions for this paper?')"><?php echo csrf_field(); ?>
    <input type="hidden" name="paper" value="<?php echo $paperId; ?>"><input type="hidden" name="action" value="publish_all">
    <button class="btn" type="submit">Publish all drafts (<?php echo $nDraft; ?>)</button></form>
  <form method="post"><?php echo csrf_field(); ?>
    <input type="hidden" name="paper" value="<?php echo $paperId; ?>"><input type="hidden" name="action" value="unpublish_all">
    <button class="btn ghost sm" type="submit">Unpublish all</button></form>
  <form method="post" onsubmit="return confirm('Delete all DRAFT questions?')"><?php echo csrf_field(); ?>
    <input type="hidden" name="paper" value="<?php echo $paperId; ?>"><input type="hidden" name="action" value="delete_drafts">
    <button class="btn ghost sm" type="submit">Clear drafts</button></form>
  <span class="spacer"></span>
  <form method="post" onsubmit="return confirm('Delete the WHOLE paper, its pages and questions?')"><?php echo csrf_field(); ?>
    <input type="hidden" name="paper" value="<?php echo $paperId; ?>"><input type="hidden" name="action" value="delete_paper">
    <button class="btn danger sm" type="submit">Delete paper</button></form>
</div>

<?php if (!$qs): ?>
  <div class="note" id="emptyNote">No questions yet. <?php echo ai_enabled()?'Run “Extract all pages” above.':'Add them manually.'; ?></div>
<?php endif; ?>

<div class="list">
<?php foreach ($qs as $q):
  $opts = $q['options'] ? json_decode($q['options'], true) : [];
?>
  <div class="qcard" style="margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px">
      <div>
        <span class="pill <?php echo $q['status']==='published'?'pub':'draft'; ?>"><?php echo $q['status']; ?></span>
        <span class="pill <?php echo e($q['difficulty']); ?>"><?php echo e($q['difficulty']); ?></span>
        <span class="pill"><?php echo e($q['subject'] ?: 'unclassified'); ?><?php echo $q['chapter']?' · '.e($q['chapter']):''; ?></span>
        <span class="pill"><?php echo e($q['qtype']); ?></span>
      </div>
      <div class="btnrow" style="margin:0">
        <form method="post" style="display:inline"><?php echo csrf_field(); ?>
          <input type="hidden" name="paper" value="<?php echo $paperId; ?>"><input type="hidden" name="action" value="toggle_q"><input type="hidden" name="qid" value="<?php echo $q['id']; ?>">
          <button class="btn sm <?php echo $q['status']==='published'?'ghost':'green'; ?>" type="submit"><?php echo $q['status']==='published'?'Unpublish':'Publish'; ?></button></form>
        <a class="btn sm ghost" href="question_edit.php?id=<?php echo $q['id']; ?>">Edit</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this question?')"><?php echo csrf_field(); ?>
          <input type="hidden" name="paper" value="<?php echo $paperId; ?>"><input type="hidden" name="action" value="delete_q"><input type="hidden" name="qid" value="<?php echo $q['id']; ?>">
          <button class="btn sm danger" type="submit">✕</button></form>
      </div>
    </div>
    <div class="qtext" style="font-size:1rem"><?php echo e($q['stem']); ?></div>
    <?php if ($q['qtype']==='mcq'): foreach ($opts as $i=>$o): ?>
      <div class="opt <?php echo ((int)$q['correct_index']===$i)?'correct':''; ?>" style="cursor:default"><?php echo e($o); ?><?php echo ((int)$q['correct_index']===$i)?' <span class="mk">✓</span>':''; ?></div>
    <?php endforeach; else: ?>
      <div class="opt correct" style="cursor:default">Answer: <?php echo e($q['correct_value'] ?: '—'); ?></div>
    <?php endif; ?>
    <?php if ($q['explanation']): ?><div class="ex"><?php echo e($q['explanation']); ?></div><?php endif; ?>
    <?php if ($q['image_ref']): ?><div class="hint">⚠ relies on a diagram:</div><?php echo question_image_html($paperId, $q['image_ref']); ?><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<script>
(function(){
  var btn=document.getElementById('extractAll'); if(!btn)return;
  var CSRF=<?php echo json_encode(csrf_token()); ?>, PAPER=<?php echo (int)$paperId; ?>;
  var pages=<?php echo json_encode($pages); ?>;
  var prog=document.getElementById('prog'),bar=prog.querySelector('i');
  function st(pg){return document.querySelector('.status[data-page="'+pg+'"]');}
  function run(){
    if(!pages.length)return;
    btn.disabled=true;btn.textContent='Extracting…';prog.style.display='block';
    var done=0,total=0;
    function next(i){
      if(i>=pages.length){btn.textContent='✓ Done — reloading…';setTimeout(function(){location.href='paper_review.php?paper='+PAPER;},700);return;}
      var pg=pages[i],el=st(pg);el.className='status pill';el.innerHTML='<span class="spin"></span>';
      var fd=new FormData();fd.append('_csrf',CSRF);fd.append('paper',PAPER);fd.append('page',pg);
      fetch('api/paper_ai.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(j){
        done++;bar.style.width=Math.round(done/pages.length*100)+'%';
        if(j.ok){el.className='status pill pub';el.textContent='+'+j.added;total+=j.added;}
        else{el.className='status pill hard';el.textContent='✗';}
        next(i+1);
      }).catch(function(){done++;bar.style.width=Math.round(done/pages.length*100)+'%';el.className='status pill hard';el.textContent='✗';next(i+1);});
    }
    next(0);
  }
  btn.onclick=run;
  <?php if (isset($_GET['fresh']) && ai_enabled()): ?>run();<?php endif; ?>
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
