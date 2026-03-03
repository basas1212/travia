<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);

$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

// Tick + queues
update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);
process_training_queue($mysqli, $vid);
process_tech_queue($mysqli, $vid);
seed_unit_research_from_existing_troops($mysqli, $vid);

$v = village_row($mysqli, $vid);
$prod = village_production($mysqli, $vid);
$cons = village_crop_consumption_per_hour($mysqli, $vid);

$troops = get_troops($mysqli, $vid);
$tribe = (string)($user['tribe'] ?? 'roman');
$units = unit_catalog_by_building($tribe, 'barracks');
$researched = researched_units_map($mysqli, $vid);

$stmt = $mysqli->prepare("SELECT * FROM troop_queue WHERE village_id=? AND building_type='barracks' ORDER BY finish_at ASC, id ASC");
$stmt->bind_param('i', $vid);
$stmt->execute();
$r = $stmt->get_result();
$trainAll = [];
while ($r && ($row = $r->fetch_assoc())) $trainAll[] = $row;
$stmt->close();

function left_mmss(string $finishAt): string {
  $t = strtotime($finishAt);
  if ($t <= 0) return '—';
  $left = max(0, $t - time());
  $m = intdiv($left, 60);
  $s = $left % 60;
  return ($m>0 ? ($m . ':' . str_pad((string)$s,2,'0',STR_PAD_LEFT)) : ($s.'s'));
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$activePage = 'city';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('barracks')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css">
</head>
<body>
<div class="page">
  <div class="shell">
    <?php include __DIR__ . '/../ui_topbar.php'; ?>

    <div class="panel">
      <div class="panelHeader">
        <div>
          <div class="title"><?php echo h(t('barracks')); ?></div>
          <div class="subtitle"><?php echo h(t('village')) . ': '; ?><b><?php echo h($v['name']); ?></b></div>
        </div>
        <a class="btn small" href="city.php"><?php echo h(t('nav_city')); ?></a>
      </div>

      <?php if ($flash): ?>
        <div class="list" style="padding-top:0;">
          <div class="errbox" style="margin:0 0 8px;"><?php echo h($flash); ?></div>
        </div>
      <?php endif; ?>

      <div class="list" style="padding-top:0;">
        <div class="resMeta">
          <div class="metaChip"><?php echo h(t('production_per_hour')); ?>: +<?php echo (int)($prod['crop'] ?? 0); ?>/h 🌾</div>
          <div class="metaChip"><?php echo h(t('population')); ?>: <?php echo (int)village_population($mysqli, $vid); ?></div>
          <div class="metaChip"><?php echo h(t('crop_consumption')); ?>: -<?php echo (int)$cons; ?>/h 🌾</div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panelHeader">
        <div>
          <div class="title"><?php echo h(t('troops')); ?></div>
          <div class="subtitle"><?php echo h(t('training_queue')); ?></div>
        </div>
      </div>
      <div class="list">
        <?php foreach ($units as $key=>$u): $amt = (int)($troops[$key] ?? 0); ?>
          <div class="listRow">
            <div class="listMain">
              <span class="rowIcon">🛡️</span>
              <span class="listTitle"><?php echo h($u['name']); ?></span>
              <span class="listLvl">× <?php echo $amt; ?></span>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($trainAll): $train = $trainAll[0]; ?>
          <div class="listRow">
            <div class="listMain">
              <span class="rowIcon">⏳</span>
              <span class="listTitle"><?php echo h(($units[$train['unit']]['name'] ?? $train['unit'])); ?> × <?php echo (int)$train['amount']; ?></span>
              <span class="listLvl"><?php echo h(t('ready_in')); ?>: <span id="tLeft"><?php echo h(left_mmss((string)$train['finish_at'])); ?></span></span>
            </div>
          </div>
          <script>
            (function(){
              var finish = new Date("<?php echo h((string)$train['finish_at']); ?>".replace(' ', 'T') ).getTime();
              function tick(){
                var s = Math.max(0, Math.floor((finish - Date.now())/1000));
                var m = Math.floor(s/60), r = s%60;
                var txt = (m>0 ? (m+':'+String(r).padStart(2,'0')) : (r+'s'));
                var el = document.getElementById('tLeft');
                if (el) el.textContent = txt;
                if (s===0) location.reload();
              }
              tick(); setInterval(tick, 1000);
            })();
          </script>
          <?php if (count($trainAll) > 1): ?>
            <?php for ($i=1; $i<count($trainAll); $i++): $q=$trainAll[$i]; ?>
              <div class="listRow">
                <div class="listMain">
                  <span class="rowIcon">🕒</span>
                  <span class="listTitle"><?php echo h(($units[$q['unit']]['name'] ?? $q['unit'])); ?> × <?php echo (int)$q['amount']; ?></span>
                  <span class="listLvl"><?php echo h(t('ready_at')); ?>: <?php echo h((string)$q['finish_at']); ?></span>
                </div>
              </div>
            <?php endfor; ?>
          <?php endif; ?>
        <?php endif; ?>

        <?php foreach ($units as $key=>$u): $isR = (bool)($researched[$key] ?? false); ?>
          <form method="post" action="train.php" class="listRow" style="gap:10px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="unit" value="<?php echo h($key); ?>">
          <input type="hidden" name="building_type" value="barracks">
            <div class="listMain">
              <span class="rowIcon"><?php echo $isR ? '➕' : '🔒'; ?></span>
              <span class="listTitle"><?php echo h($u['name']); ?></span>
              <span class="muted">🪵<?php echo (int)$u['cost']['wood']; ?> 🧱<?php echo (int)$u['cost']['clay']; ?> ⛓️<?php echo (int)$u['cost']['iron']; ?> 🌾<?php echo (int)$u['cost']['crop']; ?> · <?php echo h(t('upkeep')); ?>: <?php echo (int)($u['upkeep'] ?? 1); ?>/h</span>
              <?php if (!$isR): ?><span class="muted" style="display:block; margin-top:2px;">Reikia ištirti Akademijoje</span><?php endif; ?>
            </div>
            <div class="listAct" style="display:flex; gap:8px; align-items:center;">
              <input type="number" name="amount" value="1" min="1" max="100" style="width:84px;" <?php echo $isR ? '' : 'disabled'; ?>>
              <button class="btn small gold" type="submit" <?php echo $isR ? '' : 'disabled'; ?>><?php echo h(t('train')); ?></button>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>
</body>
</html>
