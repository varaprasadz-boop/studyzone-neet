<?php
/* ============================================================
   Study Material — browse Class → Subject → Chapters.
   (Syllabus step removed; subjects are shown one per name, mapped to
   the canonical NCERT row.) Each chapter holds bulk-uploaded Q&A
   items. Admin can add / rename / reorder / delete chapters here and
   manage each chapter's items / bulk upload from the chapter screen.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'study'; $PAGE = 'Study Material';
require_login();
ensure_study_items();
$admin = is_admin();

$classId = (int)($_GET['class'] ?? 0);
$subjId  = (int)($_GET['subject'] ?? $_POST['subject'] ?? 0);

$subj = $subjId ? q1("SELECT * FROM subjects WHERE id=?", [$subjId]) : null;
if ($subj) $classId = (int)$subj['class_id'];

/* ---- admin chapter actions (before any output) ---- */
if ($admin && $subj && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $cid    = (int)($_POST['chapter'] ?? 0);

    if ($action === 'add_chapter') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '' && !q1("SELECT id FROM chapters WHERE subject_id=? AND name=?", [$subjId, $name])) {
            $n = q1("SELECT COALESCE(MAX(sort),0)+1 AS n FROM chapters WHERE subject_id=?", [$subjId]);
            db()->prepare("INSERT INTO chapters (subject_id, name, sort) VALUES (?,?,?)")->execute([$subjId, $name, (int)$n['n']]);
            flash('Chapter added.');
        }
    } elseif ($action === 'rename_chapter') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') db()->prepare("UPDATE chapters SET name=? WHERE id=? AND subject_id=?")->execute([$name, $cid, $subjId]);
    } elseif ($action === 'move_up' || $action === 'move_down') {
        $ids = array_map('intval', array_column(qa("SELECT id FROM chapters WHERE subject_id=? ORDER BY sort, id", [$subjId]), 'id'));
        $pos = array_search($cid, $ids, true);
        if ($pos !== false) {
            $swap = $action === 'move_up' ? $pos - 1 : $pos + 1;
            if ($swap >= 0 && $swap < count($ids)) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                $up = db()->prepare("UPDATE chapters SET sort=? WHERE id=?");
                foreach ($ids as $i => $id) $up->execute([$i, $id]);
            }
        }
    } elseif ($action === 'del_chapter') {
        db()->prepare("DELETE FROM study_items WHERE chapter_id=?")->execute([$cid]);
        db()->prepare("DELETE FROM topics WHERE chapter_id=?")->execute([$cid]);
        db()->prepare("DELETE FROM flashcard_reviews WHERE chapter_id=?")->execute([$cid]);
        db()->prepare("UPDATE questions SET chapter_id=NULL WHERE chapter_id=?")->execute([$cid]);
        db()->prepare("DELETE FROM chapters WHERE id=? AND subject_id=?")->execute([$cid, $subjId]);
        flash('Chapter deleted.');
    }
    redirect('study.php?subject=' . $subjId);
}

require __DIR__.'/includes/header.php';
?>
<div class="crumbs">
  <a href="study.php">Study</a>
  <?php if ($classId): ?> › <a href="study.php?class=<?php echo $classId; ?>"><?php echo e(name_of('classes', $classId)); ?></a><?php endif; ?>
  <?php if ($subj): ?> › <span><?php echo e($subj['name']); ?></span><?php endif; ?>
</div>
<?php echo flash_render(); ?>

<?php if (!$classId): /* ---------- pick class ---------- */ ?>
  <div class="phead"><h1>📘 Study Material</h1><p>Choose a class.</p></div>
  <div class="grid">
  <?php foreach (qa("SELECT * FROM classes ORDER BY sort") as $c): ?>
    <a class="tile" href="study.php?class=<?php echo $c['id']; ?>"><span class="ic">🎓</span><h3><?php echo e($c['name']); ?></h3></a>
  <?php endforeach; ?>
  </div>

