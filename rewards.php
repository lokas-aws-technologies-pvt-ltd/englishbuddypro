<?php
/**
 * Phrase Coach - scratch-card rewards (in-app rewards only).
 * ---------------------------------------------------------------------------
 * POST JSON  { class_code, roll_no, action: "status" | "scratch", card_no? }
 *
 * Points and card unlocks are worked out HERE, from the attempts log.php saved,
 * so they can't be changed from the phone. The app only displays the result.
 *
 * Points
 *   Practice  : each attempt earns its score rounded (under 3 earns 1).
 *               Per day only the best 2 tries of each sentence count, and at
 *               most 30 tries a day. Silent / too-short takes earn nothing.
 *   Improvement: beating your own best on a sentence earns 10 points per point
 *               gained (5.2 -> 6.8 = +16). Scoring 8+ on the same sentence on
 *               2 different days "masters" it: +20 once.
 *
 * Cards unlock at 100, 250, 450, 700, 1000 points, then every +350 - but only
 * if, since the previous card, the student practised on 2 different days AND
 * showed improvement (3 new personal bests, OR 1 newly mastered sentence, OR a
 * recent 10-attempt average at least 0.5 above the 10 before the last card).
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

/* ---------------- rules (tune these after a few weeks of real data) ---------------- */
const PC_CARD_STEPS      = [100, 250, 450, 700, 1000];  // points needed for cards 1..5
const PC_STEP_AFTER      = 350;                          // then every +350
const PC_BEST_PER_DAY    = 2;     // best N tries per sentence per day count
const PC_MAX_PER_DAY     = 30;    // at most this many counted tries a day
const PC_MIN_DURATION_S  = 1.0;   // shorter takes earn nothing
const PC_PB_RATE         = 10;    // improvement points per 1.0 of score gained
const PC_MASTERY_SCORE   = 8.0;
const PC_MASTERY_DAYS    = 2;
const PC_MASTERY_BONUS   = 20;
const PC_GATE_DAYS       = 2;     // practice days needed since the last card
const PC_GATE_PBS        = 3;     // ...and 3 personal bests (or another improvement)
const PC_GATE_WINDOW     = 10;    // attempts in each average for the "average rose" test
const PC_GATE_AVG_GAIN   = 0.5;

/* ---------------- the in-app reward behind each card ---------------- */
function pc_reward($n) {
  static $cat = [
    1 => ['key'=>'brave',      'kind'=>'badge', 'icon'=>'🏅', 'name'=>'Brave Beginner badge', 'desc'=>'For showing up and trying again.'],
    2 => ['key'=>'saffron',    'kind'=>'theme', 'icon'=>'🎨', 'name'=>'Saffron colour theme', 'desc'=>'A warm new colour for your app.'],
    3 => ['key'=>'smooth',     'kind'=>'badge', 'icon'=>'🌊', 'name'=>'Smooth Talker badge',  'desc'=>'Your reading is flowing better.'],
    4 => ['key'=>'ocean',      'kind'=>'theme', 'icon'=>'🎨', 'name'=>'Ocean colour theme',   'desc'=>'A calm blue look for your app.'],
    5 => ['key'=>'office',     'kind'=>'title', 'icon'=>'💼', 'name'=>'"Office Ready" title', 'desc'=>'Shown next to your name.'],
    6 => ['key'=>'rose',       'kind'=>'theme', 'icon'=>'🎨', 'name'=>'Rose colour theme',    'desc'=>'A bright new colour for your app.'],
    7 => ['key'=>'wordmaster', 'kind'=>'badge', 'icon'=>'📚', 'name'=>'Word Master badge',    'desc'=>'You have mastered tough office words.'],
  ];
  $r = $cat[$n] ?? ['key'=>'star', 'kind'=>'star', 'icon'=>'⭐', 'name'=>'Gold Star', 'desc'=>'Another step up. Keep going!'];
  $r['no'] = $n;
  return $r;
}

function pc_threshold($n) {
  $s = PC_CARD_STEPS; $k = count($s);
  return $n <= $k ? $s[$n-1] : $s[$k-1] + PC_STEP_AFTER * ($n - $k);
}
function pc_points_for($v) { return $v < 3 ? 1 : (int)round($v); }
function pc_valid($r) {
  return $r['overall'] !== null && ($r['duration_s'] === null || (float)$r['duration_s'] >= PC_MIN_DURATION_S);
}
function pc_avg($rows) {
  return $rows ? array_sum(array_map(function($r){ return (float)$r['overall']; }, $rows)) / count($rows) : null;
}

