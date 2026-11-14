<?php
/**
 * English Buddy - backend sign-in.
 * ---------------------------------------------------------------------------
 * First visit (no logins exist yet): create the admin account. This needs the
 * $TEACHER_KEY passcode from config.php, so only someone with server access
 * can claim admin. After that, everyone signs in with username + password.
 * ---------------------------------------------------------------------------
 */
require __DIR__ . '/admin_lib.php';

if (isset($_GET['logout'])) {
  $_SESSION = [];
  session_destroy();
  pc_redirect('admin.php');
}

$err = ''; $dberr = '';
try {
  $pdo = pc_db();
  $staffCount = (int)$pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();
} catch (Throwable $e) {
  $dberr = $e->getMessage(); $staffCount = -1;
}

if (!$dberr && pc_me()) pc_redirect('teacher.php');

$TEACHER_KEY = '';
if (is_file(__DIR__ . '/config.php')) { $cfg = (function () { $TEACHER_KEY = ''; require __DIR__ . '/config.php'; return $TEACHER_KEY; })(); $TEACHER_KEY = (string)$cfg; }

$_SESSION['fails'] = $_SESSION['fails'] ?? 0;

if (!$dberr && $_SERVER['REQUEST_METHOD'] === 'POST') {
  pc_csrf_check();
  if ($_SESSION['fails'] >= 5) sleep(2);                 // slow down guessing

  if ($staffCount === 0 && isset($_POST['setup'])) {
    // ---- one-time admin setup ----
    $user = strtolower(trim((string)$_POST['username'])); $name = trim((string)$_POST['name']);
    $p1 = (string)$_POST['password']; $p2 = (string)$_POST['password2'];
    if ($TEACHER_KEY === '' || !hash_equals($TEACHER_KEY, (string)$_POST['key'])) { $err = 'The setup passcode did not match $TEACHER_KEY in config.php.'; $_SESSION['fails']++; }
    elseif (!preg_match('/^[a-z0-9._-]{3,40}$/', $user)) $err = 'Username: 3–40 letters, numbers, dot, dash or underscore.';
    elseif (strlen($p1) < 8) $err = 'Password must be at least 8 characters.';
    elseif ($p1 !== $p2) $err = 'The two passwords do not match.';
    else {
      $pdo->prepare("INSERT INTO staff (username, name, pass_hash, role) VALUES (?,?,?, 'admin')")
          ->execute([$user, $name ?: null, password_hash($p1, PASSWORD_DEFAULT)]);
      session_regenerate_id(true);
      $_SESSION['staff_id'] = (int)$pdo->lastInsertId(); $_SESSION['fails'] = 0;
      pc_flash('Admin account created. Next: add your students, then create teacher logins.');
      pc_redirect('teacher.php');
    }
  } elseif ($staffCount > 0) {
    // ---- normal sign-in ----
    $st = $pdo->prepare("SELECT id, pass_hash FROM staff WHERE username=?");
    $st->execute([strtolower(trim((string)($_POST['username'] ?? '')))]);
    $row = $st->fetch();
    if ($row && password_verify((string)($_POST['password'] ?? ''), $row['pass_hash'])) {
      session_regenerate_id(true);
      $_SESSION['staff_id'] = (int)$row['id']; $_SESSION['fails'] = 0;
      $pdo->prepare("UPDATE staff SET last_login=NOW() WHERE id=?")->execute([$row['id']]);
      pc_redirect('teacher.php');
    }
    $_SESSION['fails']++;
    $err = 'Wrong username or password.';
  }
}

pc_page_start($staffCount === 0 ? 'Set up' : 'Sign in', '');
?>
<div class="login card">
  <div class="logo"><img src="img/igiver-logo.png" alt="iGiver"><div><h1 style="font-size:21px">English Buddy</h1><div class="hint">Backend for admins and teachers</div></div></div>
<?php if ($dberr): ?>
  <p class="err">Can't reach the database: <?= h($dberr) ?></p>
  <p class="hint">Check the MySQL settings in config.php (see DEPLOY.md).</p>
<?php elseif ($staffCount === 0): ?>
  <h3 style="font-size:17px;margin-top:4px">Create the admin account</h3>
  <p class="hint">One-time setup. You need the <code>$TEACHER_KEY</code> passcode from <code>config.php</code> on the server.</p>
  <?php if ($TEACHER_KEY === ''): ?><p class="err">Set <code>$TEACHER_KEY</code> in config.php first, then reload.</p><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= pc_csrf_field() ?><input type="hidden" name="setup" value="1">
    <div class="field"><label class="fl" for="key">Setup passcode ($TEACHER_KEY)</label><input type="password" id="key" name="key" required></div>
    <div class="field"><label class="fl" for="username">Your username</label><input type="text" id="username" name="username" required pattern="[A-Za-z0-9._-]{3,40}" value="<?= h($_POST['username'] ?? '') ?>"></div>
    <div class="field"><label class="fl" for="name">Your name</label><input type="text" id="name" name="name" value="<?= h($_POST['name'] ?? '') ?>"></div>
    <div class="field"><label class="fl" for="password">Password (8+ characters)</label><input type="password" id="password" name="password" required minlength="8"></div>
    <div class="field"><label class="fl" for="password2">Password again</label><input type="password" id="password2" name="password2" required minlength="8"></div>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <div class="field"><button class="btn primary" type="submit">Create admin account</button></div>
  </form>
<?php else: ?>
  <form method="post">
    <?= pc_csrf_field() ?>
    <div class="field"><label class="fl" for="username">Username</label><input type="text" id="username" name="username" required autocomplete="username" autofocus value="<?= h($_POST['username'] ?? '') ?>"></div>
    <div class="field"><label class="fl" for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <div class="field"><button class="btn primary" type="submit">Sign in</button></div>
  </form>
  <p class="hint">Forgot your password? Ask the admin to reset it.</p>
<?php endif; ?>
</div>
<?php pc_page_end();
