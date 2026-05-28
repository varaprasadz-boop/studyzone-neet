<?php
/* ============================================================
   Phase 2+ — Admin: edit a chapter's generated study material as
   structured JSON (concepts, formulas, flashcards, quiz). Lets you
   fix a single point without regenerating the whole chapter, or
   author content by hand.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'study'; $PAGE = 'Edit Material';
require_admin();

$chapId = (int)($_GET['chapter'] ?? $_POST['chapter'] ?? 0);
$chap = q1("SELECT ch.*, s.name AS subject, s.id AS subject_id FROM chapters ch JOIN subjects s ON s.id=ch.subject_id WHERE ch.id=?", [$chapId]);
if (!$chap) { require __DIR__.'/includes/header.php'; echo '<div class="note">Chapter not found.</div>'; require __DIR__.'/includes/footer.php'; exit; }

$kinds = ['concepts','formulas','flashcards','quiz'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach ($kinds as $k) {
        $raw = trim($_POST[$k] ?? '');
        if ($raw === '') {
            db()->prepare("DELETE FROM study_content WHERE chapter_id=? AND kind=?")->execute([$chapId, $k]);
            continue;
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) { $errors[$k] = 'Invalid JSON — not saved.'; continue; }
        study_content_save($chapId, $k, $decoded, current_user()['id']);
    }
    if (!$errors) { flash('Study material saved.'); redirect('study_chapter.php?chapter=' . $chapId); }
    flash('Some sections had invalid JSON and were skipped — see below.', 'err');
}

$content = study_content_get($chapId);
function pretty($v) {
    if ($v === null) return '';
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
$hints = [
  'concepts'   => '{"tagline":"…","groups":[{"g":"Group","pts":[{"t":"title","d":"text with $LaTeX$","e":"example"}]}]}',
  'formulas'   => '[{"t":"what it is","v":"formula"}]',
  'flashcards' => '[["question","answer"]]',
  'quiz'       => '[{"q":"…","o":["A","B","C","D"],"c":0,"ex":"…"}]',
];

require __DIR__.'/includes/header.php';
?>
<div class="crumbs">
  <a href="study.php?subject=<?php echo $chap['subject_id']; ?>"><?php echo e($chap['subject']); ?></a> ›
  <a href="study_chapter.php?chapter=<?php echo $chapId; ?>"><?php echo e($chap['name']); ?></a> › <span>Edit</span>
</div>
<div class="phead"><h1>✏ Edit material — <?php echo e($chap['name']); ?></h1>
  <p>Each box is JSON. Leave a box empty to remove that section. Math goes in <code>$…$</code> (LaTeX).</p></div>
<?php echo flash_render(); ?>

<form method="post">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="chapter" value="<?php echo $chapId; ?>">
  <?php foreach ($kinds as $k): ?>
    <div class="field">
      <label><?php echo ucfirst($k); ?><?php if (isset($errors[$k])): ?> <span style="color:var(--red)">— <?php echo e($errors[$k]); ?></span><?php endif; ?></label>
      <textarea name="<?php echo $k; ?>" rows="<?php echo $k==='concepts'?14:8; ?>" spellcheck="false" style="font-family:var(--mono);font-size:.82rem"><?php echo e(pretty($content[$k] ?? null)); ?></textarea>
      <div class="hint">Shape: <code><?php echo e($hints[$k]); ?></code></div>
    </div>
  <?php endforeach; ?>
  <div class="btnrow"><button class="btn" type="submit">Save material</button>
    <a class="btn ghost" href="study_chapter.php?chapter=<?php echo $chapId; ?>">View</a></div>
</form>
<?php require __DIR__.'/includes/footer.php'; ?>
