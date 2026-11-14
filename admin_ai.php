<?php
/**
 * English Buddy - backend: AI usage and cost (admin only).
 * ---------------------------------------------------------------------------
 * Shows the paid AI pronunciation checks recorded by score_gemini.php /
 * score.php, what they cost, and sets the limits the server enforces before
 * every check: AI on/off, a monthly spending limit, and an optional number of
 * checks per student per day. When a limit is reached, students quietly get
 * their free phone score instead (no error).
 * Costs are estimates from the AI's own usage counts; your Google bill is the
 * final word.
 * ---------------------------------------------------------------------------
 */
require __DIR__ . '/admin_lib.php';
require __DIR__ . '/ai_lib.php';
$me  = pc_require_admin();
$pdo = pc_db();
const INR_PER_USD = 88;   // approximate, for a rough rupee figure only

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  pc_csrf_check();
  $limit = trim((string)($_POST['monthly_limit'] ?? ''));
  $daily = trim((string)($_POST['student_daily'] ?? ''));
  if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $limit)) pc_flash('Monthly limit: a dollar amount like 5 or 2.50 (0 = no limit).', 'err');
  elseif (!ctype_digit($daily) || (int)$daily > 500) pc_flash('Checks per student per day: a whole number from 0 to 500 (0 = no limit).', 'err');
  else {
    pc_set_setting($pdo, 'ai_enabled', !empty($_POST['enabled']) ? '1' : '0');
    pc_set_setting($pdo, 'ai_monthly_limit', (string)(float)$limit);
    pc_set_setting($pdo, 'ai_student_daily', (string)(int)$daily);
    pc_flash('AI settings saved. They apply from the next reading.');
  }
  pc_redirect('admin_ai.php');
}

$S = pc_ai_settings($pdo);
$m = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(ok),0) ok, COALESCE(SUM(cost_usd),0) cost, COUNT(DISTINCT class_code, roll_no) students
                  FROM ai_calls WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetch();
$today = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(cost_usd),0) cost FROM ai_calls WHERE created_at >= CURDATE()")->fetch();
$dayOfMonth = (int)date('j'); $daysInMonth = (int)date('t');
$monthCost = (float)$m['cost'];
$forecast = $dayOfMonth > 0 ? $monthCost / $dayOfMonth * $daysInMonth : 0;
$perCheck = (int)$m['ok'] > 0 ? $monthCost / (int)$m['ok'] : null;
$pct = $S['monthly_limit'] > 0 ? min(100, $monthCost / $S['monthly_limit'] * 100) : null;
$state = !$S['enabled'] ? ['Switched off', 'crit'] : (($S['monthly_limit'] > 0 && $monthCost >= $S['monthly_limit']) ? ['Paused: monthly limit reached', 'crit'] : ['Running', 'good']);

