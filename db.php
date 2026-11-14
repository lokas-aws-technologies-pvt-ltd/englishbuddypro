<?php
/**
 * English Buddy - database helper (Phase 1).
 * Reads DB credentials from config.php and creates the tables if missing.
 *
 * In config.php add:
 *   $DB_HOST = 'localhost';
 *   $DB_NAME = 'yourcp_englishbuddy';
 *   $DB_USER = 'yourcp_ebuser';
 *   $DB_PASS = 'the-db-password';
 */

function pc_pdo(){
  $DB_HOST='localhost'; $DB_NAME=''; $DB_USER=''; $DB_PASS='';
  if (is_file(__DIR__.'/config.php')) require __DIR__.'/config.php';
  if ($DB_NAME==='' || $DB_USER==='') throw new Exception('Database is not configured in config.php');
  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
  pc_ensure_schema($pdo);
  return $pdo;
}

function pc_ensure_schema($pdo){
  $pdo->exec("CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_code VARCHAR(40) NOT NULL,
    roll_no    VARCHAR(40) NOT NULL,
    name       VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student (class_code, roll_no)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id       INT NOT NULL,
    phrase_idx       INT NULL,
    difficulty       VARCHAR(10) NULL,
    engine           VARCHAR(10) NULL,
    overall          DECIMAL(4,1) NULL,
    fluency          DECIMAL(4,1) NULL,
    flow             DECIMAL(4,1) NULL,
    pronunciation    DECIMAL(4,1) NULL,
    completeness     DECIMAL(4,1) NULL,
    pace_wpm         INT NULL,
    pause_count      INT NULL,
    longest_pause_ms INT NULL,
    lead_ms          INT NULL,
    filler_count     INT NULL,
    mispronounced    TEXT NULL,
    skipped          TEXT NULL,
    duration_s       DECIMAL(6,2) NULL,
    lang             VARCHAR(10) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_student_time (student_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // backend logins: admin sees everything, a teacher only their class codes
  $pdo->exec("CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(40) NOT NULL,
    name       VARCHAR(80) NULL,
    pass_hash  VARCHAR(255) NOT NULL,
    role       VARCHAR(10) NOT NULL DEFAULT 'teacher',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    UNIQUE KEY uniq_user (username)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS staff_classes (
    staff_id   INT NOT NULL,
    class_code VARCHAR(40) NOT NULL,
    PRIMARY KEY (staff_id, class_code)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // app-wide switches (e.g. roster_only), changed from the backend
  $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
    k VARCHAR(40) NOT NULL PRIMARY KEY,
    v TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // one row per paid AI check (Google/Azure): usage, cost and the monthly limit
  $pdo->exec("CREATE TABLE IF NOT EXISTS ai_calls (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    engine        VARCHAR(10) NOT NULL,
    class_code    VARCHAR(40) NULL,
    roll_no       VARCHAR(40) NULL,
    ok            TINYINT(1) NOT NULL DEFAULT 1,
    audio_seconds DECIMAL(6,2) NULL,
    tokens_in     INT NULL,
    tokens_out    INT NULL,
    cost_usd      DECIMAL(10,6) NOT NULL DEFAULT 0,
    error         VARCHAR(200) NULL,
    KEY idx_time (created_at),
    KEY idx_student (class_code, roll_no, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // gifts behind the scratch cards, managed in the backend (card N = gift N, the last repeats)
  $pdo->exec("CREATE TABLE IF NOT EXISTS gifts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    position    INT NOT NULL DEFAULT 0,
    name        VARCHAR(80) NOT NULL,
    description VARCHAR(200) NULL,
    image       VARCHAR(120) NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // scratch cards: one row per unlocked card; scratched_at set when revealed
  $pdo->exec("CREATE TABLE IF NOT EXISTS rewards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id   INT NOT NULL,
    card_no      INT NOT NULL,
    reward_key   VARCHAR(20) NOT NULL,
    unlocked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scratched_at DATETIME NULL,
    UNIQUE KEY uniq_card (student_id, card_no)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* App-wide switches stored in app_settings. */
function pc_setting($pdo, $k, $default = null) {
  $st = $pdo->prepare("SELECT v FROM app_settings WHERE k=?");
  $st->execute([$k]);
  $v = $st->fetchColumn();
  return $v === false ? $default : $v;
}
function pc_set_setting($pdo, $k, $v) {
  $pdo->prepare("INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([$k, (string)$v]);
}
/* Roster only (default ON): students must be added in the backend before they can sign in. */
function pc_roster_only($pdo) { return pc_setting($pdo, 'roster_only', '1') === '1'; }

/* ---------------- points and gift settings (changed in the backend) ---------------- */
function pc_points_defaults() {
  return ['gift_steps'=>'100,250,450,700,1000', 'gift_step_after'=>'350', 'practice_cap'=>'60', 'pb_rate'=>'10', 'mastery_bonus'=>'20'];
}
/* Current points settings as numbers: steps (int[]), after, cap, pb_rate, mastery_bonus. */
function pc_points_settings($pdo) {
  $d = pc_points_defaults(); $v = [];
  foreach ($d as $k => $def) $v[$k] = pc_setting($pdo, $k, $def);
  $steps = array_values(array_filter(array_map('intval', explode(',', $v['gift_steps'])), function ($x) { return $x > 0; }));
  if (!$steps) $steps = array_map('intval', explode(',', $d['gift_steps']));
  return ['steps'=>$steps, 'after'=>max(1, (int)$v['gift_step_after']), 'cap'=>max(1, (int)$v['practice_cap']),
          'pb_rate'=>max(0, (int)$v['pb_rate']), 'mastery_bonus'=>max(0, (int)$v['mastery_bonus'])];
}

/* Active gifts in order. The first time, it starts with the original badge gifts (no pictures). */
function pc_active_gifts($pdo) {
  if ((int)$pdo->query("SELECT COUNT(*) FROM gifts")->fetchColumn() === 0) {
    $ins = $pdo->prepare("INSERT INTO gifts (position, name, description) VALUES (?,?,?)");
    foreach ([['Brave Beginner', 'For showing up and trying again.'], ['Smooth Talker', 'Your reading is flowing better.'],
              ['Office Ready', 'You sound ready for the office.'], ['Word Master', 'You have mastered tough office words.'],
              ['Gold Star', 'Another step up. Keep going!']] as $i => [$n, $d]) $ins->execute([$i + 1, $n, $d]);
  }
  return $pdo->query("SELECT id, position, name, description, image FROM gifts WHERE active=1 ORDER BY position, id")->fetchAll();
}
function pc_gift_image_url($file) { return $file ? 'uploads/gifts/' . rawurlencode($file) : null; }
