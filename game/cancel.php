<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

$queueId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $queueId = (int)($_POST['queue_id'] ?? 0);
} else {
  // backward compatibility (older links)
  $queueId = (int)($_GET['id'] ?? 0);
}

if ($queueId > 0) {
  [$ok, $msg] = cancel_queue_item($mysqli, $vid, $queueId);
  $_SESSION['flash'] = $msg;
}

// Try to return back to where user came from
$back = $_SERVER['HTTP_REFERER'] ?? '';
if ($back) {
  header('Location: ' . $back);
  exit;
}
redirect('city.php');
