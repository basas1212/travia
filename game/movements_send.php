<?php
require_once __DIR__ . '/../init.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  redirect('map.php');
}

csrf_verify();

$user = current_user($mysqli);
if (!$user) redirect('/login.php');
$uid = (int)$user['id'];

$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');

$toX = (int)($_POST['to_x'] ?? 0);
$toY = (int)($_POST['to_y'] ?? 0);
$action = (string)($_POST['action'] ?? 'attack');

process_village_queue($mysqli, (int)$v['id']);
process_movements($mysqli);

[$ok, $msg] = create_movement($mysqli, $uid, (int)$v['id'], (int)$v['x'], (int)$v['y'], $toX, $toY, $action);
$_SESSION['flash'] = $msg;

redirect('map.php?x=' . $toX . '&y=' . $toY);
