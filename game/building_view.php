<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

$slot = (int)($_GET['slot'] ?? 0);
if ($slot < 1 || $slot > 24) redirect('city.php');

$bmap = village_buildings_map($mysqli, $vid);
$row = $bmap[$slot] ?? null;
if (!$row || ($row['type'] ?? '')==='' || ($row['type'] ?? '')==='empty') redirect('build_select.php?slot=' . $slot);
$type = (string)$row['type'];
$lvl = (int)$row['level'];

update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);
$vrow = village_row($mysqli, $vid);

$cfg = is_file(__DIR__ . '/engine/buildings_config.php') ? require __DIR__ . '/engine/buildings_config.php' : [];
$meta = $cfg[$type] ?? ['max_level'=>20,'requires'=>[],'multi'=>false];
$maxLevel = (int)($meta['max_level'] ?? 20);
$next = $lvl + 1;
$canUpgrade = $next <= $maxLevel;
$nextCost = $canUpgrade ? building_cost($type, $next) : ['wood'=>0,'clay'=>0,'iron'=>0,'crop'=>0];
$nextSec  = $canUpgrade ? building_time_seconds_effective($mysqli, $vid, $type, $next) : 0;
$canAfford = $canUpgrade && (int)$vrow['wood'] >= $nextCost['wood'] && (int)$vrow['clay'] >= $nextCost['clay'] && (int)$vrow['iron'] >= $nextCost['iron'] && (int)$vrow['crop'] >= $nextCost['crop'];

$activePage='city';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(building_label($type)); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
<?php include __DIR__ . '/../ui_topbar.php'; ?>
<div class="container" style="padding-bottom:90px;">
  <div class="panel">
    <div class="panelHeader">
      <div>
        <div class="title"><?php echo h(building_label($type)); ?></div>
        <div class="subtitle"><?php echo h(t('level_short')) . ' ' . (int)$lvl . ' · ' . h(t('slot_number', ['n'=>$slot])); ?></div>
        <?php if ($type === 'barracks'): ?>
          <div style="margin-top:8px;">
            <a class="btn" href="barracks.php">Atidaryti kareivines</a>
          </div>
        <?php elseif ($type === 'stable'): ?>
          <div style="margin-top:8px;">
            <a class="btn" href="stable.php">Atidaryti arklides</a>
          </div>
        <?php elseif ($type === 'rally_point'): ?>
          <div style="margin-top:8px;">
            <a class="btn" href="rally_point.php">Atidaryti susirinkimo vietą</a>
          </div>
        <?php elseif ($type === 'academy'): ?>
          <div style="margin-top:8px;">
            <a class="btn" href="academy.php">Atidaryti akademiją</a>
          </div>
        <?php elseif ($type === 'workshop'): ?>
          <div style="margin-top:8px;">
            <a class="btn" href="workshop.php">Atidaryti dirbtuves</a>
          </div>
        <?php endif; ?>

      </div>
    </div>
    <div class="list">
      <?php if ($canUpgrade): ?>
        <div class="listRow">
          <div class="listMain" style="flex-direction:column; align-items:flex-start;">
            <div class="listTitle"><?php echo h(t('upgrade')); ?> → <?php echo h(t('level_short')) . ' ' . (int)$next; ?></div>
            <div class="subtitle">🪵<?php echo (int)$nextCost['wood']; ?> · 🧱<?php echo (int)$nextCost['clay']; ?> · ⛓️<?php echo (int)$nextCost['iron']; ?> · 🌾<?php echo (int)$nextCost['crop']; ?></div>
            <div class="subtitle"><?php echo h(t('remaining')); ?>: <?php echo h(fmt_time($nextSec)); ?></div>
          </div>
          <div class="listAction">
            <form method="post" action="build.php" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="slot" value="<?php echo (int)$slot; ?>">
              <input type="hidden" name="type" value="<?php echo h($type); ?>">
              <button class="btn small gold" type="submit" <?php echo $canAfford ? '' : 'disabled'; ?>><?php echo h(t('upgrade')); ?></button>
            </form>
          </div>
        </div>
        <?php if (!$canAfford): ?>
          <div class="errbox" style="margin:8px 12px;"><?php echo h(t('not_enough_resources')); ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="listRow">
          <div class="listMain"><span class="rowIcon">✅</span> <span class="listTitle"><?php echo h(t('max_level')); ?></span></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="margin-top:12px;">
    <a class="btn" href="city.php">← <?php echo h(t('back_to_city')); ?></a>
  </div>
</div>
</body>
</html>
