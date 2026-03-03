<?php
require_once __DIR__ . '/../init.php';
require_login();

$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);

$v = village_row($mysqli, $vid);

$slot = (int)($_GET['slot'] ?? 0);
$type = (string)($_GET['type'] ?? '');
if ($slot < 1 || $slot > 24 || $type === '') redirect('city.php');

// RC_01a: preview only (no build yet)
$cfgPath = __DIR__ . '/engine/buildings_config.php';
$cfg = is_file($cfgPath) ? require $cfgPath : [];
$meta = $cfg[$type] ?? null;

$targetLevel = 1;
$cost = building_cost($type, $targetLevel);
$sec  = building_time_seconds_effective($mysqli, $vid, $type, $targetLevel);

$canAfford = (int)$v['wood'] >= $cost['wood'] && (int)$v['clay'] >= $cost['clay'] && (int)$v['iron'] >= $cost['iron'] && (int)$v['crop'] >= $cost['crop'];

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
        <div class="subtitle"><?php echo h(t('slot_number', ['n'=>$slot])); ?></div>
      </div>
    </div>
    <div class="list">
      <div class="listRow">
        <div class="listMain" style="flex-direction:column; align-items:flex-start;">
          <div class="listTitle"><?php echo h(t('building_preview')); ?></div>
          <div class="subtitle"><?php echo h(t('building_preview_note')); ?></div>
        </div>
      </div>
      <?php if ($meta && !empty($meta['requires'])): ?>
        <div class="listRow">
          <div class="listMain" style="flex-direction:column; align-items:flex-start;">
            <div class="listTitle"><?php echo h(t('requires')); ?></div>
            <div class="subtitle">
              <?php
                $parts=[];
                foreach ($meta['requires'] as $rt=>$rl){ $parts[]=building_label((string)$rt).' '.t('level_short').' '.(int)$rl; }
                echo h(implode(', ', $parts));
              ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="listRow">
        <div class="listMain" style="flex-direction:column; align-items:flex-start;">
          <div class="listTitle"><?php echo h(t('price')); ?></div>
          <div class="subtitle">🪵<?php echo (int)$cost['wood']; ?> · 🧱<?php echo (int)$cost['clay']; ?> · ⛓️<?php echo (int)$cost['iron']; ?> · 🌾<?php echo (int)$cost['crop']; ?></div>
          <div class="subtitle"><?php echo h(t('remaining')); ?>: <?php echo h(fmt_time($sec)); ?></div>
        </div>
      </div>

      <div class="listRow" style="justify-content:space-between;">
        <div class="listMain">
          <span class="rowIcon">🏗️</span>
          <span class="listTitle"><?php echo h(t('build')); ?></span>
        </div>
        <div class="listAction">
          <form method="post" action="build.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="slot" value="<?php echo (int)$slot; ?>">
            <input type="hidden" name="type" value="<?php echo h($type); ?>">
            <button class="btn small gold" type="submit" <?php echo $canAfford ? '' : 'disabled'; ?>><?php echo h(t('build')); ?></button>
          </form>
        </div>
      </div>
      <?php if (!$canAfford): ?>
        <div class="errbox" style="margin:8px 12px;"><?php echo h(t('not_enough_resources')); ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="build_select.php?slot=<?php echo (int)$slot; ?>">← <?php echo h(t('back_to_list')); ?></a>
    <a class="btn" href="city.php">🏛 <?php echo h(t('back_to_city')); ?></a>
  </div>
</div>
</body>
</html>
