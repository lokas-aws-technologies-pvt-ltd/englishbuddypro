<?php
/**
 * English Buddy - backend: sentences (admin only).
 * ---------------------------------------------------------------------------
 * Add, edit, reorder, retire and restore practice sentences, each with a level
 * and up to two hard words with a short meaning. The app loads the active
 * sentences from library.php in this order.
 * Every sentence keeps a permanent number (students' scores refer to it), so
 * sentences are retired rather than deleted. Saving creates an Indian-English
 * voice clip (Azure "Neerja") when an Azure key is set; otherwise the app uses
 * the phone's own voice for that sentence.
 * ---------------------------------------------------------------------------
 */
require __DIR__ . '/admin_lib.php';
require __DIR__ . '/tts_lib.php';
$me  = pc_require_admin();
$pdo = pc_db();
pc_sentences($pdo);                          // loads the original 25 the first time

const LEVELS = ['Easy', 'Medium', 'Hard'];
function wkey($s) { return preg_replace('/[^a-z]/', '', strtolower((string)$s)); }
function in_sentence($word, $text) {        // same matching the app uses to underline a hard word
  $k = wkey($word);
  if ($k === '') return false;
  foreach (preg_split('/\s+/', $text) as $tok) if (wkey($tok) === $k) return true;
  return false;
}
function renumber_sentences($pdo) {
  $ids = $pdo->query("SELECT id FROM sentences WHERE active=1 ORDER BY position, id")->fetchAll(PDO::FETCH_COLUMN);
  $u = $pdo->prepare("UPDATE sentences SET position=? WHERE id=?");
  foreach ($ids as $i => $id) $u->execute([$i + 1, $id]);
}
/* Read and check the form. Returns the clean row or throws. */
function sentence_from_post() {
  $text = trim(preg_replace('/\s+/', ' ', (string)($_POST['text'] ?? '')));
  $level = in_array($_POST['level'] ?? '', LEVELS, true) ? $_POST['level'] : 'Easy';
  if (mb_strlen($text) < 3 || mb_strlen($text) > 300) throw new Exception('The sentence should be 3 to 300 characters.');
  $row = ['text' => $text, 'level' => $level];
  foreach ([1, 2] as $k) {
    $w = trim((string)($_POST['word' . $k] ?? '')); $m = trim((string)($_POST['meaning' . $k] ?? ''));
    if ($w === '') { $row['word' . $k] = null; $row['meaning' . $k] = null; continue; }
    if (!in_sentence($w, $text)) throw new Exception('Hard word "' . $w . '" is not in the sentence. Type it exactly as it appears.');
    if ($m === '') throw new Exception('Add a short meaning for "' . $w . '". Students tap the word to see it.');
    $row['word' . $k] = mb_substr($w, 0, 40); $row['meaning' . $k] = mb_substr($m, 0, 160);
  }
  return $row;
}
/* Make voice clips; returns a short note for the message. */
function make_voice($pdo, $id, $row, $textChanged) {
  if (!pc_tts_ready()) {
    if ($textChanged) $pdo->prepare("UPDATE sentences SET audio=NULL WHERE id=?")->execute([$id]);
    return ' No Azure key in config.php, so the app will use the phone\'s voice for it.';
  }
  try {
    if ($textChanged) $pdo->prepare("UPDATE sentences SET audio=? WHERE id=?")->execute([pc_tts_sentence($id, $row['text']), $id]);
    foreach ([1, 2] as $k) if (!empty($row['word' . $k])) pc_tts_word($row['word' . $k]);
    return ' Indian-voice audio is ready.';
  } catch (Exception $e) {
    if ($textChanged) $pdo->prepare("UPDATE sentences SET audio=NULL WHERE id=?")->execute([$id]);
    return ' The voice clip could not be made (' . $e->getMessage() . '), so the app will use the phone\'s voice.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  pc_csrf_check();
  $do = (string)($_POST['do'] ?? '');
  try {
    if ($do === 'add') {
      $row = sentence_from_post();
      $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM sentences WHERE active=1")->fetchColumn();
      $pdo->prepare("INSERT INTO sentences (position, text, level, word1, meaning1, word2, meaning2) VALUES (?,?,?,?,?,?,?)")
          ->execute([$pos, $row['text'], $row['level'], $row['word1'], $row['meaning1'], $row['word2'], $row['meaning2']]);
      $id = (int)$pdo->lastInsertId();
      pc_flash('Added sentence #' . $id . '.' . make_voice($pdo, $id, $row, true));
    } elseif ($do === 'voice_all') {
      if (!pc_tts_ready()) throw new Exception('Add $AZURE_KEY and $AZURE_REGION to config.php first.');
      $made = 0; $failed = 0;
      foreach (pc_sentences($pdo) as $id => $r) {
        if ((int)$r['active'] !== 1) continue;
        $missing = !$r['audio'] || !is_file(__DIR__ . '/' . $r['audio']);
        foreach ([1, 2] as $k) if ($r['word' . $k] && !pc_word_audio($r['word' . $k])) $missing = true;
        if (!$missing) continue;
        try {
          if (!$r['audio'] || !is_file(__DIR__ . '/' . $r['audio'])) $pdo->prepare("UPDATE sentences SET audio=? WHERE id=?")->execute([pc_tts_sentence($id, $r['text']), $id]);
          foreach ([1, 2] as $k) if ($r['word' . $k]) pc_tts_word($r['word' . $k]);
          $made++;
        } catch (Exception $e) { $failed++; $lastErr = $e->getMessage(); }
      }
      pc_flash($made || $failed ? "Voice clips made for $made sentence" . ($made === 1 ? '' : 's') . ($failed ? "; $failed failed (" . $lastErr . ')' : '') . '.' : 'Every sentence already has its voice clips.', $failed ? 'err' : 'ok');
    } else {
      $id = (int)($_POST['id'] ?? 0);
      $st = $pdo->prepare("SELECT * FROM sentences WHERE id=?"); $st->execute([$id]); $s = $st->fetch();
      if (!$s) throw new Exception('That sentence no longer exists.');
      if ($do === 'edit') {
        $row = sentence_from_post();
        $changed = $row['text'] !== $s['text'];
        $pdo->prepare("UPDATE sentences SET text=?, level=?, word1=?, meaning1=?, word2=?, meaning2=?, updated_at=NOW() WHERE id=?")
            ->execute([$row['text'], $row['level'], $row['word1'], $row['meaning1'], $row['word2'], $row['meaning2'], $id]);
        pc_flash('Saved sentence #' . $id . '.' . make_voice($pdo, $id, $row, $changed));
      } elseif ($do === 'voice') {
        $r = ['text' => $s['text'], 'word1' => $s['word1'], 'word2' => $s['word2']];
        pc_flash('Sentence #' . $id . ':' . make_voice($pdo, $id, $r, true), pc_tts_ready() ? 'ok' : 'err');
      } elseif ($do === 'up' || $do === 'down') {
        renumber_sentences($pdo);
        $p = (int)$pdo->query("SELECT position FROM sentences WHERE id=" . $id)->fetchColumn();
        $q = $pdo->prepare("SELECT id FROM sentences WHERE active=1 AND position=?"); $q->execute([$do === 'up' ? $p - 1 : $p + 1]);
        if ($other = $q->fetchColumn()) {
          $pdo->prepare("UPDATE sentences SET position=? WHERE id=?")->execute([$do === 'up' ? $p - 1 : $p + 1, $id]);
          $pdo->prepare("UPDATE sentences SET position=? WHERE id=?")->execute([$p, $other]);
        }
      } elseif ($do === 'retire') {
        if ((int)$pdo->query("SELECT COUNT(*) FROM sentences WHERE active=1")->fetchColumn() <= 1) throw new Exception('Keep at least one sentence.');
        $pdo->prepare("UPDATE sentences SET active=0 WHERE id=?")->execute([$id]);
        renumber_sentences($pdo);
        pc_flash('Retired sentence #' . $id . '. Students no longer see it; their past scores stay.');
      } elseif ($do === 'restore') {
        $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM sentences WHERE active=1")->fetchColumn();
        $pdo->prepare("UPDATE sentences SET active=1, position=? WHERE id=?")->execute([$pos, $id]);
        pc_flash('Brought back sentence #' . $id . ' at the end of the list.');
      }
    }
  } catch (Exception $e) { pc_flash($e->getMessage(), 'err'); }
  $anchor = $do === 'add' ? '#add' : (isset($id) ? '#s' . (int)$id : '');
  pc_redirect('admin_sentences.php' . ($do === 'edit' && isset($e) ? '?edit=' . (int)$id : '') . $anchor);
}

/* ------------------------------ page ------------------------------ */
$all = pc_sentences($pdo);
$stats = [];
foreach ($pdo->query("SELECT phrase_idx, COUNT(*) n, ROUND(AVG(overall),1) avg FROM attempts WHERE phrase_idx IS NOT NULL GROUP BY phrase_idx") as $r) $stats[(int)$r['phrase_idx']] = $r;
$active = array_filter($all, function ($r) { return (int)$r['active'] === 1; });
$retired = array_filter($all, function ($r) { return (int)$r['active'] !== 1; });
$editId = (int)($_GET['edit'] ?? 0);
$tts = pc_tts_ready();
$missingVoice = 0;
foreach ($active as $r) if (!$r['audio'] || !is_file(__DIR__ . '/' . $r['audio'])) $missingVoice++;

function sentence_form($r, $do, $id = 0) {
  $v = function ($k) use ($r) { return h($r[$k] ?? ''); };
  ob_start(); ?>
  <form method="post" class="stack" style="gap:10px">
    <?= pc_csrf_field() ?><input type="hidden" name="do" value="<?= $do ?>"><?php if ($id): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
    <div class="row">
      <div class="f" style="flex-basis:420px"><label class="fl">Sentence</label><input type="text" name="text" required maxlength="300" value="<?= $v('text') ?>" placeholder="e.g. Please share the agenda before the meeting."></div>
      <div class="f s"><label class="fl">Level</label><select name="level"><?php foreach (LEVELS as $l): ?><option<?= ($r['level'] ?? 'Easy') === $l ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="row">
      <div class="f s" style="flex-basis:150px"><label class="fl">Hard word 1</label><input type="text" name="word1" maxlength="40" value="<?= $v('word1') ?>" placeholder="e.g. agenda"></div>
      <div class="f"><label class="fl">Meaning 1</label><input type="text" name="meaning1" maxlength="160" value="<?= $v('meaning1') ?>" placeholder="e.g. a list of things to discuss"></div>
    </div>
    <div class="row">
      <div class="f s" style="flex-basis:150px"><label class="fl">Hard word 2</label><input type="text" name="word2" maxlength="40" value="<?= $v('word2') ?>"></div>
      <div class="f"><label class="fl">Meaning 2</label><input type="text" name="meaning2" maxlength="160" value="<?= $v('meaning2') ?>"></div>
    </div>
    <div class="row" style="justify-content:space-between;align-items:center">
      <span class="hint">Hard words must appear in the sentence; students tap them for the meaning and sound.</span>
      <div><button class="btn primary" type="submit"><?= $do === 'add' ? 'Add sentence' : 'Save' ?></button><?php if ($do === 'edit'): ?> <a class="btn" href="admin_sentences.php#s<?= (int)$id ?>">Cancel</a><?php endif; ?></div>
    </div>
  </form>
  <?php return ob_get_clean();
}

pc_page_start('Sentences', 'sentences', $me);
?>
<style>
  .stext{ font-size:15.5px; font-weight:600; }
  .swords{ font-size:13px; color:var(--muted); margin-top:3px; }
  .swords b{ color:var(--accent); font-weight:600; }
  .lvl{ font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; padding:2px 8px; border-radius:999px; border:1px solid; }
  .lvl.Easy{ color:var(--good); } .lvl.Medium{ color:var(--warn); } .lvl.Hard{ color:var(--crit); }
  .snum{ font-family:var(--mono); font-size:12px; color:var(--dim); white-space:nowrap; }
  audio.mini{ height:30px; width:150px; vertical-align:middle; }
</style>
<div class="stack">
  <div class="card">
    <div class="row" style="align-items:center;justify-content:space-between">
      <div style="flex:1 1 320px">
        <b><?= count($active) ?> sentences</b> in the app<?= $retired ? ' · ' . count($retired) . ' retired' : '' ?>
        <div class="hint">Students see them in this order. Each keeps its number (#) for good, so past scores stay correct.
          <?= $tts ? 'Voice clips are made automatically when you save.' : 'No Azure key in config.php: new or edited sentences use the phone\'s voice.' ?></div>
      </div>
      <?php if ($tts && $missingVoice): ?>
      <form method="post" class="inline"><?= pc_csrf_field() ?><input type="hidden" name="do" value="voice_all"><button class="btn" type="submit">Make missing voice clips (<?= $missingVoice ?>)</button></form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <table class="list">
      <thead><tr><th>Sentence</th><th class="hideS">Voice</th><th class="hideS">Readings</th><th></th></tr></thead>
      <tbody>
      <?php $i = 0; $n = count($active); foreach ($active as $id => $r): $i++; ?>
        <?php if ($editId === $id): ?>
        <tr id="s<?= $id ?>"><td colspan="4"><div class="snum" style="margin-bottom:6px">Editing #<?= $id ?> · changing the words a lot? Retire it and add a new one instead, so old scores stay meaningful.</div><?= sentence_form($r, 'edit', $id) ?></td></tr>
        <?php else: $st = $stats[$id] ?? null; $hasVoice = $r['audio'] && is_file(__DIR__ . '/' . $r['audio']); ?>
        <tr id="s<?= $id ?>">
          <td>
            <div class="snum"><?= $i ?>. &nbsp;#<?= $id ?> &nbsp;<span class="lvl <?= h($r['level']) ?>"><?= h($r['level']) ?></span></div>
            <div class="stext"><?= h($r['text']) ?></div>
            <div class="swords"><?php foreach ([1, 2] as $k) if ($r['word' . $k]) echo '<b>' . h($r['word' . $k]) . '</b> – ' . h($r['meaning' . $k]) . ($k === 1 && $r['word2'] ? ' &nbsp;·&nbsp; ' : ''); ?></div>
          </td>
          <td class="hideS"><?= $hasVoice ? '<audio class="mini" controls preload="none" src="' . h($r['audio']) . '"></audio>' : '<span class="pill">phone voice</span>' ?></td>
          <td class="hideS num"><?= $st ? (int)$st['n'] . ' · avg ' . h($st['avg']) : '<span class="hint">none yet</span>' ?></td>
          <td class="act">
            <a class="btn sm" href="admin_sentences.php?edit=<?= $id ?>#s<?= $id ?>">Edit</a>
            <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow): if (($dir === 'up' && $i === 1) || ($dir === 'down' && $i === $n)) continue; ?>
            <form method="post" class="inline"><?= pc_csrf_field() ?><input type="hidden" name="do" value="<?= $dir ?>"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm" type="submit" title="Move <?= $dir ?>"><?= $arrow ?></button></form>
            <?php endforeach; ?>
            <?php if ($tts): ?><form method="post" class="inline"><?= pc_csrf_field() ?><input type="hidden" name="do" value="voice"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm" type="submit" title="Make the voice clip again">New voice</button></form><?php endif; ?>
            <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('Retire sentence #' . $id . '? Students stop seeing it; their past scores stay.')) ?>)"><?= pc_csrf_field() ?><input type="hidden" name="do" value="retire"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm danger" type="submit">Retire</button></form>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card" id="add">
    <div class="section-t">Add a sentence</div>
    <?= sentence_form(['level' => 'Easy'], 'add') ?>
  </div>

  <?php if ($retired): ?>
  <div class="card">
    <div class="section-t">Retired · students don't see these</div>
    <table class="list"><tbody>
      <?php foreach ($retired as $id => $r): ?>
      <tr id="s<?= $id ?>"><td><span class="snum">#<?= $id ?></span> <?= h($r['text']) ?></td>
        <td class="act"><form method="post" class="inline"><?= pc_csrf_field() ?><input type="hidden" name="do" value="restore"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm" type="submit">Bring back</button></form></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>
</div>
<?php pc_page_end();
