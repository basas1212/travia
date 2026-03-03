<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
if (!$user) redirect('/login.php');

$uid = (int)$user['id'];
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');

// Tick basics
update_village_resources($mysqli, (int)$v['id']);
process_village_queue($mysqli, (int)$v['id']);
process_movements($mysqli);

$centerX = (int)$v['x'];
$centerY = (int)$v['y'];

$selX = isset($_GET['x']) ? (int)$_GET['x'] : null;
$selY = isset($_GET['y']) ? (int)$_GET['y'] : null;

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Load villages in view (11x11)
$minX = $centerX - 5; $maxX = $centerX + 5;
$minY = $centerY - 5; $maxY = $centerY + 5;

$stmt = $mysqli->prepare('SELECT id, user_id, name, x, y FROM villages WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ?');
$stmt->bind_param('iiii', $minX, $maxX, $minY, $maxY);
$stmt->execute();
$res = $stmt->get_result();
$villages = [];
while ($row = $res->fetch_assoc()) {
  $villages[((int)$row['x']) . ':' . ((int)$row['y'])] = $row;
}
$stmt->close();

// Selected tile info
$selected = null;
if ($selX !== null && $selY !== null) {
  $key = $selX . ':' . $selY;
  $selected = $villages[$key] ?? ['id'=>0,'user_id'=>0,'name'=>t('empty_tile'),'x'=>$selX,'y'=>$selY];
}

$activePage = 'map';

// Troops in current village (for quick send from map)
$troops = get_troops($mysqli, (int)$v['id']);
$unitCat = unit_catalog((string)($user['tribe'] ?? 'roman'));


