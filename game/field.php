<?php
require_once __DIR__ . '/../init.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('village.php');
}

csrf_verify();

$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

$fieldId = (int)($_POST['field_id'] ?? 0);

// Always process finished queue first
process_village_queue($mysqli, $vid);

[$ok, $msg] = start_field_upgrade($mysqli, $vid, $fieldId);
$_SESSION['flash'] = $msg;

redirect('village.php');