<?php elseif (!$subj): /* ---------- pick subject ---------- */ ?>
  <div class="phead"><h1><?php echo e(name_of('classes', $classId)); ?></h1><p>Choose a subject.</p></div>
  <div class="grid">
  <?php foreach (study_subjects_for_class($classId) as $sub):
        $n = qcount("SELECT COUNT(*) FROM chapters WHERE subject_id=?", [$sub['id']]); ?>
    <a class="tile" href="study.php?subject=<?php echo $sub['id']; ?>" style="--tc:<?php echo e($sub['color']); ?>">
      <span class="ic"><?php echo $sub['icon']; ?></span><h3><?php echo e($sub['name']); ?></h3>
      <p><?php echo $n; ?> chapter<?php echo $n==1?'':'s'; ?></p></a>
  <?php endforeach; ?>
  </div>

<?php else: /* ---------- chapter list for a subject ---------- */
  $chs = qa("SELECT * FROM chapters WHERE subject_id=? ORDER BY sort, id", [$subjId]);
?>
  <div class="phead"><h1><?php echo $subj['icon'].' '.e($subj['name']); ?></h1>
    <p><?php echo e(name_of('classes', $classId)); ?> · <?php echo count($chs); ?> chapter<?php echo count($chs)==1?'':'s'; ?></p></div>

  <?php if ($admin): ?>
    <form method="post" class="toolbar" style="gap:8px">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="subject" value="<?php echo $subjId; ?>">
      <input type="hidden" name="action" value="add_chapter">
      <input type="text" name="name" placeholder="New chapter name" required style="flex:1;min-width:200px">
      <button class="btn" type="submit">+ Add chapter</button>
    </form>
  <?php endif; ?>

  <?php if (!$chs): ?>
    <div class="note"><?php echo $admin ? 'No chapters yet — add one above.' : 'No chapters yet.'; ?></div>
  <?php else: ?>
    <div class="list">
    <?php foreach ($chs as $i => $ch):
      $isHub = !empty($ch['hub_file']);
      $items = $isHub ? 0 : chapter_item_count($ch['id']);
      $hasContent = $isHub || $items > 0;
    ?>
      <div class="row" style="display:block;cursor:default;border-left-color:<?php echo e($subj['color']); ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <div style="flex:1;min-width:180px">
            <h3><?php echo e($ch['name']); ?></h3>
            <p><?php echo $isHub ? 'Interactive hub' : ($items . ' item' . ($items==1?'':'s')); ?></p>
          </div>
          <div class="btnrow" style="margin:0">
            <?php if ($isHub): ?>
              <a class="btn sm" href="assets/hub/<?php echo e($ch['hub_file']); ?>" target="_blank">Open hub</a>
            <?php elseif ($hasContent): ?>
              <a class="btn sm" href="study_chapter.php?chapter=<?php echo $ch['id']; ?>">View</a>
            <?php elseif (!$admin): ?>
              <span class="pill">soon</span>
            <?php endif; ?>
            <?php if ($admin): ?>
              <a class="btn sm ghost" href="study_upload.php?chapter=<?php echo $ch['id']; ?>">⬆ Bulk upload</a>
              <a class="btn sm ghost" href="study_edit.php?chapter=<?php echo $ch['id']; ?>">Manage</a>
              <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="subject" value="<?php echo $subjId; ?>"><input type="hidden" name="action" value="move_up"><input type="hidden" name="chapter" value="<?php echo $ch['id']; ?>"><button class="btn sm ghost" type="submit" <?php echo $i===0?'disabled':''; ?>>↑</button></form>
              <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="subject" value="<?php echo $subjId; ?>"><input type="hidden" name="action" value="move_down"><input type="hidden" name="chapter" value="<?php echo $ch['id']; ?>"><button class="btn sm ghost" type="submit" <?php echo $i===count($chs)-1?'disabled':''; ?>>↓</button></form>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this chapter and its items? (Bank questions are kept, unlinked.)')"><?php echo csrf_field(); ?><input type="hidden" name="subject" value="<?php echo $subjId; ?>"><input type="hidden" name="action" value="del_chapter"><input type="hidden" name="chapter" value="<?php echo $ch['id']; ?>"><button class="btn sm danger" type="submit">✕</button></form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
