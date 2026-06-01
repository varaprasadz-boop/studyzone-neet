<?php
/* ============================================================
   Admin — Import bundled study material (CSV) into the right
   Class → Subject → Chapter, in one click. Reuses the same
   study_items table + dedup model as the manual bulk upload.

   Layout:
     /study_import.php                  → list available bundles
     /study_import.php?bundle=NAME      → preview screen for one bundle
     /study_import.php?bundle=all       → preview screen for ALL bundles
     POST                                → actually performs the import
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/xlsx.php';
$ACTIVE = 'study'; $PAGE = 'Import Study Bundle';
require_admin();
ensure_study_items();

$bundlesPath = __DIR__ . '/imports/study-bundles.json';
$bundles = is_file($bundlesPath) ? json_decode((string)file_get_contents($bundlesPath), true) : [];
if (!is_array($bundles)) $bundles = [];

/* Resolve canonical subject id for a (class name, subject name) pair.
   Falls back to resolve_subject_id() which prefers Class 12 NCERT. */
function _resolve_class_subject($className, $subjectName) {
    $cls = q1("SELECT id FROM classes WHERE name=?", [$className]);
    if (!$cls) return resolve_subject_id($subjectName);
    foreach (study_subjects_for_class((int)$cls['id']) as $s) {
        if (strcasecmp($s['name'], $subjectName) === 0) return (int)$s['id'];
    }
    return resolve_subject_id($subjectName);
}

/* Do the import for one bundle. Returns ['added'=>, 'dup'=>, 'blank'=>, 'chapters'=>[name=>count]]. */
function do_import_bundle(array $b) {
    $stat = ['added'=>0, 'dup'=>0, 'blank'=>0, 'chapters'=>[], 'errors'=>[]];
    if (empty($b['csv']) || empty($b['subject']) || empty($b['topic_to_chapter'])) {
        $stat['errors'][] = 'Bundle missing csv / subject / topic_to_chapter';
        return $stat;
    }
    $csvPath = __DIR__ . '/imports/' . basename($b['csv']);
    if (!is_file($csvPath)) { $stat['errors'][] = 'CSV not found: ' . $b['csv']; return $stat; }

    $subjectId = _resolve_class_subject($b['class'] ?? 'Class 12', $b['subject']);
    if (!$subjectId) { $stat['errors'][] = 'Subject not found: ' . $b['subject']; return $stat; }

    $rows = spreadsheet_rows($csvPath, 'csv');
    if (!$rows) { $stat['errors'][] = 'CSV could not be parsed'; return $stat; }

    // detect & skip header row
    $start = 0;
    if ($rows && isset($rows[0])) {
        $first = array_map(fn($c) => strtolower(trim((string)$c)), $rows[0]);
        if (in_array('question', $first, true) || in_array('topic', $first, true)) $start = 1;
    }

    $stripImage = !empty($b['strip_image']);
    // chapter_id cache keyed by chapter name; also tracks per-chapter sort counter
    $chapCache = [];
    $sortCounter = [];
    $ins = db()->prepare("INSERT IGNORE INTO study_items
                          (chapter_id, topic, subtopic, question, explanation, image, qhash, sort)
                          VALUES (?,?,?,?,?,?,?,?)");

    for ($r = $start; $r < count($rows); $r++) {
        $row = $rows[$r];
        $csvTopic  = trim((string)($row[0] ?? ''));      // CSV "Topic" → chapter mapping
        $csvSub    = trim((string)($row[1] ?? ''));      // CSV "Sub-topic" → study_items.topic
        $question  = trim((string)($row[2] ?? ''));
        $explain   = trim((string)($row[3] ?? ''));
        $imageCol  = trim((string)($row[4] ?? ''));
        if ($question === '') { $stat['blank']++; continue; }

        // resolve chapter for this row
        $chapterName = $b['topic_to_chapter'][$csvTopic] ?? null;
        if (!$chapterName) { $stat['errors'][] = "Row " . ($r+1) . ": no chapter mapping for topic '$csvTopic'"; continue; }

        if (!isset($chapCache[$chapterName])) {
            $chapCache[$chapterName]  = resolve_chapter_id($subjectId, $chapterName);
            $row0 = q1("SELECT COALESCE(MAX(sort),0) AS n FROM study_items WHERE chapter_id=?", [$chapCache[$chapterName]]);
            $sortCounter[$chapterName] = (int)$row0['n'];
            $stat['chapters'][$chapterName] = 0;
        }
        $chapId = $chapCache[$chapterName];

        $image = $stripImage ? null : ($imageCol === '' ? null : $imageCol);

        $sortCounter[$chapterName]++;
        $ins->execute([
            $chapId,
            $csvSub,           // topic (sub-topic from CSV becomes the in-chapter grouping)
            '',                // subtopic empty
            $question,
            $explain,
            $image,
            question_hash($question),
            $sortCounter[$chapterName],
        ]);
        if ($ins->rowCount() === 1) {
            $stat['added']++;
            $stat['chapters'][$chapterName]++;
        } else {
            $stat['dup']++;
            $sortCounter[$chapterName]--;   // don't waste sort numbers on dups
        }
    }
    audit('study.import', 'subject', $subjectId,
          ['bundle' => $b['title'] ?? '?', 'added' => $stat['added'], 'dup' => $stat['dup']]);
    return $stat;
}

$bundleKey = $_GET['bundle'] ?? '';
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $key = $_POST['bundle'] ?? '';
    $results = [];
    if ($key === 'all') {
        foreach ($bundles as $k => $b) { $results[$k] = do_import_bundle($b); }
    } elseif (isset($bundles[$key])) {
        $results[$key] = do_import_bundle($bundles[$key]);
    } else {
        flash('Unknown bundle: ' . $key, 'err');
        redirect('study_import.php');
    }
    // sum up
    $totals = ['added' => 0, 'dup' => 0, 'blank' => 0, 'errors' => 0];
    foreach ($results as $r) {
        $totals['added'] += $r['added'];
        $totals['dup']   += $r['dup'];
        $totals['blank'] += $r['blank'];
        $totals['errors'] += count($r['errors']);
    }
    flash("Imported {$totals['added']} item(s). Skipped {$totals['dup']} duplicate(s)."
        . ($totals['errors'] ? " {$totals['errors']} warning(s) — see report." : ''));
}

