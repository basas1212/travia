<?php
require_once __DIR__ . '/../init.php';

require_login();

$uid = (int)$_SESSION['uid'];
$vid = current_village_id($mysqli, $uid);
if (!$vid) { header('Location: game.php'); exit; }

$fid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fid <= 0) { header('Location: village.php'); exit; }

$v = get_village($mysqli, $vid);
$fields = get_village_fields($mysqli, $vid);

$field = null;
foreach ($fields as $f) {
  if ((int)$f['field_id'] === $fid) { $field = $f; break; }
}
if (!$field) { header('Location: village.php'); exit; }

$type = (string)$field['type'];
$lvl = (int)$field['level'];
$maxLevel = 20;
$target = $lvl + 1;
$isMax = ($target > $maxLevel);

$cost = $isMax ? ['wood'=>0,'clay'=>0,'iron'=>0,'crop'=>0] : field_cost($type, $target);
$tsec = $isMax ? 0 : field_time_seconds_effective($mysqli, $vid, $type, $target);
$afford = !$isMax
  && (int)$v['wood'] >= (int)$cost['wood']
  && (int)$v['clay'] >= (int)$cost['clay']
  && (int)$v['iron'] >= (int)$cost['iron']
  && (int)$v['crop'] >= (int)$cost['crop'];

$q = $mysqli->query("SELECT id FROM build_queue WHERE village_id=".(int)$vid." AND status='running' LIMIT 1");
$activeQueue = ($q && $q->num_rows > 0);

?><!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(field_label($type)); ?> - Travia</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>

<?php include __DIR__ . '/../partials/topnav.php'; ?>

<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between;gap:12px;align-items:center;">
      <div>
        <div class="hTitle" style="margin:0;">
          <span class="rowIcon rg-<?php echo h($type); ?>"></span>
          <?php echo h(field_label($type)); ?>
        </div>
        <div class="muted"><?php echo h(t('level_short')) . ' ' . $lvl . '/' . $maxLevel; ?></div>
      </div>
      <a class="btnGhost" href="village.php">← <?php echo h(t('back')); ?></a>
    </div>

    <div class="sep"></div>

    <?php if ($isMax): ?>
      <div class="muted" style="font-weight:900;"><?php echo h(t('max_level')); ?></div>
    <?php else: ?>
      <div class="grid2">
        <div class="stat">
          <div class="muted"><?php echo h(t('price')); ?></div>
          <div class="statVal">
            🪵 <?php echo fmt_int($cost['wood']); ?> · 🧱 <?php echo fmt_int($cost['clay']); ?> · ⛓️ <?php echo fmt_int($cost['iron']); ?> · 🌾 <?php echo fmt_int($cost['crop']); ?>
          </div>
        </div>
        <div class="stat">
          <div class="muted"><?php echo h(t('time')); ?></div>
          <div class="statVal">⏱ <?php echo h(fmt_time($tsec)); ?></div>
        </div>
      </div>

      <div style="margin-top:12px;">
        <?php if ($activeQueue): ?>
          <button class="btn" disabled><?php echo h(t('queue_busy')); ?></button>
        <?php elseif (!$afford): ?>
          <button class="btn" disabled><?php echo h(t('not_enough_resources')); ?></button>
        <?php else: ?>
          <form method="post" action="field.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="field_id" value="<?php echo $fid; ?>">
            <button class="btn" type="submit">⚡ <?php echo h(t('upgrade')); ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

</body>
</html>
