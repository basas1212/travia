<?php
require_once __DIR__ . '/../init.php';
require_login();

$activePage = 'stats';
$weekStart = date('Y-m-d', strtotime('monday this week'));
$now = date('Y-m-d H:i:s');

function _week_points_from_ranks(array $ranks): array {
  // $ranks: ordered user_id list top10
  $points = [];
  $p = 10;
  foreach ($ranks as $uid) {
    if ($p <= 0) break;
    $points[$uid] = ($points[$uid] ?? 0) + $p;
    $p--;
  }
  return $points;
}

function _sum_loot_json(?string $lootJson): int {
  if (!$lootJson) return 0;
  $d = json_decode($lootJson, true);
  if (!is_array($d)) return 0;
  $sum = 0;
  foreach (['wood','clay','iron','crop'] as $k) $sum += (int)($d[$k] ?? 0);
  return $sum;
}

function get_users_basic(mysqli $db): array {
  $rows = [];
  $res = $db->query("SELECT id, username FROM users");
  while ($r = $res->fetch_assoc()) $rows[(int)$r['id']] = $r['username'];
  return $rows;
}

function get_pop_totals(mysqli $db, array $usernames): array {
  // computes population by summing village_population() for each village
  $totals = [];
  foreach ($usernames as $uid => $_name) {
    $uid = (int)$uid;
    $vres = $db->query("SELECT id FROM villages WHERE user_id={$uid}");
    $sum = 0;
    while ($v = $vres->fetch_assoc()) {
      $sum += (int)village_population($db, (int)$v['id']);
    }
    $totals[$uid] = $sum;
  }
  arsort($totals);
  return $totals;
}

function get_loot_totals(mysqli $db, string $since = ''): array {
  // totals loot for raid/attack where state=done, grouped by attacker user (from_village owner)
  $where = "tm.state='done' AND tm.move_type IN ('raid','attack') AND tm.loot_json IS NOT NULL";
  if ($since !== '') $where .= " AND tm.created_at >= '" . $db->real_escape_string($since) . "'";
  $sql = "SELECT v.user_id, tm.loot_json
          FROM troop_movements tm
          JOIN villages v ON v.id = tm.from_village_id
          WHERE {$where}";
  $res = $db->query($sql);
  $totals = [];
  while ($r = $res->fetch_assoc()) {
    $uid = (int)$r['user_id'];
    $totals[$uid] = ($totals[$uid] ?? 0) + _sum_loot_json($r['loot_json']);
  }
  arsort($totals);
  return $totals;
}

function top10(array $totals): array {
  return array_slice($totals, 0, 10, true);
}

$db = $mysqli;
$usernames = get_users_basic($db);

// Live totals
$livePop = get_pop_totals($db, $usernames);
$liveLoot = get_loot_totals($db);

// Weekly totals (from week start)
$weekPop = $livePop; // pop is live; weekly pop table not tracked, so show live pop for now
$weekLoot = get_loot_totals($db, $weekStart . " 00:00:00");

// For now attack/def are not logged explicitly; show 0 until battle logging is added.
$liveAtk = array_fill_keys(array_keys($usernames), 0);
$liveDef = array_fill_keys(array_keys($usernames), 0);
$weekAtk = $liveAtk;
$weekDef = $liveDef;

// Weekly overall points from ranks
$wkPopRank = array_keys(top10($weekPop));
$wkAtkRank = array_keys(top10($weekAtk));
$wkDefRank = array_keys(top10($weekDef));
$wkLootRank = array_keys(top10($weekLoot));

$wkPoints = [];
foreach ([_week_points_from_ranks($wkPopRank), _week_points_from_ranks($wkAtkRank), _week_points_from_ranks($wkDefRank), _week_points_from_ranks($wkLootRank)] as $pp) {
  foreach ($pp as $uid => $p) $wkPoints[$uid] = ($wkPoints[$uid] ?? 0) + $p;
}
arsort($wkPoints);

