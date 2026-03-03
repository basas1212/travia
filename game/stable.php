<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

ensure_game_schema($mysqli);

update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);
process_training_queue($mysqli, $vid);
process_tech_queue($mysqli, $vid);
seed_unit_research_from_existing_troops($mysqli, $vid);

$vrow = village_row($mysqli, $vid);
$troops = get_troops($mysqli, $vid);
$tribe = (string)($user['tribe'] ?? 'roman');
$units = unit_catalog_by_building($tribe, 'stable');
$researched = researched_units_map($mysqli, $vid);

$stmt = $mysqli->prepare("SELECT * FROM troop_queue WHERE village_id=? AND building_type='stable' ORDER BY finish_at ASC, id ASC");
$stmt->bind_param('i', $vid);
$stmt->execute();
$res = $stmt->get_result();
$trainAll = [];
while ($res && ($r = $res->fetch_assoc())) $trainAll[] = $r;
$stmt->close();

$activePage = 'city';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Arklidės - TRAVIA</title>
  <link rel="stylesheet" href="/style.css">
</head>
<body>
<div class="page">
  <div class="shell">
    <?php include __DIR__ . '/../ui_topbar.php'; ?>

    <div class="panel">
      <div class="panelHeader">
        <div>
          <div class="title">Arklidės</div>
          <div class="subtitle"><?php echo h(t('village')) . ': '; ?><b><?php echo h($v['name']); ?></b></div>
        </div>
        <a class="btn small" href="city.php"><?php echo h(t('nav_city')); ?></a>
      </div>

      <div class="list">
        <?php if (!$units): ?>
          <div class="listRow"><div class="listMain"><span class="rowIcon">ℹ️</span><span class="listTitle">Ši gentis neturi arklidžių karių (MVP).</span></div></div>
        <?php endif; ?>

        <?php foreach ($units as $key=>$u): $isR = (bool)($researched[$key] ?? false); ?>
          <form method="post" action="train.php" class="listRow" style="gap:10px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="unit" value="<?php echo h($key); ?>">
            <input type="hidden" name="building_type" value="stable">
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
