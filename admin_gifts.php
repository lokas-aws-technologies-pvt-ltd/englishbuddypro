<?php
/**
 * English Buddy - backend: gifts and points (admin only).
 * ---------------------------------------------------------------------------
 * - Points rules: gift steps, the step after the last one, daily practice cap,
 *   points per personal-best step, mastery bonus.
 * - The gift list: card 1 = gift 1, card 2 = gift 2 ... after the last gift it
 *   repeats. Each gift has a name, a short line and a picture (uploaded,
 *   resized to at most 512px so students download little).
 * - Who has an unopened gift waiting.
 * A won gift is stored on the student's card, so editing, reordering or
 * removing gifts here never changes gifts already won.
 * ---------------------------------------------------------------------------
 */
require __DIR__ . '/admin_lib.php';
$me  = pc_require_admin();
$pdo = pc_db();
pc_active_gifts($pdo);                       // seeds the starter list the first time

const GIFT_DIR = __DIR__ . '/uploads/gifts';
const GIFT_MAX_BYTES = 3 * 1024 * 1024;

/* Save an uploaded picture; returns the new file name or throws with a friendly message. */
function save_gift_image($f) {
  if (empty($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('The picture did not upload (it may be too big). Try a smaller one.');
  if ($f['size'] > GIFT_MAX_BYTES) throw new Exception('Picture is too big. Please use one under 3 MB.');
  $info = @getimagesize($f['tmp_name']);
  $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
  if (!$info || !isset($types[$info[2]])) throw new Exception('That file is not a picture. Use JPG, PNG, GIF or WebP.');
  if (!is_dir(GIFT_DIR) && !@mkdir(GIFT_DIR, 0755, true)) throw new Exception('Could not create the uploads/gifts folder on the server.');
  if (!is_file(GIFT_DIR . '/.htaccess')) {   // pictures only: never run scripts from this folder
    @file_put_contents(GIFT_DIR . '/.htaccess', "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
  }
  $base = bin2hex(random_bytes(8));
  // Re-draw the picture with GD (strips anything hidden in the file) and shrink it to 512px.
  if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring(file_get_contents($f['tmp_name'])))) {
    $w = imagesx($src); $h = imagesy($src); $k = min(1, 512 / max($w, $h));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $keepAlpha = in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    $name = $base . ($keepAlpha ? '.png' : '.jpg');
    $ok = $keepAlpha ? imagepng($dst, GIFT_DIR . '/' . $name, 7) : imagejpeg($dst, GIFT_DIR . '/' . $name, 85);
    imagedestroy($src); imagedestroy($dst);
    if (!$ok) throw new Exception('Could not save the picture on the server (check folder permissions).');
    return $name;
  }
  $name = $base . '.' . $types[$info[2]];      // no GD on this server: keep the original file
  if (!move_uploaded_file($f['tmp_name'], GIFT_DIR . '/' . $name)) throw new Exception('Could not save the picture on the server (check folder permissions).');
  return $name;
}
function delete_gift_image($name) {
  $name = basename((string)$name);
  if ($name !== '' && is_file(GIFT_DIR . '/' . $name)) @unlink(GIFT_DIR . '/' . $name);
}
function gift_awarded($pdo, $id) {
  $st = $pdo->prepare("SELECT COUNT(*) FROM rewards WHERE reward_key=?"); $st->execute(['g' . (int)$id]);
  return (int)$st->fetchColumn();
}
function renumber($pdo) {   // keep positions 1, 2, 3 ... for active gifts
  $ids = $pdo->query("SELECT id FROM gifts WHERE active=1 ORDER BY position, id")->fetchAll(PDO::FETCH_COLUMN);
  $u = $pdo->prepare("UPDATE gifts SET position=? WHERE id=?");
  foreach ($ids as $i => $id) $u->execute([$i + 1, $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  pc_csrf_check();
  $do = (string)($_POST['do'] ?? '');
  try {
    if ($do === 'points') {
      $steps = array_map('trim', explode(',', (string)$_POST['gift_steps']));
      $nums = [];
      foreach ($steps as $x) { if ($x === '') continue; if (!ctype_digit($x)) throw new Exception('Gift steps: whole numbers separated by commas, e.g. 100, 250, 450.'); $nums[] = (int)$x; }
      if (!$nums || count($nums) > 20) throw new Exception('Gift steps: give between 1 and 20 numbers.');
      for ($i = 0; $i < count($nums); $i++) if ($nums[$i] < 1 || $nums[$i] > 100000 || ($i && $nums[$i] <= $nums[$i - 1])) throw new Exception('Gift steps must go up, e.g. 100, 250, 450.');
      $vals = ['gift_step_after' => [10, 100000, 'After the last step'], 'practice_cap' => [5, 1000, 'Daily practice points'], 'pb_rate' => [0, 100, 'Personal best points'], 'mastery_bonus' => [0, 1000, 'Mastery bonus']];
      foreach ($vals as $k => [$lo, $hi, $label]) { $v = trim((string)$_POST[$k]); if (!ctype_digit($v) || (int)$v < $lo || (int)$v > $hi) throw new Exception("$label: a whole number from $lo to $hi."); }
      pc_set_setting($pdo, 'gift_steps', implode(',', $nums));
      foreach (array_keys($vals) as $k) pc_set_setting($pdo, $k, (int)$_POST[$k]);
      pc_flash('Points rules saved. They apply to everyone from the next reading.');
    }
    elseif ($do === 'points_reset') {
      foreach (pc_points_defaults() as $k => $v) pc_set_setting($pdo, $k, $v);
      pc_flash('Points rules set back to the defaults.');
    }
    elseif ($do === 'add') {
      $name = trim((string)$_POST['name']); if ($name === '') throw new Exception('Give the gift a name.');
      $img = save_gift_image($_FILES['image'] ?? null);
      $pos = (int)$pdo->query("SELECT COALESCE(MAX(position),0)+1 FROM gifts WHERE active=1")->fetchColumn();
      $pdo->prepare("INSERT INTO gifts (position, name, description, image) VALUES (?,?,?,?)")->execute([$pos, mb_substr($name, 0, 80), mb_substr(trim((string)$_POST['description']), 0, 200) ?: null, $img]);
      pc_flash('Added gift #' . $pos . ': ' . $name . '.');
    }
    else {
      $id = (int)($_POST['id'] ?? 0);
      $st = $pdo->prepare("SELECT * FROM gifts WHERE id=? AND active=1"); $st->execute([$id]); $g = $st->fetch();
      if (!$g) throw new Exception('That gift no longer exists.');
      if ($do === 'edit') {
        $name = trim((string)$_POST['name']); if ($name === '') throw new Exception('Give the gift a name.');
        $img = save_gift_image($_FILES['image'] ?? null);
        $pdo->prepare("UPDATE gifts SET name=?, description=?" . ($img ? ", image=?" : "") . " WHERE id=?")
            ->execute(array_merge([mb_substr($name, 0, 80), mb_substr(trim((string)$_POST['description']), 0, 200) ?: null], $img ? [$img] : [], [$id]));
        if ($img && $g['image']) delete_gift_image($g['image']);
        pc_flash('Saved gift: ' . $name . '.');
      } elseif ($do === 'noimage') {
        $pdo->prepare("UPDATE gifts SET image=NULL WHERE id=?")->execute([$id]);
        delete_gift_image($g['image']);
        pc_flash('Picture removed from ' . $g['name'] . '.');
      } elseif ($do === 'up' || $do === 'down') {
        renumber($pdo);
        $st = $pdo->prepare("SELECT position FROM gifts WHERE id=?"); $st->execute([$id]); $p = (int)$st->fetchColumn();
        $q = $pdo->prepare("SELECT id FROM gifts WHERE active=1 AND position=?"); $q->execute([$do === 'up' ? $p - 1 : $p + 1]);
        if ($other = $q->fetchColumn()) {
          $pdo->prepare("UPDATE gifts SET position=? WHERE id=?")->execute([$do === 'up' ? $p - 1 : $p + 1, $id]);
          $pdo->prepare("UPDATE gifts SET position=? WHERE id=?")->execute([$p, $other]);
        }
      } elseif ($do === 'delete') {
        if ((int)$pdo->query("SELECT COUNT(*) FROM gifts WHERE active=1")->fetchColumn() <= 1) throw new Exception('Keep at least one gift.');
        if (gift_awarded($pdo, $id)) {   // students already won it: hide it from the list, keep it for them
          $pdo->prepare("UPDATE gifts SET active=0 WHERE id=?")->execute([$id]);
        } else {
          $pdo->prepare("DELETE FROM gifts WHERE id=?")->execute([$id]);
          delete_gift_image($g['image']);
        }
        renumber($pdo);
        pc_flash('Removed ' . $g['name'] . ' from the list. Students who already won it keep it.');
      }
    }
  } catch (Exception $e) { pc_flash($e->getMessage(), 'err'); }
  pc_redirect('admin_gifts.php' . (!empty($_POST['anchor']) ? '#' . preg_replace('/[^a-z0-9-]/', '', $_POST['anchor']) : ''));
}

/* ------------------------------ page ------------------------------ */
$PS = pc_points_settings($pdo);
$gifts = pc_active_gifts($pdo);
$editId = (int)($_GET['edit'] ?? 0);
$won = [];
foreach ($pdo->query("SELECT reward_key, COUNT(*) n FROM rewards GROUP BY reward_key") as $r) $won[$r['reward_key']] = (int)$r['n'];
$pending = $pdo->query("SELECT s.name, s.class_code, s.roll_no, r.card_no, r.unlocked_at
                        FROM rewards r JOIN students s ON s.id = r.student_id
                        WHERE r.scratched_at IS NULL ORDER BY r.unlocked_at LIMIT 300")->fetchAll();
function steps_preview($PS, $count) {
  $out = []; $s = $PS['steps']; $k = count($s);
  for ($n = 1; $n <= max($count, $k) + 1; $n++) $out[] = $n <= $k ? $s[$n - 1] : $s[$k - 1] + $PS['after'] * ($n - $k);
  return $out;
}
$preview = steps_preview($PS, count($gifts));

pc_page_start('Gifts & points', 'gifts', $me);
?>
<style>
  .gthumb{ width:56px; height:56px; border-radius:10px; object-fit:cover; background:var(--accent-soft); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; font-size:26px; flex:none; }
  img.gthumb{ display:block; }
  .gname{ font-weight:700; }
  .gdesc{ font-size:13px; color:var(--muted); }
  .gpos{ font-size:12px; color:var(--dim); white-space:nowrap; }
  table.list td.gcell{ display:flex; gap:12px; align-items:center; }
</style>
<div class="stack">

  <div class="card" id="points">
    <div class="section-t">Points rules</div>
    <form method="post" class="row">
      <?= pc_csrf_field() ?><input type="hidden" name="do" value="points"><input type="hidden" name="anchor" value="points">
      <div class="f" style="flex-basis:260px"><label class="fl" for="p_steps">Gift steps (points)</label><input type="text" id="p_steps" name="gift_steps" value="<?= h(implode(', ', $PS['steps'])) ?>"></div>
      <div class="f s" style="flex-basis:130px"><label class="fl" for="p_after">Then every +</label><input type="text" id="p_after" name="gift_step_after" value="<?= (int)$PS['after'] ?>" inputmode="numeric"></div>
      <div class="f s" style="flex-basis:150px"><label class="fl" for="p_cap">Daily practice points</label><input type="text" id="p_cap" name="practice_cap" value="<?= (int)$PS['cap'] ?>" inputmode="numeric"></div>
      <div class="f s" style="flex-basis:150px"><label class="fl" for="p_pb">Personal best (per +1.0)</label><input type="text" id="p_pb" name="pb_rate" value="<?= (int)$PS['pb_rate'] ?>" inputmode="numeric"></div>
      <div class="f s" style="flex-basis:130px"><label class="fl" for="p_mb">Mastery bonus</label><input type="text" id="p_mb" name="mastery_bonus" value="<?= (int)$PS['mastery_bonus'] ?>" inputmode="numeric"></div>
      <div><button class="btn primary" type="submit">Save</button></div>
    </form>
    <p class="hint" style="margin-top:10px">Gifts unlock at: <?= h(implode(' → ', array_slice($preview, 0, 8))) ?> points… Each reading earns its score; only the best 2 tries of a sentence count each day, up to the daily practice points. Beating a personal best and mastering a sentence (8+ on two days) earn extra and have no daily limit.</p>
    <form method="post" class="inline" onsubmit="return confirm('Set the points rules back to the defaults?')"><?= pc_csrf_field() ?><input type="hidden" name="do" value="points_reset"><input type="hidden" name="anchor" value="points"><button class="btn sm" type="submit">Use defaults</button></form>
  </div>

  <div class="card" id="gifts">
    <div class="section-t">Gifts · card 1 gets gift 1, card 2 gets gift 2, and so on; after the last one it repeats</div>
    <table class="list">
      <thead><tr><th>Gift</th><th class="hideS">Unlocks at</th><th class="hideS">Won</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($gifts as $i => $g): $n = $i + 1; ?>
        <?php if ($editId === (int)$g['id']): ?>
        <tr><td colspan="4">
          <form method="post" enctype="multipart/form-data" class="row">
            <?= pc_csrf_field() ?><input type="hidden" name="do" value="edit"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="anchor" value="gifts">
            <?php if ($g['image']): ?><img class="gthumb" src="<?= h(pc_gift_image_url($g['image'])) ?>" alt=""><?php else: ?><div class="gthumb">🎁</div><?php endif; ?>
            <div class="f"><label class="fl">Name</label><input type="text" name="name" required maxlength="80" value="<?= h($g['name']) ?>"></div>
            <div class="f" style="flex-basis:240px"><label class="fl">Short line (shown under the picture)</label><input type="text" name="description" maxlength="200" value="<?= h($g['description']) ?>"></div>
            <div class="f"><label class="fl"><?= $g['image'] ? 'Replace picture' : 'Picture' ?> (JPG, PNG, GIF, WebP · under 3 MB)</label><input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></div>
            <div><button class="btn primary" type="submit">Save</button> <a class="btn" href="admin_gifts.php#gifts">Cancel</a></div>
          </form>
        </td></tr>
        <?php else: ?>
        <tr>
          <td class="gcell">
            <?php if ($g['image']): ?><img class="gthumb" src="<?= h(pc_gift_image_url($g['image'])) ?>" alt=""><?php else: ?><div class="gthumb" title="No picture yet">🎁</div><?php endif; ?>
            <div><div class="gpos">Gift <?= $n ?><?= $n === count($gifts) ? ' · repeats after this' : '' ?></div><div class="gname"><?= h($g['name']) ?></div><div class="gdesc"><?= h($g['description']) ?><?= $g['image'] ? '' : ' <span class="pill">no picture yet</span>' ?></div></div>
          </td>
          <td class="hideS num"><?= (int)$preview[$i] ?></td>
          <td class="hideS num"><?= (int)($won['g' . $g['id']] ?? 0) ?></td>
          <td class="act">
            <a class="btn sm" href="admin_gifts.php?edit=<?= (int)$g['id'] ?>#gifts">Edit</a>
            <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow): if (($dir === 'up' && $n === 1) || ($dir === 'down' && $n === count($gifts))) continue; ?>
            <form method="post" class="inline"><?= pc_csrf_field() ?><input type="hidden" name="do" value="<?= $dir ?>"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="anchor" value="gifts"><button class="btn sm" type="submit" title="Move <?= $dir ?>"><?= $arrow ?></button></form>
            <?php endforeach; ?>
            <?php if ($g['image']): ?>
            <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('Remove the picture from ' . $g['name'] . '?')) ?>)"><?= pc_csrf_field() ?><input type="hidden" name="do" value="noimage"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="anchor" value="gifts"><button class="btn sm" type="submit">No picture</button></form>
            <?php endif; ?>
            <?php if (count($gifts) > 1): ?>
            <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('Remove ' . $g['name'] . ' from the list? Students who already won it keep it.')) ?>)"><?= pc_csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="anchor" value="gifts"><button class="btn sm danger" type="submit">Remove</button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>

    <details class="more" style="margin-top:14px" <?= $gifts ? '' : 'open' ?>>
      <summary>Add a gift</summary>
      <form method="post" enctype="multipart/form-data" class="row" style="margin-top:12px">
        <?= pc_csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="anchor" value="gifts">
        <div class="f"><label class="fl" for="n_name">Name</label><input type="text" id="n_name" name="name" required maxlength="80" placeholder="e.g. Star Reader sticker"></div>
        <div class="f" style="flex-basis:240px"><label class="fl" for="n_desc">Short line (shown under the picture)</label><input type="text" id="n_desc" name="description" maxlength="200" placeholder="e.g. Show this to your teacher for a sticker!"></div>
        <div class="f"><label class="fl" for="n_img">Picture (JPG, PNG, GIF, WebP · under 3 MB)</label><input type="file" id="n_img" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></div>
        <div><button class="btn primary" type="submit">Add gift</button></div>
      </form>
      <p class="hint" style="margin-top:8px">New gifts go to the end of the list. Pictures are shrunk to 512 px so they load fast on students' phones.</p>
    </details>
  </div>

  <div class="card" id="pending">
    <div class="section-t">Unopened gifts · <?= count($pending) ?></div>
    <?php if (!$pending): ?>
      <p class="hint">Nobody has a gift waiting right now.</p>
    <?php else: ?>
    <table class="list">
      <thead><tr><th>Student</th><th>Class</th><th>Gift</th><th class="hideS">Waiting since</th></tr></thead>
      <tbody>
      <?php foreach ($pending as $p): ?>
        <tr><td><b><?= h($p['name'] ?: 'Roll ' . $p['roll_no']) ?></b><div class="sub">#<?= h($p['roll_no']) ?></div></td><td><?= h($p['class_code']) ?></td><td class="num">#<?= (int)$p['card_no'] ?></td><td class="hideS"><?= h(date('j M', strtotime($p['unlocked_at']))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint" style="margin-top:8px">A gift unlocks on the server; students scratch it the next time they open the app.</p>
    <?php endif; ?>
  </div>
</div>
<?php pc_page_end();
