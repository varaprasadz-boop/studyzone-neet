<?php
/* ============================================================
   Phase 4+ — autosave a single answer during a test so a reload
   (common on phones) doesn't lose progress. Grading still happens
   only on final submit (test_attempt.php).
   ============================================================ */
require_once __DIR__ . '/../includes/lib.php';
require_login();
if (!csrf_ok()) json_out(['ok' => false, 'error' => 'Bad token'], 400);

$uid       = current_user()['id'];
$attemptId = (int)($_POST['attempt'] ?? 0);
$answerId  = (int)($_POST['answer'] ?? 0);
$hasIdx    = array_key_exists('idx', $_POST) && $_POST['idx'] !== '';
$idx       = $hasIdx ? (int)$_POST['idx'] : null;
$val       = array_key_exists('val', $_POST) ? trim((string)$_POST['val']) : null;

// the attempt must belong to this user and still be open
$att = q1("SELECT id FROM attempts WHERE id=? AND student_id=? AND status='in_progress'", [$attemptId, $uid]);
if (!$att) json_out(['ok' => false, 'error' => 'Attempt not open'], 409);

// the answer row must belong to this attempt
$ans = q1("SELECT id FROM attempt_answers WHERE id=? AND attempt_id=?", [$answerId, $attemptId]);
if (!$ans) json_out(['ok' => false, 'error' => 'Unknown answer'], 404);

db()->prepare("UPDATE attempt_answers SET given_index=?, given_value=? WHERE id=?")
    ->execute([$idx, ($val === '' ? null : $val), $answerId]);

json_out(['ok' => true]);
