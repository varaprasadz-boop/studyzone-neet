<?php
/* ============================================================
   Phase 2+ — Admin: manage a subject's chapters (rename, reorder,
   delete). Deleting a chapter unlinks its bank questions (keeps the
   questions) and removes its study material / topics / SR cards.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'study'; $PAGE = 'Manage Chapters';
require_admin();

$subjectId = (int)($_GET['subject'] ?? $_POST['subject'] ?? 0);
$subj = q1("SELECT s.*, c.name AS class, y.name AS syllabus
            FROM subjects s JOIN classes c ON c.id=s.class_id JOIN syllabi y ON y.id=s.syllabus_id
            WHERE s.id=?", [$subjectId]);
if (!$subj) { require __DIR__.'/includes/header.php'; echo '<div class="note">Unknown subject.</div>'; require __DIR__.'/includes/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $cid    = (int)($_POST['chapter'] ?? 0);

    if ($action === 'rename') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') db()->prepare("UPDATE chapters SET name=? WHERE id=? AND subject_id=?")->execute([$name, $cid, $subjectId]);
    } elseif ($action === 'move_up' || $action === 'move_down') {
        $ids = array_map('intval', array_column(qa("SELECT id FROM chapters WHERE subject_id=? ORDER BY sort, id", [$subjectId]), 'id'));
        $pos = array_search($cid, $ids, true);
        if ($pos !== false) {
            $swap = $action === 'move_up' ? $pos - 1 : $pos + 1;
            if ($swap >= 0 && $swap < count($ids)) {
                $tmp = $ids[$pos]; $ids[$pos] = $ids[$swap]; $ids[$swap] = $tmp;
                $up = db()->prepare("UPDATE chapters SET sort=? WHERE id=?");
                foreach ($ids as $i => $id) $up->execute([$i, $id]);
            }
        }
    } elseif ($action === 'delete') {
        db()->prepare("DELETE FROM study_content WHERE chapter_id=?")->execute([$cid]);
        db()->prepare("DELETE FROM topics WHERE chapter_id=?")->execute([$cid]);
        db()->prepare("DELETE FROM flashcard_reviews WHERE chapter_id=?")->execute([$cid]);
        db()->prepare("UPDATE questions SET chapter_id=NULL WHERE chapter_id=?")->execute([$cid]);
        db()->prepare("DELETE FROM chapters WHERE id=? AND subject_id=?")->execute([$cid, $subjectId]);
        flash('Chapter deleted (its bank questions were kept, unlinked).');
    }
    redirect('chapters_manage.php?subject=' . $subjectId);
}

$chaps = qa("SELECT * FROM chapters WHERE subject_id=? ORDER BY sort, id", [$subjectId]);
require __DIR__.'/includes/header.php';
?>
<div class="crumbs">
  <a href="study.php?class=<?php echo $subj['class_id']; ?>&syllabus=<?php echo $subj['syllabus_id']; ?>&subject=<?php echo $subjectId; ?>"><?php echo e($subj['name']); ?></a> › <span>Manage chapters</span>
</div>
<div class="phead"><h1>Manage chapters — <?php echo e($subj['name']); ?></h1>
  <p><?php echo e($subj['class']).' · '.e($subj['syllabus']); ?></p></div>
<?php echo flash_render(); ?>

<?php if (!$chaps): ?>
  <div class="note">No chapters yet. <a href="study_generate.php?subject=<?php echo $subjectId; ?>">Create study material →</a></div>
<?php else: ?>
<div class="list">
<?php foreach ($chaps as $i => $ch):
  $has = chapter_has_content($ch);
  $nq  = qcount("SELECT COUNT(*) FROM questions WHERE chapter_id=?", [$ch['id']]);
?>
  <div class="row" style="display:block;cursor:default">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <form method="post" style="display:flex;gap:8px;flex:1;min-width:220px">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="subject" value="<?php echo $subjectId; ?>">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="chapter" value="<?php echo $ch['id']; ?>">
        <input type="text" name="name" value="<?php echo e($ch['name']); ?>" style="flex:1">
        <button class="btn sm ghost" type="submit">Rename</button>
      </form>
      <span class="pill <?php echo $has?'pub':''; ?>"><?php echo !empty($ch['hub_file'])?'hub':($has?'material':'empty'); ?></span>
      <span class="pill"><?php echo $nq; ?> Q</span>
      <div class="btnrow" style="margin:0">
        <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="subject" value="<?php echo $subjectId; ?>"><input type="hidden" name="action" value="move_up"><input type="hidden" name="chapter" value="<?php echo $ch['id']; ?>"><button class="btn sm ghost" type="submit" <?php echo $i===0?'disabled':''; ?>>↑</button></form>
        <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="subject" value="<?php echo $subjectId; ?>"><input type="hidden" name="action" value="move_down"><input type="hidden" name="chapter" value="<?php echo $ch['id']; ?>"><button class="btn sm ghost" type="submit" <?php echo $i===count($chaps)-1?'disabled':''; ?>>↓</button></form>
        <a class="btn sm ghost" href="study_chapter.php?chapter=<?php echo $ch['id']; ?>">View</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this chapter? Its study material is removed; bank questions are kept but unlinked.')"><?php echo csrf_field(); ?><input type="hidden" name="subject" value="<?php echo $subjectId; ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="chapter" value="<?php echo $ch['id']; ?>"><button class="btn sm danger" type="submit">✕</button></form>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