// Lifetime overall: sum of weekly overall points stored in weekly_rewards_log category='overall'
$life = [];
$res = $db->query("SELECT user_id, SUM(points) AS pts FROM weekly_rewards_log WHERE category='overall' GROUP BY user_id");
while ($r = $res->fetch_assoc()) $life[(int)$r['user_id']] = (int)$r['pts'];
// add current week points on top (so it updates in real time)
foreach ($wkPoints as $uid => $p) $life[$uid] = ($life[$uid] ?? 0) + $p;
arsort($life);

function render_table(array $top, array $usernames, string $valueSuffix=''): void {
  echo '<table class="statTable">';
  echo '<tr><th>#</th><th>'.h(t('player') ?? 'Žaidėjas').'</th><th>'.h(t('points') ?? 'Taškai').'</th></tr>';
  $i=1;
  foreach ($top as $uid => $val) {
    $name = $usernames[$uid] ?? ('#'.$uid);
    echo '<tr><td>'.($i).'</td><td>'.h($name).'</td><td>'.h(number_format((int)$val, 0, '.', ' ')).$valueSuffix.'</td></tr>';
    $i++;
  }
  echo '</table>';
}

?><!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo h(t('nav_stats') ?? 'Statistika'); ?> - Travia</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
<?php include __DIR__ . '/../ui_topbar.php'; ?>

<div class="panel" style="margin-top:14px;">
  <div class="panelHeader">
    <div>
      <div class="title"><?php echo h(t('nav_stats') ?? 'Statistika'); ?></div>
      <div class="subtitle">Live + Savaitės TOP10 (nuo <?php echo h($weekStart); ?>)</div>
    </div>
    <div class="pill">i</div>
  </div>

  <div class="statsGrid">
    <div class="statBox">
      <div class="statTitle">Live POP TOP10</div>
      <?php render_table(top10($livePop), $usernames); ?>
    </div>

    <div class="statBox">
      <div class="statTitle">Live Plėšimas TOP10</div>
      <?php render_table(top10($liveLoot), $usernames); ?>
    </div>

    <div class="statBox">
      <div class="statTitle">Savaitės Plėšimas TOP10</div>
      <?php render_table(top10($weekLoot), $usernames); ?>
    </div>

    <div class="statBox">
      <div class="statTitle">Savaitės geriausi TOP10 (vietų taškai)</div>
      <?php render_table(top10($wkPoints), $usernames); ?>
      <div class="hint" style="opacity:.7;font-size:12px;margin-top:6px;">
        Skaičiavimas: POP+Puol+Gyn+Plėš (1vt=10 … 10vt=1). Puol/Gyn bus įjungta kai pridėsim kovų logą.
      </div>
    </div>

    <div class="statBox">
      <div class="statTitle">Žaidimo geriausi TOP10 (kaupti)</div>
      <?php render_table(top10($life), $usernames); ?>
      <div class="hint" style="opacity:.7;font-size:12px;margin-top:6px;">
        Čia sumuojami praėjusių savaičių "overall" taškai iš weekly_rewards_log + šios savaitės taškai realiu laiku.
      </div>
    </div>
  </div>
</div>

<style>
.statsGrid{display:grid;gap:14px;padding:12px;}
@media(min-width:900px){.statsGrid{grid-template-columns:repeat(2,minmax(0,1fr));}}
.statBox{background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:12px;}
.statTitle{font-weight:800;letter-spacing:.5px;margin-bottom:8px;color:#f5d66f;}
.statTable{width:100%;border-collapse:collapse;font-size:14px;}
.statTable th,.statTable td{padding:6px 4px;border-bottom:1px solid rgba(255,255,255,.06);}
.statTable th{text-align:left;opacity:.8;font-weight:700;}
.statTable td:last-child{text-align:right;font-variant-numeric:tabular-nums;}
</style>

</body>
</html>
