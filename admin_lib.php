<?php
/**
 * English Buddy - backend shared code: logins, roles, page layout.
 * ---------------------------------------------------------------------------
 * Roles
 *   admin   : sees and manages everything (all classes, logins, settings).
 *   teacher : sees and manages only the class codes assigned to them.
 * Passwords are stored as hashes (password_hash). Every form that changes
 * something carries a CSRF token. Every page re-reads the logged-in user from
 * the database, so a role or class change applies at once.
 * ---------------------------------------------------------------------------
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  ]);
  session_name('ebadmin');
  session_start();
}

require_once __DIR__ . '/db.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pc_db() {
  static $pdo = null;
  if ($pdo === null) $pdo = pc_pdo();
  return $pdo;
}

/* ---------------- who is logged in ---------------- */
function pc_me() {
  static $me = false;
  if ($me !== false) return $me;
  $me = null;
  if (empty($_SESSION['staff_id'])) return null;
  $st = pc_db()->prepare("SELECT id, username, name, role FROM staff WHERE id=?");
  $st->execute([(int)$_SESSION['staff_id']]);
  $row = $st->fetch();
  if (!$row) { $_SESSION = []; return null; }          // account was deleted
  $row['classes'] = [];
  if ($row['role'] !== 'admin') {
    $c = pc_db()->prepare("SELECT class_code FROM staff_classes WHERE staff_id=? ORDER BY class_code");
    $c->execute([$row['id']]);
    $row['classes'] = $c->fetchAll(PDO::FETCH_COLUMN);
  }
  return $me = $row;
}
function pc_is_admin($me) { return $me && $me['role'] === 'admin'; }

function pc_require_login() {
  $me = pc_me();
  if (!$me) { header('Location: admin.php'); exit; }
  return $me;
}
function pc_require_admin() {
  $me = pc_require_login();
  if (!pc_is_admin($me)) { http_response_code(403); pc_page_start('Not allowed', ''); echo '<div class="card"><div class="empty"><h3>Admins only</h3><p class="hint">Ask the admin if you need this.</p></div></div>'; pc_page_end(); exit; }
  return $me;
}

