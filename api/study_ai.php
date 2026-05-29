<?php
/* ============================================================
   Phase 2 — AJAX endpoint. Generates study material for ONE chapter
   in ONE of two parts, so each HTTP request finishes well under a
   shared-host gateway timeout:
     part=core     → concepts + formulas (+ chapter row, topics)
     part=practice → flashcards + quiz
   study_generate.php calls both parts per chapter, sequentially.
   ============================================================ */
require_once __DIR__ . '/../includes/lib.php';
require_once __DIR__ . '/../includes/ai.php';
require_admin();
if (!csrf_ok()) json_out(['ok' => false, 'error' => 'Bad token'], 400);
@set_time_limit(0);   // the host gateway is the real ceiling; don't let PHP cut it first

$subjectId = (int)($_POST['subject'] ?? 0);
$chapName  = trim($_POST['chapter'] ?? '');
$topics    = trim($_POST['topics'] ?? '');
$part      = ($_POST['part'] ?? 'core') === 'practice' ? 'practice' : 'core';

$subj = q1("SELECT s.*, c.name AS class, y.name AS syllabus
            FROM subjects s JOIN classes c ON c.id=s.class_id JOIN syllabi y ON y.id=s.syllabus_id
            WHERE s.id=?", [$subjectId]);
if (!$subj)           json_out(['ok' => false, 'error' => 'Unknown subject'], 404);
if ($chapName === '') json_out(['ok' => false, 'error' => 'Missing chapter name'], 400);
if (!ai_enabled())    json_out(['ok' => false, 'error' => 'No API key — add ANTHROPIC_API_KEY in includes/config.php.'], 400);

$system = 'You are an expert NEET (India) tutor and content author. You produce dense, exam-focused, '
        . 'factually accurate study material for Class 11/12 NEET aspirants. '
        . 'You ALWAYS respond with a single valid JSON object and nothing else — no markdown, no commentary. '
        . 'Write EVERY mathematical or chemical expression as LaTeX between single dollar signs, e.g. '
        . '$v = u + at$, $\frac{1}{f} = \frac{1}{v} - \frac{1}{u}$, $E^\circ_{cell}$. Do not use HTML tags for math.';

$head = "Subject: {$subj['subject']}\nClass/Syllabus: {$subj['class']} · {$subj['syllabus']}\nChapter: {$chapName}";
if ($topics !== '') { $head .= "\nKnown topics to cover: {$topics}"; }

if ($part === 'core') {
    $ask = $head . "\n\n" . <<<'TXT'
Return JSON with EXACTLY this shape (concepts + formulas only):
{
  "tagline": "one short line describing the chapter's scope",
  "concepts": [
    { "g": "Group/section name",
      "pts": [ { "t": "point title", "d": "explanation; write math as LaTeX in $...$", "e": "short example/memory hook or empty string" } ] }
  ],
  "formulas": [ { "t": "what it is", "v": "the formula as LaTeX in $...$" } ]
}
Rules:
- 4 to 6 concept groups, 18 to 28 total concept points, basics → advanced.
- 6 to 12 formulas (use an empty array [] for non-quantitative chapters like most Botany/Zoology).
- Accurate to NCERT. No placeholders.
TXT;
    $maxTokens = 5000;
} else {
    $ask = $head . "\n\n" . <<<'TXT'
Return JSON with EXACTLY this shape (flashcards + quiz only):
{
  "flashcards": [ ["question / prompt", "concise answer"] ],
  "quiz": [ { "q": "MCQ question", "o": ["opt A","opt B","opt C","opt D"], "c": 0, "ex": "why the correct option is right" } ]
}
Rules:
- 10 to 14 flashcards covering the highest-yield recall facts.
- 12 to 16 quiz MCQs at NEET difficulty; exactly 4 options; "c" is the 0-based correct index; one-line "ex".
- Accurate to NCERT. No placeholders.
TXT;
    $maxTokens = 3500;
}

$res = ai_call([['role' => 'user', 'content' => $ask]], $system, $maxTokens, 110);
if (!$res['ok']) json_out(['ok' => false, 'error' => $res['error']], 502);

$data = ai_json($res['text']);
if (!is_array($data)) json_out(['ok' => false, 'error' => 'Could not parse generated content. Try again.'], 502);

$uid = current_user()['id'];

// find or create the chapter row (core creates it; practice reuses it)
$chap = q1("SELECT id FROM chapters WHERE subject_id=? AND name=?", [$subjectId, $chapName]);
if (!$chap) {
    $sortR = q1("SELECT COALESCE(MAX(sort),0)+1 AS n FROM chapters WHERE subject_id=?", [$subjectId]);
    db()->prepare("INSERT INTO chapters (subject_id, name, sort) VALUES (?,?,?)")
        ->execute([$subjectId, $chapName, (int)$sortR['n']]);
    $chapId = (int)db()->lastInsertId();
} else {
    $chapId = (int)$chap['id'];
}

if ($part === 'core') {
    if (empty($data['concepts'])) json_out(['ok' => false, 'error' => 'No concepts returned. Try again.'], 502);
    $concepts = ['tagline' => (string)($data['tagline'] ?? ''), 'groups' => $data['concepts']];
    study_content_save($chapId, 'concepts', $concepts, $uid);
    if (!empty($data['formulas']) && is_array($data['formulas'])) {
        study_content_save($chapId, 'formulas', array_values($data['formulas']), $uid);
    }
    if ($topics !== '') {
        foreach (preg_split('/[,\n]+/', $topics) as $i => $t) {
            $t = trim($t);
            if ($t === '') continue;
            if (!q1("SELECT id FROM topics WHERE chapter_id=? AND name=?", [$chapId, $t])) {
                db()->prepare("INSERT INTO topics (chapter_id, name, sort) VALUES (?,?,?)")->execute([$chapId, $t, $i]);
            }
        }
    }
    $points = 0;
    foreach ($data['concepts'] as $g) { $points += count($g['pts'] ?? []); }
    json_out(['ok' => true, 'chapId' => $chapId, 'counts' => ['points' => $points, 'formulas' => count($data['formulas'] ?? [])]]);
} else {
    if (!empty($data['flashcards']) && is_array($data['flashcards'])) {
        study_content_save($chapId, 'flashcards', array_values($data['flashcards']), $uid);
    }
    if (!empty($data['quiz']) && is_array($data['quiz'])) {
        study_content_save($chapId, 'quiz', array_values($data['quiz']), $uid);
    }
    json_out(['ok' => true, 'chapId' => $chapId, 'counts' => ['cards' => count($data['flashcards'] ?? []), 'quiz' => count($data['quiz'] ?? [])]]);
}