require __DIR__.'/includes/header.php';
?>
<div class="crumbs"><a href="study.php">Study Material</a> › <span>Import bundle</span></div>
<div class="phead"><h1><?php echo icon('download','lg'); ?> Import packaged study material</h1>
  <p>Drops pre-extracted CSV bundles into the right Class → Subject → Chapter automatically.</p></div>
<?php echo flash_render(); ?>

<?php if ($results): /* ---- result screen ---- */ ?>
  <?php foreach ($results as $k => $r): $b = $bundles[$k] ?? ['title' => $k]; ?>
    <div class="qcard">
      <h3 style="font-family:var(--disp);margin-bottom:4px"><?php echo e($b['title'] ?? $k); ?></h3>
      <p class="hint" style="margin:0 0 10px"><?php echo e(($b['class'] ?? '') . ' · ' . ($b['subject'] ?? '')); ?></p>
      <div class="ok-msg" style="margin:0 0 10px">
        Added <b><?php echo (int)$r['added']; ?></b> ·
        Duplicates skipped <b><?php echo (int)$r['dup']; ?></b> ·
        Blank rows <b><?php echo (int)$r['blank']; ?></b>
      </div>
      <?php if ($r['chapters']): ?>
        <div class="hint" style="margin-bottom:6px">Per chapter:</div>
        <ul class="hint" style="margin:0 0 8px 18px">
          <?php foreach ($r['chapters'] as $cn => $n): ?>
            <li><?php echo e($cn); ?> — <b><?php echo (int)$n; ?></b> item(s)</li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($r['errors']): ?>
        <div class="err" style="margin:6px 0;font-size:.85rem"><b>Warnings:</b><br>
          <?php foreach (array_slice($r['errors'], 0, 8) as $err): ?>
            <?php echo e($err); ?><br>
          <?php endforeach; ?>
          <?php if (count($r['errors']) > 8): ?>…and <?php echo count($r['errors'])-8; ?> more<?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="btnrow"><a class="btn" href="study.php">Open Study Material</a>
    <a class="btn ghost" href="study_import.php">Back to bundles</a></div>

