<?php
/* ============================================================
   Phase 3 — AJAX endpoint. Extracts every question from ONE page
   image, classifies each by subject & chapter, and stores them as
   DRAFT questions under the paper. Called in a loop by paper_review.
   Re-running a page replaces its drafts (idempotent), keeps published.
   ============================================================ */
require_once __DIR__ . '/../includes/lib.php';
require_once __DIR__ . '/../includes/ai.php';
require_admin();
if (!csrf_ok()) json_out(['ok' => false, 'error' => 'Bad token'], 400);
@set_time_limit(0);   // host gateway is the real ceiling; don't let PHP cut it first

$paperId = (int)($_POST['paper'] ?? 0);
$file    = $_POST['page'] ?? '';
$paper = q1("SELECT * FROM papers WHERE id=?", [$paperId]);
if (!$paper) json_out(['ok' => false, 'error' => 'Unknown paper'], 404);
if (!preg_match('/^p\d+\.(jpg|jpeg|png|webp|gif)$/i', $file)) json_out(['ok' => false, 'error' => 'Bad page'], 400);
if (!ai_enabled()) json_out(['ok' => false, 'error' => 'No API key set.'], 400);

$path = __DIR__ . '/../uploads/papers/' . $paperId . '/' . $file;
if (!is_file($path)) json_out(['ok' => false, 'error' => 'Page image missing'], 404);

$ref = 'papers/' . $paperId . '/' . $file;

$subjectNames = array_column(qa("SELECT DISTINCT name FROM subjects ORDER BY name"), 'name');
$subjList = implode(', ', $subjectNames);

$system = 'You are an expert at reading scanned/photographed NEET (India) exam papers and digitising '
        . 'Multiple-Choice and integer/numeric questions accurately. Transcribe every mathematical or chemical '
        . 'expression as LaTeX between single dollar signs (e.g. $v=u+at$, $\frac{1}{2}mv^2$, $H_2SO_4$). '
        . 'Respond with ONLY a valid JSON array, no markdown or commentary. '
        . 'If a question relies on a diagram you cannot read, still transcribe its text and set "needs_image": true.';

$prompt = "This image is one page of a question paper. Extract EVERY complete question on it.\n"
        . "Classify each question's subject as one of: {$subjList}.\n"
        . "Also give the most likely NCERT chapter name.\n\n"
        . 'Return a JSON array; each element: '
        . '{"qtype":"mcq"|"numeric","stem":"full question text","options":["A","B","C","D"],'
        . '"correct_index":0,"correct_value":"","explanation":"short reason / solution","difficulty":"easy"|"medium"|"hard",'
        . '"subject":"one of the listed subjects","chapter":"chapter name","needs_image":false}'
        . "\nFor mcq give exactly 4 options and correct_index (0-based; use 0 if the answer is not shown). "
        . "For numeric leave options empty and put the answer in correct_value. "
        . "If the page has no questions, return [].";

$content = [ai_image_block($path), ai_text_block($prompt)];
$res = ai_call([['role' => 'user', 'content' => $content]], $system, 6000, 110);
if (!$res['ok']) json_out(['ok' => false, 'error' => $res['error']], 502);

$items = ai_json($res['text']);
if (!is_array($items)) json_out(['ok' => false, 'error' => 'Could not parse this page. Retry.'], 502);

// idempotent: clear previous drafts from this page
db()->prepare("DELETE FROM questions WHERE paper_id=? AND image_ref=? AND status='draft'")
    ->execute([$paperId, $ref]);

$ins = db()->prepare(
    "INSERT INTO questions
       (paper_id, subject_id, chapter_id, qtype, stem, options, correct_index, correct_value,
        explanation, difficulty, source, image_ref, status)
     VALUES (?,?,?,?,?,?,?,?,?,?, 'uploaded', ?, 'draft')"
);

$added = 0;
foreach ($items as $it) {
    $stem = trim($it['stem'] ?? '');
    if ($stem === '') continue;
    $qtype = ($it['qtype'] ?? 'mcq') === 'numeric' ? 'numeric' : 'mcq';
    $opts  = (isset($it['options']) && is_array($it['options'])) ? array_values($it['options']) : [];
    $ci    = isset($it['correct_index']) && $it['correct_index'] !== '' ? (int)$it['correct_index'] : null;
    if ($qtype === 'mcq' && (count($opts) < 2)) { $qtype = 'numeric'; }  // bad parse → treat as numeric
    $cv    = trim((string)($it['correct_value'] ?? ''));
    $diff  = in_array(($it['difficulty'] ?? ''), ['easy','medium','hard'], true) ? $it['difficulty'] : 'medium';
    $subjId= resolve_subject_id($it['subject'] ?? '');
    $chapId= $subjId ? resolve_chapter_id($subjId, $it['chapter'] ?? '') : null;
    $imgRef= !empty($it['needs_image']) ? $ref : null;

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

json_out(['ok' => true, 'page' => $file, 'added' => $added]);