// last 30 days
$days = [];
for ($i = 29; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i days"))] = ['n' => 0, 'cost' => 0.0, 'fail' => 0];
foreach ($pdo->query("SELECT DATE(created_at) d, COUNT(*) n, SUM(cost_usd) cost, SUM(1-ok) fail FROM ai_calls
                      WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)") as $r)
  if (isset($days[$r['d']])) $days[$r['d']] = ['n' => (int)$r['n'], 'cost' => (float)$r['cost'], 'fail' => (int)$r['fail']];
$maxN = max(1, max(array_column($days, 'n')));

$byClass = $pdo->query("SELECT COALESCE(class_code,'(not signed in)') c, COUNT(*) n, SUM(cost_usd) cost, COUNT(DISTINCT roll_no) students
                        FROM ai_calls WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY class_code ORDER BY n DESC")->fetchAll();
$top = $pdo->query("SELECT a.class_code, a.roll_no, MAX(s.name) name, COUNT(*) n, SUM(a.cost_usd) cost
                    FROM ai_calls a LEFT JOIN students s ON s.class_code=a.class_code AND s.roll_no=a.roll_no
                    WHERE a.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND a.roll_no IS NOT NULL
                    GROUP BY a.class_code, a.roll_no ORDER BY n DESC LIMIT 10")->fetchAll();
$fails = $pdo->query("SELECT created_at, engine, class_code, roll_no, error FROM ai_calls WHERE ok=0 ORDER BY id DESC LIMIT 8")->fetchAll();

function usd($v) { $v = (float)$v; return '$' . ($v < 1 && $v > 0 ? number_format($v, 4) : number_format($v, 2)); }
function inr($v) { return '≈ ₹' . number_format((float)$v * INR_PER_USD, $v * INR_PER_USD < 10 ? 2 : 0); }

pc_page_start('AI usage', 'ai', $me);
?>
<style>
  .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-top:12px; }
  .kpi{ border:1px solid var(--line-soft); border-radius:var(--r-sm); background:var(--ground); padding:12px 13px; }
  .kpi .v{ font-weight:700; font-size:24px; line-height:1.1; }
  .kpi .l{ font-size:12px; color:var(--muted); margin-top:4px; }
  .meter{ height:12px; border-radius:7px; background:var(--surface-2); overflow:hidden; margin-top:12px; }
  .meter > i{ display:block; height:100%; border-radius:7px; background:var(--brand); }
  .meter > i.full{ background:var(--crit); }
  .state{ font-weight:700; } .state.good{ color:var(--good); } .state.crit{ color:var(--crit); }
  .chart{ display:grid; grid-template-columns:repeat(30,1fr); gap:3px; align-items:end; height:120px; border-bottom:1px solid var(--line); }
  .chart i{ display:block; background:var(--brand); border-radius:3px 3px 0 0; min-height:0; }
  .chart i.today{ background:var(--accent); }
  .chart .col{ height:100%; display:flex; align-items:flex-end; }
  .chart .col i{ width:100%; }
  .xlab{ display:flex; justify-content:space-between; font-family:var(--mono); font-size:10.5px; color:var(--dim); margin-top:5px; }
  .err-t{ font-family:var(--mono); font-size:12px; color:var(--crit); word-break:break-word; }
  @media (max-width:640px){ .kpis{ grid-template-columns:repeat(2,1fr); } }
</style>
<div class="stack">

  <div class="card">
    <div class="eyebrow"><span>This month · <?= h(date('F Y')) ?></span><span class="state <?= $state[1] ?>"><?= h($state[0]) ?></span></div>
    <div class="kpis">
      <div class="kpi"><div class="v"><?= usd($monthCost) ?></div><div class="l">spent <?= inr($monthCost) ?><?= $S['monthly_limit'] > 0 ? ' · limit ' . usd($S['monthly_limit']) : ' · no limit' ?></div></div>
      <div class="kpi"><div class="v num"><?= number_format((int)$m['ok']) ?></div><div class="l">AI checks<?= (int)$m['n'] - (int)$m['ok'] ? ' · ' . ((int)$m['n'] - (int)$m['ok']) . ' failed' : '' ?></div></div>
      <div class="kpi"><div class="v"><?= usd($forecast) ?></div><div class="l">forecast for the month</div></div>
      <div class="kpi"><div class="v"><?= $perCheck !== null ? usd($perCheck) : '–' ?></div><div class="l">average per check · <?= (int)$m['students'] ?> student<?= (int)$m['students'] === 1 ? '' : 's' ?></div></div>
    </div>
    <?php if ($pct !== null): ?>
      <div class="meter" role="progressbar" aria-valuenow="<?= (int)$pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Share of the monthly limit used"><i class="<?= $pct >= 100 ? 'full' : '' ?>" style="width:<?= max(1, (int)round($pct)) ?>%"></i></div>
      <p class="hint" style="margin-top:6px"><?= (int)round($pct) ?>% of the monthly limit used<?= $forecast > $S['monthly_limit'] && $pct < 100 ? ' · at this pace the limit is reached before month end, then students get phone scores until next month' : '' ?>. Today: <?= (int)$today['n'] ?> checks, <?= usd($today['cost']) ?>.</p>
    <?php else: ?>
      <p class="hint" style="margin-top:10px">No monthly limit set. Today: <?= (int)$today['n'] ?> checks, <?= usd($today['cost']) ?>.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="section-t">Limits (checked by the server before every AI check)</div>
    <form method="post" class="row">
      <?= pc_csrf_field() ?>
      <div class="f s" style="flex-basis:170px"><label class="fl" for="enabled">AI pronunciation checks</label>
        <select id="enabled" name="enabled"><option value="1"<?= $S['enabled'] ? ' selected' : '' ?>>On</option><option value=""<?= $S['enabled'] ? '' : ' selected' ?>>Off (phone scoring only)</option></select></div>
      <div class="f s" style="flex-basis:170px"><label class="fl" for="ml">Monthly limit (USD)</label><input type="text" id="ml" name="monthly_limit" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format($S['monthly_limit'], 2, '.', ''), '0'), '.')) ?>"></div>
      <div class="f s" style="flex-basis:200px"><label class="fl" for="sd">Checks per student per day</label><input type="text" id="sd" name="student_daily" inputmode="numeric" value="<?= (int)$S['student_daily'] ?>"></div>
      <div><button class="btn primary" type="submit">Save</button></div>
    </form>
    <p class="hint" style="margin-top:8px">0 means no limit. When a limit is reached, students still practise and get their free phone score, with a short "pronunciation check is resting" note and no error. The app already skips AI on halting readings (below 7) and on sentences whose pronunciation is mastered.</p>
  </div>

  <div class="card">
    <div class="section-t">AI checks per day · last 30 days</div>
    <div class="chart">
      <?php $last = array_key_last($days); foreach ($days as $d => $v): ?>
        <div class="col" title="<?= h(date('D j M', strtotime($d)) . ': ' . $v['n'] . ' checks · ' . usd($v['cost']) . ($v['fail'] ? ' · ' . $v['fail'] . ' failed' : '')) ?>"><i class="<?= $d === $last ? 'today' : '' ?>" style="height:<?= $v['n'] ? max(3, round($v['n'] / $maxN * 100)) : 0 ?>%"></i></div>
      <?php endforeach; ?>
    </div>
    <div class="xlab"><span><?= h(date('j M', strtotime(array_key_first($days)))) ?></span><span>most: <?= $maxN ?> checks/day</span><span>today</span></div>
  </div>

  <div class="card">
    <div class="section-t">This month by class</div>
    <?php if (!$byClass): ?><p class="hint">No AI checks this month yet.</p><?php else: ?>
    <table class="list"><thead><tr><th>Class</th><th class="num">Checks</th><th class="num">Students</th><th class="num">Cost</th></tr></thead><tbody>
      <?php foreach ($byClass as $c): ?><tr><td><?= h($c['c']) ?></td><td class="num"><?= (int)$c['n'] ?></td><td class="num"><?= (int)$c['students'] ?></td><td class="num"><?= usd($c['cost']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <?php if ($top): ?>
  <div class="card">
    <div class="section-t">Most AI checks this month</div>
    <table class="list"><thead><tr><th>Student</th><th>Class</th><th class="num">Checks</th><th class="num">Cost</th></tr></thead><tbody>
      <?php foreach ($top as $t): ?><tr><td><b><?= h($t['name'] ?: 'Roll ' . $t['roll_no']) ?></b><div class="sub">#<?= h($t['roll_no']) ?></div></td><td><?= h($t['class_code']) ?></td><td class="num"><?= (int)$t['n'] ?></td><td class="num"><?= usd($t['cost']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>

  <?php if ($fails): ?>
  <div class="card">
    <div class="section-t">Recent failed checks</div>
    <table class="list"><thead><tr><th>When</th><th>Student</th><th>What went wrong</th></tr></thead><tbody>
      <?php foreach ($fails as $f): ?><tr><td class="num"><?= h(date('j M, g:i a', strtotime($f['created_at']))) ?></td><td><?= h(($f['class_code'] ?: '–') . ' #' . ($f['roll_no'] ?: '–')) ?></td><td class="err-t"><?= h($f['error']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="hint" style="margin-top:8px">Students still got their phone score. Repeated "missing key" or "HTTP 4xx" errors usually mean the Gemini key in config.php needs attention.</p>
  </div>
  <?php endif; ?>

  <p class="hint">Costs are estimates from Google's own usage counts at $0.30 (text) / $0.50 (audio) input and $2.50 output per million tokens. Your Google Cloud bill is the final figure. Rupee amounts use about ₹<?= INR_PER_USD ?> per dollar.</p>
</div>
<?php pc_page_end();
