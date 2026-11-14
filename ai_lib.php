<?php
/**
 * English Buddy - AI usage and spending limits (shared by score_gemini.php and score.php).
 * ---------------------------------------------------------------------------
 * Before a paid AI check, pc_ai_gate() says whether it may run:
 *   - AI checks switched off in the backend            -> 'paused'
 *   - this month's cost has reached the monthly limit  -> 'budget'
 *   - this student used today's AI checks              -> 'student_daily'
 * When blocked, the app quietly shows the free phone score instead.
 * Every check is recorded with pc_ai_log() for the backend's AI usage page.
 * If the database is not set up, nothing is limited or recorded.
 * ---------------------------------------------------------------------------
 */
function pc_ai_db() {
  static $pdo = false;
  if ($pdo !== false) return $pdo;
  $pdo = null;
  try { require_once __DIR__ . '/db.php'; $pdo = pc_pdo(); } catch (Throwable $e) { $pdo = null; }
  return $pdo;
}
function pc_ai_settings($pdo) {
  return ['enabled'       => pc_setting($pdo, 'ai_enabled', '1') === '1',
          'monthly_limit' => max(0, (float)pc_setting($pdo, 'ai_monthly_limit', '5')),    // USD; 0 = no limit
          'student_daily' => max(0, (int)pc_setting($pdo, 'ai_student_daily', '0'))];     // checks; 0 = no limit
}
function pc_ai_month_cost($pdo) {
  return (float)$pdo->query("SELECT COALESCE(SUM(cost_usd),0) FROM ai_calls WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
}
/* null = allowed; otherwise the reason code. */
function pc_ai_gate($pdo, $class, $roll) {
  $s = pc_ai_settings($pdo);
  if (!$s['enabled']) return 'paused';
  if ($s['monthly_limit'] > 0 && pc_ai_month_cost($pdo) >= $s['monthly_limit']) return 'budget';
  if ($s['student_daily'] > 0 && $class !== '' && $roll !== '') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM ai_calls WHERE class_code=? AND roll_no=? AND created_at >= CURDATE()");
    $st->execute([$class, $roll]);
    if ((int)$st->fetchColumn() >= $s['student_daily']) return 'student_daily';
  }
  return null;
}
function pc_ai_log($pdo, $engine, $class, $roll, $ok, $cost, $audioSec, $tin, $tout, $error = null) {
  if (!$pdo) return;
  try {
    $pdo->prepare("INSERT INTO ai_calls (engine, class_code, roll_no, ok, audio_seconds, tokens_in, tokens_out, cost_usd, error) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$engine, $class !== '' ? $class : null, $roll !== '' ? $roll : null, $ok ? 1 : 0,
                   $audioSec !== null ? round($audioSec, 2) : null, $tin, $tout, round((float)$cost, 6), $error !== null ? mb_substr($error, 0, 200) : null]);
  } catch (Throwable $e) { /* recording must never break scoring */ }
}
/* Reply that tells the app to fall back to the phone score without an error box. */
function pc_ai_blocked($reason) {
  http_response_code(429);
  echo json_encode(['error' => 'AI check not available (' . $reason . ')', 'fallback' => true, 'reason' => $reason]);
  exit;
}
