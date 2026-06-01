<?php
/* ============================================================
   Admin — Import a paper + extracted questions from JSON
   (produced offline / in a chat). Same shape as the array of
   question objects that api/paper_ai.php would emit, but no
   live AI call. Falls into the existing paper_review.php flow
   for fix-up and publish.

   Optional: attach the page images in the same form; they're
   written as p1.jpg, p2.jpg … in upload order under
   uploads/papers/{paperId}/  — matches the existing convention.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'questionbank'; $PAGE = 'Import Paper';
require_admin();

$err = ''; $summary = null;

// ---- "?bundle=NAME" pre-fill mode --------------------------------------
// Reads imports/paper-<name>.json (questions) and imports/paper-<name>.meta.json
// (exam name + date + note) and pre-populates the form. Admin just hits Submit.
$bundleName = '';
$bundleJson = '';
$bundleMeta = ['exam_name' => '', 'exam_date' => '', 'note' => ''];
if (isset($_GET['bundle']) && preg_match('/^[a-z0-9._-]+$/i', $_GET['bundle'])) {
    $bundleName = $_GET['bundle'];
    $jsonPath = __DIR__ . '/imports/paper-' . $bundleName . '.json';
    $metaPath = __DIR__ . '/imports/paper-' . $bundleName . '.meta.json';
    if (is_file($jsonPath)) $bundleJson = (string)@file_get_contents($jsonPath);
    if (is_file($metaPath)) {
        $m = json_decode((string)@file_get_contents($metaPath), true);
        if (is_array($m)) $bundleMeta = array_merge($bundleMeta, $m);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['exam_name'] ?? '');
    $date = trim($_POST['exam_date'] ?? '');
    $note = trim($_POST['note'] ?? '');

    // accept JSON via file upload OR textarea paste
    $jsonText = '';
    if (!empty($_FILES['json']['tmp_name']) && $_FILES['json']['error'] === UPLOAD_ERR_OK) {
        $jsonText = @file_get_contents($_FILES['json']['tmp_name']);
    }
    if (!$jsonText) $jsonText = trim($_POST['json_text'] ?? '');

    if ($name === '' || $date === '') $err = 'Exam name and date are required.';
    elseif ($jsonText === '')        $err = 'Upload a JSON file or paste the JSON.';
    else {
        $data = json_decode($jsonText, true);
        if (!is_array($data)) $err = 'JSON could not be parsed.';
        else {
            $items = isset($data['questions']) && is_array($data['questions']) ? $data['questions'] : $data;
            if (!is_array($items) || !$items) $err = 'JSON contains no questions.';
        }
    }

    if (!$err) {
        try {
            db()->prepare("INSERT INTO papers (exam_name, exam_date, note, uploaded_by) VALUES (?,?,?,?)")
                ->execute([$name, $date, ($note ?: null), current_user()['id']]);
            $paperId = (int)db()->lastInsertId();
        } catch (PDOException $ex) {
            $err = (strpos($ex->getMessage(), 'uniq_paper') !== false)
                ? 'A paper with this exam name and date already exists.'
                : 'Could not save paper.';
        }
    }

    if (!$err) {
        // optional page images, written as p1.jpg, p2.jpg … in upload order
        $dir = __DIR__ . '/uploads/papers/' . $paperId;
        @mkdir($dir, 0775, true);
        $allowed = ['jpg','jpeg','png','webp','gif'];
        $pageCount = 0;
        if (!empty($_FILES['img']['name'][0])) {
            foreach ($_FILES['img']['tmp_name'] as $i => $tmp) {
                if ($_FILES['img']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['img']['size'][$i]  > 8 * 1024 * 1024) continue;
                $ext = strtolower(pathinfo($_FILES['img']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) continue;
                $pageCount++;
                move_uploaded_file($tmp, $dir . '/p' . $pageCount . '.' . $ext);
            }
        }

        // insert questions
        $ins = db()->prepare(
            "INSERT INTO questions
               (paper_id, subject_id, chapter_id, qtype, stem, options, correct_index, correct_value,
                explanation, difficulty, source, image_ref, status)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'uploaded', ?, 'draft')"
        );
        $added = 0; $skipped = 0;
        foreach ($items as $it) {
            $stem = trim($it['stem'] ?? '');
            if ($stem === '') { $skipped++; continue; }
            $qtype = ($it['qtype'] ?? 'mcq') === 'numeric' ? 'numeric' : 'mcq';
            $opts  = (isset($it['options']) && is_array($it['options'])) ? array_values($it['options']) : [];
            $ci    = isset($it['correct_index']) && $it['correct_index'] !== '' ? (int)$it['correct_index'] : null;
            if ($qtype === 'mcq' && count($opts) < 2) $qtype = 'numeric';
            $cv    = trim((string)($it['correct_value'] ?? ''));
            $diff  = in_array(($it['difficulty'] ?? ''), ['easy','medium','hard'], true) ? $it['difficulty'] : 'medium';
            $subjId= resolve_subject_id($it['subject'] ?? '');
            $chapId= $subjId ? resolve_chapter_id($subjId, $it['chapter'] ?? '') : null;
            $imgRef= !empty($it['image_ref']) ? trim((string)$it['image_ref']) : null;
            // If image_ref is given but no matching file exists, still store the
            // reference — admin can upload the file later from File Manager.

            $ins->execute([
                $paperId, $subjId, $chapId, $qtype, $stem,
                ($opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null),
                ($qtype === 'mcq' ? $ci : null),
                ($qtype === 'numeric' ? $cv : null),
                trim((string)($it['explanation'] ?? '')),
                $diff, $imgRef,
            ]);
            $added++;
        }
        audit('paper.import', 'paper', $paperId, ['added' => $added, 'skipped' => $skipped, 'pages' => $pageCount]);
        $summary = ['paperId' => $paperId, 'added' => $added, 'skipped' => $skipped, 'pages' => $pageCount];
        flash("Imported $added question(s) into paper #$paperId.");
    }
}

require __DIR__.'/includes/header.php';
?>
<div class="crumbs"><a href="questionbank.php">Question Bank</a> › <span>Import from JSON</span></div>
<div class="phead"><h1><?php echo icon('upload','lg'); ?> Import a paper from JSON</h1>
  <p>For papers extracted offline. Same review flow as the AI path.</p></div>
<?php echo flash_render(); ?>
<?php if ($err): ?><div class="err"><?php echo e($err); ?></div><?php endif; ?>

<?php if ($summary): ?>
  <div class="ok-msg">Created paper #<?php echo (int)$summary['paperId']; ?> with
    <b><?php echo (int)$summary['added']; ?></b> question(s)<?php if ($summary['skipped']): ?>, skipped <?php echo (int)$summary['skipped']; ?><?php endif; ?><?php if ($summary['pages']): ?> · stored <b><?php echo (int)$summary['pages']; ?></b> page image(s)<?php endif; ?>.</div>
  <div class="btnrow"><a class="btn" href="paper_review.php?paper=<?php echo (int)$summary['paperId']; ?>">Open review →</a>
    <a class="btn ghost" href="questionbank.php">Question Bank</a></div>
<?php else: ?>
<?php if ($bundleName && $bundleJson): ?>
  <div class="ok-msg" style="margin-bottom:12px">📦 Pre-loaded bundle <b><?php echo e($bundleName); ?></b> — review and click <b>Import</b>. Source: <code>imports/paper-<?php echo e($bundleName); ?>.json</code></div>
<?php elseif ($bundleName && !$bundleJson): ?>
  <div class="err" style="margin-bottom:12px">Bundle <code><?php echo e($bundleName); ?></code> not found. Looked for <code>imports/paper-<?php echo e($bundleName); ?>.json</code>.</div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data">
  <?php echo csrf_field(); ?>
  <div class="row2">
    <div class="field"><label>Exam name</label><input type="text" name="exam_name" value="<?php echo e($bundleMeta['exam_name']); ?>" required placeholder="e.g. PACE-7 (Sri Gosalites)"></div>
    <div class="field"><label>Exam date</label><input type="date" name="exam_date" value="<?php echo e($bundleMeta['exam_date']); ?>" required></div>
  </div>
  <div class="field"><label>Note <span class="hint">(optional)</span></label><input type="text" name="note" value="<?php echo e($bundleMeta['note']); ?>"></div>
  <div class="field">
    <label>JSON file <span class="hint">(or use the textarea below)</span></label>
    <label class="drop"><input type="file" name="json" accept=".json,application/json"><span>Tap to choose the .json</span></label>
  </div>
  <div class="field">
    <label>… or paste JSON</label>
    <textarea name="json_text" rows="<?php echo $bundleJson ? 4 : 8; ?>" spellcheck="false" style="font-family:var(--mono);font-size:.8rem" placeholder='[{ "qtype":"mcq", "stem":"…", "options":["A","B","C","D"], "correct_index":1, … }]'><?php echo e($bundleJson); ?></textarea>
    <?php if ($bundleJson): ?><div class="hint"><?php echo number_format(strlen($bundleJson)); ?> bytes loaded · ready to import</div><?php endif; ?>
  </div>
  <div class="field">
    <label>Page images <span class="hint">(optional — upload in booklet order: p1 = first page, p2 = second, …)</span></label>
    <label class="drop"><input type="file" name="img[]" accept="image/*" multiple><span>Tap to choose page photos</span></label>
  </div>
  <div class="btnrow"><button class="btn" type="submit">Import →</button>
    <a class="btn ghost" href="questionbank.php">Cancel</a></div>
</form>
<div class="note" style="margin-top:14px">
  <b>Expected JSON shape</b>
  <pre style="font-family:var(--mono);font-size:.78rem;overflow-x:auto;color:var(--muted);margin:6px 0">[
  { "qtype":"mcq", "stem":"Question text — math as $LaTeX$",
    "options":["A","B","C","D"], "correct_index":0,
    "explanation":"why", "difficulty":"medium",
    "subject":"Physics", "chapter":"Current Electricity",
    "image_ref":null }
]</pre>
  Image refs like <code>"p3.jpg"</code> resolve against <code>uploads/papers/&lt;paperId&gt;/</code>.
</div>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
