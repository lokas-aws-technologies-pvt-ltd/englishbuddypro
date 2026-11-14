<?php
/**
 * English Buddy - scratch-card rewards (in-app rewards only).
 * ---------------------------------------------------------------------------
 * POST JSON  { class_code, roll_no, action: "status" | "scratch", card_no? }
 * The status also carries the student's own progress (streak, last 7 days).
 *
 * Points and card unlocks are worked out HERE, from the attempts log.php saved,
 * so they can't be changed from the phone. The app only displays the result.
 *
 * Points
 *   Practice  : each attempt earns its score rounded (under 3 earns 1).
 *               Per day only the best 2 tries of each sentence count, at most
 *               30 tries a day, and at most 60 practice points a day.
 *               Silent / too-short takes earn nothing.
 *   Improvement: beating your own best on a sentence earns 10 points per point
 *               gained (5.2 -> 6.8 = +16). Scoring 8+ on the same sentence on
 *               2 different days "masters" it: +20 once. No daily limit, so
 *               improving is the only way past the practice cap.
 *
 * One goal: a card unlocks as soon as the points reach 100, 250, 450, 700,
 * 1000, then every +350. A full bar always means a card.
 * ---------------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); exit; }

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) { http_response_code(400); echo json_encode(['error'=>'bad json']); exit; }

$code = substr(trim((string)($d['class_code'] ?? '')), 0, 40);
$roll = substr(trim((string)($d['roll_no'] ?? '')), 0, 40);
if ($code === '' || $roll === '') { http_response_code(400); echo json_encode(['error'=>'missing class_code / roll_no']); exit; }
$action = (string)($d['action'] ?? 'status');

/* ---------------- rules ----------------
   Gift steps, the step after them, the daily practice cap, personal-best points and the
   mastery bonus are set in the backend (admin_gifts.php) and defined below once the
   database is open. The rest are fixed here. */
const PC_BEST_PER_DAY    = 2;     // best N tries per sentence per day count
const PC_MAX_PER_DAY     = 30;    // at most this many counted tries a day
const PC_MIN_DURATION_S  = 1.0;   // shorter takes earn nothing
const PC_MASTERY_SCORE   = 8.0;
const PC_MASTERY_DAYS    = 2;
const PC_PRON_MASTER_SCORE = 8.0; // AI pronunciation score that counts toward "pronunciation mastered"
const PC_PRON_MASTER_TIMES = 2;   // ...this many times; the app then stops sending that sentence to AI

/* ---------------- the gift behind each card ----------------
   Card N holds gift N from the backend list; after the last gift, the last one repeats.
   The gift is stored on the card when it unlocks (reward_key "g<id>"), so later edits to
   the list never change a gift a student has already won. */
function pc_gift_for_card($pdo, $n) {
  $gifts = pc_active_gifts($pdo);
  if (!$gifts) return 'star';
  return 'g' . $gifts[min($n, count($gifts)) - 1]['id'];
}
/* Gifts handed out before the backend existed: shown as they were, with no effects. */
function pc_legacy_gift($key) {
  static $cat = [
    'brave'=>['🏅','Brave Beginner badge','For showing up and trying again.'], 'saffron'=>['🎨','Saffron colour','A colour gift (colours are now fixed).'],
    'smooth'=>['🌊','Smooth Talker badge','Your reading is flowing better.'], 'ocean'=>['🎨','Ocean colour','A colour gift (colours are now fixed).'],
    'office'=>['💼','"Office Ready" title','You sound ready for the office.'], 'rose'=>['🎨','Rose colour','A colour gift (colours are now fixed).'],
    'wordmaster'=>['📚','Word Master badge','You have mastered tough office words.'], 'star'=>['⭐','Gold Star','Another step up. Keep going!'],
  ];
  return $cat[$key] ?? ['🎁', 'Gift', 'A gift for your practice.'];
}
/* What the app shows for a card. */
function pc_card_view($pdo, $key, $n) {
  if (preg_match('/^g(\d+)$/', (string)$key, $m)) {
    $st = $pdo->prepare("SELECT name, description, image FROM gifts WHERE id=?");
    $st->execute([(int)$m[1]]);
    if ($g = $st->fetch()) return ['no'=>$n, 'kind'=>'gift', 'key'=>$key, 'icon'=>'🎁', 'name'=>$g['name'], 'desc'=>(string)$g['description'], 'image'=>pc_gift_image_url($g['image'])];
  }
  [$icon, $name, $desc] = pc_legacy_gift($key);
  return ['no'=>$n, 'kind'=>'gift', 'key'=>(string)$key, 'icon'=>$icon, 'name'=>$name, 'desc'=>$desc, 'image'=>null];
}

