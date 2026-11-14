<?php
/**
 * English Buddy - backend: students (the class roster).
 * ---------------------------------------------------------------------------
 * Add one student, or paste / upload a whole class list. Edit a name, roll
 * number or class (history moves with the student), reset progress, delete.
 * Teachers can only see and change students in their own class codes; every
 * action is checked on the server. Admins also switch "roster only" sign-in.
 * ---------------------------------------------------------------------------
 */
require __DIR__ . '/admin_lib.php';
$me  = pc_require_login();
$pdo = pc_db();

const ROLL_RE = '/^[A-Za-z0-9._\/-]{1,40}$/';

function clean_class($c) { return substr(trim((string)$c), 0, 40); }
function clean_roll($r)  { return substr(trim((string)$r), 0, 40); }
function clean_name($n)  { return substr(trim(preg_replace('/\s+/', ' ', (string)$n)), 0, 80); }

/* The student row, but only if this user may manage it. */
function student_for($pdo, $me, $id) {
  $st = $pdo->prepare("SELECT * FROM students WHERE id=?");
  $st->execute([(int)$id]);
  $s = $st->fetch();
  return ($s && pc_class_ok($me, $s['class_code'])) ? $s : null;
}
function back($edit = 0) {
  $q = array_filter(['class' => $_POST['back_class'] ?? $_GET['class'] ?? '', 'q' => $_POST['back_q'] ?? $_GET['q'] ?? '', 'edit' => $edit ?: '']);
  pc_redirect('admin_students.php' . ($q ? '?' . http_build_query($q) : ''));
}

/* Lines like "12, Priya Sharma", "12<TAB>Priya", "12 Priya". Header rows are skipped. */
function parse_roster($text) {
  $out = []; $bad = []; $first = true;
  foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
    $line = trim($line, " \t\xEF\xBB\xBF");
    if ($line === '') { $first = true; continue; }   // a new block (pasted text, then the file) may start with its own header
    if (preg_match('/[,;\t]/', $line)) { $parts = preg_split('/\s*[,;\t]\s*/', $line, 2); }
    else { $parts = preg_split('/\s+/', $line, 2); }
    $roll = clean_roll(trim($parts[0], " \"'")); $name = clean_name(trim($parts[1] ?? '', " \"'"));
    $isHeader = $first && (preg_match('/^(roll|no\.?|number|sr\.?|s\.?\s?no\.?|id)$/i', $roll) || preg_match('/^(student\s*)?name$/i', $name));
    $first = false;
    if ($isHeader) continue;                                                          // header row
    if (!preg_match(ROLL_RE, $roll)) { $bad[] = $line; continue; }
    $out[] = [$roll, $name];
  }
  return [$out, $bad];
}

