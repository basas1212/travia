<?php
require_once __DIR__ . '/../init.php';

require_login();
csrf_verify();

$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);

$fromVillageId = (int)($_POST['from_village_id'] ?? 0);
$moveType = (string)($_POST['move_type'] ?? 'raid');
$toX = (int)($_POST['to_x'] ?? 0);
$toY = (int)($_POST['to_y'] ?? 0);
$units = $_POST['units'] ?? [];
if (!is_array($units)) $units = [];

// Validate from village belongs to user
$v = village_row($mysqli, $fromVillageId);
if (!$v || (int)$v['user_id'] !== $uid) {
  redirect('rally_point.php?err=' . urlencode('Neteisingas kaimas.'));
}

// Validate target
$target = find_village_by_xy($mysqli, $toX, $toY);
if (!$target) {
  redirect('rally_point.php?err=' . urlencode('Toks kaimas nerastas šiame taške.'));
}
$toVillageId = (int)$target['id'];

// Filter units
$filtered = [];
foreach ($units as $k => $vAmt) {
  $k = (string)$k;
  $a = (int)$vAmt;
  if ($a > 0) $filtered[$k] = $a;
}
if (!$filtered) {
  redirect('rally_point.php?err=' . urlencode('Nepasirinkote karių.'));
}

// Tick before deduct
update_village_resources($mysqli, $fromVillageId);
process_training_queue($mysqli, $fromVillageId);

// Deduct troops
$mysqli->begin_transaction();
try {
  if (!deduct_troops($mysqli, $fromVillageId, $filtered)) {
    $mysqli->rollback();
    redirect('rally_point.php?err=' . urlencode('Nepakanka karių.'));
  }

  $created = create_troop_movement($mysqli, $fromVillageId, $toVillageId, $toX, $toY, $moveType, $filtered);
  if (!$created[0]) {
    // Gražinam karius
    add_troops($mysqli, $fromVillageId, $filtered);
    $mysqli->rollback();
    redirect('rally_point.php?err=' . urlencode((string)$created[1]));
  }

  $mysqli->commit();
} catch (Throwable $e) {
  $mysqli->rollback();
  redirect('rally_point.php?err=' . urlencode('Klaida siunčiant karius.'));
}

redirect('rally_point.php?msg=' . urlencode('Kariai išsiųsti!'));
