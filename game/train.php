<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../unit_research_helpers.php';

require_login();
csrf_verify();

$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

ensure_game_schema($mysqli);

update_village_resources($mysqli, $vid);
process_training_queue($mysqli, $vid);
process_tech_queue($mysqli, $vid);
seed_unit_research_from_existing_troops($mysqli, $vid);

$unitType = (string)($_POST['unit'] ?? '');
$buildingType = strtolower((string)($_POST['building_type'] ?? 'barracks'));
if (!in_array($buildingType, ['barracks','stable','workshop'], true)) $buildingType = 'barracks';
$amount = max(1, (int)($_POST['amount'] ?? 1));

$tribe = (string)($user['tribe'] ?? 'roman');
$units = unit_catalog_by_building($tribe, $buildingType);
if (!isset($units[$unitType])) {
  $_SESSION['flash'] = 'Bad unit.';
  redirect($buildingType === 'stable' ? 'stable.php' : 'barracks.php');
}

// Academy gate
if (!is_unit_researched($mysqli, $vid, $unitType)) {
  $_SESSION['flash'] = 'Šis karys dar neištirtas Akademijoje.';
  redirect($buildingType === 'stable' ? 'stable.php' : 'barracks.php');
}

// Requirements (mokymui – be akademijos, tik pastato lygis)
[$metReq, $missReq] = unit_train_requirements_met($mysqli, $vid, $unitType);
if (!$metReq) {
  $msg = format_requirements_missing($missReq);
  $_SESSION['flash'] = $msg !== '' ? ('Trūksta reikalavimų: ' . $msg) : 'Trūksta reikalavimų.';
  redirect($buildingType === 'stable' ? 'stable.php' : ($buildingType === 'workshop' ? 'workshop.php' : 'barracks.php'));
}

// Leidžiam eilę (stack). Naujas užsakymas prasideda po paskutinio.
$stmt = $mysqli->prepare("SELECT finish_at, (SELECT COUNT(*) FROM troop_queue WHERE village_id=? AND building_type=?) AS c FROM troop_queue WHERE village_id=? AND building_type=? ORDER BY finish_at DESC LIMIT 1");
$stmt->bind_param('isis', $vid, $buildingType, $vid, $buildingType);
$stmt->execute();
$r = $stmt->get_result();
$last = $r ? $r->fetch_assoc() : null;
$stmt->close();
$queueCount = (int)($last['c'] ?? 0);
$lastFinish = !empty($last['finish_at']) ? (string)$last['finish_at'] : '';

$vrow = village_row($mysqli, $vid);
$cost = $units[$unitType]['cost'];
$total = [
  'wood' => $cost['wood'] * $amount,
  'clay' => $cost['clay'] * $amount,
  'iron' => $cost['iron'] * $amount,
  'crop' => $cost['crop'] * $amount,
];

$afford = (int)$vrow['wood'] >= $total['wood'] && (int)$vrow['clay'] >= $total['clay'] && (int)$vrow['iron'] >= $total['iron'] && (int)$vrow['crop'] >= $total['crop'];
if (!$afford) {
  $_SESSION['flash'] = t('not_enough_resources');
  redirect($buildingType === 'stable' ? 'stable.php' : 'barracks.php');
}

// deduct
$newWood = (int)$vrow['wood'] - $total['wood'];
$newClay = (int)$vrow['clay'] - $total['clay'];
$newIron = (int)$vrow['iron'] - $total['iron'];
$newCrop = (int)$vrow['crop'] - $total['crop'];

$stmt = $mysqli->prepare('UPDATE villages SET wood=?, clay=?, iron=?, crop=? WHERE id=?');
$stmt->bind_param('iiiii', $newWood, $newClay, $newIron, $newCrop, $vid);
$stmt->execute();
$stmt->close();

$speed = (int)max(1, round(function_exists('speed_train') ? speed_train() : (defined('TRAVIA_SPEED') ? (float)TRAVIA_SPEED : 1.0)));
$mb = building_level_by_type($mysqli, $vid, 'main_building');
$baseTime = (int)$units[$unitType]['time'] * $amount;
$sec = training_time_seconds($baseTime, $speed, $mb);

$nowTs = time();
$startTs = $nowTs;
if ($lastFinish) {
  $lf = strtotime($lastFinish);
  if ($lf && $lf > $startTs) $startTs = $lf;
}
$started = date('Y-m-d H:i:s', $startTs);
$finish = date('Y-m-d H:i:s', $startTs + $sec);

$queuePos = $queueCount + 1;
$building = $buildingType;
$bt = $buildingType;
$stmt = $mysqli->prepare("INSERT INTO troop_queue (village_id, building, building_type, unit, amount, started_at, finish_at, queue_pos, cost_wood, cost_clay, cost_iron, cost_crop) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
$stmt->bind_param('isssissiiiii', $vid, $building, $bt, $unitType, $amount, $started, $finish, $queuePos, $total['wood'], $total['clay'], $total['iron'], $total['crop']);
$stmt->execute();
$stmt->close();

$_SESSION['flash'] = '✅ ' . t('train') . ' ' . $units[$unitType]['name'] . ' × ' . $amount;
redirect($buildingType === 'stable' ? 'stable.php' : ($buildingType === 'workshop' ? 'workshop.php' : 'barracks.php'));
