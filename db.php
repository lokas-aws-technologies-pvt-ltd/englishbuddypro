<?php
/**
 * Phrase Coach - database helper (Phase 1).
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
