<?php
/* ============================================================
   Shared helpers used across phases 2–5.
   ============================================================ */
require_once __DIR__ . '/auth.php';

function redirect($url) { header('Location: ' . $url); exit; }

/* ---------- one-shot flash messages ---------- */
function flash($msg, $type = 'ok') { $_SESSION['flash'][] = ['m' => $msg, 't' => $type]; }
function flash_render() {
    if (empty($_SESSION['flash'])) return '';
    $out = '';
    foreach ($_SESSION['flash'] as $f) {
        $cls = $f['t'] === 'err' ? 'err' : 'ok-msg';
        $out .= '<div class="' . $cls . '">' . e($f['m']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- small DB conveniences ---------- */
function q1($sql, $params = []) { $st = db()->prepare($sql); $st->execute($params); return $st->fetch(); }
function qa($sql, $params = []) { $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function qcount($sql, $params = []) { $r = q1($sql, $params); return (int)(reset($r) ?: 0); }
function name_of($table, $id) {
    $allowed = ['classes','syllabi','subjects','chapters','topics'];
    if (!in_array($table, $allowed, true)) return '';
    $r = q1("SELECT name FROM $table WHERE id=?", [(int)$id]);
    return $r ? $r['name'] : '';
}

/* ---------- study content (Phase 2) ---------- */
function study_content_get($chapterId) {
    $out = [];
    foreach (qa("SELECT kind, data FROM study_content WHERE chapter_id=?", [(int)$chapterId]) as $row) {
        $out[$row['kind']] = json_decode($row['data'], true);
    }
    return $out;
}
function study_content_save($chapterId, $kind, $dataArray, $userId) {
    $existing = q1("SELECT id FROM study_content WHERE chapter_id=? AND kind=?", [(int)$chapterId, $kind]);
    $json = json_encode($dataArray, JSON_UNESCAPED_UNICODE);
    if ($existing) {
        db()->prepare("UPDATE study_content SET data=?, updated_at=NOW() WHERE id=?")
            ->execute([$json, $existing['id']]);
    } else {
        db()->prepare("INSERT INTO study_content (chapter_id, kind, data, created_by) VALUES (?,?,?,?)")
            ->execute([(int)$chapterId, $kind, $json, (int)$userId]);
    }
}

/* ---------- study items (bulk-uploaded Q&A material, Phase 2 redesign) ---------- */
function ensure_study_items() {
    db()->exec("CREATE TABLE IF NOT EXISTS study_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      chapter_id INT NOT NULL,
      topic VARCHAR(180) DEFAULT '',
      subtopic VARCHAR(180) DEFAULT '',
      question TEXT NOT NULL,
      explanation MEDIUMTEXT,
      image VARCHAR(220) DEFAULT NULL,
      qhash CHAR(40) NOT NULL,
      sort INT DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_chap_q (chapter_id, qhash),
      INDEX(chapter_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Hash used for duplicate detection — duplicates are judged on the QUESTION
   text only (topic/subtopic ignored), case- and whitespace-insensitive. */
function question_hash($question) {
    $n = strip_tags((string)$question);
    $n = preg_replace('/\s+/u', ' ', $n);
    $n = trim(function_exists('mb_strtolower') ? mb_strtolower($n, 'UTF-8') : strtolower($n));
    return sha1($n);
}

/* URL for a study item's image (uploaded file under uploads/study/{chapter},
   or a pasted web URL). */
function study_image_url($chapterId, $image) {
    $image = trim((string)$image);
    if ($image === '') return '';
    if (preg_match('#^https?://#i', $image)) return $image;
    return 'api/file.php?study=' . (int)$chapterId . '&f=' . rawurlencode(basename($image));
}

function chapter_item_count($chapterId) {
    return qcount("SELECT COUNT(*) FROM study_items WHERE chapter_id=?", [(int)$chapterId]);
}

/* Does this chapter have anything to study (hub file or uploaded items)? */
function chapter_has_content($chapter) {
    if (!empty($chapter['hub_file'])) return true;
    return chapter_item_count((int)$chapter['id']) > 0;
}

/* Distinct subjects for a class, ignoring syllabus. Returns one canonical row
   per subject name (prefers the NCERT row), so the UI can go Class → Subject. */
function study_subjects_for_class($classId) {
    $rows = qa("SELECT s.*, y.sort AS ysort FROM subjects s
                JOIN syllabi y ON y.id = s.syllabus_id
                WHERE s.class_id=? ORDER BY y.sort, s.sort, s.id", [(int)$classId]);
    $byName = [];
    foreach ($rows as $r) {
        if (!isset($byName[$r['name']])) $byName[$r['name']] = $r;  // first = lowest syllabus sort (NCERT)
    }
    return array_values($byName);
}

/* ---------- subjects list for AI auto-classification (Phase 3) ---------- */
function subjects_index() {
    return qa("SELECT s.id, s.name AS subject, c.name AS class, y.name AS syllabus
               FROM subjects s
               JOIN classes c ON c.id = s.class_id
               JOIN syllabi y ON y.id = s.syllabus_id
               ORDER BY c.sort, y.sort, s.sort");
}

/* Resolve a subject NAME (Physics/Chemistry/Botany/Zoology) to a canonical
   subject id, preferring Class 12 · NCERT. Used to file extracted questions. */
function resolve_subject_id($name) {
    $name = trim((string)$name);
    if ($name === '') return null;
    $r = q1("SELECT s.id FROM subjects s
             JOIN classes c ON c.id=s.class_id JOIN syllabi y ON y.id=s.syllabus_id
             WHERE LOWER(s.name)=LOWER(?)
             ORDER BY (c.name='Class 12') DESC, (y.name='NCERT') DESC, s.id LIMIT 1", [$name]);
    return $r ? (int)$r['id'] : null;
}

/* Find a chapter by name within a subject, creating it if asked. */
function resolve_chapter_id($subjectId, $chapName, $create = true) {
    $chapName = trim((string)$chapName);
    if (!$subjectId || $chapName === '') return null;
    $r = q1("SELECT id FROM chapters WHERE subject_id=? AND LOWER(name)=LOWER(?)", [(int)$subjectId, $chapName]);
    if ($r) return (int)$r['id'];
    if (!$create) return null;
    $n = q1("SELECT COALESCE(MAX(sort),0)+1 AS n FROM chapters WHERE subject_id=?", [(int)$subjectId]);
    db()->prepare("INSERT INTO chapters (subject_id, name, sort) VALUES (?,?,?)")
        ->execute([(int)$subjectId, $chapName, (int)$n['n']]);
    return (int)db()->lastInsertId();
}

/* NEET marking */
const NEET_CORRECT = 4;
const NEET_WRONG   = -1;
const NEET_SKIP    = 0;

/* Ensure the spaced-repetition table exists (for installs whose install.php
   predates this feature / was deleted after setup). Cheap CREATE IF NOT EXISTS. */
function ensure_sr() {
    db()->exec("CREATE TABLE IF NOT EXISTS flashcard_reviews (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      chapter_id INT NOT NULL,
      card_index INT NOT NULL,
      reps INT DEFAULT 0,
      ease DECIMAL(4,2) DEFAULT 2.50,
      interval_days INT DEFAULT 0,
      due_date DATE NOT NULL,
      UNIQUE KEY uniq_card (user_id, chapter_id, card_index),
      INDEX(user_id), INDEX(due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Inline figure for a question that relies on a diagram on its source page.
   Served through the login-gated api/file.php. */
function question_image_html($paperId, $imageRef) {
    if (!$paperId || !$imageRef) return '';
    $f = basename($imageRef);
    if (!preg_match('/^p\d+\.(jpg|jpeg|png|webp|gif)$/i', $f)) return '';
    $url = 'api/file.php?paper=' . (int)$paperId . '&f=' . e($f);
    return '<a href="' . $url . '" target="_blank" class="qimg-wrap" title="open full page">'
         . '<img class="qimg" src="' . $url . '" alt="question figure" loading="lazy"></a>';
}

function fmt_hms($seconds) {
    $seconds = max(0, (int)$seconds);
    $h = intdiv($seconds, 3600); $m = intdiv($seconds % 3600, 60); $s = $seconds % 60;
    if ($h) return sprintf('%dh %02dm', $h, $m);
    if ($m) return sprintf('%dm %02ds', $m, $s);
    return $s . 's';
}
