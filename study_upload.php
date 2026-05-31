<?php
/* ============================================================
   Admin — bulk upload study items for ONE chapter from an Excel
   (.xlsx) or .csv file with columns:
     Topic | Sub-topic | Question | Explanation | Image
   Referenced images are attached in the same form and matched by
   filename. Duplicates are skipped on the QUESTION text only.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/xlsx.php';
$ACTIVE = 'study'; $PAGE = 'Bulk Upload';
require_admin();
ensure_study_items();

$chapId = (int)($_GET['chapter'] ?? $_POST['chapter'] ?? 0);
$chap = q1("SELECT ch.*, s.name AS subject, s.id AS subject_id FROM chapters ch JOIN subjects s ON s.id=ch.subject_id WHERE ch.id=?", [$chapId]);
if (!$chap) { require __DIR__.'/includes/header.php'; echo '<div class="note">Chapter not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }

/* downloadable template (CSV opens in Excel; save-as .xlsx to upload as Excel) */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="study_template.csv"');
    echo "Topic,Sub-topic,Question,Explanation,Image\n";
    echo "Genetics,Mendel's laws,\"State the Law of Segregation.\",\"Allele pairs separate during gamete formation so each gamete carries one allele.\",\n";
    echo "Genetics,Linkage,\"What is recombination frequency?\",\"% of recombinant offspring; a measure of distance between genes.\",linkage.png\n";
    exit;
}

$IMG_EXT = ['jpg','jpeg','png','webp','gif'];
$summary = null; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (empty($_FILES['sheet']['name']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Choose an .xlsx or .csv file to upload.';
    } else {
        $ext = strtolower(pathinfo($_FILES['sheet']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','csv'], true)) {
            $err = 'File must be .xlsx or .csv.';
        } elseif ($_FILES['sheet']['size'] > 10 * 1024 * 1024) {
            $err = 'File must be under 10 MB.';
        } else {
            $rows = spreadsheet_rows($_FILES['sheet']['tmp_name'], $ext);
            if ($rows === null) { $err = 'Could not read that file. For .xlsx make sure it is a real Excel file, or use .csv.'; }
        }
    }

    if (!$err) {
        // 1) store any attached images first, keyed by lowercased basename
        $dir = __DIR__ . '/uploads/study/' . $chapId;
        $stored = [];   // lcname => storedBasename
        if (!empty($_FILES['images']['name'][0])) {
            @mkdir($dir, 0775, true);
            foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['images']['size'][$i] > 8 * 1024 * 1024) continue;
                $bn  = basename($_FILES['images']['name'][$i]);
                $iex = strtolower(pathinfo($bn, PATHINFO_EXTENSION));
                if (!in_array($iex, $IMG_EXT, true)) continue;
                if (move_uploaded_file($tmp, $dir . '/' . $bn)) $stored[strtolower($bn)] = $bn;
            }
        }

        // 2) detect & drop a header row
        $start = 0;
        if ($rows && isset($rows[0])) {
            $first = array_map(fn($c) => strtolower(trim((string)$c)), $rows[0]);
            if (in_array('question', $first, true) || in_array('topic', $first, true)) $start = 1;
        }

        // 3) ingest
        $added = 0; $dup = 0; $blank = 0; $imgMissing = 0;
        $base = (int)(q1("SELECT COALESCE(MAX(sort),0) AS n FROM study_items WHERE chapter_id=?", [$chapId])['n']);
        $ins = db()->prepare("INSERT IGNORE INTO study_items (chapter_id, topic, subtopic, question, explanation, image, qhash, sort) VALUES (?,?,?,?,?,?,?,?)");
        for ($r = $start; $r < count($rows); $r++) {
            $row = $rows[$r];
            $topic = trim((string)($row[0] ?? ''));
            $sub   = trim((string)($row[1] ?? ''));
            $q     = trim((string)($row[2] ?? ''));
            $exp   = trim((string)($row[3] ?? ''));
            $imgc  = trim((string)($row[4] ?? ''));
            if ($q === '') { $blank++; continue; }

            $image = null;
            if ($imgc !== '') {
                if (preg_match('#^https?://#i', $imgc)) { $image = $imgc; }
                else {
                    $bn = basename($imgc);
                    if (isset($stored[strtolower($bn)])) $image = $stored[strtolower($bn)];
                    else { $image = $bn; $imgMissing++; }   // keep the reference even if file not attached yet
                }
            }
            $ins->execute([$chapId, $topic, $sub, $q, $exp, $image, question_hash($q), ++$base]);
            if ($ins->rowCount() === 1) $added++; else { $dup++; $base--; }
        }
        $summary = compact('added', 'dup', 'blank', 'imgMissing') + ['images' => count($stored)];
        flash("Imported $added new item(s)" . ($dup ? ", skipped $dup duplicate(s)" : '') . '.');
    }
}

