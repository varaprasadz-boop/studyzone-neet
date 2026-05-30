<?php
/* ============================================================
   Study chapter viewer — shows the chapter's Q&A study items
   grouped by Topic → Sub-topic. Items are bulk-uploaded (XLSX/CSV)
   or added by hand from the Manage screen. Math renders via KaTeX.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'study'; $PAGE = 'Study';
require_login();
ensure_study_items();

$chapId = (int)($_GET['chapter'] ?? 0);
$chap = q1("SELECT ch.*, s.name AS subject, s.color, s.icon, s.id AS subject_id, s.class_id
            FROM chapters ch JOIN subjects s ON s.id=ch.subject_id WHERE ch.id=?", [$chapId]);
if (!$chap) { require __DIR__.'/includes/header.php'; echo '<div class="note">Chapter not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }

// hub chapters open as the standalone interactive file
if (!empty($chap['hub_file'])) { redirect('assets/hub/' . rawurlencode($chap['hub_file'])); }

$admin = is_admin();
$color = $chap['color'];
$items = qa("SELECT * FROM study_items WHERE chapter_id=? ORDER BY sort, id", [$chapId]);

// group by topic → subtopic, preserving first-seen order
$groups = [];
foreach ($items as $it) {
    $t = $it['topic'] !== '' ? $it['topic'] : 'General';
    $s = $it['subtopic'] !== '' ? $it['subtopic'] : '';
    $groups[$t][$s][] = $it;
}

$TRACK_SUBJECT = (int)$chap['subject_id'];
$TRACK_CHAPTER = (int)$chap['id'];
require __DIR__.'/includes/header.php';
?>
<div class="crumbs">
  <a href="study.php?class=<?php echo $chap['class_id']; ?>"><?php echo e(name_of('classes', $chap['class_id'])); ?></a> ›
  <a href="study.php?subject=<?php echo $chap['subject_id']; ?>"><?php echo e($chap['subject']); ?></a> ›
  <span><?php echo e($chap['name']); ?></span>
</div>
<div class="phead"><h1><?php echo e($chap['name']); ?></h1>
  <p><?php echo count($items); ?> study item<?php echo count($items)==1?'':'s'; ?></p></div>

<?php if ($admin): ?>
  <div class="toolbar">
    <a class="btn sm ghost" href="study_edit.php?chapter=<?php echo $chapId; ?>">✏ Manage items</a>
    <a class="btn sm ghost" href="study_upload.php?chapter=<?php echo $chapId; ?>">⬆ Bulk upload</a>
    <a class="btn sm ghost" href="study_review.php">🔁 Flashcard review</a>
  </div>
<?php endif; ?>

<?php if (!$items): ?>
  <div class="note">No study material yet.
    <?php if ($admin): ?> <a href="study_upload.php?chapter=<?php echo $chapId; ?>">Bulk upload →</a> or <a href="study_edit.php?chapter=<?php echo $chapId; ?>">add by hand →</a><?php endif; ?>
  </div>
<?php else: ?>
  <?php foreach ($groups as $topic => $subs): ?>
    <div class="grouphdr" style="color:<?php echo e($color); ?>"><?php echo e($topic); ?></div>
    <?php foreach ($subs as $sub => $list): ?>
      <?php if ($sub !== ''): ?><h4 style="font-family:var(--disp);margin:10px 0 8px"><?php echo e($sub); ?></h4><?php endif; ?>
      <?php foreach ($list as $it): ?>
        <div class="cpt" style="border-left-color:<?php echo e($color); ?>">
          <h4><?php echo e($it['question']); ?></h4>
          <?php if (trim((string)$it['explanation']) !== ''): ?><p><?php echo nl2br(e($it['explanation'])); ?></p><?php endif; ?>
          <?php $img = study_image_url($chapId, $it['image']); if ($img): ?>
            <a href="<?php echo e($img); ?>" target="_blank" class="qimg-wrap"><img class="qimg" src="<?php echo e($img); ?>" alt="figure" loading="lazy"></a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