/* Class codes this user may see: null = all (admin). */
function pc_allowed_classes($me) { return pc_is_admin($me) ? null : $me['classes']; }
function pc_class_ok($me, $code) {
  $allowed = pc_allowed_classes($me);
  return $allowed === null || in_array(strtolower(trim($code)), array_map('strtolower', $allowed), true);
}
/* SQL fragment + params limiting a students alias to the allowed classes. */
function pc_class_where($me, $alias = 's') {
  $allowed = pc_allowed_classes($me);
  if ($allowed === null) return ['1=1', []];
  if (!$allowed) return ['1=0', []];
  return [$alias . '.class_code IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')', $allowed];
}
/* All class codes this user can pick from (for dropdowns). */
function pc_class_options($me) {
  $allowed = pc_allowed_classes($me);
  if ($allowed !== null) return $allowed;
  $codes = pc_db()->query("SELECT DISTINCT class_code FROM students UNION SELECT DISTINCT class_code FROM staff_classes ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
  return array_values(array_filter($codes, 'strlen'));
}

/* ---------------- forms ---------------- */
function pc_csrf() {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function pc_csrf_field() { return '<input type="hidden" name="csrf" value="' . h(pc_csrf()) . '">'; }
function pc_csrf_check() {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(pc_csrf(), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400); exit('This form expired. Go back, reload the page and try again.');
  }
}
function pc_flash($msg = null, $kind = 'ok') {
  if ($msg !== null) { $_SESSION['flash'][] = [$kind, $msg]; return; }
  $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}
function pc_redirect($url) { header('Location: ' . $url); exit; }

/* ---------------- page layout ---------------- */
function pc_page_start($title, $active, $me = null) {
  echo "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n";
  echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n";
  echo "<meta name=\"theme-color\" content=\"#111111\">\n<meta name=\"robots\" content=\"noindex\">\n";
  echo "<link rel=\"icon\" type=\"image/png\" href=\"img/favicon.png\">\n";
  echo "<title>" . h($title) . " - English Buddy</title>\n";
  echo <<<CSS
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;500;600;700&family=Noto+Sans+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{
    /* iGiver brand, same as the student app */
    --ground:#f6f5f2; --surface:#ffffff; --surface-2:#f1f0ec;
    --ink:#111111; --muted:#55565b; --dim:#6e6f74;
    --line:#e4e2dd; --line-soft:#eeede9;
    --brand:#ff9035; --accent:#b4500a; --accent-ink:#111111; --accent-soft:#fff0e3;
    --good:#1f8a57; --warn:#c9820a; --crit:#cf4b34;
    --good-soft:#dcefe4; --warn-soft:#f6ecd4; --crit-soft:#f6ded7;
    --display:"Noto Sans", ui-sans-serif, system-ui, sans-serif;
    --ui:"Noto Sans", ui-sans-serif, system-ui, sans-serif;
    --mono:"Noto Sans Mono", ui-monospace, Menlo, monospace;
    --r:14px; --r-sm:9px; --gutter:clamp(16px,4vw,26px); --maxw:980px;
  }
  *{ box-sizing:border-box; }
  html,body{ margin:0; background:var(--ground); color:var(--ink); }
  body{ font-family:var(--ui); font-size:15px; line-height:1.5; -webkit-font-smoothing:antialiased; }
  .wrap{ max-width:var(--maxw); margin:0 auto; padding:16px var(--gutter) 40px; }
  h1,h2,h3{ margin:0; }
  a{ color:var(--accent); }
  button{ font-family:var(--ui); cursor:pointer; }
  :focus-visible{ outline:2px solid var(--accent); outline-offset:2px; }

  /* header + tabs */
  .top{ display:flex; align-items:center; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
  .brand{ display:flex; align-items:center; gap:10px; min-width:0; text-decoration:none; color:var(--ink); }
  .brand img{ width:36px; height:36px; border-radius:8px; }
  .brand h1{ font-weight:700; font-size:20px; letter-spacing:-.01em; line-height:1.1; }
  .brand .tag{ font-size:10.5px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--accent); }
  .top .sp{ flex:1 1 auto; }
  .me{ font-size:12.5px; color:var(--muted); text-align:right; line-height:1.3; }
  .me b{ color:var(--ink); font-weight:600; }
  .iconbtn{ background:var(--surface); border:1px solid var(--line); color:var(--ink); border-radius:999px; padding:7px 12px; font-size:12.5px; line-height:1; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
  .iconbtn:hover{ border-color:var(--accent); }
  .tabs{ display:flex; gap:4px; border-bottom:1px solid var(--line); margin-bottom:18px; overflow-x:auto; }
  .tabs a{ padding:10px 14px; text-decoration:none; color:var(--muted); font-weight:600; font-size:14px; border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; }
  .tabs a.on{ color:var(--ink); border-bottom-color:var(--brand); }

  /* building blocks */
  .card{ background:var(--surface); border:1px solid var(--line); border-radius:var(--r); padding:18px; }
  .stack{ display:grid; gap:16px; }
  .eyebrow{ font-family:var(--mono); font-size:10.5px; letter-spacing:.14em; text-transform:uppercase; color:var(--dim); display:flex; align-items:center; justify-content:space-between; gap:10px; }
  .section-t{ font-family:var(--mono); font-size:10.5px; letter-spacing:.12em; text-transform:uppercase; color:var(--dim); margin:2px 0 12px; }
  .hint{ font-size:12.5px; color:var(--dim); }
  .empty{ text-align:center; color:var(--muted); padding:26px 10px; }
  .empty h3{ font-weight:700; font-size:18px; color:var(--ink); margin-bottom:8px; }
  .flash{ border-radius:var(--r-sm); padding:10px 13px; font-size:14px; margin-bottom:12px; border:1px solid; }
  .flash.ok{ background:var(--good-soft); border-color:var(--good); }
  .flash.err{ background:var(--crit-soft); border-color:var(--crit); }
  .copy{ font-size:11px; color:var(--dim); text-align:center; margin:28px 0 4px; }
  .num{ font-variant-numeric:tabular-nums; }

  /* forms */
  label.fl{ display:block; font-size:12px; color:var(--muted); margin-bottom:5px; font-weight:500; }
  input[type=text], input[type=password], input[type=search], select, textarea{
    width:100%; padding:10px 12px; border-radius:var(--r-sm); border:1px solid var(--line);
    background:var(--surface); color:var(--ink); font-family:var(--ui); font-size:14px; }
  textarea{ font-family:var(--mono); font-size:13px; min-height:130px; resize:vertical; }
  .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
  .row > .f{ flex:1 1 140px; min-width:0; }
  .row > .f.s{ flex:0 1 110px; }
  .btn{ border-radius:var(--r-sm); font-size:14px; font-weight:600; padding:10px 15px; border:1px solid var(--line);
    background:var(--surface-2); color:var(--ink); text-decoration:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
  .btn:hover{ border-color:var(--accent); }
  .btn.primary{ background:var(--brand); border-color:var(--brand); font-weight:700; }
  .btn.danger{ color:var(--crit); }
  .btn.sm{ padding:6px 10px; font-size:12.5px; }
  .btn.link{ background:none; border:0; padding:0; color:var(--accent); font-weight:600; }
  form.inline{ display:inline; margin:0; }
  details.more > summary{ cursor:pointer; color:var(--accent); font-weight:600; font-size:14px; }
  .pill{ display:inline-block; font-size:11.5px; padding:2px 9px; border-radius:999px; background:var(--surface-2); color:var(--muted); font-weight:600; }
  .pill.on{ background:var(--good-soft); color:var(--good); }
  .pill.brand{ background:var(--accent-soft); color:var(--accent); }

  /* tables */
  table.list{ width:100%; border-collapse:collapse; font-size:14px; }
  table.list th{ text-align:left; font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:var(--dim); font-weight:600; padding:0 8px 8px; border-bottom:1px solid var(--line); white-space:nowrap; }
  table.list td{ padding:10px 8px; border-bottom:1px solid var(--line-soft); vertical-align:middle; }
  table.list tr:last-child td{ border-bottom:0; }
  table.list td.act{ text-align:right; white-space:nowrap; }
  table.list .sub{ font-size:12px; color:var(--dim); }
  @media (max-width:640px){ table.list .hideS{ display:none; } .me{ display:none; } }

  /* login */
  .login{ max-width:400px; margin:9vh auto 0; }
  .login .logo{ display:flex; align-items:center; gap:12px; margin-bottom:16px; }
  .login .logo img{ width:52px; height:52px; border-radius:11px; }
  .login .field{ margin:14px 0; }
  .login .err{ color:var(--crit); font-size:13px; margin-top:8px; }
  .login .btn{ width:100%; justify-content:center; }
</style>
CSS;
  echo "</head>\n<body>\n<div class=\"wrap\">\n";
  if ($me) {
    $tabs = ['overview' => ['Overview', 'teacher.php'], 'students' => ['Students', 'admin_students.php']];
    if (pc_is_admin($me)) $tabs['sentences'] = ['Sentences', 'admin_sentences.php'];
    if (pc_is_admin($me)) $tabs['gifts'] = ['Gifts & points', 'admin_gifts.php'];
    if (pc_is_admin($me)) $tabs['ai'] = ['AI usage', 'admin_ai.php'];
    $tabs['users'] = [pc_is_admin($me) ? 'Logins' : 'My account', 'admin_users.php'];
    echo '<div class="top"><a class="brand" href="teacher.php"><img src="img/igiver-logo.png" alt="iGiver"><div><h1>English Buddy</h1><div class="tag">Backend</div></div></a><span class="sp"></span>';
    echo '<div class="me"><b>' . h($me['name'] ?: $me['username']) . '</b><br>' . (pc_is_admin($me) ? 'Admin · all classes' : 'Teacher · ' . h($me['classes'] ? implode(', ', $me['classes']) : 'no classes yet')) . '</div>';
    echo '<a class="iconbtn" href="admin.php?logout=1">Sign out</a></div>';
    echo '<nav class="tabs">';
    foreach ($tabs as $k => [$label, $href]) echo '<a href="' . $href . '"' . ($k === $active ? ' class="on"' : '') . '>' . h($label) . '</a>';
    echo '</nav>';
    foreach (pc_flash() as [$kind, $msg]) echo '<div class="flash ' . h($kind) . '">' . h($msg) . '</div>';
  }
}
function pc_page_end() {
  echo '<p class="copy">&copy; ' . date('Y') . ' Lokas AWS Technologies Pvt Ltd</p>' . "\n</div>\n</body>\n</html>\n";
}
