<?php
require_once __DIR__ . '/includes/lib.php';
require_once __DIR__ . '/includes/ai.php';
$ACTIVE = 'dashboard'; $PAGE = 'Dashboard';
require __DIR__ . '/includes/header.php';

$admin   = is_admin() || has_permission('users.manage');
$canEdit = has_permission('study.edit') || has_permission('question.publish');
$uid     = $u['id'];

/* live counts (admin sees totals; student sees personal) */
$cChapters  = qcount("SELECT COUNT(*) FROM chapters");
$cQuestions = qcount("SELECT COUNT(*) FROM questions WHERE status='published'");
$cTests     = qcount("SELECT COUNT(*) FROM tests");

/* student-side quick stats */
ensure_sr();
ensure_study_items();
$dueCards = qcount(
   "SELECT COUNT(*) FROM (
      SELECT si.id, si.chapter_id
      FROM study_items si
      LEFT JOIN flashcard_reviews fr ON fr.user_id=? AND fr.chapter_id=si.chapter_id AND fr.card_index=si.id
      WHERE si.explanation IS NOT NULL AND si.explanation <> ''
        AND (fr.id IS NULL OR fr.due_date <= CURRENT_DATE)
    ) x", [$uid]);
$todaySec = qcount("SELECT COALESCE(SUM(active_seconds),0) FROM activity_log WHERE user_id=? AND day=CURRENT_DATE", [$uid]);
$lastAtt  = q1("SELECT a.score, a.total, t.name FROM attempts a JOIN tests t ON t.id=a.test_id
                WHERE a.student_id=? AND a.status='completed' ORDER BY a.id DESC LIMIT 1", [$uid]);
$streak   = (int)user_streak($uid);
?>
<div class="phead">
  <h1>Hi <?php echo e(explode(' ', $u['name'])[0]); ?> 👋</h1>
  <p><?php echo $admin ? 'Manage users, content and tests — and see how your students are doing.' : 'Pick up where you left off.'; ?></p>
</div>

<div class="statcards" style="margin-bottom:18px">
  <div class="statcard"><div class="n" style="color:var(--gold);display:flex;justify-content:center;align-items:center;gap:6px"><?php echo icon('flame','lg'); ?><?php echo $streak; ?></div><div class="l"><?php echo $streak===1?'day streak':'day streak'; ?></div></div>
  <?php if (!$admin): ?>
    <div class="statcard"><div class="n" style="color:var(--brand-500);display:flex;justify-content:center;align-items:center;gap:6px"><?php echo icon('clock','lg'); ?><?php echo $dueCards; ?></div><div class="l">cards due</div></div>
    <div class="statcard"><div class="n"><?php echo fmt_hms($todaySec); ?></div><div class="l">studied today</div></div>
    <?php if ($lastAtt): ?>
    <div class="statcard"><div class="n" style="color:var(--green)"><?php echo (int)$lastAtt['score']; ?></div><div class="l">last score · <?php echo e($lastAtt['name']); ?></div></div>
    <?php endif; ?>
  <?php else: ?>
    <div class="statcard"><div class="n"><?php echo $cChapters; ?></div><div class="l">chapters</div></div>
    <div class="statcard"><div class="n"><?php echo $cQuestions; ?></div><div class="l">questions</div></div>
    <div class="statcard"><div class="n"><?php echo $cTests; ?></div><div class="l">tests</div></div>
  <?php endif; ?>
</div>

<?php if (!$admin):
  $badges = user_badges($uid);
  $earned = array_sum(array_map(fn($b) => $b['achieved'] ? 1 : 0, $badges));
  if ($badges): ?>
  <div class="sect" style="margin:8px 0 8px"><h2 style="font-size:1rem;font-weight:600">Badges <span class="hint" style="font-family:var(--mono);font-size:.74rem">· <?php echo $earned; ?> / <?php echo count($badges); ?></span></h2></div>
  <div class="badges">
    <?php foreach ($badges as $b): ?>
      <span class="badge <?php echo $b['achieved'] ? 'on' : ''; ?>" title="<?php echo e($b['hint']); ?>">
        <?php echo icon($b['icon']); ?><b><?php echo e($b['label']); ?></b>
      </span>
    <?php endforeach; ?>
  </div>
<?php endif; endif; ?>

<div class="grid">
  <a class="tile" href="study.php" style="--tc:var(--brand-500)">
    <span class="ic"><?php echo icon('book-open','xl'); ?></span><h3>Study Material</h3>
    <p><?php echo $canEdit ? 'Bulk-upload chapter notes' : 'Notes &amp; flashcards by chapter'; ?></p></a>
  <?php if (has_permission('study.view') || has_permission('study.edit')): ?>
  <a class="tile" href="questionbank.php" style="--tc:var(--brand-600)">
    <span class="ic"><?php echo icon('file-text','xl'); ?></span><h3>Question Bank</h3>
    <p><?php echo $canEdit ? 'Upload &amp; extract papers' : $cQuestions . ' questions'; ?></p></a>
  <?php endif; ?>
  <?php if (has_permission('test.attempt') || has_permission('test.create')): ?>
  <a class="tile" href="examzone.php" style="--tc:var(--gold)">
    <span class="ic"><?php echo icon('list-checks','xl'); ?></span><h3>Exam Zone</h3>
    <p><?php echo $canEdit ? 'Generate tests' : $cTests . ' tests available'; ?></p></a>
  <?php endif; ?>
  <?php if (has_permission('reports.view_self') || has_permission('reports.view_all')): ?>
  <a class="tile" href="reports.php" style="--tc:var(--green)">
    <span class="ic"><?php echo icon('bar-chart','xl'); ?></span><h3>Reports</h3>
    <p><?php echo has_permission('reports.view_all') ? 'Student analytics' : 'Your progress'; ?></p></a>
  <?php endif; ?>
</div>

<div class="toolbar" style="margin-top:22px">
<?php if ($admin): ?>
  <a class="btn ghost sm" href="users.php"><?php echo icon('users'); ?> Users</a>
  <a class="btn ghost sm" href="settings.php"><?php echo icon('settings'); ?> Settings</a>
  <a class="btn ghost sm" href="selftest.php"><?php echo icon('shield'); ?> Self-test</a>
  <a class="btn ghost sm" href="export.php"><?php echo icon('download'); ?> Backup</a>
<?php else: ?>
  <a class="btn ghost sm" href="study_review.php"><?php echo icon('zap'); ?> Flashcard review<?php echo $dueCards?' ('.$dueCards.')':''; ?></a>
  <a class="btn ghost sm" href="examzone.php"><?php echo icon('award'); ?> Practice mistakes</a>
<?php endif; ?>
</div>

<?php if ($admin):
  $recent = qa("SELECT al.action, al.entity, al.created_at, u.name AS who, u.username
                FROM audit_log al LEFT JOIN users u ON u.id = al.actor_user_id
                ORDER BY al.id DESC LIMIT 8");
  if ($recent): ?>
  <div class="sect" style="margin-top:22px"><h2 style="font-size:1rem;font-weight:600">Recent activity</h2></div>
  <div class="activity">
    <?php foreach ($recent as $r): ?>
      <div class="row-act">
        <?php echo icon('zap'); ?>
        <span class="who-name"><?php echo e($r['who'] ?: $r['username'] ?: 'system'); ?></span>
        <span class="what"><?php echo e(audit_label($r['action'])); ?><?php if ($r['entity']): ?> · <?php echo e($r['entity']); ?><?php endif; ?></span>
        <span class="when"><?php echo e(time_ago($r['created_at'])); ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; endif; ?>

<?php
$onlyTwo = qcount("SELECT COUNT(*) FROM users") <= 2;
if ($admin && $onlyTwo):
?>
  <div class="note" style="margin-top:18px">
    <b>Heads up:</b> only the seeded <code>appa</code> / <code>son</code> accounts exist.
    Create real users — and restrict each user to specific classes/subjects — in
    <a href="users.php">Users</a>. The defaults can be disabled afterwards.
    <?php if (!ai_enabled()): ?><br><br>Add your <code>ANTHROPIC_API_KEY</code> in <code>includes/config.php</code> to enable paper extraction.<?php endif; ?>
  </div>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
