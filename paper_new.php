<?php
/* ============================================================
   Phase 3 — Admin: add a new paper and upload its page images.
   After upload, jumps to paper_review.php which runs AI extraction
   page-by-page, then lets you review & publish.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/ai.php';
$ACTIVE = 'questionbank'; $PAGE = 'Add Paper';
require_admin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['exam_name'] ?? '');
    $date = trim($_POST['exam_date'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($name === '' || $date === '') {
        $err = 'Exam name and date are required.';
    } elseif (empty($_FILES['img']['name'][0])) {
        $err = 'Upload at least one page image.';
    } else {
        $count = count($_FILES['img']['name']);
        if ($count > 25) { $err = 'Maximum 25 pages per paper.'; }
    }

    if (!$err) {
        try {
            db()->prepare("INSERT INTO papers (exam_name, exam_date, note, uploaded_by) VALUES (?,?,?,?)")
                ->execute([$name, $date, ($note ?: null), current_user()['id']]);
        } catch (PDOException $ex) {
            $err = (strpos($ex->getMessage(), 'uniq_paper') !== false)
                 ? 'A paper with this exam name and date already exists.'
                 : 'Could not save paper.';
        }
    }

    if (!$err) {
        $paperId = (int)db()->lastInsertId();
        $dir = __DIR__ . '/uploads/papers/' . $paperId;
        @mkdir($dir, 0775, true);
        $allowed = ['jpg','jpeg','png','webp','gif'];
        $page = 0;
        foreach ($_FILES['img']['tmp_name'] as $i => $tmp) {
            if ($_FILES['img']['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($_FILES['img']['size'][$i] > 8 * 1024 * 1024) continue;
            $ext = strtolower(pathinfo($_FILES['img']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;
            $page++;
            move_uploaded_file($tmp, $dir . '/p' . $page . '.' . $ext);
        }
        if ($page === 0) {
            db()->prepare("DELETE FROM papers WHERE id=?")->execute([$paperId]);
            $err = 'No valid images were uploaded (use JPG/PNG/WebP under 8 MB).';
        } else {
            flash("Paper added with $page page(s). Extracting questions…");
            redirect('paper_review.php?paper=' . $paperId . '&fresh=1');
        }
    }
}

require __DIR__.'/includes/header.php';
?>
<div class="crumbs"><a href="questionbank.php">Question Bank</a> › <span>Add paper</span></div>
<div class="phead"><h1>🗂️ Add a paper</h1><p>Upload the question paper pages — the AI reads every question and sorts it by subject &amp; chapter.</p></div>
<?php echo flash_render(); ?>
<?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>
<?php if (!ai_enabled()): ?>
  <div class="note" style="margin-bottom:14px"><b>Note:</b> without an <code>ANTHROPIC_API_KEY</code> the pages upload but auto-extraction is skipped — you can still add questions by hand on the review screen.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <?php echo csrf_field(); ?>
  <div class="row2">
    <div class="field"><label>Exam name</label><input type="text" name="exam_name" required placeholder="NEET 2024 · Physics Set A"></div>
    <div class="field"><label>Exam date</label><input type="date" name="exam_date" required></div>
  </div>
  <div class="field"><label>Note <span class="hint">(optional)</span></label><input type="text" name="note" placeholder="e.g. retake set, or source"></div>
  <div class="field">
    <label>Page images <span class="hint">(up to 25 · JPG/PNG/WebP)</span></label>
    <label class="drop" id="drop">
      <input type="file" name="img[]" id="imgIn" accept="image/*" multiple>
      <span id="dropMsg">Tap to choose page photos / scans</span>
      <div class="thumbs" id="thumbs"></div>
    </label>
  </div>
  <div class="btnrow"><button class="btn" type="submit">Upload &amp; extract →</button>
    <a class="btn ghost" href="questionbank.php">Cancel</a></div>
</form>

<script>
(function(){
  var inp=document.getElementById('imgIn'),th=document.getElementById('thumbs'),msg=document.getElementById('dropMsg');
  inp.addEventListener('change',function(){
    th.innerHTML='';var n=inp.files.length;
    msg.textContent=n?(n+' page(s) selected'):'Tap to choose page photos / scans';
    if(n>25){msg.textContent='Too many — pick 25 or fewer ('+n+' chosen)';}
    Array.prototype.forEach.call(inp.files,function(f){var img=document.createElement('img');img.className='thumb';img.src=URL.createObjectURL(f);th.appendChild(img);});
  });
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
