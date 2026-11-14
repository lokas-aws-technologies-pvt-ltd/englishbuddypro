<?php
/**
 * English Buddy - backend Overview (class progress).
 * ---------------------------------------------------------------------------
 * Reads the attempts logged by log.php and shows progress: who is practising,
 * how confidence is trending, the words the class struggles with, and the
 * toughest phrases. Admins see every class; a teacher sees only their classes.
 * Sign in at admin.php.
 * ---------------------------------------------------------------------------
 */
require __DIR__ . '/admin_lib.php';
$me = pc_require_login();

/* =========================================================================
   Gather the data (limited to the classes this user may see)
   ========================================================================= */

// Phrase text by 1-based index. MUST stay in the same order as index.html's
// LIBRARY (and generate_audio.php). Used only to label the "toughest phrases".
$PHRASES = [];   // sentence text by permanent number, filled from the database below

function ago($ts){
  if(!$ts) return "never";
  $s = time() - strtotime($ts);
  if($s < 60)    return "just now";
  if($s < 3600)  return floor($s/60)."m ago";
  if($s < 86400) return floor($s/3600)."h ago";
  $d = floor($s/86400);
  if($d < 7)     return $d."d ago";
  return date("M j", strtotime($ts));
}

$dberr = '';
$data = [
  'generatedAt' => date('M j, Y · g:i a'),
  'kpis' => ['students'=>0,'attempts'=>0,'attemptsToday'=>0,'activeWeek'=>0,'avgAll'=>null,'avgWeek'=>null,'avgPrevWeek'=>null],
  'trend' => [], 'roster' => [], 'words' => [], 'phrases' => [], 'classCodes' => [],
];

