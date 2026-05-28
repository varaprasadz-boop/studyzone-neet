<?php
/* ============================================================
   Phase 2 — In-app study chapter viewer (generated material).
   Tabs: Concepts · Formulas · Flashcards · Self-Test.
   Chapters that ship with a standalone interactive hub_file are
   opened directly from study.php instead.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'study'; $PAGE = 'Study';
require_login();

$chapId = (int)($_GET['chapter'] ?? 0);
$chap = q1("SELECT ch.*, s.name AS subject, s.color, s.icon, s.id AS subject_id, s.class_id, s.syllabus_id
            FROM chapters ch JOIN subjects s ON s.id=ch.subject_id WHERE ch.id=?", [$chapId]);
if (!$chap) { require __DIR__.'/includes/header.php'; echo '<div class="note">Chapter not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }

// hub chapters open as the standalone file
if (!empty($chap['hub_file'])) { redirect('assets/hub/' . rawurlencode($chap['hub_file'])); }

$content  = study_content_get($chapId);
$concepts = $content['concepts'] ?? null;     // ['tagline'=>, 'groups'=>[...]]
$formulas = $content['formulas'] ?? [];
$flash    = $content['flashcards'] ?? [];
$quiz     = $content['quiz'] ?? [];
$admin    = is_admin();
$color    = $chap['color'];

$TRACK_SUBJECT = (int)$chap['subject_id'];
$TRACK_CHAPTER = (int)$chap['id'];
require __DIR__.'/includes/header.php';
?>
<div class="crumbs">
  <a href="study.php">Study</a> ›
  <a href="study.php?class=<?php echo $chap['class_id']; ?>&syllabus=<?php echo $chap['syllabus_id']; ?>&subject=<?php echo $chap['subject_id']; ?>"><?php echo e($chap['subject']); ?></a> ›
  <span><?php echo e($chap['name']); ?></span>
</div>
<div class="phead">
  <h1><?php echo e($chap['name']); ?></h1>
  <?php if (!empty($concepts['tagline'])): ?><p><?php echo e($concepts['tagline']); ?></p><?php endif; ?>
</div>
<?php if ($admin): ?>
  <div class="toolbar"><a class="btn sm ghost" href="study_edit.php?chapter=<?php echo $chapId; ?>">✏ Edit content</a>
    <a class="btn sm ghost" href="study_generate.php?subject=<?php echo $chap['subject_id']; ?>">⚡ Regenerate</a></div>
<?php endif; ?>

<?php if (!$concepts && !$formulas && !$flash && !$quiz): ?>
  <div class="note">No material generated yet for this chapter.
  <?php if ($admin): ?> <a href="study_generate.php?subject=<?php echo $chap['subject_id']; ?>">Generate with AI →</a> or <a href="study_edit.php?chapter=<?php echo $chapId; ?>">author manually →</a><?php endif; ?></div>
<?php else: ?>

<div class="ctabs" id="ctabs">
  <?php $tabs = [];
  if ($concepts) $tabs['concepts'] = '① Concepts';
  if ($formulas) $tabs['formulas'] = '② Formulas';
  if ($flash)    $tabs['flash']    = '③ Flashcards';
  if ($quiz)     $tabs['quiz']     = '④ Self-Test';
  $first = array_key_first($tabs);
  foreach ($tabs as $k => $label): ?>
    <button class="ctab <?php echo $k===$first?'on':''; ?>" data-tab="<?php echo $k; ?>"><?php echo $label; ?></button>
  <?php endforeach; ?>
</div>

<?php if ($concepts): ?>
<div class="tpane <?php echo $first==='concepts'?'on':''; ?>" id="tp-concepts">
  <?php foreach (($concepts['groups'] ?? []) as $g): ?>
    <div class="grouphdr"><?php echo e($g['g'] ?? ''); ?></div>
    <?php foreach (($g['pts'] ?? []) as $pt): ?>
      <div class="cpt">
        <h4><?php echo e($pt['t'] ?? ''); ?></h4>
        <p><?php echo $pt['d'] ?? ''; ?></p>
        <?php if (!empty($pt['e'])): ?><div class="eg"><?php echo $pt['e']; ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($formulas): ?>
<div class="tpane <?php echo $first==='formulas'?'on':''; ?>" id="tp-formulas">
  <?php foreach ($formulas as $f): ?>
    <div class="formula"><span class="t"><?php echo e($f['t'] ?? ''); ?></span><span class="v"><?php echo e($f['v'] ?? ''); ?></span></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($flash): ?>
<div class="tpane <?php echo $first==='flash'?'on':''; ?>" id="tp-flash">
  <div class="qbanner"><b><?php echo count($flash); ?></b> flashcards · tap to flip</div>
  <div class="fcards">
    <?php foreach ($flash as $fc): ?>
      <div class="fcard"><div class="inner">
        <div class="face front"><?php echo e($fc[0] ?? ''); ?></div>
        <div class="face back"><?php echo $fc[1] ?? ''; ?></div>
      </div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($quiz): ?>
<div class="tpane <?php echo $first==='quiz'?'on':''; ?>" id="tp-quiz">
  <div class="quiz" id="quizBox"></div>
</div>
<script>window.__QUIZ = <?php echo json_encode($quiz, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;</script>
<?php endif; ?>

<script>
/* tabs */
(function(){
  document.querySelectorAll('#ctabs .ctab').forEach(function(b){
    b.onclick=function(){
      document.querySelectorAll('#ctabs .ctab').forEach(x=>x.classList.remove('on'));
      document.querySelectorAll('.tpane').forEach(p=>p.classList.remove('on'));
      b.classList.add('on');
      var p=document.getElementById('tp-'+b.getAttribute('data-tab'));if(p)p.classList.add('on');
    };
  });
})();
/* flashcards flip */
document.querySelectorAll('.fcard').forEach(function(c){c.onclick=function(){c.classList.toggle('flip');};});
/* self-test: shuffle options each load, instant feedback */
(function(){
  var box=document.getElementById('quizBox'); if(!box||!window.__QUIZ)return;
  function shuffle(a){for(var i=a.length-1;i>0;i--){var j=Math.floor(Math.random()*(i+1));var t=a[i];a[i]=a[j];a[j]=t;}return a;}
  var pool=window.__QUIZ.map(function(q){
    var opts=q.o.map(function(o,i){return {t:o,correct:i===q.c};});
    shuffle(opts);
    return {q:q.q,opts:opts,ex:q.ex||''};
  });
  var score=0,answered=0;
  var html='<div class="qbanner"><b>'+pool.length+'</b> practice questions · options reshuffle each visit · not recorded</div>';
  pool.forEach(function(item,qi){
    html+='<div class="qcard" data-qi="'+qi+'"><div class="qno">Q'+(qi+1)+'</div><div class="qtext">'+item.q+'</div>';
    item.opts.forEach(function(o,oi){html+='<button class="opt" data-correct="'+(o.correct?1:0)+'">'+o.t+'</button>';});
    html+='<div class="ex" style="display:none">'+item.ex+'</div></div>';
  });
  html+='<div class="qbanner" id="qscore">Answered 0 / '+pool.length+'</div>';
  box.innerHTML=html;
  box.querySelectorAll('.qcard').forEach(function(card){
    var locked=false;
    card.querySelectorAll('.opt').forEach(function(opt){
      opt.onclick=function(){
        if(locked)return;locked=true;answered++;
        var correct=opt.getAttribute('data-correct')==='1';
        if(correct){opt.classList.add('correct');score++;}
        else{opt.classList.add('wrong');
          card.querySelectorAll('.opt').forEach(function(o){if(o.getAttribute('data-correct')==='1')o.classList.add('correct');});}
        card.querySelector('.ex').style.display='block';
        document.getElementById('qscore').innerHTML='Score <b>'+score+'</b> · answered '+answered+' / '+pool.length;
      };
    });
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
