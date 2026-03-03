<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
if (!$user) redirect('/login.php');

$v = current_village($mysqli, (int)$user['id']);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

// tick resources + finish queue
update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);
process_training_queue($mysqli, $vid);
process_due_troop_movements($mysqli);

$v = village_row($mysqli, $vid);
$prod = village_production($mysqli, $vid);
$fields = village_fields($mysqli, $vid);
$queue = village_queue($mysqli, $vid);
$activeQueue = $queue[0] ?? null;

$activePage = 'village';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('nav_village')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
<div class="page">
  <div class="shell">

    <?php include __DIR__ . '/../ui_topbar.php'; ?>

    <div class="panel">
      <div class="panelHeader">
        <div>
          <div class="title"><?php echo h(t('resource_fields')); ?></div>
          <div class="subtitle"><?php echo h(t('resource_fields_subtitle')); ?></div>
        </div>
      </div>

      <?php
        // Grupės LT pavadinimai (be FIELD_WOOD ir pan.)
        $groups = [
          'wood' => 'MEDŽIŲ KIRTAVIETĖS',
          'clay' => 'MOLIO KARJERAI',
          'iron' => 'GELEŽIES KASYKLOS',
          'crop' => 'GRŪDŲ FERMA',
        ];
        $byType = [];
        foreach ($fields as $f) {
          $t = (string)$f['type'];
          if (!isset($byType[$t])) $byType[$t] = [];
          $byType[$t][] = $f;
        }
      ?>

      <div class="villageFields">
        <?php if ($activeQueue && ($activeQueue['action'] ?? '') === 'field'): ?>
          <div class="adminNote">
            🛠️ <?php echo h(t('upgrading_field')); ?>: <b><?php echo h(field_label((string)$activeQueue['type'])); ?></b>
            (<?php echo h(t('level_short')) . ' ' . (int)$activeQueue['target_level']; ?>) · <?php echo h(t('remaining')); ?> <b><span id="qLeft">...</span></b>
            <div style="margin-top:10px;">
              <form method="post" action="cancel.php" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="queue_id" value="<?php echo (int)$activeQueue['id']; ?>">
                <button class="btn danger btnfull" type="submit"><?php echo h(t('cancel')); ?></button>
              </form>
            </div>
          </div>
          <script>
            (function(){
              var finish = new Date("<?php echo h((string)$activeQueue['finish_at']); ?>".replace(' ', 'T') ).getTime();
              function tick(){
                var now = Date.now();
                var s = Math.max(0, Math.floor((finish - now)/1000));
                var m = Math.floor(s/60), r = s%60;
                var txt = (m>0? (m+':'+String(r).padStart(2,'0')) : (r+'s'));
                var el = document.getElementById('qLeft');
                if (el) el.textContent = txt;
                if (s===0) location.reload();
              }
              tick(); setInterval(tick, 1000);
            })();
          </script>
        <?php endif; ?>

        <?php foreach ($groups as $typeKey => $title): ?>
          <?php if (empty($byType[$typeKey])) continue; ?>
          <div class="fieldGroupTitle">
            <span class="rowIcon rg-<?php echo $typeKey; ?>"></span>
            <?php echo $title; ?>
          </div>

          <?php foreach ($byType[$typeKey] as $f): ?>
            <?php
              $fid = (int)$f['field_id'];
              $type = (string)$f['type'];
              $lvl = (int)$f['level'];
              $maxLevel = 20;
              $target = $lvl + 1;
              $isMax = ($target > $maxLevel);

              $cost = $isMax ? ['wood'=>0,'clay'=>0,'iron'=>0,'crop'=>0] : field_cost($type, $target);
              $afford = !$isMax
                && (int)$v['wood'] >= (int)$cost['wood']
                && (int)$v['clay'] >= (int)$cost['clay']
                && (int)$v['iron'] >= (int)$cost['iron']
                && (int)$v['crop'] >= (int)$cost['crop'];
            ?>

            <div class="fieldRow">
              <a class="fieldMain" href="field_view.php?id=<?php echo $fid; ?>">
                <span class="rowIcon rg-<?php echo h($type); ?>"></span>
                <span class="fieldName"><?php echo h(field_label($type)); ?></span>
                <span class="fieldLvl"><?php echo h(t('level_short')) . ' ' . $lvl . '/' . $maxLevel; ?></span>
              </a>
              <div class="fieldAction">
                <?php if ($isMax): ?>
                  <div class="fieldUpBtn disabled" title="MAX">⚡</div>
                <?php elseif ($activeQueue): ?>
                  <div class="fieldUpBtn disabled" title="<?php echo h(t('queue_busy')); ?>">⏳</div>
                <?php elseif (!$afford): ?>
                  <div class="fieldUpBtn disabled" title="<?php echo h(t('not_enough_resources')); ?>">⚡</div>
                <?php else: ?>
                  <form method="post" action="field.php" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="field_id" value="<?php echo $fid; ?>">
                    <button class="fieldUpBtn" type="submit" title="<?php echo h(t('upgrade')); ?>">⚡</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
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