try {
  $pdo = pc_db();
  foreach (pc_sentences($pdo) as $sid => $sr) $PHRASES[$sid] = $sr['text'] . ((int)$sr['active'] === 1 ? '' : ' (retired)');

  [$cw, $cp] = pc_class_where($me, 's');
  $classOptions = pc_class_options($me);
  $pick = trim((string)($_GET['class'] ?? ''));
  if ($pick !== '' && pc_class_ok($me, $pick)) { $cw .= ' AND s.class_code = ?'; $cp[] = $pick; } else { $pick = ''; }
  $st = $pdo->prepare("SELECT s.id, s.class_code, s.roll_no, s.name FROM students s WHERE $cw");
  $st->execute($cp);
  $students = $st->fetchAll();
  $st = $pdo->prepare(
    "SELECT a.student_id, a.overall, a.phrase_idx, a.filler_count, a.mispronounced, a.skipped, a.created_at
     FROM attempts a JOIN students s ON s.id = a.student_id
     WHERE $cw ORDER BY a.created_at DESC LIMIT 8000");
  $st->execute($cp);
  $rows = $st->fetchAll();
  $rows = array_reverse($rows); // chronological

  $today   = date('Y-m-d');
  $weekAgo = date('Y-m-d', strtotime('-6 days'));
  $prevLo  = date('Y-m-d', strtotime('-13 days'));
  $prevHi  = date('Y-m-d', strtotime('-7 days'));

  // --- per-student grouping ---
  $byStu = [];
  foreach ($students as $s) {
    $byStu[$s['id']] = ['s'=>$s, 'rows'=>[], 'dates'=>[]];
  }
  $classCodes = [];

  // --- accumulators ---
  $sumAll=0; $nAll=0; $sumWeek=0; $nWeek=0; $sumPrev=0; $nPrev=0;
  $attemptsToday=0; $activeWeek=[]; $wordTally=[]; $phraseAgg=[];
  $trendMap=[]; // Y-m-d => [n, sum]
  for($i=13;$i>=0;$i--){ $d=date('Y-m-d', strtotime("-$i days")); $trendMap[$d]=['n'=>0,'sum'=>0,'cnt'=>0]; }

  foreach ($rows as $r) {
    $sid=$r['student_id']; $ov=$r['overall']; $day=substr($r['created_at'],0,10);
    if(isset($byStu[$sid])){ $byStu[$sid]['rows'][]=$r; $byStu[$sid]['dates'][$day]=true; }

    if($ov!==null){ $sumAll+=$ov; $nAll++; }
    if($day>=$weekAgo){ if($ov!==null){ $sumWeek+=$ov; $nWeek++; } $activeWeek[$sid]=true; }
    if($day>=$prevLo && $day<=$prevHi && $ov!==null){ $sumPrev+=$ov; $nPrev++; }
    if($day===$today) $attemptsToday++;

    if(isset($trendMap[$day])){ $trendMap[$day]['n']++; if($ov!==null){ $trendMap[$day]['sum']+=$ov; $trendMap[$day]['cnt']++; } }

    // weak/focus words from the stored JSON arrays
    foreach (['mispronounced','skipped'] as $col) {
      if(!empty($r[$col])){
        $arr=json_decode($r[$col], true);
        if(is_array($arr)) foreach($arr as $w){ $w=strtolower(trim((string)$w)); if($w!==''){ $wordTally[$w]=($wordTally[$w]??0)+1; } }
      }
    }
    // phrase difficulty
    $pi=(int)$r['phrase_idx'];
    if($pi>0){ if(!isset($phraseAgg[$pi])) $phraseAgg[$pi]=['sum'=>0,'cnt'=>0,'n'=>0]; $phraseAgg[$pi]['n']++; if($ov!==null){ $phraseAgg[$pi]['sum']+=$ov; $phraseAgg[$pi]['cnt']++; } }
  }

  // --- KPIs ---
  $data['kpis']['students']      = count($students);
  $data['kpis']['attempts']      = $nAll;                 // scored attempts
  $data['kpis']['attemptsToday'] = $attemptsToday;
  $data['kpis']['activeWeek']    = count($activeWeek);
  $data['kpis']['avgAll']        = $nAll  ? round($sumAll/$nAll,1)  : null;
  $data['kpis']['avgWeek']       = $nWeek ? round($sumWeek/$nWeek,1): null;
  $data['kpis']['avgPrevWeek']   = $nPrev ? round($sumPrev/$nPrev,1): null;

  // --- trend series (last 14 days) ---
  foreach ($trendMap as $d=>$v) {
    $data['trend'][] = ['d'=>date('M j', strtotime($d)),
                        'n'=>$v['n'],
                        'avg'=>$v['cnt']? round($v['sum']/$v['cnt'],2) : null];
  }

  // --- roster ---
  foreach ($byStu as $sid=>$g) {
    $s=$g['s']; $rs=$g['rows'];
    $cnt=count($rs);
    $sum=0; $c=0; $recent=[]; $lastAt=null;
    foreach($rs as $r){ if($r['overall']!==null){ $sum+=$r['overall']; $c++; } }
    // last up-to-12 scores for the sparkline
    $tail=array_slice($rs, -12);
    foreach($tail as $r){ if($r['overall']!==null) $recent[]=(float)$r['overall']; }
    if($cnt){ $lastAt=$rs[$cnt-1]['created_at']; }
    // streak: consecutive days with practice, counting back from today/yesterday
    $streak=0; $dts=array_keys($g['dates']); rsort($dts);
    if($dts){
      $cursor = ($dts[0]===$today) ? $today : (($dts[0]===date('Y-m-d',strtotime('-1 day'))) ? $dts[0] : null);
      if($cursor!==null){
        $set=array_flip($dts); $probe=$cursor;
        while(isset($set[$probe])){ $streak++; $probe=date('Y-m-d', strtotime($probe.' -1 day')); }
      }
    }
    if($s['class_code']!=='') $classCodes[$s['class_code']]=true;
    $data['roster'][] = [
      'name'=> $s['name']!=='' ? $s['name'] : ('Roll '.$s['roll_no']),
      'code'=> $s['class_code'], 'roll'=> $s['roll_no'],
      'attempts'=> $cnt,
      'avg'=> $c ? round($sum/$c,1) : null,
      'streak'=> $streak,
      'lastAt'=> $lastAt ? ago($lastAt) : 'never',
      'lastTs'=> $lastAt ? strtotime($lastAt) : 0,
      'recent'=> $recent,
    ];
  }

  // --- focus words (top 12) ---
  arsort($wordTally);
  foreach (array_slice($wordTally, 0, 12, true) as $w=>$n) { $data['words'][]=['w'=>$w,'n'=>$n]; }

  // --- toughest phrases (lowest avg, min 2 attempts, up to 8) ---
  $ph=[];
  foreach ($phraseAgg as $pi=>$v) {
    if($v['cnt']<2) continue;
    $ph[]=['idx'=>$pi, 'text'=> ($PHRASES[$pi] ?? ('Sentence #'.$pi)), 'n'=>$v['n'], 'avg'=>round($v['sum']/$v['cnt'],1)];
  }
  usort($ph, fn($a,$b)=> $a['avg'] <=> $b['avg']);
  $data['phrases'] = array_slice($ph, 0, 8);

  $data['classCodes'] = array_keys($classCodes);

} catch (Throwable $e) {
  $dberr = $e->getMessage();
}

$classOptions = $classOptions ?? [];
pc_page_start('Overview', 'overview', $me);
?>
<style>
  /* hero + KPI tiles */
  .heroRow{ display:flex; gap:18px; align-items:stretch; flex-wrap:wrap; }
  .hero{ flex:1 1 190px; display:flex; flex-direction:column; justify-content:center; }
  .hero .hlabel{ font-size:12.5px; color:var(--muted); }
  .hero .hval{ font-family:var(--ui); font-weight:600; font-size:52px; line-height:1; letter-spacing:-.02em; margin:4px 0 2px; }
  .hero .hden{ font-family:var(--mono); font-size:13px; color:var(--muted); }
  .hero .delta{ font-family:var(--mono); font-size:12.5px; margin-top:8px; display:inline-flex; align-items:center; gap:6px; }
  .delta.up{ color:var(--good); } .delta.down{ color:var(--crit); } .delta.flat{ color:var(--muted); }
  .tiles{ flex:2 1 320px; display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
  .tile{ border:1px solid var(--line-soft); border-radius:var(--r-sm); background:var(--ground); padding:12px 13px; }
  .tile .tval{ font-family:var(--ui); font-weight:600; font-size:26px; line-height:1; }
  .tile .tlabel{ font-size:11.5px; color:var(--muted); margin-top:5px; }

  /* charts */
  .chart-card svg{ display:block; width:100%; height:auto; overflow:visible; }
  #dash .card > svg{ display:block; width:100%; max-width:100%; height:auto; overflow:visible; }   /* charts shrink to the phone width */
  .axis{ stroke:var(--line); stroke-width:1; }
  .grid{ stroke:var(--line-soft); stroke-width:1; }
  .axtick{ font-family:var(--mono); font-size:9px; fill:var(--dim); }
  .axlab{ font-family:var(--mono); font-size:9px; fill:var(--dim); }
  .lineseries{ fill:none; stroke:var(--accent); stroke-width:2; stroke-linejoin:round; stroke-linecap:round; }
  .dot{ fill:var(--accent); stroke:var(--surface); stroke-width:2; }
  .col{ fill:var(--accent); }
  .endlab{ font-family:var(--ui); font-weight:600; font-size:11px; fill:var(--ink); }

  /* roster */
  .toolbar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
  .toolbar select{ padding:8px 10px; border-radius:var(--r-sm); border:1px solid var(--line); background:var(--ground); color:var(--ink); font-family:var(--mono); font-size:12px; }
  table.roster{ width:100%; border-collapse:collapse; font-size:13.5px; }
  table.roster th{ text-align:left; font-family:var(--mono); font-size:9.5px; letter-spacing:.08em; text-transform:uppercase; color:var(--dim); font-weight:500; padding:0 8px 9px; border-bottom:1px solid var(--line); white-space:nowrap; }
  table.roster th.num, table.roster td.num{ text-align:right; }
  table.roster td{ padding:11px 8px; border-bottom:1px solid var(--line-soft); vertical-align:middle; }
  table.roster tr:last-child td{ border-bottom:0; }
  .who .nm{ font-weight:600; color:var(--ink); }
  .who .sub{ font-family:var(--mono); font-size:10.5px; color:var(--dim); margin-top:2px; }
  .scorepill{ font-family:var(--mono); font-weight:500; font-variant-numeric:tabular-nums; padding:3px 8px; border-radius:999px; font-size:12.5px; display:inline-block; min-width:34px; text-align:center; }
  .sc-good{ color:var(--good); background:var(--good-soft); } .sc-warn{ color:var(--warn); background:var(--warn-soft); } .sc-crit{ color:var(--crit); background:var(--crit-soft); } .sc-none{ color:var(--dim); background:var(--surface-2); }
  .streak{ font-family:var(--mono); font-variant-numeric:tabular-nums; color:var(--muted); white-space:nowrap; }
  .streak b{ color:var(--ink); }
  .last{ font-family:var(--mono); font-size:11.5px; color:var(--muted); white-space:nowrap; }
  .spark{ display:block; width:96px; height:26px; }
  .num{ font-variant-numeric:tabular-nums; }

  /* word + phrase bars */
  .bars{ display:grid; gap:9px; }
  .barrow{ display:grid; grid-template-columns: 120px 1fr auto; align-items:center; gap:10px; }
  .barrow .k{ font-size:13px; color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .barrow .k.wide{ }
  .track{ height:9px; border-radius:5px; background:var(--surface-2); overflow:hidden; }
  .track > i{ display:block; height:100%; border-radius:5px; background:var(--accent); }
  .track > i.good{ background:var(--good); } .track > i.warn{ background:var(--warn); } .track > i.crit{ background:var(--crit); }
  .barrow .v{ font-family:var(--mono); font-variant-numeric:tabular-nums; font-size:12px; color:var(--muted); min-width:30px; text-align:right; }
  .phraserow{ display:grid; grid-template-columns: 1fr auto; gap:4px 12px; align-items:baseline; padding:10px 0; border-bottom:1px solid var(--line-soft); }
  .phraserow:last-child{ border-bottom:0; }
  .phraserow .pt{ font-family:var(--display); font-size:15px; color:var(--ink); }
  .phraserow .pm{ display:flex; align-items:center; gap:10px; }
  .phraserow .track{ width:90px; }

  .empty{ text-align:center; color:var(--muted); padding:26px 10px; }
  .empty h3{ font-family:var(--display); font-weight:600; font-size:19px; color:var(--ink); margin-bottom:8px; }
  .hint{ font-size:12px; color:var(--dim); }
  .footnote{ font-size:11.5px; color:var(--dim); text-align:center; margin-top:24px; line-height:1.6; }
  .tt{ position:fixed; z-index:50; pointer-events:none; background:var(--ink); color:var(--ground); font-family:var(--mono); font-size:11px; padding:6px 8px; border-radius:6px; opacity:0; transition:opacity .1s; white-space:nowrap; }

  @media (max-width:560px){
    .tiles{ grid-template-columns:repeat(2,1fr); }
    table.roster .colWpm, table.roster .hideS{ display:none; }
    .barrow{ grid-template-columns: 96px 1fr auto; }
  }
</style>
<form class="row" method="get" style="margin:-4px 0 14px">
  <div class="f" style="flex:0 1 240px">
    <label class="fl" for="cls">Class</label>
    <select id="cls" name="class" onchange="this.form.submit()">
      <option value=""><?= pc_is_admin($me) ? 'All classes' : 'All my classes' ?></option>
      <?php foreach ($classOptions as $c): ?><option value="<?= h($c) ?>"<?= $c === ($pick ?? '') ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="btn" type="submit">Show</button></noscript>
</form>

<?php if ($dberr): ?>
  <div class="card"><div class="empty">
    <h3>Can't read the database</h3>
    <p class="hint"><?= htmlspecialchars($dberr) ?></p>
    <p class="hint">Check the MySQL settings in <code>config.php</code> (see <code>DEPLOY.md</code>).</p>
  </div></div>
<?php elseif ($data['kpis']['attempts'] === 0): ?>
  <div class="card"><div class="empty">
    <h3><?= (!pc_is_admin($me) && !$me['classes']) ? 'No classes assigned yet' : 'No practice logged yet' ?></h3>
    <p class="hint">Once students sign in and score a reading, their attempts appear here — confidence trend, streaks, focus words and the toughest phrases.</p>
  </div></div>
<?php else: ?>
  <div id="dash" class="stack"></div>
<?php endif; ?>

  <p class="hint" style="text-align:center;margin-top:18px">Generated <?= h($data['generatedAt']) ?>.</p>

<div class="tt" id="tt"></div>

<script>
window.PC_DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script>
/* ============ English Buddy teacher dashboard - client render ============ */
(function(){
  "use strict";
  var D = window.PC_DATA || {};
  var $ = function(s,r){ return (r||document).querySelector(s); };

  var dash=$("#dash");
  if(!dash) return;   // empty / error state rendered server-side

  /* helpers */
  function el(tag,cls,html){ var e=document.createElement(tag); if(cls)e.className=cls; if(html!=null)e.innerHTML=html; return e; }
  function band(v){ return v==null?"none":(v>=7?"good":(v>=4?"warn":"crit")); }
  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c];}); }
  var NS="http://www.w3.org/2000/svg";
  function svg(w,h){ var s=document.createElementNS(NS,"svg"); s.setAttribute("viewBox","0 0 "+w+" "+h); s.setAttribute("width",w); s.setAttribute("height",h); return s; }
  function sadd(p,tag,attrs){ var e=document.createElementNS(NS,tag); for(var k in attrs) e.setAttribute(k,attrs[k]); p.appendChild(e); return e; }

  /* shared tooltip for SVG marks */
  var tt=$("#tt");
  function bindTip(node,text){
    node.style.cursor="default";
    node.addEventListener("mousemove",function(ev){ tt.textContent=text; tt.style.opacity="1"; tt.style.left=(ev.clientX+12)+"px"; tt.style.top=(ev.clientY-10)+"px"; });
    node.addEventListener("mouseleave",function(){ tt.style.opacity="0"; });
    var ti=document.createElementNS(NS,"title"); ti.textContent=text; node.appendChild(ti);
  }

  function card(title, extraRight){
    var c=el("section","card");
    if(title!=null){ var e=el("div","eyebrow"); e.appendChild(el("span",null,title)); if(extraRight) e.appendChild(extraRight); c.appendChild(e); c.appendChild(el("div",null,"")).style.height="12px"; }
    return c;
  }

  /* ---------------- KPI + hero ---------------- */
  (function(){
    var k=D.kpis||{};
    var c=el("section","card");
    var row=el("div","heroRow"); c.appendChild(row);

    var hero=el("div","hero");
    hero.appendChild(el("div","hlabel","Class confidence · this week"));
    var hv=el("div","hval", k.avgWeek!=null? k.avgWeek.toFixed(1) : (k.avgAll!=null?k.avgAll.toFixed(1):"–"));
    hv.style.color="var(--"+(band(k.avgWeek!=null?k.avgWeek:k.avgAll)==="none"?"ink":band(k.avgWeek!=null?k.avgWeek:k.avgAll))+")";
    hero.appendChild(hv);
    hero.appendChild(el("div","hden","out of 10"));
    if(k.avgWeek!=null && k.avgPrevWeek!=null){
      var d=Math.round((k.avgWeek-k.avgPrevWeek)*10)/10;
      var dir=d>0.05?"up":(d<-0.05?"down":"flat");
      var arrow=dir==="up"?"▲":(dir==="down"?"▼":"■");
      hero.appendChild(el("div","delta "+dir, arrow+" "+(d>0?"+":"")+d.toFixed(1)+" vs last week"));
    } else {
      hero.appendChild(el("div","delta flat","— no prior week yet"));
    }
    row.appendChild(hero);

    var tiles=el("div","tiles");
    function tile(v,l){ var t=el("div","tile"); t.appendChild(el("div","tval",v)); t.appendChild(el("div","tlabel",l)); return t; }
    tiles.appendChild(tile(k.activeWeek+" / "+k.students, "active this week"));
    tiles.appendChild(tile(k.attemptsToday, "attempts today"));
    tiles.appendChild(tile(k.attempts.toLocaleString(), "attempts logged"));
    tiles.appendChild(tile(k.avgAll!=null?k.avgAll.toFixed(1):"–", "all-time average"));
    row.appendChild(tiles);
    dash.appendChild(c);
  })();

  /* ---------------- trend: avg confidence (line) ---------------- */
  (function(){
    var tr=D.trend||[]; if(!tr.length) return;
    var c=card("Confidence trend · last 14 days");
    var W=680,H=180, padL=26,padR=34,padT=14,padB=22;
    var s=svg(W,H); c.appendChild(s);
    var x0=padL, x1=W-padR, y0=H-padB, y1=padT;
    var xat=function(i){ return tr.length<2? (x0+x1)/2 : x0+(x1-x0)*i/(tr.length-1); };
    var yat=function(v){ return y0+(y1-y0)*(v/10); };
    // gridlines + y ticks at 0,5,10
    [0,5,10].forEach(function(g){ var y=yat(g); sadd(s,"line",{x1:x0,y1:y,x2:x1,y2:y,class:g===0?"axis":"grid"});
      var t=sadd(s,"text",{x:x0-6,y:y+3,class:"axtick","text-anchor":"end"}); t.textContent=g; });
    // x labels (first, mid, last)
    [0, Math.floor((tr.length-1)/2), tr.length-1].forEach(function(i){
      var t=sadd(s,"text",{x:xat(i),y:H-6,class:"axlab","text-anchor":"middle"}); t.textContent=tr[i].d; });
    // polyline across days that have an average (skip empty days, connect the rest)
    var pts=[]; tr.forEach(function(p,i){ if(p.avg!=null) pts.push([xat(i),yat(p.avg),i,p]); });
    if(pts.length>1){ var d="M"+pts.map(function(p){return p[0]+","+p[1];}).join(" L"); sadd(s,"path",{d:d,class:"lineseries"}); }
    pts.forEach(function(p,idx){
      var dot=sadd(s,"circle",{cx:p[0],cy:p[1],r:4,class:"dot"});
      bindTip(dot, tr[p[2]].d+": "+p[3].avg.toFixed(1)+" avg · "+p[3].n+" attempt"+(p[3].n===1?"":"s"));
      if(idx===pts.length-1){ var t=sadd(s,"text",{x:p[0]+7,y:p[1]-6,class:"endlab","text-anchor":p[0]>W-70?"end":"start"}); t.textContent=p[3].avg.toFixed(1); }
    });
    if(pts.length<=1){ c.appendChild(el("p","hint","Not enough days with practice yet to draw a trend.")); }
    dash.appendChild(c);
  })();

  /* ---------------- trend: attempts per day (columns) ---------------- */
  (function(){
    var tr=D.trend||[]; if(!tr.length) return;
    var maxN=Math.max(1, Math.max.apply(null, tr.map(function(p){return p.n;})));
    var c=card("Practice volume · last 14 days");
    var W=680,H=150, padL=26,padR=10,padT=14,padB=22;
    var s=svg(W,H); c.appendChild(s);
    var x0=padL,x1=W-padR,y0=H-padB,y1=padT;
    var band=(x1-x0)/tr.length, bw=Math.min(24, band-6), gap=2;
    // y grid at 0 and max
    [0,maxN].forEach(function(g){ var y=y0+(y1-y0)*(g/maxN); sadd(s,"line",{x1:x0,y1:y,x2:x1,y2:y,class:g===0?"axis":"grid"});
      var t=sadd(s,"text",{x:x0-6,y:y+3,class:"axtick","text-anchor":"end"}); t.textContent=g; });
    tr.forEach(function(p,i){
      var cx=x0+band*i+band/2, h=(y0-y1)*(p.n/maxN);
      if(p.n>0){
        var r=sadd(s,"rect",{x:cx-bw/2,y:y0-h,width:bw,height:Math.max(2,h),rx:3,class:"col"});
        bindTip(r, tr[i].d+": "+p.n+" attempt"+(p.n===1?"":"s"));
      }
    });
    [0, Math.floor((tr.length-1)/2), tr.length-1].forEach(function(i){
      var cx=x0+band*i+band/2; var t=sadd(s,"text",{x:cx,y:H-6,class:"axlab","text-anchor":"middle"}); t.textContent=tr[i].d; });
    dash.appendChild(c);
  })();

  /* ---------------- roster ---------------- */
  (function(){
    var ro=(D.roster||[]).slice(); if(!ro.length) return;
    var sortSel=el("select");
    [["recent","Most recent"],["avg","Highest average"],["avglow","Lowest average"],["attempts","Most attempts"],["streak","Longest streak"],["name","Name (A–Z)"]]
      .forEach(function(o){ var op=el("option",null,o[1]); op.value=o[0]; sortSel.appendChild(op); });

    var c=card("Students");
    var bar=el("div","toolbar");
    bar.appendChild(el("span","hint","Sort")); bar.appendChild(sortSel);
    c.appendChild(bar);
    var host=el("div"); c.appendChild(host);

    function sparkline(recent){
      var w=96,h=26,pad=3; var s=svg(w,h); s.setAttribute("class","spark");
      if(!recent||!recent.length){ return s; }
      var n=recent.length, x=function(i){ return n<2? w/2 : pad+(w-2*pad)*i/(n-1); }, y=function(v){ return h-pad-(h-2*pad)*(v/10); };
      if(n>1){ var d="M"+recent.map(function(v,i){return x(i)+","+y(v);}).join(" L"); sadd(s,"path",{d:d,fill:"none",stroke:"var(--dim)","stroke-width":1.5,"stroke-linejoin":"round","stroke-linecap":"round"}); }
      var last=recent[n-1], b=band(last);
      sadd(s,"circle",{cx:x(n-1),cy:y(last),r:3.2,fill:"var(--"+b+")",stroke:"var(--surface)","stroke-width":1.5});
      return s;
    }
    function render(){
      var key=sortSel.value;
      ro.sort(function(a,b){
        if(key==="name") return String(a.name).localeCompare(String(b.name));
        if(key==="attempts") return b.attempts-a.attempts;
        if(key==="streak") return b.streak-a.streak || b.lastTs-a.lastTs;
        if(key==="avg") return (b.avg==null?-1:b.avg)-(a.avg==null?-1:a.avg);
        if(key==="avglow") return (a.avg==null?99:a.avg)-(b.avg==null?99:b.avg);
        return b.lastTs-a.lastTs; // recent
      });
      host.innerHTML="";
      var tbl=el("table","roster");
      tbl.innerHTML="<thead><tr><th>Student</th><th class='num'>Att.</th><th class='num'>Avg</th><th class='hideS'>Streak</th><th class='hideS'>Last</th><th>Recent</th></tr></thead>";
      var tb=el("tbody");
      ro.forEach(function(r){
        var tr=el("tr");
        var who=el("td","who"); who.innerHTML="<div class='nm'>"+esc(r.name)+"</div><div class='sub'>"+(r.code?esc(r.code)+" · ":"")+"#"+esc(r.roll)+"</div>"; tr.appendChild(who);
        tr.appendChild(el("td","num", r.attempts));
        var av=el("td","num"); var b=band(r.avg); av.innerHTML="<span class='scorepill sc-"+b+"'>"+(r.avg!=null?r.avg.toFixed(1):"–")+"</span>"; tr.appendChild(av);
        tr.appendChild(el("td","hideS streak", r.streak>0? ("<b>"+r.streak+"</b>d 🔥") : "—"));
        tr.appendChild(el("td","hideS last", r.lastAt));
        var sp=el("td"); sp.appendChild(sparkline(r.recent)); tr.appendChild(sp);
        tb.appendChild(tr);
      });
      tbl.appendChild(tb); host.appendChild(tbl);
    }
    sortSel.onchange=render; render();
    dash.appendChild(c);
  })();

  /* ---------------- focus words ---------------- */
  (function(){
    var ws=D.words||[]; if(!ws.length) return;
    var c=card("Focus words · most-missed across the class");
    var max=Math.max.apply(null, ws.map(function(w){return w.n;}));
    var bars=el("div","bars");
    ws.forEach(function(w){
      var row=el("div","barrow");
      row.appendChild(el("div","k",esc(w.w)));
      var tk=el("div","track"); var fill=el("i"); fill.style.width=Math.max(4,(w.n/max*100))+"%"; tk.appendChild(fill); row.appendChild(tk);
      row.appendChild(el("div","v", w.n));
      bars.appendChild(row);
    });
    c.appendChild(bars);
    c.appendChild(el("p","hint","Words flagged as mispronounced or skipped. These are the natural targets for focused drills.")).style.marginTop="12px";
    dash.appendChild(c);
  })();

  /* ---------------- toughest phrases ---------------- */
  (function(){
    var ps=D.phrases||[]; if(!ps.length) return;
    var c=card("Toughest phrases · lowest average score");
    ps.forEach(function(p){
      var row=el("div","phraserow");
      row.appendChild(el("div","pt", esc(p.text)));
      var pm=el("div","pm");
      var tk=el("div","track"); var fill=el("i"); fill.className=band(p.avg); fill.style.width=Math.max(4,(p.avg/10*100))+"%"; tk.appendChild(fill); pm.appendChild(tk);
      pm.appendChild(el("span","scorepill sc-"+band(p.avg), p.avg.toFixed(1)));
      pm.appendChild(el("span","v num", p.n+"×"));
      row.appendChild(pm);
      c.appendChild(row);
    });
    dash.appendChild(c);
  })();

})();
</script>
<?php pc_page_end();
