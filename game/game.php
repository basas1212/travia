<?php
require_once __DIR__ . '/../init.php';


// Local helpers (avoid undefined function fatals)
if (!function_exists('_pct')) {
  function _pct($cur, $cap) {
    $cap = (float)$cap;
    if ($cap <= 0) return 0;
    $cur = (float)$cur;
    $p = ($cur / $cap) * 100.0;
    if ($p < 0) $p = 0;
    if ($p > 100) $p = 100;
    return $p;
  }
}
if (!function_exists('fmt_int')) {
  function fmt_int($n) {
    return number_format((int)floor((float)$n), 0, ',', ' ');
  }
}
if (!function_exists('fmt_rate')) {
  function fmt_rate($n) {
    $n = (int)round((float)$n);
    $sign = $n > 0 ? '+' : ($n < 0 ? '−' : '');
    return $sign . number_format(abs($n), 0, ',', ' ') . '/h';
  }
}
require_login();
$user = current_user($mysqli);
$activePage = 'menu';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('nav_menu')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
  <div class="page">
    <div class="shell">

      <?php include __DIR__ . '/../ui_topbar.php'; ?>

<?php
      $v = current_village($mysqli, (int)($user['id'] ?? 0));
      if ($v) {
        $vid = (int)$v['id'];
        // užtikrinam, kad DB atnaujinta prieš rodant
        update_village_resources($mysqli, $vid);
        $v = village_row($mysqli, $vid);
        $capW = effective_warehouse_cap($v);
        $capG = effective_granary_cap($v);
        $prod = village_production($mysqli, $vid);
        $pop = village_population($mysqli, $vid);
        $cropUp = village_crop_upkeep($mysqli, $vid);
        $cropNet = (int)($prod['crop'] ?? 0) - $cropUp;
      }
    ?>
    <?php if (!empty($v)): ?>
    <div class="panel" id="resPanel">
  <div class="panelHeader">
    <div>
      <div class="title">GYVENVIETĖS RESURSAI</div>
      <div class="subtitle"><?php echo h($v['name']); ?></div>
    </div>
    <div class="pill">i</div>
  </div>

  <?php
    $wCur = (int)$v['wood']; $cCur=(int)$v['clay']; $iCur=(int)$v['iron']; $gCur=(int)$v['crop'];
    $wRate = (int)($prod['wood'] ?? 0);
    $cRate = (int)($prod['clay'] ?? 0);
    $iRate = (int)($prod['iron'] ?? 0);
    $gRate = (int)$cropNet;
  ?>

  <div class="rLine" data-res="wood">
    <div class="rIco"><img src="../img/wood.png" alt=""></div>
    <div class="rMain">
      <div class="rTop">
        <div class="rCur" id="res-wood"><?php echo h(fmt_int($wCur)); ?></div>
        <div class="rInfo" id="res-wood-info"><?php echo h(fmt_rate($wRate)); ?> | maks.: <?php echo h(fmt_int($capW)); ?></div>
      </div>
      <div class="rBar"><div class="rFill" id="res-wood-fill" style="width:<?php echo (int)_pct($wCur,$capW); ?>%"></div></div>
    </div>
  </div>

  <div class="rLine" data-res="clay">
    <div class="rIco"><img src="../img/clay.png" alt=""></div>
    <div class="rMain">
      <div class="rTop">
        <div class="rCur" id="res-clay"><?php echo h(fmt_int($cCur)); ?></div>
        <div class="rInfo" id="res-clay-info"><?php echo h(fmt_rate($cRate)); ?> | maks.: <?php echo h(fmt_int($capW)); ?></div>
      </div>
      <div class="rBar"><div class="rFill" id="res-clay-fill" style="width:<?php echo (int)_pct($cCur,$capW); ?>%"></div></div>
    </div>
  </div>

  <div class="rLine" data-res="iron">
    <div class="rIco"><img src="../img/iron.png" alt=""></div>
    <div class="rMain">
      <div class="rTop">
        <div class="rCur" id="res-iron"><?php echo h(fmt_int($iCur)); ?></div>
        <div class="rInfo" id="res-iron-info"><?php echo h(fmt_rate($iRate)); ?> | maks.: <?php echo h(fmt_int($capW)); ?></div>
      </div>
      <div class="rBar"><div class="rFill" id="res-iron-fill" style="width:<?php echo (int)_pct($iCur,$capW); ?>%"></div></div>
    </div>
  </div>

  <div class="rLine" data-res="crop">
    <div class="rIco"><img src="../img/crop.png" alt=""></div>
    <div class="rMain">
      <div class="rTop">
        <div class="rCur" id="res-crop"><?php echo h(fmt_int($gCur)); ?></div>
        <div class="rInfo" id="res-crop-info"><?php echo h(fmt_rate($gRate)); ?> | maks.: <?php echo h(fmt_int($capG)); ?></div>
      </div>
      <div class="rBar"><div class="rFill" id="res-crop-fill" style="width:<?php echo (int)_pct($gCur,$capG); ?>%"></div></div>
    </div>
  </div>

  <div class="resMeta">
    <div class="metaChip">Pop.: <b id="pop"><?php echo (int)$pop; ?></b></div>
    <div class="metaChip">Grūdų suvartojimas: <b id="cropUp"><?php echo (int)$cropUp; ?></b>/h</div>
  </div>
