<?php
/* ============================================================
   Phase 5 — Reports.
   Student: own scores, accuracy and study time.
   Admin: pick a student and see the same analytics.
   Charts are inline SVG (includes/chart.php) — no external libs.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/chart.php';
$ACTIVE = 'reports'; $PAGE = 'Reports';
require __DIR__.'/includes/header.php';
$admin = is_admin();

// whose data are we showing?
if ($admin) {
    $students = qa("SELECT id, name, username FROM users WHERE role='student' ORDER BY name");
    $who = (int)($_GET['student'] ?? ($students[0]['id'] ?? 0));
} else {
    $who = current_user()['id'];
}
$student = $who ? q1("SELECT id, name FROM users WHERE id=?", [$who]) : null;
?>
<div class="phead"><h1>📊 Reports</h1>
  <p><?php echo $admin?'Performance and idle-free study time.':'Your performance and study time.'; ?></p></div>

<?php if ($admin): ?>
  <form method="get" class="toolbar">
    <label style="margin:0 8px 0 0">Student</label>
    <select name="student" onchange="this.form.submit()">
      <?php foreach ($students as $s): ?>
        <option value="<?php echo $s['id']; ?>" <?php echo $who===(int)$s['id']?'selected':''; ?>><?php echo e($s['name']); ?> (<?php echo e($s['username']); ?>)</option>
      <?php endforeach; ?>
    </select>
  </form>
<?php endif; ?>

<?php if (!$student): ?>
  <div class="note">No student to report on yet.</div>
  <?php require __DIR__.'/includes/footer.php'; exit; ?>
<?php endif; ?>

<?php
/* ---- headline numbers ---- */
$nTests   = qcount("SELECT COUNT(*) FROM attempts WHERE student_id=? AND status='completed'", [$who]);
$avgScore = q1("SELECT AVG(score) a FROM attempts WHERE student_id=? AND status='completed'", [$who])['a'];
$studySec = qcount("SELECT COALESCE(SUM(active_seconds),0) FROM activity_log WHERE user_id=?", [$who]);
$qAnswered= qcount("SELECT COUNT(*) FROM attempt_answers aa JOIN attempts a ON a.id=aa.attempt_id
                    WHERE a.student_id=? AND (aa.given_index IS NOT NULL OR (aa.given_value IS NOT NULL AND aa.given_value<>''))", [$who]);
?>
<div class="statcards">
  <div class="statcard"><div class="n"><?php echo $nTests; ?></div><div class="l">Tests taken</div></div>
  <div class="statcard"><div class="n"><?php echo $avgScore!==null?round($avgScore):'—'; ?></div><div class="l">Avg score</div></div>
  <div class="statcard"><div class="n"><?php echo $qAnswered; ?></div><div class="l">Questions answered</div></div>
  <div class="statcard"><div class="n"><?php echo fmt_hms($studySec); ?></div><div class="l">Total time</div></div>
</div>

<?php
/* ---- scores over time ---- */
$attempts = qa("SELECT a.score, a.total, a.started_at, t.name
                FROM attempts a JOIN tests t ON t.id=a.test_id
                WHERE a.student_id=? AND a.status='completed' ORDER BY a.started_at", [$who]);
$pts = [];
foreach ($attempts as $a) {
    $max = max(1, (int)$a['total'] * NEET_CORRECT);
    $pts[] = ['label' => date('d/m', strtotime($a['started_at'])), 'value' => max(0, round((int)$a['score'] / $max * 100))];
}
?>
<div class="sect"><h2>Test scores over time</h2><p>Each point is one completed attempt (% of max).</p></div>
<?php echo chart_line($pts, ['max' => 100, 'color' => '#0f8a7e']); ?>

<?php
/* ---- accuracy by subject ---- */
$bySubj = qa("SELECT COALESCE(s.name,'Unclassified') AS name,
                     SUM(aa.is_correct) AS correct,
                     SUM(CASE WHEN aa.given_index IS NOT NULL OR (aa.given_value IS NOT NULL AND aa.given_value<>'') THEN 1 ELSE 0 END) AS answered
              FROM attempt_answers aa
              JOIN attempts a ON a.id=aa.attempt_id
              JOIN questions q ON q.id=aa.question_id
              LEFT JOIN subjects s ON s.id=q.subject_id
              WHERE a.student_id=? AND a.status='completed'
              GROUP BY s.name HAVING answered > 0 ORDER BY answered DESC", [$who]);
$subjRows = [];
foreach ($bySubj as $r) {
    $pct = $r['answered'] > 0 ? $r['correct'] / $r['answered'] * 100 : 0;
    $subjRows[] = ['label' => $r['name'], 'pct' => $pct, 'sub' => $r['correct'].'/'.$r['answered'].' ('.round($pct).'%)'];
}
?>
<div class="sect"><h2>Accuracy by subject</h2></div>
<?php echo chart_hbars($subjRows, ['color' => '#3b5bdb']); ?>

<?php
/* ---- accuracy by chapter (top 8 by volume) ---- */
$byChap = qa("SELECT ch.name AS name,
                     SUM(aa.is_correct) AS correct,
                     SUM(CASE WHEN aa.given_index IS NOT NULL OR (aa.given_value IS NOT NULL AND aa.given_value<>'') THEN 1 ELSE 0 END) AS answered
              FROM attempt_answers aa
              JOIN attempts a ON a.id=aa.attempt_id
              JOIN questions q ON q.id=aa.question_id
              JOIN chapters ch ON ch.id=q.chapter_id
              WHERE a.student_id=? AND a.status='completed'
              GROUP BY ch.name HAVING answered > 0 ORDER BY answered DESC LIMIT 8", [$who]);
$chapRows = [];
foreach ($byChap as $r) {
    $pct = $r['answered'] > 0 ? $r['correct'] / $r['answered'] * 100 : 0;
    $chapRows[] = ['label' => $r['name'], 'pct' => $pct, 'sub' => round($pct).'% · '.$r['answered'].'Q'];
}
?>
<?php if ($chapRows): ?>
<div class="sect"><h2>Accuracy by chapter</h2><p>Most-practised chapters.</p></div>
<?php echo chart_hbars($chapRows, ['color' => '#4a8c3f']); ?>
<?php endif; ?>

<?php
/* ---- time by area ---- */
$byScreen = qa("SELECT screen, SUM(active_seconds) AS secs FROM activity_log WHERE user_id=? GROUP BY screen ORDER BY secs DESC", [$who]);
$niceName = ['study'=>'Study','questionbank'=>'Q-Bank','examzone'=>'Exam','dashboard'=>'Dashboard','reports'=>'Reports','account'=>'Account'];
$screenBars = [];
foreach ($byScreen as $r) {
    if ((int)$r['secs'] < 1) continue;
    $screenBars[] = ['label' => $niceName[$r['screen']] ?? ucfirst($r['screen']), 'value' => round($r['secs'] / 60, 1)];
}
?>
<div class="sect"><h2>Time spent by area</h2><p>Engaged minutes (idle time over 5 min is excluded automatically).</p></div>
<?php echo chart_bars($screenBars, ['unit' => 'm', 'color' => '#c2683a']); ?>

<?php
/* ---- last 7 days ---- */
$since = date('Y-m-d', strtotime('-6 days'));
$byDay = qa("SELECT day, SUM(active_seconds) AS secs FROM activity_log WHERE user_id=? AND day>=? GROUP BY day", [$who, $since]);
$map = []; foreach ($byDay as $r) { $map[$r['day']] = (int)$r['secs']; }
$dayBars = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dayBars[] = ['label' => date('D', strtotime($d)), 'value' => round(($map[$d] ?? 0) / 60, 1)];
}
?>
<div class="sect"><h2>Last 7 days</h2><p>Active minutes per day.</p></div>
<?php echo chart_bars($dayBars, ['unit' => 'm', 'color' => '#b8893b']); ?>

<?php require __DIR__.'/includes/footer.php'; ?>