function pc_threshold($n) {
  $s = PC_CARD_STEPS; $k = count($s);
  return $n <= $k ? $s[$n-1] : $s[$k-1] + PC_STEP_AFTER * ($n - $k);
}
function pc_points_for($v) { return $v < 3 ? 1 : (int)round($v); }
function pc_valid($r) {
  return $r['overall'] !== null && ($r['duration_s'] === null || (float)$r['duration_s'] >= PC_MIN_DURATION_S);
}

/* Work out all points from a student's attempts (chronological). Every step is a
   "keep the top N" choice or a cap, so adding an attempt can never lower the total. */
function pc_compute($rows) {
  $valid = array_values(array_filter($rows, 'pc_valid'));

  // practice: best 2 per sentence per day, then best 30 per day, then the daily cap
  $byDay = [];
  foreach ($valid as $r) $byDay[substr($r['created_at'], 0, 10)][] = $r;
  $practice = 0; $practiceDay = [];
  $desc = function($a, $b){ return (float)$b['overall'] <=> (float)$a['overall']; };
  foreach ($byDay as $day => $list) {
    $byPh = [];
    foreach ($list as $r) $byPh[(int)$r['phrase_idx']][] = $r;
    $counted = [];
    foreach ($byPh as $pl) { usort($pl, $desc); foreach (array_slice($pl, 0, PC_BEST_PER_DAY) as $r) $counted[] = $r; }
    usort($counted, $desc);
    $sum = 0;
    foreach (array_slice($counted, 0, PC_MAX_PER_DAY) as $r) $sum += pc_points_for((float)$r['overall']);
    $practiceDay[$day] = min(PC_PRACTICE_CAP, $sum);
    $practice += $practiceDay[$day];
  }

  // improvement: personal bests and mastery
  $best = []; $high = []; $mastered = []; $pbT = []; $mastT = []; $improve = 0;
  foreach ($valid as $r) {
    $p = (int)$r['phrase_idx']; $v = (float)$r['overall']; $day = substr($r['created_at'], 0, 10);
    if (!isset($best[$p])) {
      $best[$p] = $v;                                   // first try sets the starting point
    } elseif ($v > $best[$p]) {
      $g = (int)round(($v - $best[$p]) * PC_PB_RATE);
      if ($g > 0) { $improve += $g; $pbT[] = $r['created_at']; }
      $best[$p] = $v;
    }
    if ($v >= PC_MASTERY_SCORE) {
      $high[$p][$day] = true;
      if (count($high[$p]) >= PC_MASTERY_DAYS && !isset($mastered[$p])) {
        $mastered[$p] = true; $improve += PC_MASTERY_BONUS; $mastT[] = $r['created_at'];
      }
    }
  }
  return ['valid'=>$valid, 'practice'=>$practice, 'practiceDay'=>$practiceDay, 'improve'=>$improve, 'total'=>$practice + $improve,
          'best'=>$best, 'high'=>$high, 'mastered'=>$mastered, 'pbT'=>$pbT, 'mastT'=>$mastT];
}

/* The student's own progress: streak, best streak and the last 7 days. */
function pc_progress($c, $today) {
  $days = [];                                          // Y-m-d => [tries, sum of scores]
  $phrases = [];
  foreach ($c['valid'] as $r) {
    $d = substr($r['created_at'], 0, 10);
    if (!isset($days[$d])) $days[$d] = ['n'=>0, 'sum'=>0.0];
    $days[$d]['n']++; $days[$d]['sum'] += (float)$r['overall'];
    if ((int)$r['phrase_idx'] > 0) $phrases[(int)$r['phrase_idx']] = true;
  }
  $t = strtotime($today);
  $shift = function($d, $n) { return date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' day', strtotime($d))); };

  $week = [];
  for ($i = 6; $i >= 0; $i--) {
    $d = $shift($today, -$i); $v = $days[$d] ?? null;
    $week[] = ['date'=>$d, 'dow'=>date('D', strtotime($d)), 'today'=>$i === 0,
               'n'=>$v ? $v['n'] : 0, 'avg'=>$v ? round($v['sum'] / $v['n'], 1) : null];
  }

  $sum7 = 0; $n7 = 0; $sumP = 0; $nP = 0;             // this 7 days vs the 7 before
  foreach ($days as $d => $v) {
    $age = (int)round(($t - strtotime($d)) / 86400);
    if ($age >= 0 && $age <= 6)       { $sum7 += $v['sum']; $n7 += $v['n']; }
    elseif ($age >= 7 && $age <= 13)  { $sumP += $v['sum']; $nP += $v['n']; }
  }

  // current streak: consecutive practice days ending today (or yesterday, still savable today)
  $streak = 0;
  $cur = isset($days[$today]) ? $today : (isset($days[$shift($today, -1)]) ? $shift($today, -1) : null);
  while ($cur !== null && isset($days[$cur])) { $streak++; $cur = $shift($cur, -1); }

  $best = 0; $run = 0; $prev = null; $keys = array_keys($days); sort($keys);
  foreach ($keys as $d) { $run = ($prev !== null && $shift($prev, 1) === $d) ? $run + 1 : 1; $best = max($best, $run); $prev = $d; }

  return ['streak'=>$streak, 'best_streak'=>$best, 'practised_today'=>isset($days[$today]),
          'week'=>$week, 'week_tries'=>$n7, 'week_avg'=>$n7 ? round($sum7 / $n7, 1) : null,
          'prev_week_avg'=>$nP ? round($sumP / $nP, 1) : null,
          'total_tries'=>count($c['valid']), 'sentences_tried'=>count($phrases), 'mastered'=>count($c['mastered']),
          'mastered_list'=>array_map('intval', array_keys($c['mastered']))];   // the app skips these
}