<?php elseif ($bundleKey === 'all'): /* ---- preview ALL ---- */ ?>
  <div class="note">This will import <b><?php echo count($bundles); ?></b> bundle(s) — see below. Duplicates (same question text within a chapter) are skipped automatically.</div>
  <?php foreach ($bundles as $k => $b): ?>
    <div class="qcard">
      <h3 style="font-family:var(--disp);margin-bottom:4px"><?php echo e($b['title']); ?></h3>
      <p class="hint" style="margin:0"><?php echo e(($b['class'] ?? '') . ' · ' . ($b['subject'] ?? '')); ?> · CSV: <code><?php echo e($b['csv']); ?></code></p>
      <p style="margin:8px 0 0">Chapters created (or updated): <?php echo e(implode(' · ', array_values($b['topic_to_chapter']))); ?></p>
    </div>
  <?php endforeach; ?>
  <form method="post" class="btnrow"><?php echo csrf_field(); ?>
    <input type="hidden" name="bundle" value="all">
    <button class="btn green" type="submit">⬇ Import all bundles</button>
    <a class="btn ghost" href="study_import.php">Cancel</a>
  </form>

<?php elseif ($bundleKey && isset($bundles[$bundleKey])): /* ---- preview ONE ---- */
  $b = $bundles[$bundleKey];
?>
  <div class="qcard">
    <h3 style="font-family:var(--disp);margin-bottom:4px"><?php echo e($b['title']); ?></h3>
    <p class="hint" style="margin:0"><?php echo e(($b['class'] ?? '') . ' · ' . ($b['subject'] ?? '')); ?> · CSV: <code><?php echo e($b['csv']); ?></code></p>
    <p style="margin:10px 0 0">Will create / update the chapter(s): <b><?php echo e(implode(', ', array_values($b['topic_to_chapter']))); ?></b></p>
    <p class="hint" style="margin:6px 0 0">Duplicate questions (same text within a chapter) are skipped. Images are <?php echo !empty($b['strip_image']) ? 'stripped (add manually later if needed)' : 'kept'; ?>.</p>
  </div>
  <form method="post" class="btnrow"><?php echo csrf_field(); ?>
    <input type="hidden" name="bundle" value="<?php echo e($bundleKey); ?>">
    <button class="btn green" type="submit">⬇ Import this bundle</button>
    <a class="btn ghost" href="study_import.php">Back</a>
  </form>

<?php else: /* ---- list available bundles ---- */ ?>
  <?php if (!$bundles): ?>
    <div class="note empty">
      <b>No bundles found</b>
      Drop a JSON map in <code>imports/study-bundles.json</code> and CSVs in <code>imports/</code>.
    </div>
  <?php else: ?>
    <div class="toolbar">
      <a class="btn" href="study_import.php?bundle=all"><?php echo icon('download'); ?> Import all bundles (<?php echo count($bundles); ?>)</a>
      <span class="hint">Or pick one below ↓</span>
    </div>
    <div class="list">
    <?php foreach ($bundles as $k => $b): ?>
      <a class="row" href="study_import.php?bundle=<?php echo e($k); ?>" style="border-left-color:var(--brand-500)">
        <div><h3><?php echo e($b['title']); ?></h3>
          <p><?php echo e(($b['class'] ?? '') . ' · ' . ($b['subject'] ?? '')); ?> · <?php echo count($b['topic_to_chapter'] ?? []); ?> chapter(s)</p></div>
        <span class="badge"><?php echo e(basename($b['csv'])); ?></span>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
