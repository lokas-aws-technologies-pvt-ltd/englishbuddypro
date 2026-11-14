<?php
/**
 * English Buddy - student sign-in check.
 * POST JSON { class_code, roll_no } -> { ok:true, name } when the student is on
 * the roster, or { ok:false, error:"not_on_roster" } when roster-only sign-in
 * is on and they are not. With roster-only off, everyone is allowed.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); exit; }
$d = json_decode(file_get_contents('php://input'), true);
$code = substr(trim((string)($d['class_code'] ?? '')), 0, 40);
$roll = substr(trim((string)($d['roll_no'] ?? '')), 0, 40);
if ($code === '' || $roll === '') { http_response_code(400); echo json_encode(['error'=>'missing class_code / roll_no']); exit; }
try {
  require __DIR__ . '/db.php';
  $pdo = pc_pdo();
  $st = $pdo->prepare("SELECT class_code, roll_no, name FROM students WHERE class_code=? AND roll_no=?");
  $st->execute([$code, $roll]);
  $s = $st->fetch();
  if ($s)                    echo json_encode(['ok'=>true, 'name'=>(string)$s['name'], 'class_code'=>$s['class_code'], 'roll_no'=>$s['roll_no']]);
  elseif (pc_roster_only($pdo)) echo json_encode(['ok'=>false, 'error'=>'not_on_roster']);
  else                       echo json_encode(['ok'=>true, 'name'=>'', 'open'=>true]);
} catch (Throwable $e) {
  http_response_code(500); echo json_encode(['error'=>'server: '.$e->getMessage()]);
}
