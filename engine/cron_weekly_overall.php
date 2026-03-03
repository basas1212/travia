<?php
require_once __DIR__ . '/../init.php';

$db = $mysqli;
$weekStart = date('Y-m-d', strtotime('monday this week'));
$prevWeekStart = date('Y-m-d', strtotime($weekStart . ' -7 days'));

// If already finalized, stop
$chk = $db->prepare("SELECT 1 FROM weekly_rewards_log WHERE week_start=? AND category='overall' LIMIT 1");
$chk->bind_param('s', $prevWeekStart);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) { echo "Already finalized\n"; exit; }

// NOTE: This script assumes you run it on Monday shortly after 00:00.
// It recomputes previous week's loot ranks from troop_movements and uses weekly_snapshots for pop/atk/def if you later fill them.
// Right now only loot + pop (live pop) are available; atk/def placeholders are 0.

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
  $res = $db->query("SELECT id FROM users");
  while ($r = $res->fetch_assoc()) $rows[] = (int)$r['id'];
  return $rows;
}

function get_pop_totals(mysqli $db, array $uids): array {
  $totals = [];
  foreach ($uids as $uid) {
    $vres = $db->query("SELECT id FROM villages WHERE user_id=".(int)$uid);
    $sum = 0;
    while ($v = $vres->fetch_assoc()) $sum += (int)village_population($db, (int)$v['id']);
    $totals[$uid] = $sum;
  }
  arsort($totals);
  return $totals;
}

function get_loot_totals(mysqli $db, string $from, string $to): array {
  $sql = "SELECT v.user_id, tm.loot_json
          FROM troop_movements tm
          JOIN villages v ON v.id = tm.from_village_id
          WHERE tm.state='done'
            AND tm.move_type IN ('raid','attack')
            AND tm.loot_json IS NOT NULL
            AND tm.created_at >= '".$db->real_escape_string($from)."'
            AND tm.created_at <  '".$db->real_escape_string($to)."'";
  $res = $db->query($sql);
  $totals = [];
  while ($r = $res->fetch_assoc()) {
    $uid = (int)$r['user_id'];
    $totals[$uid] = ($totals[$uid] ?? 0) + _sum_loot_json($r['loot_json']);
  }
  arsort($totals);
  return $totals;
}

function top10_ids(array $totals): array { return array_slice(array_keys($totals), 0, 10); }

function add_rank_points(array $rankIds, array &$points): void {
  $p=10;
  foreach ($rankIds as $uid) {
    if ($p<=0) break;
    $points[$uid] = ($points[$uid] ?? 0) + $p;
    $p--;
  }
}

$uids = get_users_basic($db);
$pop = get_pop_totals($db, $uids);
$loot = get_loot_totals($db, $prevWeekStart." 00:00:00", $weekStart." 00:00:00");

$points = [];
add_rank_points(top10_ids($pop), $points);
// atk/def points can be added later when weekly_snapshots is filled
add_rank_points(top10_ids($loot), $points);

arsort($points);

// Insert into weekly_rewards_log as category overall
$ins = $db->prepare("INSERT INTO weekly_rewards_log (week_start, category, user_id, rank_pos, points, reward_gold) VALUES (?,?,?,?,?,0)");
$rank=1;
foreach ($points as $uid => $pts) {
  if ($rank>10) break;
  $ins->bind_param('ssiii', $prevWeekStart, $cat='overall', $uid, $rank, $pts);
  $ins->execute();
  $rank++;
}
echo "Finalized overall points for week ".$prevWeekStart."\n";