require __DIR__.'/includes/header.php';
?>
<div class="crumbs">
  <a href="study.php?subject=<?php echo $chap['subject_id']; ?>"><?php echo e($chap['subject']); ?></a> ›
  <a href="study_chapter.php?chapter=<?php echo $chapId; ?>"><?php echo e($chap['name']); ?></a> › <span>Bulk upload</span>
</div>
<div class="phead"><h1><?php echo icon('upload','lg'); ?> Bulk upload — <?php echo e($chap['name']); ?></h1>
  <p>Upload an Excel (.xlsx) or .csv with columns: <b>Topic · Sub-topic · Question · Explanation · Image</b>.</p></div>
<?php echo flash_render(); ?>
<?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>

<?php if ($summary): ?>
  <div class="ok-msg">
    Added <b><?php echo $summary['added']; ?></b> ·
    Duplicates skipped <b><?php echo $summary['dup']; ?></b> ·
    Blank rows <b><?php echo $summary['blank']; ?></b> ·
    Images stored <b><?php echo $summary['images']; ?></b>
    <?php if ($summary['imgMissing']): ?> · <span style="color:var(--amber)"><?php echo $summary['imgMissing']; ?> image name(s) had no matching file</span><?php endif; ?>
  </div>
  <div class="btnrow"><a class="btn" href="study_chapter.php?chapter=<?php echo $chapId; ?>">View chapter</a>
    <a class="btn ghost" href="study_edit.php?chapter=<?php echo $chapId; ?>">Manage items</a></div>
<?php endif; ?>

<div class="note" style="margin:14px 0">
  <b>How it works</b>
  <ul style="margin:6px 0 0 18px">
    <li>Row 1 may be a header (Topic, Sub-topic, Question, Explanation, Image) — it's detected and skipped.</li>
    <li>Duplicates are judged on the <b>Question</b> text only (case/space-insensitive) and skipped.</li>
    <li><b>Image</b> column: paste a web URL, or put a filename and attach that image file below.</li>
    <li>Write maths as LaTeX in <code>$…$</code> — it renders in the student view.</li>
  </ul>
  <div style="margin-top:8px"><a href="study_upload.php?chapter=<?php echo $chapId; ?>&template=1">⬇ Download CSV template</a></div>
</div>

<form method="post" enctype="multipart/form-data">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="chapter" value="<?php echo $chapId; ?>">
  <div class="field">
    <label>Spreadsheet (.xlsx or .csv)</label>
    <label class="drop" id="dropSheet"><input type="file" name="sheet" id="sheetIn" accept=".xlsx,.csv"><span id="sheetMsg">Tap to choose the Excel/CSV file</span></label>
  </div>
  <div class="field">
    <label>Images <span class="hint">(optional — attach files named in the Image column)</span></label>
    <label class="drop" id="dropImgs"><input type="file" name="images[]" id="imgsIn" accept="image/*" multiple><span id="imgsMsg">Tap to choose image files</span><div class="thumbs" id="thumbs"></div></label>
  </div>
  <div class="btnrow"><button class="btn" type="submit">Import →</button>
    <a class="btn ghost" href="study.php?subject=<?php echo $chap['subject_id']; ?>">Back</a></div>
</form>

<script>
(function(){
  var s=document.getElementById('sheetIn'),sm=document.getElementById('sheetMsg');
  if(s)s.addEventListener('change',function(){sm.textContent=s.files.length?s.files[0].name:'Tap to choose the Excel/CSV file';});
  var im=document.getElementById('imgsIn'),th=document.getElementById('thumbs'),img=document.getElementById('imgsMsg');
  if(im)im.addEventListener('change',function(){
    th.innerHTML='';var n=im.files.length;img.textContent=n?(n+' image(s) selected'):'Tap to choose image files';
    Array.prototype.forEach.call(im.files,function(f){var x=document.createElement('img');x.className='thumb';x.src=URL.createObjectURL(f);th.appendChild(x);});
  });
})();
</script>
<?php require __DIR__.'/includes/footer.php'; ?>
