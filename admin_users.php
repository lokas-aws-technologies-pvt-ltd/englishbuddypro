<?php
/**
 * English Buddy - backend: logins.
 * ---------------------------------------------------------------------------
 * Everyone: change your own password.
 * Admin: create teacher (or admin) logins, assign class codes, reset a
 * password, remove a login. There must always be at least one admin, and you
 * cannot remove your own login.
 * ---------------------------------------------------------------------------
 */
require __DIR__ . '/admin_lib.php';
$me  = pc_require_login();
$pdo = pc_db();
$isAdmin = pc_is_admin($me);

function parse_classes($text) {
  $out = [];
  foreach (preg_split('/[\s,;]+/', (string)$text) as $c) { $c = substr(trim($c), 0, 40); if ($c !== '') $out[strtolower($c)] = $c; }
  return array_values($out);
}
function set_classes($pdo, $id, $codes) {
  $pdo->prepare("DELETE FROM staff_classes WHERE staff_id=?")->execute([$id]);
  $ins = $pdo->prepare("INSERT IGNORE INTO staff_classes (staff_id, class_code) VALUES (?,?)");
  foreach ($codes as $c) $ins->execute([$id, $c]);
}
function admin_count($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE role='admin'")->fetchColumn(); }
function temp_password() {   // easy to read out loud: no 0/O, 1/l
  $a = 'abcdefghjkmnpqrstuvwxyz23456789'; $p = '';
  for ($i = 0; $i < 10; $i++) $p .= $a[random_int(0, strlen($a) - 1)];
  return $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  pc_csrf_check();
  $do = (string)($_POST['do'] ?? '');

  if ($do === 'mypass') {
    $st = $pdo->prepare("SELECT pass_hash FROM staff WHERE id=?"); $st->execute([$me['id']]);
    $p1 = (string)$_POST['new1']; $p2 = (string)$_POST['new2'];
    if (!password_verify((string)$_POST['current'], (string)$st->fetchColumn())) pc_flash('Your current password is not right.', 'err');
    elseif (strlen($p1) < 8) pc_flash('New password must be at least 8 characters.', 'err');
    elseif ($p1 !== $p2) pc_flash('The two new passwords do not match.', 'err');
    else { $pdo->prepare("UPDATE staff SET pass_hash=? WHERE id=?")->execute([password_hash($p1, PASSWORD_DEFAULT), $me['id']]); pc_flash('Your password is changed.'); }
    pc_redirect('admin_users.php');
  }

  if (!$isAdmin) pc_redirect('admin_users.php');

  if ($do === 'create') {
    $user = strtolower(trim((string)$_POST['username'])); $name = substr(trim((string)$_POST['name']), 0, 80);
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'teacher';
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $user)) { pc_flash('Username: 3–40 letters, numbers, dot, dash or underscore.', 'err'); pc_redirect('admin_users.php'); }
    $pass = trim((string)$_POST['password']) !== '' ? (string)$_POST['password'] : temp_password();
    if (strlen($pass) < 8) { pc_flash('Password must be at least 8 characters (or leave it empty to generate one).', 'err'); pc_redirect('admin_users.php'); }
    try {
      $pdo->prepare("INSERT INTO staff (username, name, pass_hash, role) VALUES (?,?,?,?)")->execute([$user, $name ?: null, password_hash($pass, PASSWORD_DEFAULT), $role]);
      $id = (int)$pdo->lastInsertId();
      if ($role === 'teacher') set_classes($pdo, $id, parse_classes($_POST['classes'] ?? ''));
      pc_flash('Login created. Username: ' . $user . ' · Password: ' . $pass . ' (share it privately; they can change it under My account).');
    } catch (PDOException $e) { pc_flash('That username is taken.', 'err'); }
    pc_redirect('admin_users.php');
  }

  $id = (int)($_POST['id'] ?? 0);
  $st = $pdo->prepare("SELECT id, username, role FROM staff WHERE id=?"); $st->execute([$id]); $u = $st->fetch();
  if (!$u) { pc_flash('That login no longer exists.', 'err'); pc_redirect('admin_users.php'); }

  if ($do === 'edit') {
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'teacher';
    if ($u['role'] === 'admin' && $role !== 'admin' && admin_count($pdo) <= 1) { pc_flash('Keep at least one admin.', 'err'); pc_redirect('admin_users.php'); }
    $pdo->prepare("UPDATE staff SET name=?, role=? WHERE id=?")->execute([substr(trim((string)$_POST['name']), 0, 80) ?: null, $role, $id]);
    set_classes($pdo, $id, $role === 'teacher' ? parse_classes($_POST['classes'] ?? '') : []);
    pc_flash('Saved ' . $u['username'] . '.');
  } elseif ($do === 'reset') {
    $pass = temp_password();
    $pdo->prepare("UPDATE staff SET pass_hash=? WHERE id=?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    pc_flash('New password for ' . $u['username'] . ': ' . $pass . ' (share it privately).');
  } elseif ($do === 'delete') {
    if ($id === (int)$me['id']) pc_flash('You cannot remove your own login.', 'err');
    elseif ($u['role'] === 'admin' && admin_count($pdo) <= 1) pc_flash('Keep at least one admin.', 'err');
    else { $pdo->prepare("DELETE FROM staff_classes WHERE staff_id=?")->execute([$id]); $pdo->prepare("DELETE FROM staff WHERE id=?")->execute([$id]); pc_flash('Removed ' . $u['username'] . '. Their students are not affected.'); }
  }
  pc_redirect('admin_users.php');
}

