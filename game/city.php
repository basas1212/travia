<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);

$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

// Tick resources + finish queue
update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);
process_training_queue($mysqli, $vid);
process_due_troop_movements($mysqli);

$v = village_row($mysqli, $vid);
$prod = village_production($mysqli, $vid);

$bmap  = village_buildings_map($mysqli, $vid);

// Prefill City slot #1 with Main Building lvl 1 (RC_01a safe display)
if (!isset($bmap[1])) {
  $bmap[1] = ['type'=>'main_building','level'=>1];
}

$queue = village_queue($mysqli, $vid);
$hasQueue = !empty($queue);

$queueLimit = build_queue_limit($user);

$catalog = building_catalog();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

function left_mmss(string $finishAt): string {
  $t = strtotime($finishAt);
  if ($t <= 0) return '—';
  $left = max(0, $t - time());
  $m = intdiv($left, 60);
  $s = $left % 60;
  if ($m <= 0) return $s . 's';
  return $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
}

$activePage = 'city';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('nav_city')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
  <div class="page">
    <div class="shell">

      <?php include __DIR__ . '/../ui_topbar.php'; ?>

      <div class="panel">
        <div class="panelHeader">
          <div>
            <div class="title"><?php echo h(t('build_queue')); ?></div>
            <div class="subtitle"><?php echo h(t('queue_slots', ['n'=>$queueLimit])); ?></div>
          </div>
        </div>

        <div class="list">
          <?php if (!$queue): ?>
            <div class="listRow emptySlot">
              <div class="listMain"><span class="rowIcon">✅</span> <span class="listTitle"><?php echo h(t('no_active_build')); ?></span></div>
            </div>
          <?php else: foreach ($queue as $q): ?>
            <div class="listRow">
              <div class="listMain" style="gap:14px;">
                <div class="listNum">⏳</div>
                <div style="min-width:0;">
                  <div class="listTitle"><?php echo h(building_label((string)$q['type'])); ?> · <?php echo h(t('level_short')) . ' ' . (int)$q['target_level']; ?></div>
                  <div class="muted" style="font-weight:900;"><?php echo h(t('remaining')); ?>: <span id="qLeft_<?php echo (int)$q['id']; ?>"><?php echo h(left_mmss((string)$q['finish_at'])); ?></span></div>
                </div>
              </div>
              <div class="listAct">
                <a class="btn small danger" href="cancel.php?id=<?php echo (int)$q['id']; ?>"><?php echo h(t('cancel')); ?></a>
              </div>
            </div>
            <script>
              (function(){
                var finish = new Date("<?php echo h((string)$q['finish_at']); ?>".replace(' ', 'T') ).getTime();
                function tick(){
                  var now = Date.now();
                  var s = Math.max(0, Math.floor((finish - now)/1000));
				  var h = Math.floor(s/3600);
				  var m = Math.floor((s%3600)/60);
				  var r = s%60;
				  var txt;
				  if (h===0 && m===0) txt = String(r).padStart(2,'0') + 's';
				  else if (h===0) txt = String(m).padStart(2,'0') + ':' + String(r).padStart(2,'0');
				  else txt = String(h) + ':' + String(m).padStart(2,'0') + ':' + String(r).padStart(2,'0');
                  var el = document.getElementById('qLeft_<?php echo (int)$q['id']; ?>');
                  if (el) el.textContent = txt;
                  if (s===0) location.reload();
                }
                tick(); setInterval(tick, 1000);
              })();
            </script>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panelHeader">
          <div>
            <div class="title"><?php echo h(t('buildings')); ?></div>
            <div class="subtitle"><?php echo h(t('building_slots', ['n'=>24])); ?></div>
          </div>
          <div class="info" title="Core">i</div>
        </div>

        <div class="list">
          <?php for ($slot=1; $slot<=24; $slot++):
  $row = $bmap[$slot] ?? null;
  $type = $row['type'] ?? '';
  $lvl  = (int)($row['level'] ?? 0);
  $isBuilt = ($row && $type !== '' && $type !== 'empty');
  $href = $isBuilt ? ('building_view.php?slot=' . $slot) : ('build_select.php?slot=' . $slot);
?>
  <a class="listRow" href="<?php echo h($href); ?>" style="text-decoration:none;">
    <div class="listNum"><?php echo (int)$slot; ?></div>
    <div class="listMain">
      <span class="rowIcon"><?php echo $isBuilt ? '🏛️' : '➕'; ?></span>
      <?php if ($isBuilt): ?>
        <span class="listTitle"><?php echo h($isBuilt ? building_label((string)$type) : t('empty_slot')); ?></span>
        <span class="listLvl"><?php echo h(t('level_short')) . ' ' . $lvl; ?></span>
      <?php else: ?>
        <span class="listTitle"><?php echo h(t('empty_slot')); ?></span>
        <span class="listLvl">—</span>
      <?php endif; ?>
    </div>
    <div class="listAction"><?php echo h(t('open')); ?> ›</div>
  </a>
<?php endfor; ?>
        </div>
      </div>


      
    </div>
  </div>
</div>

<script>
  // Live status refresh (display-only). Server truth remains on each request.
  (function(){
    const nf = (n) => {
      n = Number(n||0);
      try { return n.toLocaleString('lt-LT'); } catch(e) { return String(n); }
    };
    const setTxt = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };
    const setFill = (id, cur, cap) => {
      const el = document.getElementById(id);
      if (!el) return;
      const pct = cap>0 ? Math.min(100, Math.max(0, (cur/cap)*100)) : 0;
      el.style.width = pct + '%';
    };

    async function refresh(){
      try{
        const r = await fetch('ajax_status.php', {cache:'no-store'});
        if(!r.ok) return;
        const s = await r.json();
        const w = Number(s.cap?.warehouse||0);
        const g = Number(s.cap?.granary||0);

        setTxt('res-wood', nf(s.resources?.wood));
        setTxt('res-clay', nf(s.resources?.clay));
        setTxt('res-iron', nf(s.resources?.iron));
        setTxt('res-crop', nf(s.resources?.crop));

        setTxt('cap-wood', nf(w));
        setTxt('cap-clay', nf(w));
        setTxt('cap-iron', nf(w));
        setTxt('cap-crop', nf(g));

        setFill('fill-wood', Number(s.resources?.wood||0), w);
        setFill('fill-clay', Number(s.resources?.clay||0), w);
        setFill('fill-iron', Number(s.resources?.iron||0), w);
        setFill('fill-crop', Number(s.resources?.crop||0), g);

        setTxt('prod-wood', nf(s.prod?.wood));
        setTxt('prod-clay', nf(s.prod?.clay));
        setTxt('prod-iron', nf(s.prod?.iron));
        setTxt('prod-crop', nf(s.prod?.crop));
      }catch(e){ /* ignore */ }
    }

    refresh();
    setInterval(refresh, 5000);
  })();
</script>

</body>
</html>
