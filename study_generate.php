<?php
/* ============================================================
   Phase 2 — Admin: create study material for a subject.
   1) Upload the syllabus image(s)  → AI lists the chapters, OR
      type chapter names manually (works without an API key).
   2) Generate full material per chapter (AJAX loop → api/study_ai.php).
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/ai.php';
$ACTIVE = 'study'; $PAGE = 'Create Study Material';
require_admin();

$subjectId = (int)($_GET['subject'] ?? $_POST['subject'] ?? 0);
$subj = q1("SELECT s.*, c.name AS class, y.name AS syllabus
            FROM subjects s JOIN classes c ON c.id=s.class_id JOIN syllabi y ON y.id=s.syllabus_id
            WHERE s.id=?", [$subjectId]);
if (!$subj) { require __DIR__.'/includes/header.php'; echo '<div class="note">Unknown subject.</div>'; require __DIR__.'/includes/footer.php'; exit; }

$extracted = $_SESSION['extracted'][$subjectId] ?? null;  // [['name'=>..,'topics'=>..], ...]
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'extract') {
        if (!ai_enabled()) {
            $err = 'No API key set — use the manual list below, or add ANTHROPIC_API_KEY in includes/config.php.';
        } elseif (empty($_FILES['img']['name'][0])) {
            $err = 'Choose at least one syllabus image.';
        } else {
            $blocks = [];
            foreach ($_FILES['img']['tmp_name'] as $i => $tmp) {
                if ($_FILES['img']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['img']['size'][$i] > 8 * 1024 * 1024) { $err = 'Each image must be under 8 MB.'; break; }
                $b = ai_image_block($tmp);
                if ($b) $blocks[] = $b;
            }
            if (!$err && $blocks) {
                $blocks[] = ai_text_block(
                    "These image(s) show the syllabus for {$subj['subject']} ({$subj['class']} · {$subj['syllabus']}). "
                  . "List EVERY chapter/unit in order. Respond with ONLY JSON: "
                  . '{"chapters":[{"name":"Chapter name","topics":["topic","topic"]}]} . '
                  . "Topics may be an empty array if not visible."
                );
                $sys = "You read academic syllabus images and extract a clean, ordered chapter list. Respond with only valid JSON.";
                $res = ai_call([['role' => 'user', 'content' => $blocks]], $sys, 2048);
                if (!$res['ok']) { $err = $res['error']; }
                else {
                    $data = ai_json($res['text']);
                    $chs = $data['chapters'] ?? null;
                    if (!is_array($chs) || !$chs) { $err = 'Could not read chapters from the image. Try a clearer photo or use the manual list.'; }
                    else {
                        $extracted = [];
                        foreach ($chs as $c) {
                            $nm = trim($c['name'] ?? '');
                            if ($nm === '') continue;
                            $tp = '';
                            if (!empty($c['topics']) && is_array($c['topics'])) $tp = implode(', ', array_map('trim', $c['topics']));
                            $extracted[] = ['name' => $nm, 'topics' => $tp];
                        }
                        $_SESSION['extracted'][$subjectId] = $extracted;
                        flash(count($extracted) . ' chapters read from the syllabus. Review, then generate.');
                        redirect('study_generate.php?subject=' . $subjectId);
                    }
                }
            }
        }
    }

    if ($action === 'manual') {
        $lines = preg_split('/\n+/', trim($_POST['chapters'] ?? ''));
        $extracted = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $extracted[] = ['name' => $ln, 'topics' => ''];
        }
        if (!$extracted) { $err = 'Type at least one chapter name.'; }
        else {
            $_SESSION['extracted'][$subjectId] = $extracted;
            redirect('study_generate.php?subject=' . $subjectId);
        }
    }

    if ($action === 'shells') {  // create empty chapter rows without AI
        $created = 0;
        foreach (($extracted ?: []) as $c) {
            $exists = q1("SELECT id FROM chapters WHERE subject_id=? AND name=?", [$subjectId, $c['name']]);
            if (!$exists) {
                $sortR = q1("SELECT COALESCE(MAX(sort),0)+1 AS n FROM chapters WHERE subject_id=?", [$subjectId]);
                db()->prepare("INSERT INTO chapters (subject_id, name, sort) VALUES (?,?,?)")
                    ->execute([$subjectId, $c['name'], (int)$sortR['n']]);
                $created++;
            }
        }
        unset($_SESSION['extracted'][$subjectId]);
        flash("Created $created chapter(s). Generate material later from this page.");
        redirect('study.php?class=' . $subj['class_id'] . '&syllabus=' . $subj['syllabus_id'] . '&subject=' . $subjectId);
    }
}

require __DIR__.'/includes/header.php';
?>
<div class="crumbs">
  <a href="study.php">Study</a> ›
  <a href="study.php?class=<?php echo $subj['class_id']; ?>&syllabus=<?php echo $subj['syllabus_id']; ?>&subject=<?php echo $subjectId; ?>"><?php echo e($subj['subject']); ?></a> ›
  <span>Create material</span>
</div>
<div class="phead"><h1><?php echo $subj['icon'].' '.e($subj['subject']); ?> — create material</h1>
  <p><?php echo e($subj['class']).' · '.e($subj['syllabus']); ?></p></div>
<?php echo flash_render(); ?>
<?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>

<?php if (!ai_enabled()): ?>
  <div class="note" style="margin-bottom:16px"><b>AI is off.</b> Add your <code>ANTHROPIC_API_KEY</code> in <code>includes/config.php</code> to read a syllabus image and auto-write material. Until then you can add chapter names manually below.</div>
<?php endif; ?>

<div class="row2">
  <form method="post" enctype="multipart/form-data" id="extractForm">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="subject" value="<?php echo $subjectId; ?>">
    <input type="hidden" name="action" value="extract">
    <label>① From a syllabus image <span class="hint">(AI reads the chapter list)</span></label>
    <label class="drop" id="drop">
      <input type="file" name="img[]" id="imgIn" accept="image/*" multiple>
      <span id="dropMsg">Tap to choose photo(s) of the syllabus page</span>
      <div class="thumbs" id="thumbs"></div>
    </label>
    <div class="btnrow"><button class="btn" type="submit" <?php echo ai_enabled()?'':'disabled'; ?>>Read chapters →</button></div>
  </form>

  <form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="subject" value="<?php echo $subjectId; ?>">
    <input type="hidden" name="action" value="manual">
    <label>② Or type chapter names <span class="hint">(one per line)</span></label>
    <textarea name="chapters" rows="6" placeholder="Current Electricity&#10;Ray Optics&#10;Moving Charges &amp; Magnetism"></textarea>
    <div class="btnrow"><button class="btn ghost" type="submit">Use this list →</button></div>
  </form>
</div>

<?php if ($extracted): ?>
<div class="sect"><h2>Chapters to generate</h2><p>Pick chapters, then generate. Each chapter runs in two quick steps (concepts, then questions) to stay within the host's time limit. Existing chapters with the same name are updated.</p></div>
<div class="toolbar">
  <button class="btn sm ghost" type="button" id="selAll">Select all</button>
  <button class="btn sm ghost" type="button" id="selNone">Clear</button>
  <span class="spacer"></span>
  <button class="btn green" type="button" id="genBtn" <?php echo ai_enabled()?'':'disabled'; ?>>⚡ Generate selected with AI</button>
  <form method="post" style="display:inline">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="subject" value="<?php echo $subjectId; ?>">
    <input type="hidden" name="action" value="shells">
    <button class="btn sm ghost" type="submit">Create as empty shells</button>
  </form>
</div>
<div class="progress" id="prog" style="display:none"><i></i></div>
<div id="genLog"></div>
<div class="list" id="chapList">
  <?php foreach ($extracted as $i => $c): ?>
    <label class="row" style="cursor:pointer" data-name="<?php echo e($c['name']); ?>" data-topics="<?php echo e($c['topics']); ?>">
      <div style="display:flex;align-items:center;gap:12px">
        <input type="checkbox" class="chk" checked>
        <div><h3><?php echo e($c['name']); ?></h3>
          <?php if ($c['topics']): ?><p><?php echo e($c['topics']); ?></p><?php endif; ?></div>
      </div>
      <span class="status pill">queued</span>
    </label>
  <?php endforeach; ?>
</div>
<div class="btnrow" style="margin-top:14px"><a class="btn ghost sm" href="study_generate.php?subject=<?php echo $subjectId; ?>&clear=1" onclick="return false" id="doneBtn" style="display:none">Done → view subject</a></div>
<?php endif; ?>

<script>
/* image picker preview */
(function(){
  var inp=document.getElementById('imgIn'),th=document.getElementById('thumbs'),msg=document.getElementById('dropMsg');
  if(!inp)return;
  inp.addEventListener('change',function(){
    th.innerHTML='';var n=inp.files.length;
    if(msg)msg.textContent=n?(n+' image(s) selected'):'Tap to choose photo(s) of the syllabus page';
    Array.prototype.forEach.call(inp.files,function(f){
      var img=document.createElement('img');img.className='thumb';img.src=URL.createObjectURL(f);th.appendChild(img);
    });
  });
})();

