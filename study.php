<?php $ACTIVE='study'; $PAGE='Study Material';
require_once __DIR__.'/includes/lib.php';
require __DIR__.'/includes/header.php';
$admin = is_admin();
$pdo = db();
$classId = isset($_GET['class'])    ? (int)$_GET['class']    : 0;
$sylId   = isset($_GET['syllabus']) ? (int)$_GET['syllabus'] : 0;
$subjId  = isset($_GET['subject'])  ? (int)$_GET['subject']  : 0;

function nameOf($pdo,$table,$id){ $st=$pdo->prepare("SELECT name FROM $table WHERE id=?"); $st->execute([$id]); $r=$st->fetch(); return $r?$r['name']:''; }
?>
<div class="crumbs">
  <a href="study.php">Study</a>
  <?php if($classId): ?> › <a href="study.php?class=<?php echo $classId; ?>"><?php echo e(nameOf($pdo,'classes',$classId)); ?></a><?php endif; ?>
  <?php if($sylId):   ?> › <a href="study.php?class=<?php echo $classId; ?>&syllabus=<?php echo $sylId; ?>"><?php echo e(nameOf($pdo,'syllabi',$sylId)); ?></a><?php endif; ?>
  <?php if($subjId):  ?> › <span><?php echo e(nameOf($pdo,'subjects',$subjId)); ?></span><?php endif; ?>
</div>

<?php if (!$classId): ?>
  <div class="phead"><h1>📘 Study Material</h1><p>Choose a class to begin.</p></div>
  <div class="grid">
  <?php foreach ($pdo->query("SELECT * FROM classes ORDER BY sort")->fetchAll() as $c): ?>
    <a class="tile" href="study.php?class=<?php echo $c['id']; ?>"><span class="ic">🎓</span><h3><?php echo e($c['name']); ?></h3><p>NCERT &amp; State</p></a>
  <?php endforeach; ?>
  </div>

<?php elseif (!$sylId): ?>
  <div class="phead"><h1><?php echo e(nameOf($pdo,'classes',$classId)); ?></h1><p>Choose a syllabus.</p></div>
  <div class="grid">
  <?php foreach ($pdo->query("SELECT * FROM syllabi ORDER BY sort")->fetchAll() as $s): ?>
    <a class="tile" href="study.php?class=<?php echo $classId; ?>&syllabus=<?php echo $s['id']; ?>" style="--tc:#b8893b"><span class="ic">📚</span><h3><?php echo e($s['name']); ?></h3></a>
  <?php endforeach; ?>
  </div>

<?php elseif (!$subjId): ?>
  <div class="phead"><h1>Subjects</h1><p><?php echo e(nameOf($pdo,'classes',$classId)).' · '.e(nameOf($pdo,'syllabi',$sylId)); ?></p></div>
  <div class="grid">
  <?php
    $st=$pdo->prepare("SELECT * FROM subjects WHERE class_id=? AND syllabus_id=? ORDER BY sort");
    $st->execute([$classId,$sylId]);
    foreach ($st->fetchAll() as $sub):
      $cc=$pdo->prepare("SELECT COUNT(*) c FROM chapters WHERE subject_id=?"); $cc->execute([$sub['id']]); $n=(int)$cc->fetch()['c'];
  ?>
    <a class="tile" href="study.php?class=<?php echo $classId; ?>&syllabus=<?php echo $sylId; ?>&subject=<?php echo $sub['id']; ?>" style="--tc:<?php echo e($sub['color']); ?>">
      <span class="ic"><?php echo $sub['icon']; ?></span><h3><?php echo e($sub['name']); ?></h3><p><?php echo $n; ?> chapter<?php echo $n==1?'':'s'; ?></p></a>
  <?php endforeach; ?>
  </div>

<?php else:
  $subj = $pdo->prepare("SELECT * FROM subjects WHERE id=?"); $subj->execute([$subjId]); $subj=$subj->fetch();
  $chs  = $pdo->prepare("SELECT * FROM chapters WHERE subject_id=? ORDER BY sort"); $chs->execute([$subjId]); $chs=$chs->fetchAll();
?>
  <div class="phead"><h1><?php echo $subj['icon'].' '.e($subj['name']); ?></h1>
    <p><?php echo e(nameOf($pdo,'classes',$classId)).' · '.e(nameOf($pdo,'syllabi',$sylId)); ?></p></div>
  <?php if ($admin): ?>
    <div class="toolbar">
      <a class="btn" href="study_generate.php?subject=<?php echo $subjId; ?>">+ Create study material from syllabus image</a>
      <?php if ($chs): ?><a class="btn ghost" href="chapters_manage.php?subject=<?php echo $subjId; ?>">Manage chapters</a><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$chs): ?>
    <div class="note">No chapters yet for this subject.<?php echo $admin?' Use “Create study material” above.':' Your tutor will add them soon.'; ?></div>
  <?php else: ?>
    <div class="list">
    <?php foreach ($chs as $ch): ?>
      <?php if ($ch['hub_file']): ?>
        <a class="row" href="assets/hub/<?php echo e($ch['hub_file']); ?>" target="_blank" style="border-left-color:<?php echo e($subj['color']); ?>">
          <div><h3><?php echo e($ch['name']); ?></h3><p>Open interactive hub — concepts · visuals · flashcards · self-test</p></div>
          <span class="badge">READY</span>
        </a>
      <?php elseif (chapter_has_content($ch)): ?>
        <a class="row" href="study_chapter.php?chapter=<?php echo $ch['id']; ?>" style="border-left-color:<?php echo e($subj['color']); ?>">
          <div><h3><?php echo e($ch['name']); ?></h3><p>Concepts · formulas · flashcards · self-test<?php echo $admin?' · tap to view, regenerate from “Create” above':''; ?></p></div>
          <span class="badge">READY</span>
        </a>
      <?php else: ?>
        <div class="row" style="border-left-color:<?php echo e($subj['color']); ?>;cursor:default">
          <div><h3><?php echo e($ch['name']); ?></h3><p><?php echo $admin?'Not generated yet — create from syllabus.':'Content coming soon.'; ?></p></div>
          <span class="soon">SOON</span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
