<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) redirect('/game/game.php');
$vid = (int)$v['id'];

ensure_game_schema($mysqli);

// Tick + queues
update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);
process_training_queue($mysqli, $vid);
process_tech_queue($mysqli, $vid);
seed_unit_research_from_existing_troops($mysqli, $vid);

$tribe = (string)($user['tribe'] ?? 'roman');
$academyLvl = building_level_by_type($mysqli, $vid, 'academy');

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Handle research start
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $unit = (string)($_POST['unit'] ?? '');

  if ($academyLvl <= 0) {
    $_SESSION['flash'] = 'Akademija turi būti pastatyta.';
    redirect('academy.php');
  }

  $all = unit_catalog($tribe);
  if (!isset($all[$unit])) {
    $_SESSION['flash'] = 'Neteisingas unit.';
    redirect('academy.php');
  }

  if (is_unit_researched($mysqli, $vid, $unit)) {
    $_SESSION['flash'] = 'Jau ištirta.';
    redirect('academy.php');
  }

  // One at a time
  if (!can_start_research($mysqli, $vid)) {
    $_SESSION['flash'] = 'Jau vyksta tyrimas.';
    redirect('academy.php');
  }

  // Research cost (MVP): 50% of unit cost
  $c = $all[$unit]['cost'];
  $cost = [
    'wood' => (int)ceil($c['wood'] * 0.5),
    'clay' => (int)ceil($c['clay'] * 0.5),
    'iron' => (int)ceil($c['iron'] * 0.5),
    'crop' => (int)ceil($c['crop'] * 0.5),
  ];

  $vrow = village_row($mysqli, $vid);
  $afford = (int)$vrow['wood'] >= $cost['wood'] && (int)$vrow['clay'] >= $cost['clay'] && (int)$vrow['iron'] >= $cost['iron'] && (int)$vrow['crop'] >= $cost['crop'];
  if (!$afford) {
    $_SESSION['flash'] = t('not_enough_resources');
    redirect('academy.php');
  }

  // Deduct
  $stmt = $mysqli->prepare('UPDATE villages SET wood=wood-?, clay=clay-?, iron=iron-?, crop=crop-? WHERE id=?');
  $stmt->bind_param('iiiii', $cost['wood'], $cost['clay'], $cost['iron'], $cost['crop'], $vid);
  $stmt->execute();
  $stmt->close();

  $speed = (int)max(1, round(function_exists('speed_train') ? speed_train() : (defined('TRAVIA_SPEED') ? (float)TRAVIA_SPEED : 1.0)));
  $sec = research_time_seconds($all[$unit], $speed, $academyLvl);
  [$ok, $msg] = start_unit_research($mysqli, $vid, $unit, $sec);
  $_SESSION['flash'] = $ok ? ('✅ Tyrimas pradėtas: '.$all[$unit]['name']) : ('❌ '.$msg);
  redirect('academy.php');
}

// Load status maps
$researched = researched_units_map($mysqli, $vid);

$stmt = $mysqli->prepare("SELECT * FROM tech_queue WHERE village_id=? AND action='research' ORDER BY finish_at ASC, id ASC");
$stmt->bind_param('i', $vid);
$stmt->execute();
$res = $stmt->get_result();
$queue = [];
while ($res && ($r = $res->fetch_assoc())) $queue[] = $r;
$stmt->close();

$queuedUnit = $queue ? (string)$queue[0]['unit'] : '';

$activePage = 'city';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Akademija - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
<div class="page">
  <div class="shell">
    <?php include __DIR__ . '/../ui_topbar.php'; ?>

    <div class="panel">
      <div class="panelHeader">
        <div>
          <div class="title">Akademija</div>
          <div class="subtitle"><?php echo h(t('village')) . ': '; ?><b><?php echo h($v['name']); ?></b> · Lygis: <?php echo (int)$academyLvl; ?></div>
        </div>
        <a class="btn small" href="city.php"><?php echo h(t('nav_city')); ?></a>
      </div>

      <?php if ($flash): ?>
        <div class="list" style="padding-top:0;">
          <div class="errbox" style="margin:0 0 8px;"><?php echo h($flash); ?></div>
        </div>
      <?php endif; ?>

      <?php if ($academyLvl <= 0): ?>
        <div class="list">
          <div class="listRow"><div class="listMain"><span class="rowIcon">ℹ️</span><span class="listTitle">Pastatyk Akademiją, kad galėtum tirti karius.</span></div></div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($queue): ?>
      <div class="panel">
        <div class="panelHeader"><div><div class="title">Vykstantis tyrimas</div></div></div>
        <div class="list">
          <?php $q = $queue[0]; $all = unit_catalog($tribe); $uName = $all[$q['unit']]['name'] ?? $q['unit']; ?>
          <div class="listRow">
            <div class="listMain">
              <span class="rowIcon">⏳</span>
              <span class="listTitle"><?php echo h($uName); ?></span>
              <span class="listLvl"><?php echo h(t('ready_at')); ?>: <?php echo h((string)$q['finish_at']); ?></span>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panelHeader"><div><div class="title">Tyrimai</div><div class="subtitle">Ištirk, kad atsirastų kareivinėse / arklidėse / dirbtuvėse</div></div></div>
      <div class="list">
        <?php
          $all = unit_catalog($tribe);
          foreach ($all as $k=>$u):
            $isR = (bool)($researched[$k] ?? false);
            if ($isR) { continue; }
            $isQ = ($queuedUnit !== '' && $queuedUnit === $k);
            $icon = $isR ? '✅' : ($isQ ? '⏳' : '🔬');
            $disabled = ($academyLvl<=0) || $isR || $isQ || !can_start_research($mysqli, $vid);
            $c = $u['cost'];
            $rc = [
              'wood' => (int)ceil($c['wood'] * 0.5),
              'clay' => (int)ceil($c['clay'] * 0.5),
              'iron' => (int)ceil($c['iron'] * 0.5),
              'crop' => (int)ceil($c['crop'] * 0.5),
            ];
        ?>
          <form method="post" class="listRow" style="gap:10px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="unit" value="<?php echo h($k); ?>">
            <div class="listMain">
              <span class="rowIcon"><?php echo $icon; ?></span>
              <span class="listTitle"><?php echo h($u['name']); ?></span>
              <span class="muted">Tyrimo kaina: 🪵<?php echo $rc['wood']; ?> 🧱<?php echo $rc['clay']; ?> ⛓️<?php echo $rc['iron']; ?> 🌾<?php echo $rc['crop']; ?></span>
            </div>
            <div class="listAct">
              <button class="btn small gold" type="submit" <?php echo $disabled ? 'disabled' : ''; ?>><?php echo $isR ? 'Ištirta' : 'Tirti'; ?></button>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>
</body>
</html>