function mmss(int $sec): string {
  $sec = max(0, $sec);
  $m = intdiv($sec, 60);
  $s = $sec % 60;
  if ($m <= 0) return $s . 's';
  return $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
}
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('nav_map')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
<div class="page">
  <div class="shell">

    <?php include __DIR__ . '/../ui_topbar.php'; ?>

    <div class="panel">
      <div class="panelHeader">
        <div>
          <div class="title"><?php echo h(t('nav_map')); ?></div>
          <div class="subtitle"><?php echo h(t('map_subtitle', ['x'=>$centerX, 'y'=>$centerY])); ?></div>
        </div>
        <a class="btn small danger" href="/logout.php"><?php echo h(t('logout')); ?></a>
      </div>

      <?php if ($flash): ?>
        <div class="list" style="padding-top:0;">
          <div class="errbox" style="margin:0 0 8px;"><?php echo h($flash); ?></div>
        </div>
      <?php endif; ?>

      <div class="list mapWrap">
        <div class="mapLegend">
          <div class="legendChip">🏡 <?php echo h(t('legend_my_village')); ?></div>
          <div class="legendChip">🏘️ <?php echo h(t('legend_other_village')); ?></div>
          <div class="legendChip">· <?php echo h(t('legend_empty')); ?></div>
        </div>

        <div class="mapGrid">
          <?php for ($y=$maxY; $y>=$minY; $y--): ?>
            <?php for ($x=$minX; $x<=$maxX; $x++):
              $k = $x . ':' . $y;
              $vv = $villages[$k] ?? null;
              $isMine = $vv && ((int)$vv['id'] === (int)$v['id']);
              $isVillage = (bool)$vv;
              $cls = 'mapCell' . ($isMine ? ' mine' : '') . ($isVillage ? ' village' : '');
              $ico = $isMine ? '🏡' : ($isVillage ? '🏘️' : '·');
              $href = 'map.php?x=' . $x . '&y=' . $y;
            ?>
              <a class="<?php echo $cls; ?>" href="<?php echo h($href); ?>">
                <div class="ico"><?php echo $ico; ?></div>
                <div class="xy"><?php echo $x . '|' . $y; ?></div>
              </a>
            <?php endfor; ?>
          <?php endfor; ?>
        </div>

        <?php if ($selected): ?>
          <div class="card">
            <div class="titled"><?php echo h(t('tile_title', ['x'=>(int)$selected['x'], 'y'=>(int)$selected['y']])); ?></div>
            <p class="muted" style="margin:10px 0 0;">
              <?php if (!empty($selected['id'])): ?>
                <?php echo h(t('tile_village_name', ['name'=>$selected['name']])); ?>
              <?php else: ?>
                <?php echo h(t('tile_empty')); ?>
              <?php endif; ?>
            </p>

            <?php if (!empty($selected['id']) && (int)$selected['id'] !== (int)$v['id']): ?>
              <div style="margin-top:12px;">
                <form method="post" action="send_troops.php" class="sendForm" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                  <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                  <input type="hidden" name="from_village_id" value="<?php echo (int)$v['id']; ?>">
                  <input type="hidden" name="to_x" value="<?php echo (int)$selected['x']; ?>">
                  <input type="hidden" name="to_y" value="<?php echo (int)$selected['y']; ?>">

                  <select name="move_type" style="padding:10px 12px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.22);color:#fff;">
                    <option value="attack"><?php echo h(t('move_attack')); ?></option>
                    <option value="raid"><?php echo h(t('move_raid')); ?></option>
                    <option value="reinforce"><?php echo h(t('move_reinforce')); ?></option>
                  </select>

                  <div style="flex-basis:100%;height:0;"></div>

                  <div style="width:100%;display:grid;grid-template-columns:1fr;gap:10px;margin-top:4px;">
                    <?php foreach ($troops as $unitKey => $amt): ?>
                      <?php $amt = (int)$amt; if ($amt <= 0) continue; ?>
                      <?php $u = $unitCat[$unitKey] ?? ['name'=>$unitKey,'att'=>0,'def_i'=>0,'def_c'=>0,'carry'=>0,'upkeep'=>1]; ?>
                      <div class="card" style="padding:10px 12px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                          <div>
                            <div style="font-weight:900;">
                              <?php echo h((string)$u['name']); ?>
                              <span class="muted">(<?php echo (int)$amt; ?>)</span>
                            </div>
                            <div class="muted small">
                              ATK: <?php echo (int)($u['att'] ?? 0); ?> ·
                              DEF(I/C): <?php echo (int)($u['def_i'] ?? 0); ?>/<?php echo (int)($u['def_c'] ?? 0); ?> ·
                              Carry: <?php echo (int)($u['carry'] ?? 0); ?> ·
                              Upkeep: <?php echo (int)($u['upkeep'] ?? 1); ?>/h
                            </div>
                          </div>
                          <input type="number"
                                 min="0"
                                 max="<?php echo (int)$amt; ?>"
                                 name="units[<?php echo h((string)$unitKey); ?>]"
                                 value="0"
                                 style="width:110px;padding:10px 12px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.22);color:#fff;">
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <button class="btn success" type="submit" style="margin-top:10px;"><?php echo h(t('send')); ?></button>
                </form>
                <div class="muted" style="margin-top:8px;font-weight:800;">
                  <?php
                    $sec = travel_seconds($centerX, $centerY, (int)$selected['x'], (int)$selected['y'], 'attack');
                    echo h(t('travel_time')) . ': ' . h(fmt_time($sec));
                  ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="card">
            <div class="titled"><?php echo h(t('map_tip_title')); ?></div>
            <p class="muted" style="margin:10px 0 0;"><?php echo h(t('map_tip_body')); ?></p>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="titled"><?php echo h(t('movements')); ?></div>
          <?php $moves = user_movements($mysqli, $uid, 10); ?>
          <?php if (!$moves): ?>
            <p class="muted" style="margin:10px 0 0;"><?php echo h(t('no_movements')); ?></p>
          <?php else: ?>
            <div class="list" style="padding:0;margin-top:10px;">
              <?php foreach ($moves as $m):
                $arr = strtotime((string)$m['arrive_at']);
                $left = $arr ? max(0, $arr - time()) : 0;
              ?>
                <div class="listRow">
                  <div class="listMain" style="gap:14px;">
                    <div class="listNum"><?php echo ((string)$m['action'] === 'raid') ? '🪓' : '⚔️'; ?></div>
                    <div style="min-width:0;">
                      <div class="listTitle"><?php echo h(t('to_xy', ['x'=>(int)$m['to_x'], 'y'=>(int)$m['to_y']])); ?></div>
                      <div class="muted" style="font-weight:800;">
                        <?php echo h(t('status_' . (string)$m['status'])); ?>
                        <?php if ((string)$m['status'] === 'traveling'): ?> · <?php echo h(t('remaining')) . ': ' . mmss($left); ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

  </div>
</div>
</body>
</html>
