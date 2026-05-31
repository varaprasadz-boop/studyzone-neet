<?php
/* ============================================================
   Admin — manage one chapter's study items: add / edit / delete a
   single Q&A item, or clear all. Bulk import is on study_upload.php.
   Duplicates are detected on the QUESTION text only.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'study'; $PAGE = 'Manage Items';
require_admin();
ensure_study_items();

$chapId = (int)($_GET['chapter'] ?? $_POST['chapter'] ?? 0);
$chap = q1("SELECT ch.*, s.name AS subject, s.id AS subject_id, s.class_id FROM chapters ch JOIN subjects s ON s.id=ch.subject_id WHERE ch.id=?", [$chapId]);
if (!$chap) { require __DIR__.'/includes/header.php'; echo '<div class="note">Chapter not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $topic = trim($_POST['topic'] ?? '');
        $sub   = trim($_POST['subtopic'] ?? '');
        $q     = trim($_POST['question'] ?? '');
        $exp   = trim($_POST['explanation'] ?? '');
        $img   = trim($_POST['image'] ?? '');
        if ($q === '') { flash('Question text is required.', 'err'); redirect('study_edit.php?chapter=' . $chapId); }
        $hash = question_hash($q);
        try {
            if ($action === 'add') {
                $n = q1("SELECT COALESCE(MAX(sort),0)+1 AS n FROM study_items WHERE chapter_id=?", [$chapId]);
                db()->prepare("INSERT INTO study_items (chapter_id, topic, subtopic, question, explanation, image, qhash, sort) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$chapId, $topic, $sub, $q, $exp, ($img ?: null), $hash, (int)$n['n']]);
                flash('Item added.');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                db()->prepare("UPDATE study_items SET topic=?, subtopic=?, question=?, explanation=?, image=?, qhash=? WHERE id=? AND chapter_id=?")
                    ->execute([$topic, $sub, $q, $exp, ($img ?: null), $hash, $id, $chapId]);
                flash('Item updated.');
            }
        } catch (PDOException $ex) {
            flash(strpos($ex->getMessage(), 'uniq_chap_q') !== false ? 'That question already exists in this chapter.' : 'Could not save item.', 'err');
        }
        redirect('study_edit.php?chapter=' . $chapId);
    }
    if ($action === 'delete') {
        db()->prepare("DELETE FROM study_items WHERE id=? AND chapter_id=?")->execute([(int)($_POST['id'] ?? 0), $chapId]);
        flash('Item deleted.');
        redirect('study_edit.php?chapter=' . $chapId);
    }
    if ($action === 'delete_all') {
        db()->prepare("DELETE FROM study_items WHERE chapter_id=?")->execute([$chapId]);
        flash('All items cleared.');
        redirect('study_edit.php?chapter=' . $chapId);
    }
    redirect('study_edit.php?chapter=' . $chapId);
}

$items = qa("SELECT * FROM study_items WHERE chapter_id=? ORDER BY sort, id", [$chapId]);
require __DIR__.'/includes/header.php';
?>
<div class="crumbs">
  <a href="study.php?subject=<?php echo $chap['subject_id']; ?>"><?php echo e($chap['subject']); ?></a> ›
  <a href="study_chapter.php?chapter=<?php echo $chapId; ?>"><?php echo e($chap['name']); ?></a> › <span>Manage</span>
</div>
<div class="phead"><h1><?php echo icon('edit','lg'); ?> Manage — <?php echo e($chap['name']); ?></h1>
  <p><?php echo count($items); ?> item<?php echo count($items)==1?'':'s'; ?>. Math goes in <code>$…$</code> (LaTeX).</p></div>
<?php echo flash_render(); ?>

<div class="toolbar">
  <a class="btn sm" href="study_upload.php?chapter=<?php echo $chapId; ?>"><?php echo icon('upload'); ?> Bulk upload (Excel/CSV)</a>
  <a class="btn sm ghost" href="study_chapter.php?chapter=<?php echo $chapId; ?>">View</a>
  <?php if ($items): ?>
  <span class="spacer"></span>
  <form method="post" onsubmit="return confirm('Delete ALL items in this chapter?')"><?php echo csrf_field(); ?>
    <input type="hidden" name="chapter" value="<?php echo $chapId; ?>"><input type="hidden" name="action" value="delete_all">
    <button class="btn sm danger" type="submit">Clear all</button></form>
  <?php endif; ?>
</div>

<div class="qcard">
  <h3 style="font-family:var(--disp);margin-bottom:10px">Add an item</h3>
  <form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="chapter" value="<?php echo $chapId; ?>"><input type="hidden" name="action" value="add">
    <div class="row2">
      <div class="field"><label>Topic</label><input type="text" name="topic" placeholder="e.g. Genetics"></div>
      <div class="field"><label>Sub-topic</label><input type="text" name="subtopic" placeholder="e.g. Mendel's laws"></div>
    </div>
    <div class="field"><label>Question</label><textarea name="question" rows="2" required></textarea></div>
    <div class="field"><label>Explanation / answer</label><textarea name="explanation" rows="3"></textarea></div>
    <div class="field"><label>Image <span class="hint">(URL, or a filename you uploaded via Bulk upload)</span></label><input type="text" name="image" placeholder="https://… or figure1.png"></div>
    <div class="btnrow"><button class="btn" type="submit">Add item</button></div>
  </form>
</div>

<?php if ($items): ?>
<div class="sect"><h2>Items</h2></div>
<div class="list">
<?php foreach ($items as $it): ?>
  <div class="row" style="display:block;cursor:default">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
      <div style="flex:1">
        <?php if ($it['topic'] || $it['subtopic']): ?><div class="hint" style="margin:0 0 4px"><?php echo e(trim($it['topic'].' · '.$it['subtopic'], ' ·')); ?></div><?php endif; ?>
        <h3 style="font-size:1.02rem"><?php echo e($it['question']); ?></h3>
        <?php if (trim((string)$it['explanation'])!==''): ?><p><?php echo nl2br(e($it['explanation'])); ?></p><?php endif; ?>
        <?php if ($it['image']): ?><div class="hint">🖼 <?php echo e($it['image']); ?></div><?php endif; ?>
      </div>
      <div class="btnrow" style="margin:0">
        <button class="btn sm ghost" type="button" onclick="document.getElementById('ed<?php echo $it['id']; ?>').style.display=(document.getElementById('ed<?php echo $it['id']; ?>').style.display==='block'?'none':'block')">Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this item?')"><?php echo csrf_field(); ?><input type="hidden" name="chapter" value="<?php echo $chapId; ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $it['id']; ?>"><button class="btn sm danger" type="submit">✕</button></form>
      </div>
    </div>
    <div id="ed<?php echo $it['id']; ?>" style="display:none;margin-top:12px;border-top:1px solid var(--line);padding-top:12px">
      <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="chapter" value="<?php echo $chapId; ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?php echo $it['id']; ?>">
        <div class="row2">
          <div class="field"><label>Topic</label><input type="text" name="topic" value="<?php echo e($it['topic']); ?>"></div>
          <div class="field"><label>Sub-topic</label><input type="text" name="subtopic" value="<?php echo e($it['subtopic']); ?>"></div>
        </div>
        <div class="field"><label>Question</label><textarea name="question" rows="2" required><?php echo e($it['question']); ?></textarea></div>
        <div class="field"><label>Explanation / answer</label><textarea name="explanation" rows="3"><?php echo e($it['explanation']); ?></textarea></div>
        <div class="field"><label>Image</label><input type="text" name="image" value="<?php echo e($it['image']); ?>"></div>
        <div class="btnrow"><button class="btn sm" type="submit">Save changes</button></div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
