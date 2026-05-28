<?php
/* ============================================================
   Spaced-repetition flashcard review (SM-2 lite).
   Shows cards that are new or due today, across every chapter that
   has generated flashcards. Rating each card schedules its next review.
   ============================================================ */
require_once __DIR__ . '/includes/lib.php';
$ACTIVE = 'study'; $PAGE = 'Flashcard Review';
require_login();
ensure_sr();
$uid = current_user()['id'];
$today = date('Y-m-d');
$NEW_CAP = 20;

// all flashcards across chapters with generated content
$cards = [];
foreach (qa("SELECT sc.chapter_id, sc.data, ch.name AS chapter
             FROM study_content sc JOIN chapters ch ON ch.id=sc.chapter_id
             WHERE sc.kind='flashcards'") as $r) {
    $list = json_decode($r['data'], true);
    if (!is_array($list)) continue;
    foreach ($list as $i => $fc) {
        if (!is_array($fc) || count($fc) < 2) continue;
        $cards[] = ['chapter_id' => (int)$r['chapter_id'], 'chapter' => $r['chapter'], 'idx' => $i, 'q' => $fc[0], 'a' => $fc[1]];
    }
}

// existing schedule
$sched = [];
foreach (qa("SELECT chapter_id, card_index, due_date FROM flashcard_reviews WHERE user_id=?", [$uid]) as $r) {
    $sched[$r['chapter_id'] . ':' . $r['card_index']] = $r['due_date'];
}

$dueDeck = []; $newDeck = []; $nextDue = null;
foreach ($cards as $c) {
    $key = $c['chapter_id'] . ':' . $c['idx'];
    if (!isset($sched[$key])) { $newDeck[] = $c; }
    elseif ($sched[$key] <= $today) { $dueDeck[] = $c; }
    else { if ($nextDue === null || $sched[$key] < $nextDue) $nextDue = $sched[$key]; }
}
shuffle($newDeck); shuffle($dueDeck);
$deck = array_merge($dueDeck, array_slice($newDeck, 0, $NEW_CAP));
shuffle($deck);

require __DIR__.'/includes/header.php';
?>
<div class="crumbs"><a href="study.php">Study</a> › <span>Flashcard review</span></div>
<div class="phead"><h1>🔁 Flashcard review</h1>
  <p>Spaced repetition — rate each card and it comes back at the right time.</p></div>

<div class="statcards">
  <div class="statcard"><div class="n" style="color:var(--acc)"><?php echo count($dueDeck); ?></div><div class="l">Due today</div></div>
  <div class="statcard"><div class="n" style="color:var(--green)"><?php echo min(count($newDeck), $NEW_CAP); ?></div><div class="l">New</div></div>
  <div class="statcard"><div class="n"><?php echo count($cards); ?></div><div class="l">Total cards</div></div>
</div>

<?php if (!$deck): ?>
  <div class="note">🎉 All caught up — nothing due right now.
    <?php if ($nextDue): ?> Next review on <b><?php echo e(date('d M Y', strtotime($nextDue))); ?></b>.<?php endif; ?>
    <?php if (!$cards): ?> (No flashcards exist yet — generate study material first.)<?php endif; ?>
  </div>
<?php else: ?>
  <div class="qbanner" id="srProgress"><b><?php echo count($deck); ?></b> cards in this session</div>
  <div id="srBox"></div>
  <script>window.__DECK = <?php echo json_encode($deck, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;</script>
  <script>
  (function(){
    var deck = window.__DECK.slice();
    var CSRF = <?php echo json_encode(csrf_token()); ?>;
    var box = document.getElementById('srBox'), prog = document.getElementById('srProgress');
    var done = 0, total = deck.length, i = 0;

    function rate(card, rating){
      var fd = new FormData();
      fd.append('_csrf', CSRF); fd.append('chapter', card.chapter_id);
      fd.append('idx', card.idx); fd.append('rating', rating);
      fetch('api/sr.php', {method:'POST', body:fd, keepalive:true}).catch(function(){});
      if (rating === 'again') { deck.push(card); }   // see it again later this session
      done++; i++; render();
    }

    function render(){
      if (i >= deck.length) {
        box.innerHTML = '<div class="qcard" style="text-align:center"><div class="scbig">✓</div>' +
          '<p style="color:var(--muted)">Session complete — reviewed ' + done + ' card(s).</p>' +
          '<div class="btnrow" style="justify-content:center"><a class="btn" href="study_review.php">Review again</a>' +
          '<a class="btn ghost" href="dashboard.php">Done</a></div></div>';
        prog.innerHTML = 'Reviewed <b>' + done + '</b> card(s)';
        return;
      }
      var c = deck[i];
      prog.innerHTML = 'Card <b>' + (done + 1) + '</b> · ' + (deck.length - i - 1) + ' left';
      box.innerHTML =
        '<div class="qcard"><div class="qno">' + (c.chapter || '') + '</div>' +
        '<div class="qtext" id="srQ"></div>' +
        '<div class="ex" id="srA" style="display:none"></div>' +
        '<div class="btnrow" id="srShowRow"><button class="btn full" id="srShow">Show answer</button></div>' +
        '<div class="btnrow" id="srRate" style="display:none">' +
          '<button class="btn danger sm" data-r="again">Again</button>' +
          '<button class="btn sm" data-r="good">Good</button>' +
          '<button class="btn green sm" data-r="easy">Easy</button>' +
        '</div></div>';
      document.getElementById('srQ').innerHTML = c.q;
      var ab = document.getElementById('srA'); ab.innerHTML = c.a;
      if (window.__renderMath) window.__renderMath(box);
      document.getElementById('srShow').onclick = function(){
        ab.style.display = 'block';
        document.getElementById('srShowRow').style.display = 'none';
        document.getElementById('srRate').style.display = 'flex';
      };
      box.querySelectorAll('#srRate button').forEach(function(b){
        b.onclick = function(){ rate(c, b.getAttribute('data-r')); };
      });
    }
    render();
  })();
  </script>
<?php endif; ?>
<?php require __DIR__.'/includes/footer.php'; ?>