</div>

<script>
(function(){
  const nf=(n)=>Math.floor(Number(n||0)).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');
  const fmtRate=(n)=>{n=Number(n||0);const sign=n>0?'+':(n<0?'−':'');return sign+nf(Math.abs(n))+'/h';};
  const clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
  const setText=(id,val)=>{const el=document.getElementById(id); if(el) el.textContent=val;};
  const setFill=(id,cur,cap)=>{
    const el=document.getElementById(id);
    if(!el) return;
    const pct=cap>0?clamp((cur/cap)*100,0,100):0;
    el.style.width=pct+'%';

    // Pilnas sandėlis / klėtis -> raudonas baras
    const line = el.closest('.rLine');
    if(line){
      const full = (cap>0) && (cur >= cap);
      line.classList.toggle('isFull', full);
    }
  };

  let state={
    resources:{
      wood: <?php echo (int)$wCur; ?>,
      clay: <?php echo (int)$cCur; ?>,
      iron: <?php echo (int)$iCur; ?>,
      crop: <?php echo (int)$gCur; ?>
    },
    cap:{warehouse: <?php echo (int)$capW; ?>, granary: <?php echo (int)$capG; ?>},
    prod:{
      wood: <?php echo (int)$wRate; ?>,
      clay: <?php echo (int)$cRate; ?>,
      iron: <?php echo (int)$iRate; ?>,
      crop_net: <?php echo (int)$gRate; ?>
    }
  };

  function render(){
    setText('res-wood', nf(state.resources.wood));
    setText('res-clay', nf(state.resources.clay));
    setText('res-iron', nf(state.resources.iron));
    setText('res-crop', nf(state.resources.crop));

    setText('res-wood-info', fmtRate(state.prod.wood)+' | maks.: '+nf(state.cap.warehouse));
    setText('res-clay-info', fmtRate(state.prod.clay)+' | maks.: '+nf(state.cap.warehouse));
    setText('res-iron-info', fmtRate(state.prod.iron)+' | maks.: '+nf(state.cap.warehouse));
    setText('res-crop-info', fmtRate(state.prod.crop_net)+' | maks.: '+nf(state.cap.granary));

    setFill('res-wood-fill', state.resources.wood, state.cap.warehouse);
    setFill('res-clay-fill', state.resources.clay, state.cap.warehouse);
    setFill('res-iron-fill', state.resources.iron, state.cap.warehouse);
    setFill('res-crop-fill', state.resources.crop, state.cap.granary);
  }

  function smoothTick(){
    state.resources.wood = clamp(state.resources.wood + state.prod.wood/3600, 0, state.cap.warehouse);
    state.resources.clay = clamp(state.resources.clay + state.prod.clay/3600, 0, state.cap.warehouse);
    state.resources.iron = clamp(state.resources.iron + state.prod.iron/3600, 0, state.cap.warehouse);
    state.resources.crop = clamp(state.resources.crop + state.prod.crop_net/3600, 0, state.cap.granary);
    render();
  }

  async function sync(){
    try{
      const r = await fetch('ajax_status.php', {cache:'no-store'});
      if(!r.ok) return;
      const s = await r.json();
      if(!s || !s.ok) return;

      if(s.cap){
        state.cap.warehouse = Number(s.cap.warehouse||0);
        state.cap.granary   = Number(s.cap.granary||0);
      }
      if(s.resources){
        state.resources.wood = Number(s.resources.wood||0);
        state.resources.clay = Number(s.resources.clay||0);
        state.resources.iron = Number(s.resources.iron||0);
        state.resources.crop = Number(s.resources.crop||0);
      }
      if(s.prod){
        state.prod.wood = Number(s.prod.wood||0);
        state.prod.clay = Number(s.prod.clay||0);
        state.prod.iron = Number(s.prod.iron||0);
        state.prod.crop_net = Number(s.prod.crop_net ?? 0);
        setText('pop', nf(s.prod.pop||0));
        setText('cropUp', nf(s.prod.crop_upkeep||0));
      }
      render();
    }catch(e){}
  }

  render();
  sync();
  setInterval(smoothTick, 1000);
  setInterval(sync, 10000);
})();
</script>
<?php endif; ?>


      <div class="panel">
        <div class="panelHeader">
          <div>
            <div class="title">TRAVIA</div>
            <div class="subtitle"><?php echo h(t('logged_in_as')); ?>: <b><?php echo h($user['username'] ?? ''); ?></b></div>
          </div>
          <a class="btn small danger" href="/logout.php"><?php echo h(t('logout')); ?></a>
        </div>
        <div class="list">
          <div class="listRow">
            <div class="listMain"><span class="rowIcon">🏡</span> <span class="listTitle"><?php echo h(t('nav_village')); ?></span></div>
            <div class="listAct"><a class="upBtn" href="village.php"><span class="rIco">→</span></a></div>
          </div>
          <div class="listRow">
            <div class="listMain"><span class="rowIcon">🏰</span> <span class="listTitle"><?php echo h(t('nav_city')); ?></span></div>
            <div class="listAct"><a class="upBtn" href="city.php"><span class="rIco">→</span></a></div>
          </div>
          <div class="listRow">
            <div class="listMain"><span class="rowIcon">🗺️</span> <span class="listTitle"><?php echo h(t('nav_map')); ?></span></div>
            <div class="listAct"><a class="upBtn" href="map.php"><span class="rIco">→</span></a></div>
          </div>

          <div class="listRow">
            <div class="listMain"><span class="listTitle"><?php echo h(t('nav_reports')); ?></span></div>
            <div class="listAct"><a class="upBtn" href="reports.php"><span class="rIco">→</span></a></div>
          </div>
          <div class="listRow">
            <div class="listMain"><span class="listTitle"><?php echo h(t('nav_alliance')); ?></span></div>
            <div class="listAct"><a class="upBtn" href="alliance.php"><span class="rIco">→</span></a></div>
          </div>
          <div class="listRow">
            <div class="listMain"><span class="listTitle"><?php echo h(t('nav_hero')); ?></span></div>
            <div class="listAct"><a class="upBtn" href="hero.php"><span class="rIco">→</span></a></div>
          </div>

          <?php if (!empty($user['is_admin'])): ?>
          <div class="listRow">
            <div class="listMain"><span class="rowIcon">⚙️</span> <span class="listTitle"><?php echo h(t('admin_panel')); ?></span></div>
            <div class="listAct"><a class="upBtn" href="/admin.php"><span class="rIco">→</span></a></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="titled"><?php echo h(t('note')); ?></div>
        <p class="muted" style="margin:10px 0 0;">
          <?php echo h(t('core_note')); ?>
        </p>
      </div>
    </div>
  </div>
</body>
</html>