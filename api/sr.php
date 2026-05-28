<?php
/* ============================================================
   Spaced-repetition scheduler (SM-2 lite). Records a flashcard
   review and computes the next due date.
   ============================================================ */
require_once __DIR__ . '/../includes/lib.php';
require_login();
if (!csrf_ok()) json_out(['ok' => false, 'error' => 'Bad token'], 400);
ensure_sr();

$uid     = current_user()['id'];
$chapter = (int)($_POST['chapter'] ?? 0);
$idx     = (int)($_POST['idx'] ?? -1);
$rating  = $_POST['rating'] ?? '';
if (!$chapter || $idx < 0 || !in_array($rating, ['again','good','easy'], true)) {
    json_out(['ok' => false, 'error' => 'Bad input'], 400);
}

$row = q1("SELECT reps, ease, interval_days FROM flashcard_reviews WHERE user_id=? AND chapter_id=? AND card_index=?",
          [$uid, $chapter, $idx]);
$reps     = $row ? (int)$row['reps'] : 0;
$ease     = $row ? (float)$row['ease'] : 2.5;
$interval = $row ? (int)$row['interval_days'] : 0;

if ($rating === 'again') {
    $reps = 0;
    $ease = max(1.3, $ease - 0.20);
    $interval = 0;
} else {
    $reps++;
    if ($rating === 'easy') $ease = min(2.8, $ease + 0.15);
    if ($reps === 1)      $interval = ($rating === 'easy') ? 2 : 1;
    elseif ($reps === 2)  $interval = ($rating === 'easy') ? 6 : 3;
    else                  $interval = max(1, (int)round($interval * $ease * ($rating === 'easy' ? 1.3 : 1.0)));
}
$due = date('Y-m-d', strtotime("+$interval days"));

db()->prepare("INSERT INTO flashcard_reviews (user_id, chapter_id, card_index, reps, ease, interval_days, due_date)
               VALUES (?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE reps=VALUES(reps), ease=VALUES(ease),
                 interval_days=VALUES(interval_days), due_date=VALUES(due_date)")
    ->execute([$uid, $chapter, $idx, $reps, $ease, $interval, $due]);

json_out(['ok' => true, 'interval' => $interval, 'due' => $due]);