/* ------------------------------ actions ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  pc_csrf_check();
  $do = (string)($_POST['do'] ?? '');

  if ($do === 'add' || $do === 'bulk') {
    $class = clean_class($_POST['class'] ?? '');
    if ($class === '' || !pc_class_ok($me, $class)) { pc_flash('Pick one of your classes first.', 'err'); back(); }
    if ($do === 'add') {
      $rows = [[clean_roll($_POST['roll'] ?? ''), clean_name($_POST['name'] ?? '')]]; $bad = [];
      if (!preg_match(ROLL_RE, $rows[0][0])) { pc_flash('Roll number: letters, numbers, - / . _ only.', 'err'); back(); }
    } else {
      $text = (string)($_POST['list'] ?? '');
      if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) $text .= "\n" . file_get_contents($_FILES['csv']['tmp_name'], false, null, 0, 200000);
      [$rows, $bad] = parse_roster($text);
      if (!$rows) { pc_flash('No students found. Put one per line: roll number, then name.', 'err'); back(); }
    }
    $ins = $pdo->prepare("INSERT IGNORE INTO students (class_code, roll_no, name) VALUES (?,?,?)");
    $added = 0; $dupes = [];
    foreach ($rows as [$roll, $name]) {
      $ins->execute([$class, $roll, $name !== '' ? $name : null]);
      if ($ins->rowCount()) $added++; else $dupes[] = $roll;
    }
    $msg = $added . ' student' . ($added === 1 ? '' : 's') . ' added to ' . $class . '.';
    if ($dupes) $msg .= ' Already on the roster, skipped: roll ' . implode(', ', array_slice($dupes, 0, 12)) . (count($dupes) > 12 ? '…' : '') . '.';
    if ($bad)   $msg .= ' Could not read ' . count($bad) . ' line' . (count($bad) === 1 ? '' : 's') . ': "' . implode('", "', array_slice($bad, 0, 3)) . '".';
    pc_flash($msg, ($added || !$bad) ? 'ok' : 'err');
    back();
  }

  if ($do === 'edit') {
    $s = student_for($pdo, $me, $_POST['id'] ?? 0);
    if (!$s) { pc_flash('That student is not in your classes.', 'err'); back(); }
    $class = clean_class($_POST['class'] ?? ''); $roll = clean_roll($_POST['roll'] ?? ''); $name = clean_name($_POST['name'] ?? '');
    if ($class === '' || !pc_class_ok($me, $class)) { pc_flash('You can only move students into your own classes.', 'err'); back($s['id']); }
    if (!preg_match(ROLL_RE, $roll)) { pc_flash('Roll number: letters, numbers, - / . _ only.', 'err'); back($s['id']); }
    try {
      $pdo->prepare("UPDATE students SET class_code=?, roll_no=?, name=? WHERE id=?")->execute([$class, $roll, $name !== '' ? $name : null, $s['id']]);
      pc_flash('Saved ' . ($name ?: 'roll ' . $roll) . ' (' . $class . ' · #' . $roll . ').');
    } catch (PDOException $e) {
      pc_flash('Another student already has roll ' . $roll . ' in ' . $class . '.', 'err'); back($s['id']);
    }
    back();
  }

  if ($do === 'reset' || $do === 'delete') {
    $s = student_for($pdo, $me, $_POST['id'] ?? 0);
    if (!$s) { pc_flash('That student is not in your classes.', 'err'); back(); }
    $label = ($s['name'] ?: 'Roll ' . $s['roll_no']) . ' (' . $s['class_code'] . ' · #' . $s['roll_no'] . ')';
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM attempts WHERE student_id=?")->execute([$s['id']]);
    $pdo->prepare("DELETE FROM rewards WHERE student_id=?")->execute([$s['id']]);
    if ($do === 'delete') $pdo->prepare("DELETE FROM students WHERE id=?")->execute([$s['id']]);
    $pdo->commit();
    pc_flash($do === 'delete' ? 'Deleted ' . $label . ' and all their practice.' : 'Reset ' . $label . ': practice, points and gifts cleared.');
    back();
  }

  if ($do === 'roster' && pc_is_admin($me)) {
    pc_set_setting($pdo, 'roster_only', !empty($_POST['on']) ? '1' : '0');
    pc_flash(!empty($_POST['on']) ? 'Roster only is ON: students must be added here before they can sign in.' : 'Roster only is OFF: any class code and roll number can sign in.');
    back();
  }
  back();
}

/* ------------------------------ page ------------------------------ */
$classOptions = pc_class_options($me);
$pickClass = trim((string)($_GET['class'] ?? ''));
if ($pickClass !== '' && !pc_class_ok($me, $pickClass)) $pickClass = '';
$q = trim((string)($_GET['q'] ?? ''));
[$cw, $cp] = pc_class_where($me, 's');
if ($pickClass !== '') { $cw .= ' AND s.class_code = ?'; $cp[] = $pickClass; }
if ($q !== '') { $cw .= ' AND (s.name LIKE ? OR s.roll_no LIKE ?)'; $cp[] = '%' . $q . '%'; $cp[] = $q . '%'; }
$st = $pdo->prepare("SELECT s.id, s.class_code, s.roll_no, s.name, s.created_at,
                            COUNT(a.id) AS attempts, MAX(a.created_at) AS last_at
                     FROM students s LEFT JOIN attempts a ON a.student_id = s.id
                     WHERE $cw GROUP BY s.id
                     ORDER BY s.class_code, CAST(s.roll_no AS UNSIGNED), s.roll_no LIMIT 1000");
$st->execute($cp);
$list = $st->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$roster = pc_roster_only($pdo);
$defaultClass = $pickClass !== '' ? $pickClass : ($classOptions[0] ?? '');
$isAdmin = pc_is_admin($me);

function ago_short($ts) {
  if (!$ts) return '<span class="pill">not started</span>';
  $d = (int)floor((time() - strtotime($ts)) / 86400);
  return $d <= 0 ? 'today' : ($d === 1 ? 'yesterday' : ($d < 7 ? $d . ' days ago' : date('j M', strtotime($ts))));
}
function class_input($name, $options, $value, $isAdmin, $id) {
  // admins may type a new class code; teachers pick one of theirs
  if ($isAdmin) {
    $o = '<input type="text" id="' . $id . '" name="' . $name . '" list="classlist" required maxlength="40" value="' . h($value) . '" placeholder="e.g. 7B">';
    return $o;
  }
  $o = '<select id="' . $id . '" name="' . $name . '" required>';
  foreach ($options as $c) $o .= '<option value="' . h($c) . '"' . ($c === $value ? ' selected' : '') . '>' . h($c) . '</option>';
  return $o . '</select>';
}

pc_page_start('Students', 'students', $me);
?>
<datalist id="classlist"><?php foreach ($classOptions as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>
<div class="stack">

<?php if ($isAdmin): ?>
  <div class="card">
    <div class="row" style="align-items:center;justify-content:space-between">
      <div style="flex:1 1 300px">
        <b>Sign-in: roster only</b> <span class="pill <?= $roster ? 'on' : '' ?>"><?= $roster ? 'ON' : 'OFF' ?></span>
        <div class="hint"><?= $roster ? 'Students can sign in only if they are on this list. Add new students here first.' : 'Any class code and roll number can sign in; new students appear automatically.' ?></div>
      </div>
      <form method="post" class="inline"><?= pc_csrf_field() ?><input type="hidden" name="do" value="roster"><input type="hidden" name="on" value="<?= $roster ? '' : '1' ?>">
        <button class="btn" type="submit"><?= $roster ? 'Turn off' : 'Turn on' ?></button></form>
    </div>
  </div>
<?php endif; ?>

<?php if (!$isAdmin && !$classOptions): ?>
  <div class="card"><div class="empty"><h3>No classes assigned yet</h3><p class="hint">Ask the admin to give your login a class code.</p></div></div>
<?php else: ?>
  <div class="card">
    <div class="section-t">Add students</div>
    <form method="post" class="row">
      <?= pc_csrf_field() ?><input type="hidden" name="do" value="add">
      <input type="hidden" name="back_class" value="<?= h($pickClass) ?>">
      <div class="f s"><label class="fl" for="a_class">Class</label><?= class_input('class', $classOptions, $defaultClass, $isAdmin, 'a_class') ?></div>
      <div class="f s"><label class="fl" for="a_roll">Roll no.</label><input type="text" id="a_roll" name="roll" required maxlength="40"></div>
      <div class="f"><label class="fl" for="a_name">Name</label><input type="text" id="a_name" name="name" maxlength="80" placeholder="e.g. Priya Sharma"></div>
      <div><button class="btn primary" type="submit">Add</button></div>
    </form>
    <details class="more" style="margin-top:14px">
      <summary>Add a whole class at once</summary>
      <form method="post" enctype="multipart/form-data" style="margin-top:12px">
        <?= pc_csrf_field() ?><input type="hidden" name="do" value="bulk">
        <input type="hidden" name="back_class" value="<?= h($pickClass) ?>">
        <div class="row">
          <div class="f s"><label class="fl" for="b_class">Class</label><?= class_input('class', $classOptions, $defaultClass, $isAdmin, 'b_class') ?></div>
          <div class="f"><label class="fl" for="b_csv">Or upload a CSV file (roll, name)</label><input type="file" id="b_csv" name="csv" accept=".csv,.txt,text/csv,text/plain"></div>
        </div>
        <label class="fl" for="b_list" style="margin-top:10px">Paste one student per line: roll number, then name</label>
        <textarea id="b_list" name="list" placeholder="1, Priya Sharma&#10;2, Arjun Kumar&#10;3, Fatima Khan"></textarea>
        <div class="row" style="margin-top:10px;justify-content:space-between;align-items:center">
          <span class="hint">Students already on the roster are skipped, nothing is overwritten.</span>
          <button class="btn primary" type="submit">Add all</button>
        </div>
      </form>
    </details>
  </div>

  <div class="card">
    <form method="get" class="row" style="margin-bottom:12px">
      <div class="f s" style="flex-basis:150px"><label class="fl" for="f_class">Class</label>
        <select id="f_class" name="class" onchange="this.form.submit()">
          <option value=""><?= $isAdmin ? 'All classes' : 'All my classes' ?></option>
          <?php foreach ($classOptions as $c): ?><option value="<?= h($c) ?>"<?= $c === $pickClass ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
        </select></div>
      <div class="f"><label class="fl" for="f_q">Search name or roll</label><input type="search" id="f_q" name="q" value="<?= h($q) ?>"></div>
      <div><button class="btn" type="submit">Find</button></div>
    </form>
    <div class="section-t"><?= count($list) ?> student<?= count($list) === 1 ? '' : 's' ?></div>
    <?php if (!$list): ?>
      <div class="empty"><h3>No students here yet</h3><p class="hint">Add them above, one at a time or a whole class at once.</p></div>
    <?php else: ?>
    <table class="list">
      <thead><tr><th>Student</th><th>Class</th><th class="hideS">Readings</th><th class="hideS">Last practice</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $s): ?>
        <?php if ($editId === (int)$s['id']): ?>
        <tr><td colspan="5">
          <form method="post" class="row">
            <?= pc_csrf_field() ?><input type="hidden" name="do" value="edit"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="back_class" value="<?= h($pickClass) ?>"><input type="hidden" name="back_q" value="<?= h($q) ?>">
            <div class="f s"><label class="fl" for="e_class">Class</label><?= class_input('class', $classOptions, $s['class_code'], $isAdmin, 'e_class') ?></div>
            <div class="f s"><label class="fl" for="e_roll">Roll no.</label><input type="text" id="e_roll" name="roll" required maxlength="40" value="<?= h($s['roll_no']) ?>"></div>
            <div class="f"><label class="fl" for="e_name">Name</label><input type="text" id="e_name" name="name" maxlength="80" value="<?= h($s['name']) ?>"></div>
            <div><button class="btn primary" type="submit">Save</button> <a class="btn" href="admin_students.php?<?= h(http_build_query(array_filter(['class' => $pickClass, 'q' => $q]))) ?>">Cancel</a></div>
          </form>
          <div class="hint" style="margin-top:6px">Practice, points and gifts stay with the student.</div>
        </td></tr>
        <?php else: ?>
        <tr>
          <td><b><?= h($s['name'] ?: 'Roll ' . $s['roll_no']) ?></b><div class="sub">#<?= h($s['roll_no']) ?></div></td>
          <td><?= h($s['class_code']) ?></td>
          <td class="hideS num"><?= (int)$s['attempts'] ?></td>
          <td class="hideS"><?= ago_short($s['last_at']) ?></td>
          <td class="act">
            <a class="btn sm" href="admin_students.php?<?= h(http_build_query(array_filter(['class' => $pickClass, 'q' => $q, 'edit' => $s['id']]))) ?>">Edit</a>
            <?php $label = ($s['name'] ?: 'Roll ' . $s['roll_no']) . ' (' . $s['class_code'] . ' #' . $s['roll_no'] . ')'; ?>
            <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('Reset ' . $label . '? Their practice, points and gifts are cleared. This cannot be undone.')) ?>)">
              <?= pc_csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="back_class" value="<?= h($pickClass) ?>"><input type="hidden" name="back_q" value="<?= h($q) ?>">
              <button class="btn sm" type="submit">Reset</button></form>
            <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('Delete ' . $label . ' and all their practice? This cannot be undone.')) ?>)">
              <?= pc_csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="back_class" value="<?= h($pickClass) ?>"><input type="hidden" name="back_q" value="<?= h($q) ?>">
              <button class="btn sm danger" type="submit">Delete</button></form>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<?php pc_page_end();
