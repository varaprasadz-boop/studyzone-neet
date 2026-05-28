<?php $ACTIVE='dashboard'; $PAGE='Dashboard';
require_once __DIR__.'/includes/ai.php';
require __DIR__.'/includes/header.php';
$admin = is_admin();
// quick counts for a live feel
$cChapters = (int)db()->query("SELECT COUNT(*) c FROM chapters")->fetch()['c'];
$cQuestions= (int)db()->query("SELECT COUNT(*) c FROM questions WHERE status='published'")->fetch()['c'];
$cTests    = (int)db()->query("SELECT COUNT(*) c FROM tests")->fetch()['c'];
?>
<div class="phead">
  <h1>Hi <?php echo e(explode(' ', $u['name'])[0]); ?> 👋</h1>
  <p><?php echo $admin ? 'Manage study material, papers, tests and reports.' : 'Study, practise and track your progress.'; ?></p>
</div>

<div class="grid">
  <a class="tile" href="study.php" style="--tc:#3b5bdb"><span class="ic">📘</span><h3>Study Material</h3>
    <p><?php echo $admin?'Create from syllabus':'Topics, formulas, flashcards'; ?></p></a>
  <a class="tile" href="questionbank.php" style="--tc:#0f8a7e"><span class="ic">🗂️</span><h3>Question Bank</h3>
    <p><?php echo $admin?'Upload &amp; extract papers':$cQuestions.' questions'; ?></p></a>
  <a class="tile" href="examzone.php" style="--tc:#c2683a"><span class="ic">📝</span><h3>Exam Zone</h3>
    <p><?php echo $admin?'Generate tests':$cTests.' tests available'; ?></p></a>
  <a class="tile" href="reports.php" style="--tc:#4a8c3f"><span class="ic">📊</span><h3>Reports</h3>
    <p><?php echo $admin?'Student analytics':'Your progress'; ?></p></a>
</div>

<div class="note" style="margin-top:18px">
  <b>All phases live:</b> Study Material (AI-generated from a syllabus image), Question Bank (AI-extracted from paper photos),
  Exam Zone (timed tests with NEET marking &amp; reshuffled options) and Reports (scores, accuracy and idle-free time).
  <?php if ($admin): ?><br><br><b>Reminders:</b> change the default passwords in <a href="account.php">Account</a><?php echo ai_enabled()?'':', and add your <code>ANTHROPIC_API_KEY</code> in <code>includes/config.php</code> to enable AI generation/extraction'; ?>.<?php endif; ?>
</div>
<?php require __DIR__.'/includes/footer.php'; ?>
