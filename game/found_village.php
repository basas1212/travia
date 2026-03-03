<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);

ensure_game_schema($mysqli);

// checks
$stmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM villages WHERE user_id=?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$r = $stmt->get_result();
$row = $r ? $r->fetch_assoc() : null;
$stmt->close();
$vcount = $row ? (int)$row['c'] : 0;

if ($vcount >= 3) {
  $_SESSION['flash'] = 'Maksimaliai 3 kaimai (Sostinė + 2).';
  redirect('city.php');
}

$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

$resLvl = building_level_by_type($mysqli, $vid, 'residence');
$need = ($vcount <= 1) ? 10 : 20; // 1-as naujas kaimas nuo 10 lvl, 2-as nuo 20 lvl
if ($resLvl < $need) {
  $_SESSION['flash'] = 'Reikia Rezidencijos ' . $need . ' lygio šiame kaime.';
  redirect('city.php');
}

$stmt = $mysqli->prepare('SELECT culture_points FROM users WHERE id=? LIMIT 1');
$stmt->bind_param('i', $uid);
$stmt->execute();
$r = $stmt->get_result();
$u = $r ? $r->fetch_assoc() : null;
$stmt->close();
$cp = $u && isset($u['culture_points']) ? (int)$u['culture_points'] : 0;

// Kultūros taškai (kol kas išjungta testavimui)
$cpCost = 0;
if ($cpCost > 0 && $cp < $cpCost) {
  $_SESSION['flash'] = 'Reikia ' . $cpCost . ' kultūros taškų.';
  redirect('city.php');
}

try {
  $mysqli->begin_transaction();

  // Deduct CP (jei įjungta)
  if (!empty($cpCost)) {
    $stmt = $mysqli->prepare('UPDATE users SET culture_points=culture_points-? WHERE id=?');
    $stmt->bind_param('ii', $cpCost, $uid);
    $stmt->execute();
    $stmt->close();
  }

  // Create village
  $vname = 'Naujas kaimas';
  $wood = 750; $clay = 750; $iron = 750; $crop = 750;
  $xy = allocate_start_coordinates($mysqli, 75);
  $x = (int)$xy['x'];
  $y = (int)$xy['y'];

  $stmt = $mysqli->prepare('INSERT INTO villages (user_id,name,wood,clay,iron,crop,x,y,is_capital) VALUES (?,?,?,?,?,?,?,?,0)');
  $stmt->bind_param('isiiiiii', $uid, $vname, $wood, $clay, $iron, $crop, $x, $y);
  $stmt->execute();
  $newVid = (int)$stmt->insert_id;
  $stmt->close();

  occupy_world_tile($mysqli, $x, $y, $newVid);

  // Seed 18 resource fields (4-4-4-6) – visi 0 lygio
  $types = array_merge(
    array_fill(0, 4, 'wood'),
    array_fill(0, 4, 'clay'),
    array_fill(0, 4, 'iron'),
    array_fill(0, 6, 'crop')
  );
  for ($i = 0; $i < 18; $i++) {
    $field_id = $i + 1;
    $type = $types[$i];
    $level = 0;
    $stmt = $mysqli->prepare('INSERT INTO resource_fields (village_id, field_id, type, level) VALUES (?,?,?,?)');
    $stmt->bind_param('iisi', $newVid, $field_id, $type, $level);
    $stmt->execute();
    $stmt->close();
  }

  // ✅ City: 24 slotai. Slot #1 = Gyvenamasis pastatas Lv 1, kiti tušti.
  for ($slot = 1; $slot <= 24; $slot++) {
    if ($slot === 1) {
      // Slot #1 is always occupied by the Residential/Main building (Lv 1)
      $type = 'main_building';
      $lvl  = 1;
    } else {
      $type = 'empty';
      $lvl  = 0;
    }
    $stmt = $mysqli->prepare('INSERT INTO village_buildings (village_id, slot, type, level) VALUES (?,?,?,?)');
    $stmt->bind_param('iisi', $newVid, $slot, $type, $lvl);
    $stmt->execute();
    $stmt->close();
  }

  init_storage_caps_raw($mysqli, $vid);

        $mysqli->commit();

  $_SESSION['flash'] = '✅ Kaimas įkurtas!';
  redirect('city.php');

} catch (Throwable $e) {
  $mysqli->rollback();
  $_SESSION['flash'] = APP_DEBUG ? ('Klaida: '.$e->getMessage()) : 'Įvyko klaida.';
  redirect('city.php');
}

function init_storage_caps_raw(mysqli $db, int $villageId): void {
  // Pradinės talpos pagal serverio greitį (SPEED_STORE=TRAVIA_SPEED)
  $base = defined('TRAVIA_STORAGE_BASE') ? (int)TRAVIA_STORAGE_BASE : 800;
  if ($base <= 0) $base = 800;
  $growth = defined('TRAVIA_STORAGE_GROWTH') ? (float)TRAVIA_STORAGE_GROWTH : 1.28;
  if ($growth <= 1.0) $growth = 1.28;

  // Start: lvl 0 (jei vėliau užkelsi warehouse/granary – queue procesorius perskaičiuos)
  $cap = (int)max($base, round(($base * ($growth ** 0))));

  $stmt = $db->prepare('UPDATE villages SET warehouse=?, granary=? WHERE id=?');
  $stmt->bind_param('iii', $cap, $cap, $villageId);
  $stmt->execute();
  $stmt->close();
}

