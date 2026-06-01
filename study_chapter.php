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
// Phase 1: scoped users see only their allowed chapters; foreign deep links
// look identical to a stale link (no permission-leaking 403 message).
$allowedChapIds = scoped_chapter_ids();
if ($allowedChapIds !== null && $chap && !in_array((int)$chap['id'], $allowedChapIds, true)) $chap = null;
if (!$chap) { require __DIR__.'/includes/header.php'; echo '<div class="note">Chapter not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }

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
  <p><?php echo count($items); ?> study item<?php echo count($items)==1?'':'s'; ?>
    <?php if (count($groups) > 1): ?> · <?php echo count($groups); ?> topics<?php endif; ?></p></div>

<?php if ($admin): ?>
  <div class="toolbar">
    <a class="btn sm ghost" href="study_edit.php?chapter=<?php echo $chapId; ?>"><?php echo icon('edit'); ?> Manage items</a>
    <a class="btn sm ghost" href="study_upload.php?chapter=<?php echo $chapId; ?>"><?php echo icon('upload'); ?> Bulk upload</a>
    <a class="btn sm ghost" href="study_review.php"><?php echo icon('zap'); ?> Flashcard review</a>
  </div>
<?php endif; ?>

<?php if (!$items): ?>
  <div class="note empty">
    <?php echo illus('empty'); ?>
    <b>No study material yet</b>
    <?php if ($admin): ?>
      <a href="study_upload.php?chapter=<?php echo $chapId; ?>">Bulk upload →</a> or
      <a href="study_edit.php?chapter=<?php echo $chapId; ?>">add by hand →</a>
    <?php else: ?>
      Your tutor will publish notes for this chapter soon.
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php if (count($groups) > 1): ?>
    <nav class="topic-nav" id="topicNav">
      <?php $ti = 0; foreach ($groups as $topic => $_): ?>
        <a href="#t-<?php echo $ti; ?>" data-topic="t-<?php echo $ti; ?>" class="<?php echo $ti===0?'on':''; ?>"><?php echo e($topic); ?></a>
      <?php $ti++; endforeach; ?>
    </nav>
  <?php endif; ?>
  <?php $ti = 0; foreach ($groups as $topic => $subs): ?>
    <section class="topic-sec" id="t-<?php echo $ti; ?>" data-topic-name="<?php echo e($topic); ?>">
      <div class="grouphdr" style="color:<?php echo e($color); ?>"><?php echo e($topic); ?></div>
      <?php foreach ($subs as $sub => $list): ?>
        <?php if ($sub !== ''): ?><div class="subhdr"><?php echo e($sub); ?></div><?php endif; ?>
        <?php foreach ($list as $it): ?>
          <article class="cpt qa" style="border-left-color:<?php echo e($color); ?>">
            <div class="qa-q"><span class="qa-tag" style="background:<?php echo e($color); ?>">Q</span>
              <span class="qa-text"><?php echo e($it['question']); ?></span></div>
            <?php if (trim((string)$it['explanation']) !== ''): ?>
              <div class="qa-a"><?php echo nl2br(e($it['explanation'])); ?></div>
            <?php endif; ?>
            <?php $img = study_image_url($chapId, $it['image']); if ($img): ?>
              <a href="<?php echo e($img); ?>" target="_blank" class="qimg-wrap" style="margin-left:34px"><img class="qimg" src="<?php echo e($img); ?>" alt="figure" loading="lazy"></a>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </section>
  <?php $ti++; endforeach; ?>
  <?php if (count($groups) > 1): ?>
  <script>
  /* scrollspy: highlight the current topic in the sticky nav */
  (function(){
    var nav = document.getElementById('topicNav'); if (!nav) return;
    var links = {};
    nav.querySelectorAll('a[data-topic]').forEach(function(a){ links[a.getAttribute('data-topic')] = a; });
    if (!('IntersectionObserver' in window)) return;
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(en){
        if (en.isIntersecting) {
          var id = en.target.getAttribute('id');
          Object.keys(links).forEach(function(k){ links[k].classList.toggle('on', k === id); });
          var a = links[id]; if (a) a.scrollIntoView({behavior:'smooth', block:'nearest', inline:'center'});
        }
      });
    }, {rootMargin:'-30% 0px -60% 0px', threshold:0});
    document.querySelectorAll('.topic-sec').forEach(function(s){ io.observe(s); });
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
