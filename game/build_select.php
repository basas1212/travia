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

// Read current buildings levels per type
$typeLvls = [];
$stmt = $mysqli->prepare("SELECT type, MAX(level) AS lvl, COUNT(*) AS cnt FROM village_buildings WHERE village_id=? GROUP BY type");
$stmt->bind_param('i', $vid);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $t = (string)$row['type'];
  $typeLvls[$t] = ['lvl'=>(int)$row['lvl'], 'cnt'=>(int)$row['cnt']];
}
$stmt->close();

// Slot status
$bmap = village_buildings_map($mysqli, $vid);
$slotRow = $bmap[$slot] ?? null;
if ($slotRow && isset($slotRow['type'])) {
  // ✅ village_buildings_map dažniausiai grąžina įrašą ir tuščiam slotui (type='empty').
  // Jei čia redirectinsim aklai – gausim kilpą: build_select -> building_view -> build_select ...
  $t = (string)$slotRow['type'];
  if ($t !== '' && $t !== 'empty') {
    // If already built, go to slot view
    redirect('building_view.php?slot=' . $slot);
  }
}

// Load building config
$cfgPath = __DIR__ . '/../engine/buildings_config.php';
$cfg = is_file($cfgPath) ? require $cfgPath : [];

function missing_requirements(array $cfg, array $typeLvls, string $type): array {
  $req = $cfg[$type]['requires'] ?? [];
  $missing = [];
  foreach ($req as $rType => $rLvl) {
    $have = (int)($typeLvls[$rType]['lvl'] ?? 0);
    if ($have < (int)$rLvl) $missing[$rType] = (int)$rLvl;
  }
  return $missing;
}

function can_build_now(array $cfg, array $typeLvls, string $type): bool {
  return count(missing_requirements($cfg, $typeLvls, $type)) === 0;
}

function is_multi_allowed(array $cfg, string $type): bool {
  return (bool)($cfg[$type]['multi'] ?? false);
}

$buildable = [];
$locked = [];

foreach ($cfg as $type => $meta) {
  // Hide main_building from selection (it is prebuilt)
  if ($type === 'main_building') continue;

  // Unique building rule
  if (!is_multi_allowed($cfg, $type) && !empty($typeLvls[$type]['cnt'])) {
    continue;
  }

  $miss = missing_requirements($cfg, $typeLvls, $type);
  if (!$miss) $buildable[] = $type;
  else $locked[] = ['type'=>$type, 'missing'=>$miss];
}

// Sort by label
usort($buildable, function($a,$b){
  return strcmp(building_label($a), building_label($b));
});
usort($locked, function($a,$b){
  return strcmp(building_label($a['type']), building_label($b['type']));
});

$activePage = 'city';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('select_building')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
  <?php include __DIR__ . '/../ui_topbar.php'; ?>

  <div class="container" style="padding-bottom:90px;">
    <div class="panel">
      <div class="panelHeader">
        <div>
          <div class="title"><?php echo h(t('select_building')); ?></div>
          <div class="subtitle"><?php echo h(t('slot_number', ['n'=>$slot])); ?></div>
        </div>
      </div>

      <div class="list">
        <div class="listRow">
          <div class="listMain">
            <span class="rowIcon">🟢</span>
            <span class="listTitle"><?php echo h(t('can_build_now')); ?></span>
          </div>
        </div>

        <?php if (!$buildable): ?>
          <div class="listRow emptySlot">
            <div class="listMain"><span class="rowIcon">⛔</span> <span class="listTitle"><?php echo h(t('no_buildings_available')); ?></span></div>
          </div>
        <?php else: foreach ($buildable as $type): ?>
          <a class="listRow" href="build_preview.php?slot=<?php echo (int)$slot; ?>&type=<?php echo urlencode($type); ?>" style="text-decoration:none;">
            <div class="listMain">
              <span class="rowIcon">🏗️</span>
              <span class="listTitle"><?php echo h(building_label($type)); ?></span>
              <span class="listLvl"><?php echo h(t('max_level')) . ': ' . (int)($cfg[$type]['max_level'] ?? 20); ?></span>
            </div>
            <div class="listAction"><?php echo h(t('view')); ?> ›</div>
          </a>
        <?php endforeach; endif; ?>

        <div class="listRow" style="margin-top:6px;">
          <div class="listMain">
            <span class="rowIcon">🔒</span>
            <span class="listTitle"><?php echo h(t('available_later')); ?></span>
          </div>
          <div class="listAction"><a class="btn" href="#locked"><?php echo h(t('show')); ?></a></div>
        </div>

        <div id="locked"></div>
        <?php if ($locked): foreach ($locked as $it): ?>
          <div class="listRow emptySlot">
            <div class="listMain" style="flex-direction:column; align-items:flex-start; gap:6px;">
              <div style="display:flex; align-items:center; gap:10px;">
                <span class="rowIcon">🔒</span>
                <span class="listTitle"><?php echo h(building_label($it['type'])); ?></span>
              </div>
              <div class="subtitle"><?php echo h(t('missing_requirements')); ?>:
                <?php
                  $parts = [];
                  foreach ($it['missing'] as $rt => $rl) {
                    $parts[] = building_label((string)$rt) . ' ' . t('level_short') . ' ' . (int)$rl;
                  }
                  echo h(implode(', ', $parts));
                ?>
              </div>
            </div>
          </div>
        <?php endforeach; else: ?>
          <div class="listRow emptySlot">
            <div class="listMain"><span class="rowIcon">✅</span> <span class="listTitle"><?php echo h(t('no_locked_buildings')); ?></span></div>
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