/* per-chapter generation loop */
(function(){
  var genBtn=document.getElementById('genBtn');
  if(!genBtn)return;
  var CSRF=<?php echo json_encode(csrf_token()); ?>, SUBJ=<?php echo (int)$subjectId; ?>;
  var prog=document.getElementById('prog'),progBar=prog.querySelector('i'),log=document.getElementById('genLog');
  document.getElementById('selAll').onclick=function(){document.querySelectorAll('.chk').forEach(c=>c.checked=true);};
  document.getElementById('selNone').onclick=function(){document.querySelectorAll('.chk').forEach(c=>c.checked=false);};

  genBtn.onclick=function(){
    var rows=Array.prototype.slice.call(document.querySelectorAll('#chapList .row')).filter(function(r){return r.querySelector('.chk').checked;});
    if(!rows.length){alert('Select at least one chapter.');return;}
    genBtn.disabled=true;genBtn.textContent='Generating…';
    prog.style.display='block';
    var done=0,ok=0;
    function setProg(){progBar.style.width=Math.round(done/rows.length*100)+'%';}
    function callPart(row,part){
      var fd=new FormData();
      fd.append('_csrf',CSRF);fd.append('subject',SUBJ);
      fd.append('chapter',row.getAttribute('data-name'));
      fd.append('topics',row.getAttribute('data-topics')||'');
      fd.append('part',part);
      return fetch('api/study_ai.php',{method:'POST',body:fd}).then(function(r){return r.json();});
    }
    function next(i){
      if(i>=rows.length){
        genBtn.textContent='✓ Generated '+ok+'/'+rows.length;
        var d=document.getElementById('doneBtn');if(d){d.style.display='inline-block';d.href='study.php?class=<?php echo $subj['class_id'];?>&syllabus=<?php echo $subj['syllabus_id'];?>&subject='+SUBJ;d.onclick=null;}
        return;
      }
      var row=rows[i],st=row.querySelector('.status');
      st.className='status pill';st.innerHTML='<span class="spin"></span> concepts…';
      callPart(row,'core').then(function(c){
        if(!c.ok) throw new Error(c.error||'concepts failed');
        st.innerHTML='<span class="spin"></span> questions…';
        return callPart(row,'practice').then(function(p){
          done++;setProg();ok++;
          var qn=(p.ok&&p.counts)?p.counts.quiz:0;
          st.className='status pill pub';
          st.textContent='✓ '+c.counts.points+'pts'+(p.ok?(' · '+qn+'Q'):' · (quiz failed)');
          next(i+1);
        });
      }).catch(function(e){
        done++;setProg();st.className='status pill hard';st.textContent='✗ '+(e.message||'network');next(i+1);
      });
    }
    next(0);
  };
})();
</script>
<?php
if (isset($_GET['clear'])) { unset($_SESSION['extracted'][$subjectId]); }
require __DIR__.'/includes/footer.php';
?>
