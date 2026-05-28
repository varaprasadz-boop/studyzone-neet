<?php
/* ============================================================
   Phase 5 — records engaged (non-idle) seconds per screen/chapter.
   Called by assets/js/app.js every ~20s and on page-hide. Idle time
   (>5 min inactivity) is never sent by the client, so it's excluded.
   ============================================================ */
require_once __DIR__ . '/../includes/lib.php';
header('Content-Type: application/json');
if (!current_user()) { http_response_code(401); echo '{"ok":false}'; exit; }

$uid     = current_user()['id'];
$screen  = substr(trim($_POST['screen'] ?? 'app'), 0, 60) ?: 'app';
$seconds = (int)($_POST['seconds'] ?? 0);
$subject = isset($_POST['subject']) && $_POST['subject'] !== '' ? (int)$_POST['subject'] : null;
$chapter = isset($_POST['chapter']) && $_POST['chapter'] !== '' ? (int)$_POST['chapter'] : null;

// guard against bogus/huge deltas (a single beacon covers ≤ a few minutes)
if ($seconds < 1) { echo '{"ok":true,"skip":1}'; exit; }
if ($seconds > 600) { $seconds = 600; }

$day = date('Y-m-d');
$row = q1("SELECT id FROM activity_log
           WHERE user_id=? AND day=? AND screen=? AND subject_id <=> ? AND chapter_id <=> ?
           LIMIT 1", [$uid, $day, $screen, $subject, $chapter]);
if ($row) {
    db()->prepare("UPDATE activity_log SET active_seconds = active_seconds + ? WHERE id=?")
        ->execute([$seconds, $row['id']]);
} else {
    db()->prepare("INSERT INTO activity_log (user_id, screen, subject_id, chapter_id, active_seconds, day)
                   VALUES (?,?,?,?,?,?)")
        ->execute([$uid, $screen, $subject, $chapter, $seconds, $day]);
}

echo json_encode(['ok' => true]);
