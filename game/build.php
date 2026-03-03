<?php
require_once __DIR__ . '/../init.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('city.php');
}

csrf_verify();

$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

$slot = (int)($_POST['slot'] ?? 0);
$type = trim((string)($_POST['type'] ?? ''));

// Always process finished queue first
process_village_queue($mysqli, $vid);

[$ok, $msg] = start_building($mysqli, $vid, $slot, $type);
$_SESSION['flash'] = $msg;

redirect('city.php');
