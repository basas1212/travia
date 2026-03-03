<?php
// Run weekly (DirectAdmin Cron): Monday 00:05
require_once __DIR__ . '/../init.php';

ensure_battle_stats_table($mysqli);

// Determine previous week start
$now = time();
$wsCurrent = week_start_date($now);
$wsPrevTs = strtotime($wsCurrent) - 7*24*3600;
$wsPrev = week_start_date($wsPrevTs);

// If already finalized, exit
$chk = $mysqli->prepare("SELECT 1 FROM weekly_rewards_log WHERE week_start=? AND category='overall_points' LIMIT 1");
$chk->bind_param('s', $wsPrev);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) { $chk->close(); exit("Already finalized\n"); }
$chk->close();

// Get top10 lists for previous week
function top10_for(mysqli $db, string $metric, string $wsPrev) : array {
  $rows = [];
  if ($metric === 'pop') {
    // Pop snapshot at week end: approximate by current pop at finalize time; for historical accuracy you'd store snapshots.
    $res = $db->query("SELECT u.id AS user_id, COALESCE(SUM(v.population),0) AS val
                       FROM users u LEFT JOIN villages v ON v.user_id=u.id
                       GROUP BY u.id ORDER BY val DESC LIMIT 10");
    if ($res) while ($r=$res->fetch_assoc()) $rows[] = $r;
    return $rows;
  }
  $col = ($metric==='atk') ? 'atk_points' : (($metric==='def') ? 'def_points' : 'plunder');
  $stmt = $db->prepare("SELECT u.id AS user_id, COALESCE(SUM(b.$col),0) AS val
                        FROM users u
                        LEFT JOIN battle_stats b ON b.att_user_id=u.id AND b.week_start=?
                        GROUP BY u.id ORDER BY val DESC LIMIT 10");
  $stmt->bind_param('s', $wsPrev);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r=$res->fetch_assoc()) $rows[]=$r;
  $stmt->close();
  return $rows;
}
function add_points(array &$acc, array $top10) : void {
  $rank=1;
  foreach ($top10 as $r) {
    $uid=(int)$r['user_id'];
    $p=max(0, 11-$rank);
    $acc[$uid]=($acc[$uid]??0)+$p;
    $rank++; if ($rank>10) break;
  }
}

$acc=[];
add_points($acc, top10_for($mysqli,'pop',$wsPrev));
add_points($acc, top10_for($mysqli,'atk',$wsPrev));
add_points($acc, top10_for($mysqli,'def',$wsPrev));
add_points($acc, top10_for($mysqli,'plunder',$wsPrev));

arsort($acc);
$top = array_slice($acc, 0, 10, true);

// Store into weekly_rewards_log as overall_points (points = weekly points, reward_gold=0)
$stmt = $mysqli->prepare("INSERT INTO weekly_rewards_log (week_start, category, user_id, rank_pos, points, reward_gold) VALUES (?,?,?,?,?,0)");
$rank=1;
foreach ($top as $uid=>$pts) {
  $uid=(int)$uid; $pts=(int)$pts;
  $cat='overall_points';
  $stmt->bind_param('ssiii', $wsPrev, $cat, $uid, $rank, $pts);
  $stmt->execute();
  $rank++;
}
$stmt->close();

echo "Finalized $wsPrev\n";