/* Sentences whose pronunciation this student has already nailed on AI scoring
   (8+ twice). The app skips the paid AI check for them. */
function pc_pron_mastered($pdo, $sid) {
  if (!$sid) return [];
  $st = $pdo->prepare("SELECT phrase_idx FROM attempts
                       WHERE student_id=? AND engine IN ('azure','gemini') AND pronunciation >= ?
                       GROUP BY phrase_idx HAVING COUNT(*) >= ?");
  $st->execute([$sid, PC_PRON_MASTER_SCORE, PC_PRON_MASTER_TIMES]);
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function pc_cards($pdo, $sid) {
  $st = $pdo->prepare("SELECT card_no, reward_key, unlocked_at, scratched_at FROM rewards WHERE student_id=? ORDER BY card_no");
  $st->execute([$sid]);
  return $st->fetchAll();
}

function pc_status($pdo, $sid, $name) {
  $clock = $pdo->query("SELECT CURDATE() AS d")->fetch();
  $today = $clock['d'];

  $rows = [];
  if ($sid) {
    $st = $pdo->prepare("SELECT id, phrase_idx, overall, duration_s, created_at FROM attempts WHERE student_id=? ORDER BY created_at, id");
    $st->execute([$sid]);
    $rows = $st->fetchAll();
  }
  $c = pc_compute($rows);

  // what the latest attempt earned (difference with and without it)
  $last = null;
  if ($rows) {
    $prev = pc_compute(array_slice($rows, 0, -1));
    $lr = $rows[count($rows) - 1];
    $masteredNow = count($c['mastT']) > count($prev['mastT']);
    $mastery = $masteredNow ? PC_MASTERY_BONUS : 0;
    $lastDay = substr($lr['created_at'], 0, 10);
    $last = ['attempt_id'=>(int)$lr['id'], 'phrase_idx'=>(int)$lr['phrase_idx'],
             'practice'=>$c['practice'] - $prev['practice'],
             'pb_points'=>($c['improve'] - $prev['improve']) - $mastery, 'mastery_points'=>$mastery,
             'pb'=>count($c['pbT']) > count($prev['pbT']), 'mastered'=>$masteredNow,
             'capped'=>($c['practiceDay'][$lastDay] ?? 0) >= PC_PRACTICE_CAP];
  }

  // unlock the next card as soon as its points are reached
  $cards = $sid ? pc_cards($pdo, $sid) : [];
  if ($sid) {
    for ($guard = 0; $guard < 3; $guard++) {
      $n = count($cards) + 1;
      if ($c['total'] < pc_threshold($n)) break;
      $pdo->prepare("INSERT IGNORE INTO rewards (student_id, card_no, reward_key, unlocked_at) VALUES (?,?,?,NOW())")
          ->execute([$sid, $n, pc_gift_for_card($pdo, $n)]);
      $cards = pc_cards($pdo, $sid);
    }
  }

  // the next card: one goal, points only
  $n = count($cards) + 1;
  $thr = pc_threshold($n);
  $from = $n > 1 ? pc_threshold($n - 1) : 0;
  $need = max(0, $thr - $c['total']);

  // concrete targets for the hint
  $pbTarget = null;                                   // the tried sentence with most room to grow
  foreach ($c['best'] as $p => $b) {
    if ($p < 1 || $b >= 9.5 || isset($c['mastered'][$p])) continue;   // never suggest a mastered sentence
    if ($pbTarget === null || $b < $pbTarget['best']) $pbTarget = ['phrase_idx'=>$p, 'best'=>round($b, 1)];
  }
  $mTarget = null;                                    // a sentence one good day away from mastery
  foreach ($c['high'] as $p => $days) {
    if ($p < 1 || isset($c['mastered'][$p]) || count($days) != PC_MASTERY_DAYS - 1) continue;
    $cand = ['phrase_idx'=>$p, 'today'=>isset($days[$today])];
    if ($mTarget === null || ($mTarget['today'] && !$cand['today'])) $mTarget = $cand;
  }
  $practiceToday = $c['practiceDay'][$today] ?? 0;
  $capped = $practiceToday >= PC_PRACTICE_CAP;

  $pending = null;
  foreach ($cards as $cd) if ($cd['scratched_at'] === null) { $pending = (int)$cd['card_no']; break; }

  // the single next action: the quickest way to more points right now
  if ($pending)                         $hint = ['kind'=>'scratch', 'card_no'=>$pending];
  elseif (!$c['valid'])                 $hint = ['kind'=>'start'];
  elseif ($mTarget && !$mTarget['today']) $hint = ['kind'=>'master', 'phrase_idx'=>$mTarget['phrase_idx'], 'bonus'=>PC_MASTERY_BONUS, 'capped'=>$capped];
  elseif ($pbTarget)                    $hint = ['kind'=>'pb', 'phrase_idx'=>$pbTarget['phrase_idx'], 'best'=>$pbTarget['best'], 'rate'=>PC_PB_RATE, 'capped'=>$capped];
  elseif ($capped)                      $hint = ['kind'=>'tomorrow'];
  else                                  $hint = ['kind'=>'new'];

  // what the scratched cards have unlocked
  // colour themes are retired (the app keeps the iGiver colours), so themes stay empty
  $cardsOut = []; $themes = []; $badges = []; $title = null; $stars = 0;
  foreach ($cards as $cd) {
    $rw = pc_card_view($pdo, $cd['reward_key'], (int)$cd['card_no']);
    $rw['scratched'] = $cd['scratched_at'] !== null;
    $cardsOut[] = $rw;
    if ($rw['scratched']) $badges[] = ['key'=>$rw['key'], 'icon'=>$rw['icon'], 'name'=>$rw['name'], 'image'=>$rw['image']];
  }

  return [
    'ok'=>true, 'name'=>$name,
    'points'=>$c['total'], 'practice'=>$c['practice'], 'improve'=>$c['improve'],
    'stats'=>['attempts'=>count($c['valid']), 'personal_bests'=>count($c['pbT']), 'mastered'=>count($c['mastered'])],
    'last'=>$last,
    'cards'=>$cardsOut, 'pending'=>$pending,
    'next'=>['no'=>$n, 'threshold'=>$thr, 'from'=>$from, 'need'=>$need],   // the gift itself stays a mystery
    'today'=>['practice'=>$practiceToday, 'cap'=>PC_PRACTICE_CAP, 'capped'=>$capped],
    'hint'=>$hint,
    'progress'=>pc_progress($c, $today),
    'pron_mastered'=>pc_pron_mastered($pdo, $sid),
    'unlocks'=>['themes'=>$themes, 'badges'=>$badges, 'title'=>$title, 'stars'=>$stars],
  ];
}

try {
  require __DIR__ . '/db.php';
  $pdo = pc_pdo();
  $PS = pc_points_settings($pdo);
  define('PC_CARD_STEPS', $PS['steps']);
  define('PC_STEP_AFTER', $PS['after']);
  define('PC_PRACTICE_CAP', $PS['cap']);
  define('PC_PB_RATE', $PS['pb_rate']);
  define('PC_MASTERY_BONUS', $PS['mastery_bonus']);

  $st = $pdo->prepare("SELECT id, name FROM students WHERE class_code=? AND roll_no=?");
  $st->execute([$code, $roll]);
  $stu = $st->fetch();
  $sid = $stu ? (int)$stu['id'] : null;
  $name = $stu ? (string)$stu['name'] : '';

  if ($action === 'scratch') {
    $no = (int)($d['card_no'] ?? 0);
    if (!$sid || $no < 1) { http_response_code(404); echo json_encode(['error'=>'no such card']); exit; }
    $chk = $pdo->prepare("SELECT card_no FROM rewards WHERE student_id=? AND card_no=?");
    $chk->execute([$sid, $no]);
    if (!$chk->fetch()) { http_response_code(404); echo json_encode(['error'=>'card not unlocked yet']); exit; }
    $pdo->prepare("UPDATE rewards SET scratched_at=NOW() WHERE student_id=? AND card_no=? AND scratched_at IS NULL")
        ->execute([$sid, $no]);
    $out = pc_status($pdo, $sid, $name);
    $k = $pdo->prepare("SELECT reward_key FROM rewards WHERE student_id=? AND card_no=?");
    $k->execute([$sid, $no]);
    $out['revealed'] = pc_card_view($pdo, $k->fetchColumn(), $no);
    echo json_encode($out);
    exit;
  }

  echo json_encode(pc_status($pdo, $sid, $name));
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server: ' . $e->getMessage()]);
}
