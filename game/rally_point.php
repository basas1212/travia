<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);

$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

// Tick
update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);
process_training_queue($mysqli, $vid);
process_due_troop_movements($mysqli);

$v = village_row($mysqli, $vid);


$troops = get_troops($mysqli, $vid);
// unit_catalog reikalauja genties (tribe), todėl paduodame iš current_user
$unitCat = unit_catalog((string)($user['tribe'] ?? 'roman'));

$msg = '';
if (!empty($_GET['msg'])) $msg = (string)$_GET['msg'];
if (!empty($_GET['err'])) $msg = (string)$_GET['err'];

$outgoing = village_outgoing_movements($mysqli, $vid);
$incoming = village_incoming_movements($mysqli, $vid);

$activePage = 'map';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Susirinkimo vieta - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
  <div class="page">
    <div class="shell">
      <?php include __DIR__ . '/../ui_topbar.php'; ?>

      <div class="panel">
        <div class="panelHeader">
          <div>
            <div class="panelTitle">Susirinkimo vieta</div>
            <div class="muted">Jūsų kaimas: (<?php echo (int)$v['x']; ?>|<?php echo (int)$v['y']; ?>)</div>
          </div>
          <a class="btn" href="city.php">Atgal</a>
        </div>

        <?php if ($msg !== ''): ?>
          <div class="notice"><?php echo h($msg); ?></div>
        <?php endif; ?>

        <div class="section">
          <h3>Siųsti karius</h3>
          <form method="post" action="send_troops.php" class="form">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="from_village_id" value="<?php echo (int)$vid; ?>">

            <div class="row">
              <label>Veiksmas</label>
              <select name="move_type" required>
                <option value="raid">Reidas</option>
                <option value="attack">Puolimas</option>
                <option value="reinforce">Pastiprinimas</option>
              </select>
            </div>

            <div class="row">
              <label>Tikslas (X|Y)</label>
              <div class="xy">
                <input type="number" name="to_x" placeholder="X" required>
                <input type="number" name="to_y" placeholder="Y" required>
              </div>
            </div>

            <div class="row">
              <label>Kariai</label>
              <div class="troopsGrid">
                <?php foreach ($troops as $unitKey => $amt): ?>
                  <?php if ($amt <= 0) continue; ?>
                  <?php $u = $unitCat[$unitKey] ?? ['name'=>$unitKey, 'upkeep'=>1, 'speed'=>6]; ?>
                  <div class="troopLine">
                    <div class="troopName"><?php echo h($u['name']); ?> <span class="muted">(turite: <?php echo (int)$amt; ?>)</span></div>
                    <input type="number" min="0" max="<?php echo (int)$amt; ?>" name="units[<?php echo h($unitKey); ?>]" value="0">
                    <div class="muted small">Išlaikymas: <?php echo (int)($u['upkeep'] ?? 1); ?>/h · Greitis: <?php echo (int)($u['speed'] ?? 6); ?></div>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($troops)): ?>
                  <div class="muted">Neturite karių.</div>
                <?php endif; ?>
              </div>
            </div>

            <button class="btn primary" type="submit">Siųsti</button>
          </form>
        </div>

        <div class="section">
          <h3>Išeinantys / grįžtantys</h3>
          <?php if (!$outgoing): ?>
            <div class="muted">Nėra judėjimų.</div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($outgoing as $m): ?>
                <?php
                  $toX = (int)($m['to_x'] ?? 0);
                  $toY = (int)($m['to_y'] ?? 0);
                  $type = (string)$m['move_type'];
                  $state = (string)$m['state'];
                  $t = ($state === 'returning') ? (string)$m['return_at'] : (string)$m['arrive_at'];
                  $units = json_decode((string)$m['units_json'], true);
                  if (!is_array($units)) $units = [];
                  $sum = 0; foreach ($units as $a) $sum += (int)$a;
                ?>
                <div class="item">
                  <div><b><?php echo h($type); ?></b> → (<?php echo $toX; ?>|<?php echo $toY; ?>) · <span class="muted"><?php echo h($state); ?></span></div>
                  <div class="muted">Kariai: <?php echo (int)$sum; ?> · Laikas: <?php echo h($t); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="section">
          <h3>Įeinantys</h3>
          <?php if (!$incoming): ?>
            <div class="muted">Nėra įeinančių judėjimų.</div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($incoming as $m): ?>
                <?php
                  $fromVid = (int)$m['from_village_id'];
                  $from = village_coords($mysqli, $fromVid);
                  $fx = $from ? (int)$from['x'] : 0;
                  $fy = $from ? (int)$from['y'] : 0;
                  $type = (string)$m['move_type'];
                  $t = (string)$m['arrive_at'];
                  $units = json_decode((string)$m['units_json'], true);
                  if (!is_array($units)) $units = [];
                  $sum = 0; foreach ($units as $a) $sum += (int)$a;
                ?>
                <div class="item">
                  <div><b><?php echo h($type); ?></b> iš (<?php echo $fx; ?>|<?php echo $fy; ?>)</div>
                  <div class="muted">Kariai: <?php echo (int)$sum; ?> · Atvyksta: <?php echo h($t); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <style>
    .form .row{margin:10px 0;}
    .form label{display:block;font-weight:600;margin-bottom:6px;}
    .xy{display:flex;gap:10px;}
    .xy input{flex:1;}
    .troopsGrid{display:flex;flex-direction:column;gap:10px;}
    .troopLine{border:1px solid #2a2f36;border-radius:10px;padding:10px;background:#14181f;}
    .troopName{font-weight:600;margin-bottom:6px;}
    .small{font-size:12px;}
    .notice{padding:10px;border-radius:10px;background:#1c2430;border:1px solid #2a2f36;margin-bottom:10px;}
    .list{display:flex;flex-direction:column;gap:8px;}
    .item{border:1px solid #2a2f36;border-radius:10px;padding:10px;background:#14181f;}
  </style>
</body>
</html>
