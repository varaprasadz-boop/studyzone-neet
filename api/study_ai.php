<?php
/* ============================================================
   Phase 2 — AJAX endpoint. Generates full study material for ONE
   chapter (called in a loop by study_generate.php so each request
   stays well within shared-hosting time limits).
   ============================================================ */
require_once __DIR__ . '/../includes/lib.php';
require_once __DIR__ . '/../includes/ai.php';
require_admin();
if (!csrf_ok()) json_out(['ok' => false, 'error' => 'Bad token'], 400);

$subjectId = (int)($_POST['subject'] ?? 0);
$chapName  = trim($_POST['chapter'] ?? '');
$topics    = trim($_POST['topics'] ?? '');

$subj = q1("SELECT s.*, c.name AS class, y.name AS syllabus
            FROM subjects s JOIN classes c ON c.id=s.class_id JOIN syllabi y ON y.id=s.syllabus_id
            WHERE s.id=?", [$subjectId]);
if (!$subj)        json_out(['ok' => false, 'error' => 'Unknown subject'], 404);
if ($chapName === '') json_out(['ok' => false, 'error' => 'Missing chapter name'], 400);
if (!ai_enabled())  json_out(['ok' => false, 'error' => 'No API key — add ANTHROPIC_API_KEY in includes/config.php.'], 400);

$system = 'You are an expert NEET (India) tutor and content author. You produce dense, exam-focused, '
        . 'factually accurate study material for Class 11/12 NEET aspirants. '
        . 'You ALWAYS respond with a single valid JSON object and nothing else — no markdown, no commentary. '
        . 'Write EVERY mathematical or chemical expression as LaTeX between single dollar signs, e.g. '
        . '$v = u + at$, $\frac{1}{f} = \frac{1}{v} - \frac{1}{u}$, $E^\circ_{cell}$. Do not use HTML tags for math.';

$schema = <<<TXT
Generate complete study material for this chapter.

Subject: {$subj['subject']}
Class/Syllabus: {$subj['class']} · {$subj['syllabus']}
Chapter: {$chapName}
TXT;
if ($topics !== '') { $schema .= "\nKnown topics to cover: {$topics}"; }

$schema .= <<<'TXT'


Return JSON with EXACTLY this shape:
{
  "tagline": "one short line describing the chapter's scope",
  "concepts": [
    { "g": "Group/section name",
      "pts": [ { "t": "point title", "d": "explanation; write math as LaTeX in $...$", "e": "a short example or memory hook, or empty string" } ]
    }
  ],
  "formulas": [ { "t": "what it is", "v": "the formula in plain text/unicode" } ],
  "flashcards": [ ["question / prompt", "concise answer"] ],
  "quiz": [ { "q": "MCQ question", "o": ["opt A","opt B","opt C","opt D"], "c": 0, "ex": "why the correct option is right" } ]
}

Rules:
- 5 to 8 concept groups, 25 to 45 total concept points, ordered from basics to advanced.
- 8 to 16 formulas (omit the array if the chapter is non-quantitative like most Botany/Zoology).
- 12 to 16 flashcards covering the highest-yield recall facts.
- 16 to 22 quiz MCQs at NEET difficulty; exactly 4 options each; "c" is the 0-based index of the correct option; include a one-line "ex".
- Be accurate to NCERT. No placeholders.
TXT;

$res = ai_call(
    [['role' => 'user', 'content' => $schema]],
    $system,
    8192
);
if (!$res['ok']) json_out(['ok' => false, 'error' => $res['error']], 502);

$data = ai_json($res['text']);
if (!is_array($data) || empty($data['concepts'])) {
    json_out(['ok' => false, 'error' => 'Could not parse generated content. Try again.'], 502);
}

$uid = current_user()['id'];

// find or create the chapter row
$chap = q1("SELECT * FROM chapters WHERE subject_id=? AND name=?", [$subjectId, $chapName]);
if (!$chap) {
    $sortR = q1("SELECT COALESCE(MAX(sort),0)+1 AS n FROM chapters WHERE subject_id=?", [$subjectId]);
    db()->prepare("INSERT INTO chapters (subject_id, name, sort) VALUES (?,?,?)")
        ->execute([$subjectId, $chapName, (int)$sortR['n']]);
    $chapId = (int)db()->lastInsertId();
} else {
    $chapId = (int)$chap['id'];
}

// store tagline in topics? keep tagline inside concepts payload via a wrapper
$concepts = ['tagline' => (string)($data['tagline'] ?? ''), 'groups' => $data['concepts']];
study_content_save($chapId, 'concepts', $concepts, $uid);

if (!empty($data['formulas']) && is_array($data['formulas'])) {
    study_content_save($chapId, 'formulas', array_values($data['formulas']), $uid);
}
if (!empty($data['flashcards']) && is_array($data['flashcards'])) {
    study_content_save($chapId, 'flashcards', array_values($data['flashcards']), $uid);
}
if (!empty($data['quiz']) && is_array($data['quiz'])) {
    study_content_save($chapId, 'quiz', array_values($data['quiz']), $uid);
}

// save topics list too (for later test generation)
if ($topics !== '') {
    foreach (preg_split('/[,\n]+/', $topics) as $i => $t) {
        $t = trim($t);
        if ($t === '') continue;
        $exists = q1("SELECT id FROM topics WHERE chapter_id=? AND name=?", [$chapId, $t]);
        if (!$exists) db()->prepare("INSERT INTO topics (chapter_id, name, sort) VALUES (?,?,?)")->execute([$chapId, $t, $i]);
    }
}

$pointCount = 0;
foreach ($data['concepts'] as $g) { $pointCount += count($g['pts'] ?? []); }

json_out([
    'ok'      => true,
    'chapter' => $chapName,
    'chapId'  => $chapId,
    'counts'  => [
        'points'  => $pointCount,
        'formulas'=> count($data['formulas'] ?? []),
        'cards'   => count($data['flashcards'] ?? []),
        'quiz'    => count($data['quiz'] ?? []),
    ],
]);
