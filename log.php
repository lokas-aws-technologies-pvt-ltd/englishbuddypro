<?php
/**
 * Phrase Coach - log one practice attempt (Phase 1).
 * The app POSTs a small JSON body after each real scored attempt.
 * Same domain as the page, so no CORS. Fails quietly if the DB isn't set up
 * (the app ignores the response, so practice is never blocked).
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); exit; }

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) { http_response_code(400); echo json_encode(['error'=>'bad json']); exit; }

$code = trim((string)($d['class_code'] ?? ''));
$roll = trim((string)($d['roll_no'] ?? ''));
if ($code === '' || $roll === '') { http_response_code(400); echo json_encode(['error'=>'missing class_code / roll_no']); exit; }

// helpers to coerce values safely
$numOrNull = function($v){ return is_numeric($v) ? $v + 0 : null; };
$intOrNull = function($v){ return is_numeric($v) ? (int)$v : null; };

try {
  require __DIR__ . '/db.php';
  $pdo = pc_pdo();

  // find-or-create the student; LAST_INSERT_ID(id) returns the existing id on update
  $pdo->prepare("INSERT INTO students (class_code, roll_no, name) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE name=VALUES(name), id=LAST_INSERT_ID(id)")
      ->execute([substr($code,0,40), substr($roll,0,40), substr(trim((string)($d['name'] ?? '')),0,80)]);
  $sid = (int)$pdo->lastInsertId();

  $stmt = $pdo->prepare("INSERT INTO attempts
    (student_id, phrase_idx, difficulty, engine, overall, fluency, flow, pronunciation, completeness,
     pace_wpm, pause_count, longest_pause_ms, lead_ms, filler_count, mispronounced, skipped, duration_s, lang)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([
    $sid,
    $intOrNull($d['phrase_idx'] ?? null),
    substr((string)($d['difficulty'] ?? ''),0,10) ?: null,
    substr((string)($d['engine'] ?? ''),0,10) ?: null,
    $numOrNull($d['overall'] ?? null),
    $numOrNull($d['fluency'] ?? null),
    $numOrNull($d['flow'] ?? null),
    $numOrNull($d['pronunciation'] ?? null),
    $numOrNull($d['completeness'] ?? null),
    $intOrNull($d['pace_wpm'] ?? null),
    $intOrNull($d['pause_count'] ?? null),
    $intOrNull($d['longest_pause_ms'] ?? null),
    $intOrNull($d['lead_ms'] ?? null),
    $intOrNull($d['filler_count'] ?? null),
    isset($d['mispronounced']) && is_array($d['mispronounced']) ? json_encode(array_slice($d['mispronounced'],0,20)) : null,
    isset($d['skipped']) && is_array($d['skipped']) ? json_encode(array_slice($d['skipped'],0,20)) : null,
    $numOrNull($d['duration_s'] ?? null),
    substr((string)($d['lang'] ?? ''),0,10) ?: null,
  ]);

  echo json_encode(['ok'=>true, 'attempt_id'=>(int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server: '.$e->getMessage()]);
}