/* Work out all points from a student's attempts (chronological). Every step is a
   "keep the top N" choice, so adding an attempt can never lower the total. */
function pc_compute($rows) {
  $valid = array_values(array_filter($rows, 'pc_valid'));

  // practice: best 2 per sentence per day, then best 30 per day
  $byDay = [];
  foreach ($valid as $r) $byDay[substr($r['created_at'], 0, 10)][] = $r;
  $practice = 0;
  $desc = function($a, $b){ return (float)$b['overall'] <=> (float)$a['overall']; };
  foreach ($byDay as $list) {
    $byPh = [];
    foreach ($list as $r) $byPh[(int)$r['phrase_idx']][] = $r;
    $counted = [];
    foreach ($byPh as $pl) { usort($pl, $desc); foreach (array_slice($pl, 0, PC_BEST_PER_DAY) as $r) $counted[] = $r; }
    usort($counted, $desc);
    foreach (array_slice($counted, 0, PC_MAX_PER_DAY) as $r) $practice += pc_points_for((float)$r['overall']);
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
  return ['valid'=>$valid, 'practice'=>$practice, 'improve'=>$improve, 'total'=>$practice + $improve,
          'best'=>$best, 'high'=>$high, 'mastered'=>$mastered, 'pbT'=>$pbT, 'mastT'=>$mastT];
}

/* Has the student practised and improved since $since (the last card's unlock time)? */
function pc_gate($c, $since) {
  $days = []; $after = []; $before = [];
  foreach ($c['valid'] as $r) {
    if ($r['created_at'] > $since) { $days[substr($r['created_at'], 0, 10)] = true; $after[] = $r; }
    else $before[] = $r;
  }
  $pbs = 0;  foreach ($c['pbT']   as $t) if ($t > $since) $pbs++;
  $mast = 0; foreach ($c['mastT'] as $t) if ($t > $since) $mast++;

  // average rose: recent 10 vs the 10 before the last card (or vs the first 10 ever)
  $avgUp = false;
  if ($before) {
    $base = array_slice($before, -PC_GATE_WINDOW);
    $rec  = count($after) >= PC_GATE_WINDOW ? array_slice($after, -PC_GATE_WINDOW) : null;
  } else {
    $base = count($after) >= 2 * PC_GATE_WINDOW ? array_slice($after, 0, PC_GATE_WINDOW) : null;
    $rec  = $base ? array_slice($after, -PC_GATE_WINDOW) : null;
  }
  if ($base && $rec) $avgUp = pc_avg($rec) >= pc_avg($base) + PC_GATE_AVG_GAIN;

  return ['days'=>count($days), 'days_ok'=>count($days) >= PC_GATE_DAYS,
          'pbs'=>$pbs, 'mastered'=>$mast, 'avg_up'=>$avgUp,
          'improve_ok'=> $pbs >= PC_GATE_PBS || $mast >= 1 || $avgUp];
}

function pc_cards($pdo, $sid) {
  $st = $pdo->prepare("SELECT card_no, unlocked_at, scratched_at FROM rewards WHERE student_id=? ORDER BY card_no");
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
    $last = ['attempt_id'=>(int)$lr['id'], 'phrase_idx'=>(int)$lr['phrase_idx'],
             'practice'=>$c['practice'] - $prev['practice'],
             'pb_points'=>($c['improve'] - $prev['improve']) - $mastery, 'mastery_points'=>$mastery,
             'pb'=>count($c['pbT']) > count($prev['pbT']), 'mastered'=>$masteredNow];
  }

  // unlock the next card when its points AND its practice/improvement check are met
  $cards = $sid ? pc_cards($pdo, $sid) : [];
  if ($sid) {
    for ($guard = 0; $guard < 3; $guard++) {
      $n = count($cards) + 1;
      $since = $cards ? $cards[count($cards) - 1]['unlocked_at'] : '0000-00-00 00:00:00';
      $g = pc_gate($c, $since);
      if (!($c['total'] >= pc_threshold($n) && $g['days_ok'] && $g['improve_ok'])) break;
      $pdo->prepare("INSERT IGNORE INTO rewards (student_id, card_no, reward_key, unlocked_at) VALUES (?,?,?,NOW())")
          ->execute([$sid, $n, pc_reward($n)['key']]);
      $cards = pc_cards($pdo, $sid);
    }
  }

  // the next card and what still stands between the student and it
  $n = count($cards) + 1;
  $since = $cards ? $cards[count($cards) - 1]['unlocked_at'] : '0000-00-00 00:00:00';
  $g = pc_gate($c, $since);
  $thr = pc_threshold($n);
  $from = $n > 1 ? pc_threshold($n - 1) : 0;
  $need = max(0, $thr - $c['total']);

  // concrete targets for the hint
  $pbTarget = null;                                   // the tried sentence with most room to grow
  foreach ($c['best'] as $p => $b) {
    if ($p < 1 || $b >= 9.5) continue;
    if ($pbTarget === null || $b < $pbTarget['best']) $pbTarget = ['phrase_idx'=>$p, 'best'=>round($b, 1)];
  }
  $mTarget = null;                                    // a sentence one good day away from mastery
  foreach ($c['high'] as $p => $days) {
    if ($p < 1 || isset($c['mastered'][$p]) || count($days) != PC_MASTERY_DAYS - 1) continue;
    $cand = ['phrase_idx'=>$p, 'today'=>isset($days[$today])];
    if ($mTarget === null || ($mTarget['today'] && !$cand['today'])) $mTarget = $cand;
  }
  $recentAvg = pc_avg(array_slice($c['valid'], -PC_GATE_WINDOW));
  $perSentence = max(1, pc_points_for($recentAvg ?? 5));

  $pending = null;
  foreach ($cards as $cd) if ($cd['scratched_at'] === null) { $pending = (int)$cd['card_no']; break; }

  if ($pending)                     $hint = ['kind'=>'scratch', 'card_no'=>$pending];
  elseif (!$c['valid'])             $hint = ['kind'=>'start'];
  elseif (!$g['improve_ok'])        $hint = $pbTarget
                                      ? ['kind'=>'pb', 'need'=>PC_GATE_PBS - $g['pbs'], 'phrase_idx'=>$pbTarget['phrase_idx'], 'best'=>$pbTarget['best'], 'alt'=>$mTarget]
                                      : ($mTarget ? ['kind'=>'master'] + $mTarget : ['kind'=>'pb', 'need'=>PC_GATE_PBS - $g['pbs']]);
  elseif ($need > 0)                $hint = ['kind'=>'points', 'need'=>$need, 'sentences'=>(int)ceil($need / $perSentence)];
  elseif (!$g['days_ok'])           $hint = ['kind'=>'days', 'have'=>$g['days'], 'need'=>PC_GATE_DAYS];
  else                              $hint = ['kind'=>'ready'];

  // what the scratched cards have unlocked
  $cardsOut = []; $themes = []; $badges = []; $title = null; $stars = 0;
  foreach ($cards as $cd) {
    $rw = pc_reward((int)$cd['card_no']);
    $rw['scratched'] = $cd['scratched_at'] !== null;
    $cardsOut[] = $rw;
    if (!$rw['scratched']) continue;
    if ($rw['kind'] === 'theme') $themes[] = $rw['key'];
    elseif ($rw['kind'] === 'badge') $badges[] = ['key'=>$rw['key'], 'icon'=>$rw['icon'], 'name'=>preg_replace('/ badge$/', '', $rw['name'])];
    elseif ($rw['kind'] === 'title') $title = 'Office Ready';
    elseif ($rw['kind'] === 'star') $stars++;
  }

  return [
    'ok'=>true, 'name'=>$name,
    'points'=>$c['total'], 'practice'=>$c['practice'], 'improve'=>$c['improve'],
    'stats'=>['attempts'=>count($c['valid']), 'personal_bests'=>count($c['pbT']), 'mastered'=>count($c['mastered'])],
    'last'=>$last,
    'cards'=>$cardsOut, 'pending'=>$pending,
    'next'=>[
      'no'=>$n, 'reward'=>pc_reward($n), 'threshold'=>$thr, 'from'=>$from, 'need'=>$need, 'points_ok'=>$need === 0,
      'days'=>$g['days'], 'days_need'=>PC_GATE_DAYS, 'days_ok'=>$g['days_ok'],
      'pbs'=>$g['pbs'], 'pbs_need'=>PC_GATE_PBS, 'mastered_since'=>$g['mastered'], 'avg_up'=>$g['avg_up'],
      'improve_ok'=>$g['improve_ok'],
    ],
    'hint'=>$hint,
    'unlocks'=>['themes'=>$themes, 'badges'=>$badges, 'title'=>$title, 'stars'=>$stars],
  ];
}

try {
  require __DIR__ . '/db.php';
  $pdo = pc_pdo();

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
    $out['revealed'] = pc_reward($no);
    echo json_encode($out);
    exit;
  }

  echo json_encode(pc_status($pdo, $sid, $name));
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server: ' . $e->getMessage()]);
}