$staff = [];
if ($isAdmin) {
  $staff = $pdo->query("SELECT s.id, s.username, s.name, s.role, s.last_login,
                               GROUP_CONCAT(c.class_code ORDER BY c.class_code SEPARATOR ', ') AS classes
                        FROM staff s LEFT JOIN staff_classes c ON c.staff_id = s.id
                        GROUP BY s.id ORDER BY s.role, s.username")->fetchAll();
}
$editId = (int)($_GET['edit'] ?? 0);

pc_page_start($isAdmin ? 'Logins' : 'My account', 'users', $me);
?>
<div class="stack">
<?php if ($isAdmin): ?>
  <div class="card">
    <div class="section-t">Logins</div>
    <table class="list">
      <thead><tr><th>Login</th><th>Role</th><th>Classes</th><th class="hideS">Last sign-in</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($staff as $u): ?>
        <?php if ($editId === (int)$u['id']): ?>
        <tr><td colspan="5">
          <form method="post" class="row">
            <?= pc_csrf_field() ?><input type="hidden" name="do" value="edit"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <div class="f"><label class="fl">Name</label><input type="text" name="name" maxlength="80" value="<?= h($u['name']) ?>"></div>
            <div class="f s"><label class="fl">Role</label><select name="role"><option value="teacher"<?= $u['role'] === 'teacher' ? ' selected' : '' ?>>Teacher</option><option value="admin"<?= $u['role'] === 'admin' ? ' selected' : '' ?>>Admin</option></select></div>
            <div class="f"><label class="fl">Class codes (teachers)</label><input type="text" name="classes" value="<?= h($u['classes']) ?>" placeholder="e.g. 7B, 8A"></div>
            <div><button class="btn primary" type="submit">Save</button> <a class="btn" href="admin_users.php">Cancel</a></div>
          </form>
        </td></tr>
        <?php else: ?>
        <tr>
          <td><b><?= h($u['name'] ?: $u['username']) ?></b><div class="sub"><?= h($u['username']) ?><?= (int)$u['id'] === (int)$me['id'] ? ' · you' : '' ?></div></td>
          <td><span class="pill <?= $u['role'] === 'admin' ? 'brand' : '' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Teacher' ?></span></td>
          <td><?= $u['role'] === 'admin' ? '<span class="hint">all classes</span>' : ($u['classes'] ? h($u['classes']) : '<span class="hint">none yet</span>') ?></td>
          <td class="hideS"><?= $u['last_login'] ? h(date('j M, g:i a', strtotime($u['last_login']))) : '<span class="hint">never</span>' ?></td>
          <td class="act">
            <a class="btn sm" href="admin_users.php?edit=<?= (int)$u['id'] ?>">Edit</a>
            <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('Give ' . $u['username'] . ' a new password? The old one stops working.')) ?>)"><?= pc_csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn sm" type="submit">New password</button></form>
            <?php if ((int)$u['id'] !== (int)$me['id']): ?>
            <form method="post" class="inline" onsubmit="return confirm(<?= h(json_encode('Remove the login ' . $u['username'] . '? Students are not affected.')) ?>)"><?= pc_csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn sm danger" type="submit">Remove</button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="section-t">Add a login</div>
    <form method="post" class="row" autocomplete="off">
      <?= pc_csrf_field() ?><input type="hidden" name="do" value="create">
      <div class="f"><label class="fl" for="c_name">Name</label><input type="text" id="c_name" name="name" maxlength="80" placeholder="e.g. Mrs Rao"></div>
      <div class="f s" style="flex-basis:150px"><label class="fl" for="c_user">Username</label><input type="text" id="c_user" name="username" required pattern="[A-Za-z0-9._-]{3,40}" placeholder="e.g. rao"></div>
      <div class="f s"><label class="fl" for="c_role">Role</label><select id="c_role" name="role"><option value="teacher">Teacher</option><option value="admin">Admin</option></select></div>
      <div class="f"><label class="fl" for="c_classes">Class codes (teachers)</label><input type="text" id="c_classes" name="classes" placeholder="e.g. 7B, 8A"></div>
      <div class="f s" style="flex-basis:150px"><label class="fl" for="c_pass">Password</label><input type="text" id="c_pass" name="password" placeholder="blank = make one"></div>
      <div><button class="btn primary" type="submit">Add login</button></div>
    </form>
    <p class="hint" style="margin-top:8px">The password is shown once after you add the login. Teachers see only the classes listed here.</p>
  </div>
<?php endif; ?>

  <div class="card" style="max-width:520px">
    <div class="section-t">My account · <?= h($me['username']) ?></div>
    <form method="post" autocomplete="off">
      <?= pc_csrf_field() ?><input type="hidden" name="do" value="mypass">
      <div style="margin-bottom:10px"><label class="fl" for="m_cur">Current password</label><input type="password" id="m_cur" name="current" required autocomplete="current-password"></div>
      <div class="row">
        <div class="f"><label class="fl" for="m_n1">New password (8+)</label><input type="password" id="m_n1" name="new1" required minlength="8" autocomplete="new-password"></div>
        <div class="f"><label class="fl" for="m_n2">New password again</label><input type="password" id="m_n2" name="new2" required minlength="8" autocomplete="new-password"></div>
      </div>
      <div style="margin-top:12px"><button class="btn primary" type="submit">Change password</button></div>
    </form>
  </div>
</div>
<?php pc_page_end();
