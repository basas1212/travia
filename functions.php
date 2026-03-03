<?php

// --- Timezone fix (LT) ---
@date_default_timezone_set('Europe/Vilnius');
@ini_set('date.timezone', 'Europe/Vilnius');

function ensure_db_timezone(mysqli $db): void {
    static $done = false;
    if ($done) return;
    // Align MySQL session timezone with PHP (Europe/Vilnius). Prevents +2h drift in build/train timers.
    @$db->query("SET time_zone = '+02:00'");
    $done = true;
}

// functions.php - game helpers (safe, minimal)
require_once __DIR__ . '/unit_research_helpers.php';

/**
 * Ensure a current village is selected in session.
 * Returns village row.
 */
function current_village(mysqli $db, int $userId) : ?array {
  ensure_db_timezone($db);
  $vid = isset($_SESSION['village_id']) ? (int)$_SESSION['village_id'] : 0;

  if ($vid > 0) {
    $stmt = $db->prepare('SELECT * FROM villages WHERE id=? AND user_id=? LIMIT 1');
    $stmt->bind_param('ii', $vid, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $v = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($v) return $v;
  }

  // fallback: first village
  $stmt = $db->prepare('SELECT * FROM villages WHERE user_id=? ORDER BY id ASC LIMIT 1');
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $v = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  if ($v) {
    $_SESSION['village_id'] = (int)$v['id'];
  }
  return $v ?: null;
}

function set_current_village(int $villageId) : void {
  $_SESSION['village_id'] = (int)$villageId;
}

/* =========================
   ✅ Greitis (X1, X3, X10...) + Pagrindinio pastato bonusas
   - Greitis valdomas per TRAVIA_SPEED (config.php / ENV)
   - Pagrindinis pastatas trumpina statybų laiką.
========================= */
function game_speed() : float {
  // Accept numeric or strings like "x100", "100x", "speed=50".
  $raw = defined('TRAVIA_SPEED') ? TRAVIA_SPEED : 1.0;
  if (is_string($raw)) {
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $raw, $m)) {
      $raw = $m[1];
    }
  }
  $s = (float)$raw;
  if ($s <= 0) $s = 1.0;
  return max(1.0, min(1000.0, $s));
}

// Backward-compat alias (older code/patches referenced server_speed()).
function server_speed() : float { return game_speed(); }

function storage_mult(): float {
  $s = game_speed();
  return ($s > 0.0) ? $s : 1.0;
}

function effective_warehouse_cap(array $village): int {
  $base = (int)($village['warehouse'] ?? 0);
  $s = storage_mult();
  if ($base < 0) $base = 0;
  if ($s <= 0.0) $s = 1.0;
  return (int)floor($base * $s);
}

function effective_granary_cap(array $village): int {
  $base = (int)($village['granary'] ?? 0);
  $s = storage_mult();
  if ($base < 0) $base = 0;
  if ($s <= 0.0) $s = 1.0;
  return (int)floor($base * $s);
}

function main_building_level(mysqli $db, int $villageId) : int {
  $stmt = $db->prepare("SELECT MAX(level) AS lvl FROM village_buildings WHERE village_id=? AND type='main_building'");
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return (int)($row['lvl'] ?? 0);
}

function main_building_time_factor(int $level) : float {
  // Travian-like: each level gives diminishing speed-up.
  // Multiplier = 1 / (1 + level * 0.05)
  // lvl0 => 1.00, lvl10 => ~0.67, lvl20 => 0.50
  if ($level <= 0) return 1.0;

  $perLevel = defined('TRAVIA_MB_PER_LEVEL') ? (float)TRAVIA_MB_PER_LEVEL : 0.05;
  if ($perLevel <= 0) $perLevel = 0.05;

  $factor = 1.0 / (1.0 + ($level * $perLevel));

  // Safety clamp: never faster than 20% of base time
  return max(0.20, (float)$factor);
}

function effective_build_seconds(mysqli $db, int $villageId, int $baseSeconds) : int {
  $speed = game_speed();
  $mb = main_building_level($db, $villageId);
  $factor = main_building_time_factor($mb);
  $sec = (int)round(($baseSeconds * $factor) / $speed);
  // For x100+ servers upgrades should be very quick; keep minimum 3s.
  return max(3, $sec);
}

// --- Production tables (per hour) ---
function production_by_level(int $level) : int {
  // Simple Travian-like curve (good enough for stable core)
  static $tbl = [
    0,
    2,5,9,15,22,33,50,70,100,145,
    200,280,390,540,750,1030,1420,1950,2700,3730
  ];
  if ($level < 0) $level = 0;
  if ($level >= count($tbl)) {
    // Extend smoothly after 20
    $base = $tbl[count($tbl)-1];
    $extra = $level - (count($tbl)-1);
    return (int)round($base * (1.30 ** $extra));
  }
  return $tbl[$level];
}

function village_production(mysqli $db, int $villageId) : array {
  // Bazinė gamyba iš resursų laukų (per valandą, greitis pritaikomas pabaigoje)
  $stmt = $db->prepare('SELECT type, level FROM resource_fields WHERE village_id=?');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();

  $p = ['wood'=>0,'clay'=>0,'iron'=>0,'crop'=>0];
  while ($row = $res->fetch_assoc()) {
    $type = (string)$row['type'];
    $lvl = (int)$row['level'];
    $val = production_by_level($lvl);
    if (isset($p[$type])) $p[$type] += $val;
  }
  $stmt->close();

  // === Miesto bonus pastatai (max lvl 5) ===
  // Pjūklinė / Molio duobė / Geležies liejykla: +5% per lygį atitinkamam resursui
  // Malūnas: +5% per lygį javams
  // Kepykla: +5% per lygį javams
  $levels = [
    'sawmill' => 0,
    'brickyard' => 0,
    'iron_foundry' => 0,
    'grain_mill' => 0,
    'bakery' => 0,
  ];
  $stmt = $db->prepare("SELECT type, MAX(level) AS lvl FROM village_buildings WHERE village_id=? AND type IN ('sawmill','brickyard','iron_foundry','grain_mill','bakery') GROUP BY type");
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $t = (string)$row['type'];
    $levels[$t] = (int)$row['lvl'];
  }
  $stmt->close();

  $pctPerLevel = 0.05; // 5% per lygį
  $woodFactor = 1.0 + max(0, min(5, $levels['sawmill'])) * $pctPerLevel;
  $clayFactor = 1.0 + max(0, min(5, $levels['brickyard'])) * $pctPerLevel;
  $ironFactor = 1.0 + max(0, min(5, $levels['iron_foundry'])) * $pctPerLevel;

  $millFactor = 1.0 + max(0, min(5, $levels['grain_mill'])) * $pctPerLevel;
  $bakeryFactor = 1.0 + max(0, min(5, $levels['bakery'])) * $pctPerLevel;

  $cropBonus = village_crop_bonus_factor($db, $villageId); // oazė ir pan.
  $cropFactor = $millFactor * $bakeryFactor * $cropBonus;

  $p['wood'] = (int)round($p['wood'] * $woodFactor);
  $p['clay'] = (int)round($p['clay'] * $clayFactor);
  $p['iron'] = (int)round($p['iron'] * $ironFactor);
  $p['crop'] = (int)round($p['crop'] * $cropFactor);

  // === Javų suvartojimas (per valandą) ===
  // MVP: pagal populiaciją. Vėliau pridėsim kariuomenę + pastiprinimus.
  $cons = village_crop_consumption_per_hour($db, $villageId);
  $p['crop'] = (int)$p['crop'] - (int)$cons; // gali būti neigiamas


  // === Herojaus bonus (MVP) ===
  // stat_production: +1% visai gamybai už tašką
  if (function_exists('table_exists') && table_exists($db, 'heroes')) {
    $stH = $db->prepare('SELECT user_id FROM villages WHERE id=? LIMIT 1');
    $stH->bind_param('i', $villageId);
    $stH->execute();
    $vr = $stH->get_result()->fetch_assoc();
    $stH->close();
    $uid = (int)($vr['user_id'] ?? 0);
    if ($uid > 0) {
      $stH2 = $db->prepare('SELECT stat_production FROM heroes WHERE user_id=? LIMIT 1');
      $stH2->bind_param('i', $uid);
      $stH2->execute();
      $hr = $stH2->get_result()->fetch_assoc();
      $stH2->close();
      $sp = (int)($hr['stat_production'] ?? 0);
      if ($sp > 0) {
        $f = 1.0 + ($sp / 100.0);
        foreach ($p as $k => $v) $p[$k] = (int)round($v * $f);
      }
    }
  }

  // === Greitis (X1, X3, X10...) ===
  $speed = game_speed();
  foreach ($p as $k => $v) {
    $p[$k] = (int)round($v * $speed);
  }
  return $p;
}

// =========================
// SCHEMA (best-effort)
// =========================

function column_exists(mysqli $db, string $table, string $column) : bool {
  // Kai kuriose MariaDB/MySQL versijose `SHOW COLUMNS ... LIKE ?` su placeholder'iu
  // meta SQL sintaksės klaidą. Naudojam information_schema – stabilu.
	// SVARBU: nenaudojam "\\" eilučių tęsimo SQL string'uose – MariaDB tai laiko simboliu ir gaunasi sintaksės klaida.
	$stmt = $db->prepare(
	  "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1"
	);
  if (!$stmt) return false;
  $stmt->bind_param("ss", $table, $column);
  $stmt->execute();
  $res = $stmt->get_result();
  $ok = ($res && $res->num_rows > 0);
  $stmt->close();
  return $ok;
}

function table_exists(mysqli $db, string $table) : bool {
  // MariaDB/MySQL ne visada leidžia placeholders su `SHOW TABLES ...`.
  // Naudojam information_schema – stabilu ir saugu.
  $stmt = $db->prepare(
    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
  );
  if (!$stmt) {
    return false;
  }
  $stmt->bind_param("s", $table);
  $stmt->execute();
  $res = $stmt->get_result();
  $ok = ($res && $res->num_rows > 0);
  $stmt->close();
  return $ok;
}

function ensure_game_schema(mysqli $db) : void {
  // Saugios DB papildymo migracijos (idempotent)
  if (table_exists($db, 'users')) {
    if (!column_exists($db, 'users', 'premium_until')) {
      $db->query("ALTER TABLE users ADD COLUMN premium_until DATETIME DEFAULT NULL");
    }
    if (!column_exists($db, 'users', 'is_admin')) {
      $db->query("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
    }
  }

  if (table_exists($db, 'villages')) {
    if (!column_exists($db, 'villages', 'extra_city_slots')) {
      $db->query("ALTER TABLE villages ADD COLUMN extra_city_slots INT NOT NULL DEFAULT 0");
    }
    // koordinatems (jei senesne DB)
    if (!column_exists($db, 'villages', 'x')) {
      $db->query("ALTER TABLE villages ADD COLUMN x INT NOT NULL DEFAULT 0");
    }
    if (!column_exists($db, 'villages', 'y')) {
      $db->query("ALTER TABLE villages ADD COLUMN y INT NOT NULL DEFAULT 0");
    }
  }

  if (!table_exists($db, 'admin_logs')) {
    $db->query("CREATE TABLE IF NOT EXISTS admin_logs (\
      id INT AUTO_INCREMENT PRIMARY KEY,\
      admin_user_id INT NOT NULL,\
      action VARCHAR(64) NOT NULL,\
      payload TEXT NULL,\
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\
      INDEX(admin_user_id),\
      INDEX(created_at)\
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}


function is_premium(array $user) : bool {
  $ts = (string)($user['premium_until'] ?? '');
  if ($ts === '') return false;
  $t = strtotime($ts);
  return $t > time();
}

function build_queue_limit(array $user) : int {
  // ✅ 2 statybos vienu metu, su premium 3
  return is_premium($user) ? 3 : 2;
}


// =========================
// POPULATION + CROP BALANCE
// =========================
function village_population(mysqli $db, int $villageId) : int {
  $pop = 0;
  foreach (village_fields($db, $villageId) as $f) {
    $lvl = (int)$f['level'];
    $pop += $lvl; // MVP: 1 pop už lygį
  }
  foreach (village_buildings($db, $villageId) as $b) {
    $lvl = (int)$b['level'];
    if ($lvl > 0) $pop += $lvl; // MVP: 1 pop už lygį
  }
  return $pop;
}

// Grūdų suvartojimas (MVP): populiacija + (vėliau) kariuomenė
function village_crop_upkeep(mysqli $db, int $villageId): int {
  $pop = village_population($db, $villageId);
  return max(0, (int)$pop);
}



function village_crop_consumption_per_hour(mysqli $db, int $villageId) : int {
  $pop = village_population($db, $villageId);

  // + kariuomenės javų suvartojimas (per val., MVP)
  $upkeepMap = unit_upkeep_map();
  $troopCrop = 0;
  $stmt = $db->prepare('SELECT unit, amount FROM village_troops WHERE village_id=?');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $unit = (string)$row['unit'];
    $amt  = (int)$row['amount'];
    if ($amt <= 0) continue;
    $u = (int)($upkeepMap[$unit] ?? 1);
    $troopCrop += $u * $amt;
  }
  $stmt->close();

  // IMPORTANT: return *base* consumption per hour at x1.
  // Server speed is applied once in village_production_per_hour_map().
  $base = (int)($pop + $troopCrop);
  return (int)max(0, $base);
}

function village_crop_bonus_factor(mysqli $db, int $villageId) : float {
  $v = village_row($db, $villageId);
  if (!$v) return 1.0;
  $pct = isset($v['oasis_crop_bonus_pct']) ? (int)$v['oasis_crop_bonus_pct'] : 0;
  return 1.0 + max(0, $pct) / 100.0;
}




// =========================
// WORLD MAP / KOORDINATĖS
// =========================

/**
 * Užtikrina, kad world_tiles turėtų įrašą (x,y). Jei nėra – sukuria.
 */
function ensure_world_tile(mysqli $db, int $x, int $y) : void {
  $stmt = $db->prepare("INSERT IGNORE INTO world_tiles (x,y,tile_type) VALUES (?,?, 'empty')");
  $stmt->bind_param('ii', $x, $y);
  $stmt->execute();
  $stmt->close();
}

/**
 * Pažymi (x,y) kaip užimtą konkrečiu kaimu.
 */
function occupy_world_tile(mysqli $db, int $x, int $y, int $villageId) : void {
  // jei lentelės nėra – tiesiog nieko nedarom
  $hasWorld = @$db->query("SHOW TABLES LIKE 'world_tiles'");
  if (!$hasWorld || $hasWorld->num_rows === 0) return;

  ensure_world_tile($db, $x, $y);
  $stmt = $db->prepare("UPDATE world_tiles SET tile_type='village', occupied_village_id=? WHERE x=? AND y=?");
  $stmt->bind_param('iii', $villageId, $x, $y);
  $stmt->execute();
  $stmt->close();
}

/**
 * Randa laisvas startines koordinates.
 * Taisyklė: negalima dubliuoti kaimo koordinačių.
 */
function allocate_start_coordinates(mysqli $db, int $maxRadius = 75) : array {
  $x = 0; $y = 0;
  $dx = 0; $dy = -1;
  $steps = (2*$maxRadius + 1) * (2*$maxRadius + 1);

  $hasWorld = @$db->query("SHOW TABLES LIKE 'world_tiles'");
  $useWorld = (bool)($hasWorld && $hasWorld->num_rows > 0);

  for ($i = 0; $i < $steps; $i++) {
    if (abs($x) <= $maxRadius && abs($y) <= $maxRadius) {
      $stmt = $db->prepare('SELECT id FROM villages WHERE x=? AND y=? LIMIT 1');
      $stmt->bind_param('ii', $x, $y);
      $stmt->execute();
      $res = $stmt->get_result();
      $busy = $res && $res->fetch_assoc();
      $stmt->close();

      if (!$busy) {
        if ($useWorld) {
          ensure_world_tile($db, $x, $y);
          $stmt = $db->prepare('SELECT occupied_village_id FROM world_tiles WHERE x=? AND y=? LIMIT 1');
          $stmt->bind_param('ii', $x, $y);
          $stmt->execute();
          $r = $stmt->get_result();
          $row = $r ? $r->fetch_assoc() : null;
          $stmt->close();
          if (!$row || empty($row['occupied_village_id'])) {
            return ['x'=>$x,'y'=>$y];
          }
        } else {
          return ['x'=>$x,'y'=>$y];
        }
      }
    }

    if ($x == $y || ($x < 0 && $x == -$y) || ($x > 0 && $x == 1 - $y)) {
      $tmp = $dx; $dx = -$dy; $dy = $tmp;
    }
    $x += $dx; $y += $dy;
  }

  // fallback
  return ['x'=>random_int(-200,200), 'y'=>random_int(-200,200)];
}


/**
 * Tick resources based on last_update and resource field production.
 */
function update_village_resources(mysqli $db, int $villageId) : void {
  // lock row for consistency
  $stmt = $db->prepare('SELECT user_id, wood, clay, iron, crop, warehouse, granary, last_update FROM villages WHERE id=? LIMIT 1');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $v = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$v) return;

  $last = strtotime($v['last_update'] ?? '');
  if ($last <= 0) $last = time();
  $now = time();
  $elapsed = $now - $last;
  if ($elapsed < 1) return;

  $prod = village_production($db, $villageId); // per hour

  $wood = (int)$v['wood'];
  $clay = (int)$v['clay'];
  $iron = (int)$v['iron'];
  $crop = (int)$v['crop'];

  $warehouse = max(0, effective_warehouse_cap($v));
  $granary = max(0, effective_granary_cap($v));

  $delta = function(int $perHour, int $seconds) : int {
    $raw = ($perHour * $seconds) / 3600.0;
    if ($raw >= 0) return (int)floor($raw);
    return -(int)floor(abs($raw));
  };

  $wood += $delta((int)$prod['wood'], $elapsed);
  $clay += $delta((int)$prod['clay'], $elapsed);
  $iron += $delta((int)$prod['iron'], $elapsed);
  $crop += $delta((int)$prod['crop'], $elapsed);

  if ($wood > $warehouse) $wood = $warehouse;
  if ($clay > $warehouse) $clay = $warehouse;
  if ($iron > $warehouse) $iron = $warehouse;
  if ($crop > $granary) $crop = $granary;
  if ($crop < 0) $crop = 0;

  // Culture points (simple): based on population over time
  $pop = village_population($db, $villageId);
  $cpAdd = (int)floor(max(0,$pop) * $elapsed / 36000); // ~pop/10 per hour
  if ($cpAdd > 0 && !empty($v['user_id'])) {
    @$db->query('UPDATE users SET culture_points = COALESCE(culture_points,0) + '.(int)$cpAdd.' WHERE id='.(int)$v['user_id']);
  }

  // Pastaba: bada (crop = 0) logiką įdėsim vėliau, kai pilnai sutvarkysim kariuomenės balansą.

  $dt = date('Y-m-d H:i:s', $now);
  $stmt = $db->prepare('UPDATE villages SET wood=?, clay=?, iron=?, crop=?, last_update=? WHERE id=?');
  $stmt->bind_param('iiiisi', $wood, $clay, $iron, $crop, $dt, $villageId);
  $stmt->execute();
  $stmt->close();
}

function village_row(mysqli $db, int $villageId) : ?array {
  $stmt = $db->prepare('SELECT * FROM villages WHERE id=? LIMIT 1');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $v = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $v ?: null;
}

function village_fields(mysqli $db, int $villageId) : array {
  $stmt = $db->prepare('SELECT field_id, type, level FROM resource_fields WHERE village_id=? ORDER BY field_id ASC');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) $out[] = $row;
  $stmt->close();
  return $out;
}

function village_buildings(mysqli $db, int $villageId) : array {
  $stmt = $db->prepare('SELECT slot, type, level FROM village_buildings WHERE village_id=? ORDER BY slot ASC');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) $out[] = $row;
  $stmt->close();
  return $out;
}

// =========================
// BUILDINGS + QUEUE (CITY)
// =========================

/**
 * Minimal building catalog for stable core.
 * You can expand later.
 */
function building_catalog() : array {
  return [
    // Core
    'main_building' => ['name'=>'Gyvenamasis pastatas', 'base_cost'=>['wood'=>70,'clay'=>40,'iron'=>60,'crop'=>20], 'base_time'=>60],
    'warehouse'     => ['name'=>'Sandėlis',             'base_cost'=>['wood'=>130,'clay'=>160,'iron'=>90,'crop'=>40], 'base_time'=>80],
    'granary'       => ['name'=>'Klėtis',               'base_cost'=>['wood'=>80,'clay'=>100,'iron'=>70,'crop'=>20], 'base_time'=>70],
    'hideout'       => ['name'=>'Slėptuvė',             'base_cost'=>['wood'=>40,'clay'=>50,'iron'=>30,'crop'=>10], 'base_time'=>40],
    'residence'     => ['name'=>'Rezidencija',          'base_cost'=>['wood'=>580,'clay'=>460,'iron'=>350,'crop'=>180], 'base_time'=>220],
    'palace'        => ['name'=>'Rūmai',                'base_cost'=>['wood'=>550,'clay'=>800,'iron'=>750,'crop'=>250], 'base_time'=>260],
    'town_hall'     => ['name'=>'Kaimo centras',        'base_cost'=>['wood'=>1250,'clay'=>1110,'iron'=>1260,'crop'=>600], 'base_time'=>420],
    'wall'          => ['name'=>'Siena',                'base_cost'=>['wood'=>70,'clay'=>90,'iron'=>20,'crop'=>10], 'base_time'=>60],

    // Kariniai
    'rally_point'   => ['name'=>'Susirinkimo vieta',    'base_cost'=>['wood'=>110,'clay'=>160,'iron'=>90,'crop'=>70], 'base_time'=>90],
    'barracks'      => ['name'=>'Kareivinės',           'base_cost'=>['wood'=>210,'clay'=>140,'iron'=>260,'crop'=>120], 'base_time'=>120],
    'stable'        => ['name'=>'Arklidės',             'base_cost'=>['wood'=>260,'clay'=>140,'iron'=>220,'crop'=>100], 'base_time'=>140],
    'workshop'      => ['name'=>'Dirbtuvės',            'base_cost'=>['wood'=>460,'clay'=>510,'iron'=>600,'crop'=>320], 'base_time'=>180],
    'academy'       => ['name'=>'Akademija',            'base_cost'=>['wood'=>220,'clay'=>160,'iron'=>90,'crop'=>40], 'base_time'=>120],
    'smithy'        => ['name'=>'Kalvė',                'base_cost'=>['wood'=>180,'clay'=>250,'iron'=>500,'crop'=>160], 'base_time'=>160],
    'armory'        => ['name'=>'Šarvų kalvė',          'base_cost'=>['wood'=>130,'clay'=>210,'iron'=>410,'crop'=>130], 'base_time'=>160],

    // Ekonominiai
    'market'        => ['name'=>'Turgavietė',           'base_cost'=>['wood'=>80,'clay'=>70,'iron'=>120,'crop'=>70], 'base_time'=>90],
    'grain_mill'    => ['name'=>'Malūnas',              'base_cost'=>['wood'=>250,'clay'=>200,'iron'=>150,'crop'=>250], 'base_time'=>180],
    'bakery'        => ['name'=>'Kepykla',              'base_cost'=>['wood'=>1200,'clay'=>1480,'iron'=>870,'crop'=>1600], 'base_time'=>520],
    'iron_foundry'  => ['name'=>'Geležies liejykla',    'base_cost'=>['wood'=>200,'clay'=>450,'iron'=>510,'crop'=>120], 'base_time'=>240],
    'sawmill'       => ['name'=>'Pjūklinė',             'base_cost'=>['wood'=>520,'clay'=>380,'iron'=>290,'crop'=>90], 'base_time'=>240],
    'brickyard'     => ['name'=>'Molio duobė',          'base_cost'=>['wood'=>440,'clay'=>480,'iron'=>320,'crop'=>50], 'base_time'=>240],

    // Specialūs
    'great_warehouse' => ['name'=>'Didysis sandėlis',   'base_cost'=>['wood'=>650,'clay'=>800,'iron'=>450,'crop'=>200], 'base_time'=>260],
    'great_granary'   => ['name'=>'Didžioji klėtis',    'base_cost'=>['wood'=>520,'clay'=>650,'iron'=>400,'crop'=>180], 'base_time'=>260],
    'trapper'         => ['name'=>'Spąstų dirbtuvė',    'base_cost'=>['wood'=>100,'clay'=>100,'iron'=>100,'crop'=>50], 'base_time'=>140],
  ];
}

function building_label(string $type) : string {
  // Prefer i18n dictionary if available: key "b_<type>"
  if (function_exists('t')) {
    $k = 'b_' . $type;
    $v = t($k);
    if ($v !== $k) return $v;
  }
  $cat = building_catalog();
  return $cat[$type]['name'] ?? $type;
}

/**
 * Cost curve: base * 1.6^(level-1)
 */
function building_cost(string $type, int $targetLevel) : array {
  $cat = building_catalog();
  $base = $cat[$type]['base_cost'] ?? ['wood'=>99999,'clay'=>99999,'iron'=>99999,'crop'=>99999];
  $lvl = max(1, $targetLevel);
  $m = ($lvl > 1) ? (1.6 ** ($lvl - 1)) : 1.0;
  return [
    'wood' => (int)round($base['wood'] * $m),
    'clay' => (int)round($base['clay'] * $m),
    'iron' => (int)round($base['iron'] * $m),
    'crop' => (int)round($base['crop'] * $m),
  ];
}

/**
 * Time curve: base_time * 1.45^(level-1)
 */
function building_time_seconds(string $type, int $targetLevel) : int {
  $cat = building_catalog();
  $base = (int)($cat[$type]['base_time'] ?? 120);
  $lvl = max(1, $targetLevel);
  $m = ($lvl > 1) ? (1.45 ** ($lvl - 1)) : 1.0;
  return max(10, (int)round($base * $m));
}

function building_time_seconds_effective(mysqli $db, int $villageId, string $type, int $targetLevel) : int {
  return effective_build_seconds($db, $villageId, building_time_seconds($type, $targetLevel));
}

function storage_capacity(string $type, int $level) : int {
  // Travian-like base capacity for both Warehouse and Granary
  $base = defined('TRAVIA_STORAGE_BASE') ? (int)TRAVIA_STORAGE_BASE : 800;
  if ($base <= 0) $base = 800;

  $growth = defined('TRAVIA_STORAGE_GROWTH') ? (float)TRAVIA_STORAGE_GROWTH : 1.28;
  if ($growth <= 1.0) $growth = 1.28;

  $lvl = max(0, (int)$level);
  $cap = (int)round($base * ($growth ** $lvl));
  return max($base, $cap);
}

function village_buildings_map(mysqli $db, int $villageId) : array {
  $rows = village_buildings($db, $villageId);
  $map = [];
  foreach ($rows as $r) $map[(int)$r['slot']] = $r;
  return $map;
}

function building_level_by_type(mysqli $db, int $villageId, string $type) : int {
  $stmt = $db->prepare('SELECT level FROM village_buildings WHERE village_id=? AND type=? LIMIT 1');
  $stmt->bind_param('is', $villageId, $type);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ? (int)$row['level'] : 0;
}


function village_queue(mysqli $db, int $villageId) : array {
  $stmt = $db->prepare('SELECT * FROM village_queue WHERE village_id=? ORDER BY queue_slot ASC, finish_at ASC, id ASC');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) $out[] = $row;
  $stmt->close();
  return $out;
}

function active_queue_count(mysqli $db, int $villageId) : int {
  $stmt = $db->prepare('SELECT COUNT(*) AS c FROM village_queue WHERE village_id=?');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return (int)($row['c'] ?? 0);
}

function used_queue_slots(mysqli $db, int $villageId) : array {
  $stmt = $db->prepare('SELECT queue_slot FROM village_queue WHERE village_id=?');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $used = [];
  while ($r = $res->fetch_assoc()) $used[(int)$r['queue_slot']] = true;
  $stmt->close();
  return $used;
}

function pick_queue_slot(mysqli $db, int $villageId, int $limit) : int {
  $used = used_queue_slots($db, $villageId);
  for ($i=1; $i<=$limit; $i++) {
    if (empty($used[$i])) return $i;
  }
  return 0;
}

/**
 * Apply finished queue items.
 */
function process_village_queue(mysqli $db, int $villageId) : void {
  $now = date('Y-m-d H:i:s');
  $stmt = $db->prepare('SELECT * FROM village_queue WHERE village_id=? AND finish_at<=? ORDER BY finish_at ASC, id ASC');
  $stmt->bind_param('is', $villageId, $now);
  $stmt->execute();
  $res = $stmt->get_result();
  $done = [];
  while ($row = $res->fetch_assoc()) $done[] = $row;
  $stmt->close();

  foreach ($done as $q) {
    if (($q['action'] ?? '') === 'building') {
      $slot = (int)($q['slot'] ?? 0);
      $type = (string)$q['type'];
      $lvl  = (int)$q['target_level'];
      if ($slot > 0) {
        // Upsert building
        $stmt = $db->prepare('SELECT id FROM village_buildings WHERE village_id=? AND slot=? LIMIT 1');
        $stmt->bind_param('ii', $villageId, $slot);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($existing) {
          $stmt = $db->prepare('UPDATE village_buildings SET type=?, level=? WHERE village_id=? AND slot=?');
          $stmt->bind_param('siii', $type, $lvl, $villageId, $slot);
          $stmt->execute();
          $stmt->close();
        } else {
          $stmt = $db->prepare('INSERT INTO village_buildings (village_id, slot, type, level) VALUES (?,?,?,?)');
          $stmt->bind_param('iisi', $villageId, $slot, $type, $lvl);
          $stmt->execute();
          $stmt->close();
        }

        // Apply special effects (caps)
        if ($type === 'warehouse') {
          $cap = storage_capacity('warehouse', $lvl);
          $stmt = $db->prepare('UPDATE villages SET warehouse=? WHERE id=?');
          $stmt->bind_param('ii', $cap, $villageId);
          $stmt->execute();
          $stmt->close();
        }
        if ($type === 'granary') {
          $cap = storage_capacity('granary', $lvl);
          $stmt = $db->prepare('UPDATE villages SET granary=? WHERE id=?');
          $stmt->bind_param('ii', $cap, $villageId);
          $stmt->execute();
          $stmt->close();
        }
      }
    }


    // === APPLY FIELD ===
    if (($q['action'] ?? '') === 'field') {
      $fieldId = (int)($q['field_id'] ?? 0);
      $lvl  = (int)$q['target_level'];
      if ($fieldId > 0) {
        $stmt = $db->prepare('UPDATE resource_fields SET level=? WHERE village_id=? AND field_id=?');
        $stmt->bind_param('iii', $lvl, $villageId, $fieldId);
        $stmt->execute();
        $stmt->close();
      }
    }

    $qid = (int)$q['id'];
    $stmt = $db->prepare('DELETE FROM village_queue WHERE id=? AND village_id=?');
    $stmt->bind_param('ii', $qid, $villageId);
    $stmt->execute();
    $stmt->close();
  }
}

// =========================
// TRAINING (BARRACKS)
// =========================
function unit_catalog(?string $tribe = null) : array {
  if ($tribe === null || $tribe === "") {
    $tribe = "roman";
    if (isset($GLOBALS["mysqli"])) {
      try {
        $u = current_user($GLOBALS["mysqli"]);
        if (!empty($u["tribe"])) $tribe = (string)$u["tribe"];
      } catch (Throwable $e) { /* ignore */ }
    }
  }

  $tribe = strtolower(trim($tribe));

  // Pastaba: building_type nusako kur treniruojama (barracks/stable/workshop)
  // speed - kelionės greitis (langeliai/val.), MVP naudota judėjimams.
  $all = [
    'roman' => [
      // KAREIVINĖS (pėstininkai)
      'roman_legionierius' => ['name'=>'Legionierius', 'building_type'=>'barracks', 'cost'=>['wood'=>120,'clay'=>100,'iron'=>150,'crop'=>30], 'time'=>70,  'upkeep'=>1, 'speed'=>6],
      'roman_pretorietis'  => ['name'=>'Pretorietis',  'building_type'=>'barracks', 'cost'=>['wood'=>100,'clay'=>130,'iron'=>160,'crop'=>70], 'time'=>90,  'upkeep'=>1, 'speed'=>5],
      'roman_imperatorius' => ['name'=>'Imperatorius', 'building_type'=>'barracks', 'cost'=>['wood'=>150,'clay'=>160,'iron'=>210,'crop'=>80], 'time'=>110, 'upkeep'=>2, 'speed'=>7],
      // ARKLYDĖS (raiteliai)
      'roman_eques_legati' => ['name'=>'Žvalgas raitelis', 'building_type'=>'stable', 'cost'=>['wood'=>140,'clay'=>160,'iron'=>20,'crop'=>40], 'time'=>120, 'upkeep'=>2, 'speed'=>16],
      'roman_eques_imperatoris' => ['name'=>'Imperatoriaus raitelis', 'building_type'=>'stable', 'cost'=>['wood'=>550,'clay'=>440,'iron'=>320,'crop'=>100], 'time'=>180, 'upkeep'=>3, 'speed'=>14],
    ],
    'gaul' => [
      // KAREIVINĖS
      'gaul_falanga'      => ['name'=>'Falanga',      'building_type'=>'barracks', 'cost'=>['wood'=>100,'clay'=>130,'iron'=>55,'crop'=>30],  'time'=>70, 'upkeep'=>1, 'speed'=>7],
      'gaul_kalavijuotis' => ['name'=>'Kalavijuotis', 'building_type'=>'barracks', 'cost'=>['wood'=>140,'clay'=>150,'iron'=>185,'crop'=>60], 'time'=>95, 'upkeep'=>1, 'speed'=>6],
      'gaul_žvalgas'      => ['name'=>'Žvalgas',      'building_type'=>'barracks', 'cost'=>['wood'=>170,'clay'=>150,'iron'=>20,'crop'=>40],  'time'=>85, 'upkeep'=>2, 'speed'=>17],
      // ARKLYDĖS
      'gaul_druidas'      => ['name'=>'Druidas raitelis', 'building_type'=>'stable', 'cost'=>['wood'=>360,'clay'=>330,'iron'=>280,'crop'=>120], 'time'=>190,'upkeep'=>2,'speed'=>16],
      'gaul_heduanas'     => ['name'=>'Heduonas',         'building_type'=>'stable', 'cost'=>['wood'=>500,'clay'=>620,'iron'=>675,'crop'=>170], 'time'=>230,'upkeep'=>3,'speed'=>13],
    ],
    'teuton' => [
      // KAREIVINĖS
      'teuton_kuokininkas' => ['name'=>'Kuokininkas', 'building_type'=>'barracks', 'cost'=>['wood'=>95,'clay'=>75,'iron'=>40,'crop'=>40], 'time'=>65, 'upkeep'=>1, 'speed'=>7],
      'teuton_ieties'      => ['name'=>'Ietininkas',  'building_type'=>'barracks', 'cost'=>['wood'=>145,'clay'=>70,'iron'=>85,'crop'=>40], 'time'=>75, 'upkeep'=>1, 'speed'=>7],
      'teuton_kirvininkas' => ['name'=>'Kirvininkas', 'building_type'=>'barracks', 'cost'=>['wood'=>130,'clay'=>120,'iron'=>170,'crop'=>70], 'time'=>95, 'upkeep'=>2, 'speed'=>6],
      // ARKLYDĖS
      'teuton_skautas'     => ['name'=>'Skautas',  'building_type'=>'stable', 'cost'=>['wood'=>140,'clay'=>160,'iron'=>20,'crop'=>40], 'time'=>120, 'upkeep'=>2, 'speed'=>16],
      'teuton_paladinas'   => ['name'=>'Paladinas','building_type'=>'stable', 'cost'=>['wood'=>370,'clay'=>270,'iron'=>290,'crop'=>75], 'time'=>190, 'upkeep'=>2, 'speed'=>14],
    ],
    'hun' => [
      // KAREIVINĖS
      'hun_klajoklis'   => ['name'=>'Klajoklis',   'building_type'=>'barracks', 'cost'=>['wood'=>110,'clay'=>80,'iron'=>100,'crop'=>60], 'time'=>80,  'upkeep'=>2, 'speed'=>8],
      'hun_lankininkas' => ['name'=>'Lankininkas', 'building_type'=>'barracks', 'cost'=>['wood'=>140,'clay'=>90,'iron'=>120,'crop'=>60], 'time'=>90,  'upkeep'=>2, 'speed'=>9],
      'hun_sargybinis'  => ['name'=>'Sargybinis',  'building_type'=>'barracks', 'cost'=>['wood'=>160,'clay'=>120,'iron'=>180,'crop'=>80], 'time'=>110, 'upkeep'=>2, 'speed'=>7],
      // ARKLYDĖS
      'hun_raitelis_lankininkas' => ['name'=>'Raitas lankininkas', 'building_type'=>'stable', 'cost'=>['wood'=>280,'clay'=>220,'iron'=>200,'crop'=>100], 'time'=>170, 'upkeep'=>2, 'speed'=>15],
      'hun_tarkanas'             => ['name'=>'Tarkanas',          'building_type'=>'stable', 'cost'=>['wood'=>450,'clay'=>515,'iron'=>480,'crop'=>140], 'time'=>220, 'upkeep'=>3, 'speed'=>14],
    ],
    'egyptian' => [
      // KAREIVINĖS
      'egypt_karys'   => ['name'=>'Karys', 'building_type'=>'barracks', 'cost'=>['wood'=>110,'clay'=>120,'iron'=>90,'crop'=>40], 'time'=>75, 'upkeep'=>1, 'speed'=>7],
      'egypt_sargas'  => ['name'=>'Sargas','building_type'=>'barracks', 'cost'=>['wood'=>140,'clay'=>160,'iron'=>120,'crop'=>60], 'time'=>90, 'upkeep'=>1, 'speed'=>6],
      'egypt_puolikas'=> ['name'=>'Puolikas','building_type'=>'barracks','cost'=>['wood'=>170,'clay'=>150,'iron'=>185,'crop'=>60], 'time'=>105,'upkeep'=>2, 'speed'=>6],
      // ARKLYDĖS
      'egypt_skautas' => ['name'=>'Skautas', 'building_type'=>'stable', 'cost'=>['wood'=>140,'clay'=>160,'iron'=>20,'crop'=>40], 'time'=>120, 'upkeep'=>2, 'speed'=>16],
      'egypt_raitelis'=> ['name'=>'Raitelis', 'building_type'=>'stable','cost'=>['wood'=>350,'clay'=>300,'iron'=>280,'crop'=>110], 'time'=>200, 'upkeep'=>2, 'speed'=>14],
    ],
  ];

  if (!isset($all[$tribe])) $tribe = 'roman';
  return $all[$tribe];
}

function unit_catalog_by_building(string $tribe, string $buildingType) : array {
  $buildingType = strtolower(trim($buildingType));
  $units = unit_catalog($tribe);
  $out = [];
  foreach ($units as $k=>$u) {
    $bt = (string)($u['building_type'] ?? 'barracks');
    if ($bt === $buildingType) $out[$k] = $u;
  }
  return $out;
}

function unit_speed(string $tribe, string $unitKey) : int {
  $units = unit_catalog($tribe);
  if (isset($units[$unitKey]['speed'])) return (int)$units[$unitKey]['speed'];
  return 6;
}

function unit_upkeep_map() : array {
  static $map = null;
  if ($map !== null) return $map;
  $map = [];
  foreach (['roman','gaul','teuton','hun','egyptian'] as $tr) {
    foreach (unit_catalog($tr) as $k=>$u) {
      $map[$k] = (int)($u['upkeep'] ?? 1);
    }
  }
  return $map;
}

// =========================
// UNIT RESEARCH (ACADEMY)
// =========================

function seed_unit_research_from_existing_troops(mysqli $db, int $villageId) : void {
  // If a village already has troops (legacy DB), mark those units as researched so UI won't break.
  if (!table_exists($db, 'unit_research')) return;
  $stmt = $db->prepare('SELECT unit FROM village_troops WHERE village_id=? AND amount>0');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $units = [];
  while ($res && ($r = $res->fetch_assoc())) $units[] = (string)$r['unit'];
  $stmt->close();
  if (!$units) return;

  $now = date('Y-m-d H:i:s');
  foreach (array_unique($units) as $u) {
    $s = $db->prepare('INSERT INTO unit_research (village_id, unit, researched, researched_at) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE researched=1, researched_at=IFNULL(researched_at, VALUES(researched_at))');
    $s->bind_param('iss', $villageId, $u, $now);
    $s->execute();
    $s->close();
  }
}

if (!function_exists('is_unit_researched')) {
function is_unit_researched(mysqli $db, int $villageId, string $unitKey) : bool {
  if (!table_exists($db, 'unit_research')) return true; // fallback
  $stmt = $db->prepare('SELECT researched FROM unit_research WHERE village_id=? AND unit=? LIMIT 1');
  $stmt->bind_param('is', $villageId, $unitKey);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return (int)($row['researched'] ?? 0) === 1;
}
}

function researched_units_map(mysqli $db, int $villageId) : array {
  $map = [];
  if (!table_exists($db, 'unit_research')) return $map;
  $stmt = $db->prepare('SELECT unit, researched FROM unit_research WHERE village_id=?');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($res && ($r = $res->fetch_assoc())) {
    $map[(string)$r['unit']] = ((int)$r['researched'] === 1);
  }
  $stmt->close();
  return $map;
}

function research_time_seconds(array $unitDef, int $speed, int $academyLevel) : int {
  // Simple, predictable MVP formula.
  $c = $unitDef['cost'] ?? ['wood'=>0,'clay'=>0,'iron'=>0,'crop'=>0];
  $sum = (int)($c['wood'] + $c['clay'] + $c['iron'] + $c['crop']);
  $base = 120 + (int)ceil($sum / 40); // 120s + scaled by cost
  $base = (int)ceil($base / max(1, $speed));
  $factor = 1.0 / (1.0 + max(0, $academyLevel) * 0.04);
  return max(5, (int)ceil($base * $factor));
}

function can_start_research(mysqli $db, int $villageId) : bool {
  if (!table_exists($db, 'tech_queue')) return false;
  $stmt = $db->prepare("SELECT COUNT(*) c FROM tech_queue WHERE village_id=? AND action='research'");
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return (int)($row['c'] ?? 0) === 0; // 1 research at a time (MVP)
}

function start_unit_research(mysqli $db, int $villageId, string $unitKey, int $seconds) : array {
  if (!table_exists($db, 'tech_queue')) return [false, 'Nėra tech_queue lentelės.'];
  if (!can_start_research($db, $villageId)) return [false, 'Jau vyksta tyrimas.'];
  if (is_unit_researched($db, $villageId, $unitKey)) return [false, 'Jau ištirta.'];

  $now = time();
  $started = date('Y-m-d H:i:s', $now);
  $finish  = date('Y-m-d H:i:s', $now + max(1, $seconds));
  $action = 'research';
  $target = 1;
  $stmt = $db->prepare('INSERT INTO tech_queue (village_id, action, unit, target_level, started_at, finish_at) VALUES (?,?,?,?,?,?)');
  $stmt->bind_param('ississ', $villageId, $action, $unitKey, $target, $started, $finish);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok ? [true, 'OK'] : [false, 'Nepavyko pradėti tyrimo.'];
}

function process_tech_queue(mysqli $db, int $villageId) : void {
  if (!table_exists($db, 'tech_queue') || !table_exists($db, 'unit_research')) return;
  $now = date('Y-m-d H:i:s');
  $stmt = $db->prepare("SELECT * FROM tech_queue WHERE village_id=? AND action='research' AND finish_at<=? ORDER BY finish_at ASC, id ASC");
  $stmt->bind_param('is', $villageId, $now);
  $stmt->execute();
  $res = $stmt->get_result();
  $done = [];
  while ($res && ($r = $res->fetch_assoc())) $done[] = $r;
  $stmt->close();
  if (!$done) return;

  foreach ($done as $q) {
    $unit = (string)$q['unit'];
    $ts = date('Y-m-d H:i:s');
    $s = $db->prepare('INSERT INTO unit_research (village_id, unit, researched, researched_at) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE researched=1, researched_at=VALUES(researched_at)');
    $s->bind_param('iss', $villageId, $unit, $ts);
    $s->execute();
    $s->close();
    $db->query('DELETE FROM tech_queue WHERE id='.(int)$q['id'].' LIMIT 1');
  }
}

function process_training_queue(mysqli $db, int $villageId) : void {
  $now = date('Y-m-d H:i:s');
  $stmt = $db->prepare('SELECT * FROM troop_queue WHERE village_id=? AND finish_at<=? ORDER BY finish_at ASC, id ASC');
  $stmt->bind_param('is', $villageId, $now);
  $stmt->execute();
  $res = $stmt->get_result();
  $done = [];
  while ($r = $res->fetch_assoc()) $done[] = $r;
  $stmt->close();
  if (!$done) return;

  foreach ($done as $q) {
    $unit = (string)$q['unit'];
    $amt = (int)$q['amount'];
    if ($amt <= 0) continue;
    $stmt2 = $db->prepare('INSERT INTO village_troops (village_id, unit, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)');
    $stmt2->bind_param('isi', $villageId, $unit, $amt);
    $stmt2->execute();
    $stmt2->close();
    $db->query('DELETE FROM troop_queue WHERE id='.(int)$q['id'].' LIMIT 1');
  }
}

function training_time_seconds(int $baseSeconds, int $speed, int $mainBuildingLevel) : int {
  $t = max(1, $baseSeconds);
  $t = (int)ceil($t / max(1, $speed));
  $mbPer = defined('TRAVIA_MB_PER_LEVEL') ? (float)TRAVIA_MB_PER_LEVEL : 0.05;
  $factor = 1.0 / (1.0 + max(0,$mainBuildingLevel) * $mbPer);
  $t = (int)ceil($t * $factor);
  return max(1, $t);
}

function get_troops(mysqli $db, int $villageId) : array {
  $out = [];
  $stmt = $db->prepare('SELECT unit, amount FROM village_troops WHERE village_id=?');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) $out[(string)$row['unit']] = (int)$row['amount'];
  $stmt->close();
  return $out;
}


function start_building(mysqli $db, int $villageId, int $slot, string $type) : array {
  $cat = building_catalog();
  if (!isset($cat[$type])) return [false, 'Nežinomas pastatas.'];
  if ($slot < 1 || $slot > 24) return [false, 'Neteisingas slot.'];

  $u = current_user($db);
  $limit = build_queue_limit($u);
  $queueSlot = pick_queue_slot($db, $villageId, $limit);
  if ($queueSlot === 0) return [false, 'Šiuo metu vyksta per daug statybų.'];

  // ✅ Reikalavimai + max level + unikalumas (Travian-style)
  $cfg = is_file(__DIR__ . '/engine/buildings_config.php') ? require __DIR__ . '/engine/buildings_config.php' : [];
  $meta = $cfg[$type] ?? null;
  if (!$meta) return [false, 'Nežinomas pastatas.'];

  update_village_resources($db, $villageId);
  $v = village_row($db, $villageId);
  if (!$v) return [false, 'Village nerastas.'];

  $bmap = village_buildings_map($db, $villageId);
  $curLvl = 0;
  if (isset($bmap[$slot])) {
    $curType = (string)($bmap[$slot]['type'] ?? '');
    if ($curType !== '' && $curType !== 'empty' && $curType !== $type) {
      return [false, 'Slot užimtas kitu pastatu.'];
    }
    $curLvl = (int)$bmap[$slot]['level'];
  }
  $target = $curLvl + 1;

  $maxLevel = (int)($meta['max_level'] ?? 20);
  if ($target > $maxLevel) return [false, 'Maksimalus lygis.'];

  // Unikalus pastatas (statomas 1 kartą) – leidžiam upgrade tik tame pačiame slote
  $isMulti = (bool)($meta['multi'] ?? false);
  if (!$isMulti && $curLvl === 0) {
    $stmtU = $db->prepare('SELECT COUNT(*) AS c FROM village_buildings WHERE village_id=? AND type=? AND level>0');
    $stmtU->bind_param('is', $villageId, $type);
    $stmtU->execute();
    $rU = $stmtU->get_result();
    $rowU = $rU ? $rU->fetch_assoc() : null;
    $stmtU->close();
    if ((int)($rowU['c'] ?? 0) > 0) return [false, 'Šis pastatas jau pastatytas.'];
  }

  // Reikalavimai (Travian-style)
  $requires = $meta['requires'] ?? [];
  if ($requires) {
    foreach ($requires as $rt => $rl) {
      $have = building_level_by_type($db, $villageId, (string)$rt);
      if ($have < (int)$rl) return [false, 'Trūksta reikalavimų.'];
    }
  }

  $cost = building_cost($type, $target);
  if ((int)$v['wood'] < $cost['wood'] || (int)$v['clay'] < $cost['clay'] || (int)$v['iron'] < $cost['iron'] || (int)$v['crop'] < $cost['crop']) {
    return [false, 'Nepakanka resursų.'];
  }

  $newWood = (int)$v['wood'] - $cost['wood'];
  $newClay = (int)$v['clay'] - $cost['clay'];
  $newIron = (int)$v['iron'] - $cost['iron'];
  $newCrop = (int)$v['crop'] - $cost['crop'];

  $stmt = $db->prepare('UPDATE villages SET wood=?, clay=?, iron=?, crop=? WHERE id=?');
  $stmt->bind_param('iiiii', $newWood, $newClay, $newIron, $newCrop, $villageId);
  $stmt->execute();
  $stmt->close();

  $start = time();
  $dur = building_time_seconds_effective($db, $villageId, $type, $target);
  $finish = $start + $dur;

  $startedAt = date('Y-m-d H:i:s', $start);
  $finishAt  = date('Y-m-d H:i:s', $finish);

  $action = 'building';
  $stmt = $db->prepare('INSERT INTO village_queue (village_id, queue_slot, action, type, target_level, started_at, finish_at, cost_wood, cost_clay, cost_iron, cost_crop, slot) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
  $stmt->bind_param('iississiiiii', $villageId, $queueSlot, $action, $type, $target, $startedAt, $finishAt, $cost['wood'], $cost['clay'], $cost['iron'], $cost['crop'], $slot);
  $stmt->execute();
  $stmt->close();

  return [true, 'Statyba pradėta.'];
}

function cancel_queue_item(mysqli $db, int $villageId, int $queueId) : array {
  $stmt = $db->prepare('SELECT * FROM village_queue WHERE id=? AND village_id=? LIMIT 1');
  $stmt->bind_param('ii', $queueId, $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $q = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$q) return [false, 'Queue nerastas.'];

  update_village_resources($db, $villageId);
  $v = village_row($db, $villageId);
  if (!$v) return [false, 'Village nerastas.'];

  $wood = min(effective_warehouse_cap($v), (int)$v['wood'] + (int)$q['cost_wood']);
  $clay = min(effective_warehouse_cap($v), (int)$v['clay'] + (int)$q['cost_clay']);
  $iron = min(effective_warehouse_cap($v), (int)$v['iron'] + (int)$q['cost_iron']);
  $crop = min(effective_granary_cap($v), (int)$v['crop'] + (int)$q['cost_crop']);

  $stmt = $db->prepare('UPDATE villages SET wood=?, clay=?, iron=?, crop=? WHERE id=?');
  $stmt->bind_param('iiiii', $wood, $clay, $iron, $crop, $villageId);
  $stmt->execute();
  $stmt->close();

  $stmt = $db->prepare('DELETE FROM village_queue WHERE id=? AND village_id=?');
  $stmt->bind_param('ii', $queueId, $villageId);
  $stmt->execute();
  $stmt->close();

  return [true, 'Atšaukta.'];
}



// =========================
// FIELDS (RESOURCE UPGRADES)
// =========================

function field_label(string $type) : string {
  // Prefer i18n dictionary if available
  if (function_exists('t')) {
    $k = 'res_' . $type;
    $v = t($k);
    if ($v !== $k) return $v;
  }
  $map = ['wood'=>'Mediena','clay'=>'Molis','iron'=>'Geležis','crop'=>'Grūdai'];
  return $map[$type] ?? $type;
}

/**
 * Field cost curve (next level):
 * base * 1.55^(level-1)
 */
function field_cost(string $type, int $targetLevel) : array {
  // Balanced formula (Step 6)
  // targetLevel starts from 1
  $lvl = max(1, $targetLevel);
  $mul = (1.6 ** ($lvl - 1));
  return [
    'wood' => (int)floor(40 * $mul),
    'clay' => (int)floor(40 * $mul),
    'iron' => (int)floor(40 * $mul),
    'crop' => (int)floor(20 * $mul),
  ];
}

/**
 * Field time curve (seconds): base_time * 1.5^(level-1)
 */
function field_time_seconds(string $type, int $targetLevel) : int {
  // Balanced formula (Step 6)
  $lvl = max(1, $targetLevel);
  $mul = (1.5 ** ($lvl - 1));
  return max(10, (int)floor(60 * $mul));
}

function field_time_seconds_effective(mysqli $db, int $villageId, string $type, int $targetLevel) : int {
  return effective_build_seconds($db, $villageId, field_time_seconds($type, $targetLevel));
}

function start_field_upgrade(mysqli $db, int $villageId, int $fieldId) : array {
  if ($fieldId < 1 || $fieldId > 18) return [false, 'Neteisingas laukas.'];
  // ✅ Bendra statybų eilė: 2 slotai, su premium 3
  $u = current_user($db);
  $limit = build_queue_limit($u);
  $queueSlot = pick_queue_slot($db, $villageId, $limit);
  if ($queueSlot === 0) return [false, 'Šiuo metu vyksta per daug statybų.'];

  update_village_resources($db, $villageId);

  $stmt = $db->prepare('SELECT type, level FROM resource_fields WHERE village_id=? AND field_id=? LIMIT 1');
  $stmt->bind_param('ii', $villageId, $fieldId);
  $stmt->execute();
  $res = $stmt->get_result();
  $f = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$f) return [false, 'Laukas nerastas.'];

  $type = (string)$f['type'];
  $cur = (int)$f['level'];
  $target = $cur + 1;

  $maxLevel = 20;
  if ($target > $maxLevel) return [false, 'Maksimalus lygis.'];

  $v = village_row($db, $villageId);
  if (!$v) return [false, 'Village nerastas.'];

  $cost = field_cost($type, $target);
  if ((int)$v['wood'] < $cost['wood'] || (int)$v['clay'] < $cost['clay'] || (int)$v['iron'] < $cost['iron'] || (int)$v['crop'] < $cost['crop']) {
    return [false, 'Nepakanka resursų.'];
  }

  $newWood = (int)$v['wood'] - $cost['wood'];
  $newClay = (int)$v['clay'] - $cost['clay'];
  $newIron = (int)$v['iron'] - $cost['iron'];
  $newCrop = (int)$v['crop'] - $cost['crop'];

  $stmt = $db->prepare('UPDATE villages SET wood=?, clay=?, iron=?, crop=? WHERE id=?');
  $stmt->bind_param('iiiii', $newWood, $newClay, $newIron, $newCrop, $villageId);
  $stmt->execute();
  $stmt->close();

  $start = time();
  $dur = field_time_seconds_effective($db, $villageId, $type, $target);
  $finish = $start + $dur;

  $startedAt = date('Y-m-d H:i:s', $start);
  $finishAt  = date('Y-m-d H:i:s', $finish);

  $action = 'field';
  $stmt = $db->prepare('INSERT INTO village_queue (village_id, queue_slot, action, type, target_level, started_at, finish_at, cost_wood, cost_clay, cost_iron, cost_crop, field_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
  $stmt->bind_param('iississiiiii', $villageId, $queueSlot, $action, $type, $target, $startedAt, $finishAt, $cost['wood'], $cost['clay'], $cost['iron'], $cost['crop'], $fieldId);
  $stmt->execute();
  $stmt->close();

  return [true, 'Lauko upgrade pradėtas.'];
}

// ===== UI helpers =====
function clamp_percent($current, $capacity, $minVisible = 4){
    $current = (float)$current;
    $capacity = (float)$capacity;
    if ($capacity <= 0) return 0;
    $p = ($current / $capacity) * 100.0;
    if ($p < 0) $p = 0;
    if ($p > 100) $p = 100;
    if ($current > 0 && $p > 0 && $p < $minVisible) $p = $minVisible;
    return $p;
}

function fmt_int($n){
    return number_format((int)$n, 0, '', ' ');
}

function fmt_prod_per_h($p){
    $p = (int)$p;
    if ($p <= 0) return null;
    return '+' . $p . '/h';
}


function fmt_time(int $sec) : string {
  if ($sec < 60) return $sec . 's';
  $m = intdiv($sec, 60);
  $s = $sec % 60;
  return $m . 'm ' . str_pad((string)$s, 2, '0', STR_PAD_LEFT) . 's';
}


// =========================
// MAP + MOVEMENTS (minimal)
// =========================

/**
 * Best-effort table creation. If hosting disallows DDL, we simply skip.
 */
function ensure_movements_table(mysqli $db) : void {
  static $done = false;
  if ($done) return;
  $done = true;

  $sql = "CREATE TABLE IF NOT EXISTS movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    from_village_id INT NOT NULL,
    to_x INT NOT NULL,
    to_y INT NOT NULL,
    action VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    depart_at DATETIME NOT NULL,
    arrive_at DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'traveling',
    INDEX(user_id),
    INDEX(from_village_id),
    INDEX(status),
    INDEX(arrive_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  @$db->query($sql);
}

function movement_speed_tiles_per_hour(string $action) : float {
  // Very simple baseline. Later you'll tie to troops.
  switch ($action) {
    case 'raid': return 10.0;
    case 'attack': return 9.0;
    default: return 9.0;
  }
}

function travel_seconds(int $fromX, int $fromY, int $toX, int $toY, string $action) : int {
  $dx = $toX - $fromX;
  $dy = $toY - $fromY;
  $dist = sqrt(($dx*$dx) + ($dy*$dy));
  $speed = movement_speed_tiles_per_hour($action);
  if ($speed <= 0) $speed = 9.0;
  $hours = $dist / $speed;
  return max(10, (int)round($hours * 3600));
}

function create_movement(mysqli $db, int $userId, int $fromVillageId, int $fromX, int $fromY, int $toX, int $toY, string $action) : array {
  ensure_movements_table($db);
  $action = strtolower(trim($action));
  if (!in_array($action, ['attack','raid'], true)) $action = 'attack';

  if ($toX === $fromX && $toY === $fromY) return [false, 'Negalima siųsti į tą patį tašką.'];

  $dur = travel_seconds($fromX, $fromY, $toX, $toY, $action);
  $now = time();
  $depart = date('Y-m-d H:i:s', $now);
  $arrive = date('Y-m-d H:i:s', $now + $dur);
  $created = $depart;

  $stmt = @$db->prepare('INSERT INTO movements (user_id, from_village_id, to_x, to_y, action, created_at, depart_at, arrive_at, status) VALUES (?,?,?,?,?,?,?,?,\'traveling\')');
  if (!$stmt) {
    return [false, 'Nepavyko sukurti žygio (DB).'];
  }
  $stmt->bind_param('iiiiisss', $userId, $fromVillageId, $toX, $toY, $action, $created, $depart, $arrive);
  $stmt->execute();
  $stmt->close();
  return [true, 'Žygis išsiųstas.'];
}

function process_movements(mysqli $db) : void {
  ensure_movements_table($db);
  // Mark arrived movements
  @$db->query("UPDATE movements SET status='arrived' WHERE status='traveling' AND arrive_at <= NOW()");
}

function user_movements(mysqli $db, int $userId, int $limit = 50) : array {
  ensure_movements_table($db);
  $limit = max(1, min(200, (int)$limit));
  $rows = [];
  $stmt = @$db->prepare('SELECT * FROM movements WHERE user_id=? ORDER BY id DESC LIMIT ' . $limit);
  if (!$stmt) return [];
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $stmt->close();
  return $rows;
}

// =========================================================
// RC_04.2 – Karių judėjimai (troop_movements)
// =========================================================

function ensure_troop_movements_table(mysqli $db) : void {
  // Lentelė jau yra DB, bet saugiai paliekam, kad nelūžtų dev aplinkoje.
  @$db->query("CREATE TABLE IF NOT EXISTS troop_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_village_id INT NOT NULL,
    to_village_id INT NOT NULL,
    to_x INT NULL,
    to_y INT NULL,
    move_type ENUM('attack','raid','reinforce','trade') NOT NULL,
    state ENUM('going','returning','done') NOT NULL DEFAULT 'going',
    units_json TEXT NOT NULL,
    cargo_json TEXT NULL,
    loot_json TEXT NULL,
    arrive_at DATETIME NOT NULL,
    return_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function village_coords(mysqli $db, int $villageId) : ?array {
  $stmt = $db->prepare('SELECT id, x, y, user_id, name FROM villages WHERE id=? LIMIT 1');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$row) return null;
  return [
    'id' => (int)$row['id'],
    'x' => (int)$row['x'],
    'y' => (int)$row['y'],
    'user_id' => (int)$row['user_id'],
    'name' => (string)$row['name'],
  ];
}

function find_village_by_xy(mysqli $db, int $x, int $y) : ?array {
  $stmt = $db->prepare('SELECT id, user_id, name FROM villages WHERE x=? AND y=? LIMIT 1');
  $stmt->bind_param('ii', $x, $y);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$row) return null;
  return ['id'=>(int)$row['id'], 'user_id'=>(int)$row['user_id'], 'name'=>(string)$row['name']];
}

function distance_tiles(int $x1, int $y1, int $x2, int $y2) : float {
  $dx = $x2 - $x1;
  $dy = $y2 - $y1;
  return sqrt($dx*$dx + $dy*$dy);
}

function movement_travel_seconds(mysqli $db, array $units, int $fromVillageId, int $toX, int $toY) : int {
  $from = village_coords($db, $fromVillageId);
  if (!$from) return 3600;
  $dist = max(0.0, distance_tiles((int)$from['x'], (int)$from['y'], $toX, $toY));
  // speed yra langeliai/val., imam lėčiausią vienetą
  $cat = unit_catalog();
  $slow = 999999;
  foreach ($units as $unitKey => $amt) {
    if ((int)$amt <= 0) continue;
    $u = $cat[$unitKey] ?? null;
    $sp = (int)($u['speed'] ?? 6);
    $slow = min($slow, max(1, $sp));
  }
  if ($slow === 999999) $slow = 6;
  $hours = $dist / $slow;
  $sec = (int)ceil($hours * 3600);
  // server speed: faster servers -> shorter travel time
  $spd = game_speed();
  if ($spd > 0) {
    $sec = (int)max(30, ceil($sec / $spd));
  }
  return max(30, $sec);
}

function troops_available(mysqli $db, int $villageId) : array {
  $rows = [];
  $stmt = $db->prepare('SELECT unit, amount FROM village_troops WHERE village_id=?');
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $rows[(string)$r['unit']] = (int)$r['amount'];
  }
  $stmt->close();
  return $rows;
}

function deduct_troops(mysqli $db, int $villageId, array $units) : bool {
  $have = troops_available($db, $villageId);
  foreach ($units as $unit => $amt) {
    $amt = (int)$amt;
    if ($amt <= 0) continue;
    if (($have[$unit] ?? 0) < $amt) return false;
  }
  foreach ($units as $unit => $amt) {
    $amt = (int)$amt;
    if ($amt <= 0) continue;
    $stmt = $db->prepare('UPDATE village_troops SET amount=amount-? WHERE village_id=? AND unit=?');
    $stmt->bind_param('iis', $amt, $villageId, $unit);
    $stmt->execute();
    $stmt->close();
  }
  return true;
}

function add_troops(mysqli $db, int $villageId, array $units) : void {
  foreach ($units as $unit => $amt) {
    $amt = (int)$amt;
    if ($amt <= 0) continue;
    $stmt = $db->prepare('INSERT INTO village_troops (village_id, unit, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)');
    $stmt->bind_param('isi', $villageId, $unit, $amt);
    $stmt->execute();
    $stmt->close();
  }
}

function add_support_troops(mysqli $db, int $toVillageId, int $fromUserId, array $units) : void {
  foreach ($units as $unit => $amt) {
    $amt = (int)$amt;
    if ($amt <= 0) continue;
    $stmt = $db->prepare('INSERT INTO village_support_troops (to_village_id, from_user_id, unit, amount) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)');
    $stmt->bind_param('iisi', $toVillageId, $fromUserId, $unit, $amt);
    $stmt->execute();
    $stmt->close();
  }
}

function create_troop_movement(mysqli $db, int $fromVillageId, int $toVillageId, int $toX, int $toY, string $moveType, array $units) : array {
  ensure_troop_movements_table($db);
  $moveType = in_array($moveType, ['attack','raid','reinforce','trade'], true) ? $moveType : 'raid';

  $filtered = [];
  foreach ($units as $k => $v) {
    $v = (int)$v;
    if ($v > 0) $filtered[$k] = $v;
  }
  if (!$filtered) return [false, 'Neparinkta karių.'];

  $sec = movement_travel_seconds($db, $filtered, $fromVillageId, $toX, $toY);
  $now = time();
  $arriveAt = date('Y-m-d H:i:s', $now + $sec);
  $unitsJson = json_encode($filtered, JSON_UNESCAPED_UNICODE);

  $stmt = $db->prepare("INSERT INTO troop_movements (from_village_id,to_village_id,to_x,to_y,move_type,state,units_json,arrive_at) VALUES (?,?,?,?,?,'going',?,?)");
  $stmt->bind_param('iiiisss', $fromVillageId, $toVillageId, $toX, $toY, $moveType, $unitsJson, $arriveAt);
  $stmt->execute();
  $id = (int)$db->insert_id;
  $stmt->close();
  return [true, $id];
}

function process_due_troop_movements(mysqli $db, int $limit = 30) : void {
  ensure_troop_movements_table($db);
  $limit = max(1, min(200, $limit));

  // Atvykę "going"
  $stmt = $db->prepare('SELECT * FROM troop_movements WHERE state=\'going\' AND arrive_at<=NOW() ORDER BY arrive_at ASC, id ASC LIMIT ' . $limit);
  $stmt->execute();
  $res = $stmt->get_result();
  $arrived = [];
  while ($r = $res->fetch_assoc()) $arrived[] = $r;
  $stmt->close();

  foreach ($arrived as $m) {
    $id = (int)$m['id'];
    $fromVid = (int)$m['from_village_id'];
    $toVid = (int)$m['to_village_id'];
    $type = (string)$m['move_type'];
    $units = json_decode((string)$m['units_json'], true);
    if (!is_array($units)) $units = [];

    if ($type === 'reinforce') {
      $from = village_coords($db, $fromVid);
      $fromUserId = $from ? (int)$from['user_id'] : 0;
      add_support_troops($db, $toVid, $fromUserId, $units);
      $db->query("UPDATE troop_movements SET state='done' WHERE id={$id} LIMIT 1");
      continue;
    }

    // Attack/Raid: vykdome supaprastintą kovą + grobį + ataskaitas

    // Atnaujinam resursus prieš kovą, kad vagystė būtų teisinga
    update_village_resources($db, $toVid);

    $from = village_coords($db, $fromVid);
    $to   = village_coords($db, $toVid);
    $attUserId = $from ? (int)$from['user_id'] : 0;
    $defUserId = $to ? (int)$to['user_id'] : 0;

    $attTribe = 'roman';
    $defTribe = 'roman';

    if ($attUserId > 0) {
      $su = $db->prepare('SELECT tribe FROM users WHERE id=? LIMIT 1');
      $su->bind_param('i', $attUserId);
      $su->execute();
      $ru = $su->get_result();
      if ($urow = ($ru ? $ru->fetch_assoc() : null)) {
        if (!empty($urow['tribe'])) $attTribe = (string)$urow['tribe'];
      }
      $su->close();
    }

    if ($defUserId > 0) {
      $su = $db->prepare('SELECT tribe FROM users WHERE id=? LIMIT 1');
      $su->bind_param('i', $defUserId);
      $su->execute();
      $ru = $su->get_result();
      if ($urow = ($ru ? $ru->fetch_assoc() : null)) {
        if (!empty($urow['tribe'])) $defTribe = (string)$urow['tribe'];
      }
      $su->close();
    }

    // Gynėjų kariai: vietiniai + palaikymai
    $defLocal = get_troops($db, $toVid);
    $defSupports = get_support_troops_grouped($db, $toVid);

    $combat = resolve_combat_simple($units, $attTribe, $defLocal, $defSupports, $defTribe);

    // Pritaikom nuostolius gynėjams
    apply_defender_losses($db, $toVid, $combat['def_losses_local'], $combat['def_losses_supports']);

    // Pritaikom nuostolius puolėjui (siunčiamiems kariams)
    $attSurvivors = $combat['att_survivors'];

    // Grobis (tik jei puolėjas liko gyvas)
    $loot = ['wood'=>0,'clay'=>0,'iron'=>0,'crop'=>0];
    if (total_units($attSurvivors) > 0 && ($type === 'raid' || $type === 'attack')) {
      $cap = calc_carry_capacity($attSurvivors, $attTribe);
      if ($cap > 0) $loot = steal_resources($db, $toVid, $cap);
    }

    // Ataskaitos
    $title = ($type === 'raid') ? 'Reidas' : 'Ataka';
    $body = build_battle_report_body($from, $to, $units, $attSurvivors, $combat, $loot, $type);
    if ($attUserId > 0) create_report($db, $attUserId, 'battle', $title . ' prieš ' . (($to && !empty($to['name'])) ? $to['name'] : ('Kaimas #' . $toVid)), $body);
    if ($defUserId > 0) create_report($db, $defUserId, 'battle', $title . ' iš ' . (($from && !empty($from['name'])) ? $from['name'] : ('Kaimas #' . $fromVid)), $body);

    // Statistikos įrašas (reitingams)
    $atkPts = (int)calc_power((array)($combat['def_losses_total'] ?? []), $defTribe, 'def');
    $defPts = (int)calc_power((array)($combat['att_losses'] ?? []), $attTribe, 'att');
    $plu    = (int)sum_loot($loot);
    if ($attUserId > 0 || $defUserId > 0) {
      log_battle_stats($db, $id, $type, $attUserId, $defUserId, $atkPts, $defPts, $plu);
    }

    if (total_units($attSurvivors) <= 0) {
      // puolėjas žuvo
      $db->query("UPDATE troop_movements SET state='done' WHERE id={$id} LIMIT 1");
      continue;
    }

    // grįžta su išlikusiais + grobiu
    $sec = max(30, (int)(strtotime((string)$m['arrive_at']) - strtotime((string)$m['created_at'])));
    $returnAt = date('Y-m-d H:i:s', time() + $sec);
    $unitsJson = json_encode($attSurvivors, JSON_UNESCAPED_UNICODE);
    $lootJson  = json_encode($loot, JSON_UNESCAPED_UNICODE);

    $stmtU = $db->prepare("UPDATE troop_movements SET state='returning', return_at=?, units_json=?, loot_json=? WHERE id=? LIMIT 1");
    $stmtU->bind_param('sssi', $returnAt, $unitsJson, $lootJson, $id);
    $stmtU->execute();
    $stmtU->close();
  }

  // Grįžtantys
  $stmt = $db->prepare('SELECT * FROM troop_movements WHERE state=\'returning\' AND return_at<=NOW() ORDER BY return_at ASC, id ASC LIMIT ' . $limit);
  $stmt->execute();
  $res = $stmt->get_result();
  $ret = [];
  while ($r = $res->fetch_assoc()) $ret[] = $r;
  $stmt->close();

  foreach ($ret as $m) {
    $id = (int)$m['id'];
    $fromVid = (int)$m['from_village_id'];

    $units = json_decode((string)$m['units_json'], true);
    if (!is_array($units)) $units = [];

    // Pridedam karius atgal
    add_troops($db, $fromVid, $units);

    // Pridedam grobį (jei yra)
    $loot = json_decode((string)$m['loot_json'], true);
    if (is_array($loot) && (int)($loot['wood'] ?? 0) + (int)($loot['clay'] ?? 0) + (int)($loot['iron'] ?? 0) + (int)($loot['crop'] ?? 0) > 0) {
      update_village_resources($db, $fromVid);
      $v = village_row($db, $fromVid);
      if ($v) {
        // Talpos saugomos kaimų lentelėje kaip `warehouse` (resursai) ir `granary` (javai).
        // Senesnėse iteracijose buvo naudoti pavadinimai `warehouse_cap`/`granary_cap` –
        // paliekam suderinamumą per fallback.
        $wcap = (int)($v['warehouse'] ?? $v['warehouse_cap'] ?? 800);
        $gcap = (int)($v['granary'] ?? $v['granary_cap'] ?? 800);
        $wood = min($wcap, (int)$v['wood'] + (int)($loot['wood'] ?? 0));
        $clay = min($wcap, (int)$v['clay'] + (int)($loot['clay'] ?? 0));
        $iron = min($wcap, (int)$v['iron'] + (int)($loot['iron'] ?? 0));
        $crop = min($gcap, (int)$v['crop'] + (int)($loot['crop'] ?? 0));
        $st = $db->prepare('UPDATE villages SET wood=?, clay=?, iron=?, crop=? WHERE id=? LIMIT 1');
        $st->bind_param('iiiii', $wood, $clay, $iron, $crop, $fromVid);
        $st->execute();
        $st->close();
      }
    }

    $db->query("UPDATE troop_movements SET state='done' WHERE id={$id} LIMIT 1");
  }
}

function village_outgoing_movements(mysqli $db, int $villageId, int $limit = 50) : array {
  ensure_troop_movements_table($db);
  $limit = max(1, min(200, (int)$limit));
  $rows = [];
  $stmt = $db->prepare('SELECT * FROM troop_movements WHERE from_village_id=? AND state<>\'done\' ORDER BY created_at DESC LIMIT ' . $limit);
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $stmt->close();
  return $rows;
}

function village_incoming_movements(mysqli $db, int $villageId, int $limit = 50) : array {
  ensure_troop_movements_table($db);
  $limit = max(1, min(200, (int)$limit));
  $rows = [];
  $stmt = $db->prepare('SELECT * FROM troop_movements WHERE to_village_id=? AND state=\'going\' ORDER BY arrive_at ASC LIMIT ' . $limit);
  $stmt->bind_param('i', $villageId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $stmt->close();
  return $rows;
}


// ====== RC04.3 Kovos + ataskaitos (supaprastinta) ======


// =========================================================
// STATISTICS – Battle stats log (for rankings)
// =========================================================

function ensure_battle_stats_table(mysqli $db) : void {
  @$db->query("CREATE TABLE IF NOT EXISTS battle_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    move_id INT NULL,
    move_type VARCHAR(16) NOT NULL DEFAULT 'raid',
    happened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    week_start DATE NOT NULL,
    att_user_id INT NOT NULL DEFAULT 0,
    def_user_id INT NOT NULL DEFAULT 0,
    atk_points BIGINT NOT NULL DEFAULT 0,
    def_points BIGINT NOT NULL DEFAULT 0,
    plunder BIGINT NOT NULL DEFAULT 0,
    INDEX(week_start),
    INDEX(att_user_id),
    INDEX(def_user_id),
    INDEX(happened_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function week_start_date(?int $ts = null) : string {
  // Monday 00:00:00 of current week (server timezone)
  if ($ts === null) $ts = time();
  $dt = new DateTime('@' . $ts);
  $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
  $dow = (int)$dt->format('N'); // 1..7 (Mon..Sun)
  if ($dow > 1) $dt->modify('-' . ($dow - 1) . ' days');
  $dt->setTime(0,0,0);
  return $dt->format('Y-m-d');
}

function sum_loot(array $loot) : int {
  return (int)($loot['wood'] ?? 0) + (int)($loot['clay'] ?? 0) + (int)($loot['iron'] ?? 0) + (int)($loot['crop'] ?? 0);
}

function log_battle_stats(mysqli $db, int $moveId, string $moveType, int $attUserId, int $defUserId, int $atkPts, int $defPts, int $plunder) : void {
  ensure_battle_stats_table($db);
  $ws = week_start_date();
  $stmt = $db->prepare('INSERT INTO battle_stats (move_id, move_type, week_start, att_user_id, def_user_id, atk_points, def_points, plunder) VALUES (?,?,?,?,?,?,?,?)');
  if (!$stmt) return;
  $stmt->bind_param('issiiiii', $moveId, $moveType, $ws, $attUserId, $defUserId, $atkPts, $defPts, $plunder);
  $stmt->execute();
  $stmt->close();
}

function ensure_reports_table(mysqli $db) : void {
  // Jei jau yra DB iš RC02+ – nieko nedarom
  $db->query("CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(user_id),
    INDEX(created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function create_report(mysqli $db, int $userId, string $type, string $title, string $body) : void {
  ensure_reports_table($db);
  $stmt = $db->prepare('INSERT INTO reports (user_id, type, title, body) VALUES (?,?,?,?)');
  $stmt->bind_param('isss', $userId, $type, $title, $body);
  $stmt->execute();
  $stmt->close();
}

function total_units(array $units) : int {
  $sum = 0;
  foreach ($units as $k=>$v) $sum += max(0, (int)$v);
  return $sum;
}

function ensure_support_troops_table(mysqli $db) : void {
  // Legacy helper. Lentelė pas tave jau sukurta (village_support_troops),
  // todėl čia sąmoningai nieko nedarom.
  // Palikta tam, kad senas kodas nekristų su "undefined function".
  return;
}


function get_support_troops_grouped(mysqli $db, int $toVillageId) : array {
  // Grąžina: [from_user_id => [unit_key => amount, ...], ...]
  ensure_support_troops_table($db);

  $rows = [];
  $stmt = $db->prepare('SELECT from_user_id, unit, amount FROM village_support_troops WHERE to_village_id=? AND amount>0');
  $stmt->bind_param('i', $toVillageId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $fromUser = (int)$r['from_user_id'];
    $unit = (string)$r['unit'];
    $amt  = (int)$r['amount'];
    if ($amt <= 0 || $unit === '') continue;
    if (!isset($rows[$fromUser])) $rows[$fromUser] = [];
    $rows[$fromUser][$unit] = ($rows[$fromUser][$unit] ?? 0) + $amt;
  }
  $stmt->close();
  return $rows;
}

function unit_stats(string $tribe, string $unitKey) : array {
  $cat = unit_catalog($tribe);
  if (!isset($cat[$unitKey])) {
    // fallback
    return ['att'=>10,'def'=>10,'carry'=>10];
  }
  $u = $cat[$unitKey];
  // jei senesnė definicija be stats
  $att = (int)($u['att'] ?? 10);
  $def = (int)($u['def'] ?? 10);
  $car = (int)($u['carry'] ?? 10);
  return ['att'=>$att,'def'=>$def,'carry'=>$car];
}

function calc_power(array $units, string $tribe, string $mode='att') : int {
  $sum = 0;
  foreach ($units as $k=>$amt) {
    $a = max(0, (int)$amt);
    if ($a<=0) continue;
    $st = unit_stats($tribe, (string)$k);
    $sum += $a * (int)($st[$mode] ?? 0);
  }
  return $sum;
}

function calc_carry_capacity(array $units, string $tribe) : int {
  $sum = 0;
  foreach ($units as $k=>$amt) {
    $a = max(0, (int)$amt);
    if ($a<=0) continue;
    $st = unit_stats($tribe, (string)$k);
    $sum += $a * (int)($st['carry'] ?? 0);
  }
  return $sum;
}

function scale_losses(array $units, float $lossRatio) : array {
  $out = [];
  foreach ($units as $k=>$amt) {
    $a = max(0, (int)$amt);
    if ($a<=0) { $out[$k]=0; continue; }
    $loss = (int)floor($a * $lossRatio + 0.000001);
    if ($loss > $a) $loss = $a;
    $out[$k] = $loss;
  }
  return $out;
}

function subtract_units(array $units, array $losses) : array {
  $out = $units;
  foreach ($losses as $k=>$loss) {
    $out[$k] = max(0, (int)($out[$k] ?? 0) - max(0,(int)$loss));
    if ($out[$k] === 0) unset($out[$k]);
  }
  return $out;
}

function resolve_combat_simple(array $attUnits, string $attTribe, array $defLocal, array $defSupportsGrouped, string $defTribe) : array {
  // Sujungiame gynėjus bendram skaičiavimui
  $defAll = $defLocal;
  foreach ($defSupportsGrouped as $fromUser=>$u) {
    foreach ($u as $k=>$amt) $defAll[$k] = (int)($defAll[$k] ?? 0) + (int)$amt;
  }

  $attPower = calc_power($attUnits, $attTribe, 'att');
  $defPower = calc_power($defAll, $defTribe, 'def');

  if ($attPower <= 0) {
    return [
      'att_power'=>0,'def_power'=>$defPower,
      'att_survivors'=>[],
      'att_losses'=>$attUnits,
      'def_losses_local'=>[],
      'def_losses_supports'=>[],
      'def_losses_total'=>$defAll,
      'winner'=>'def'
    ];
  }
  if ($defPower <= 0) {
    return [
      'att_power'=>$attPower,'def_power'=>0,
      'att_survivors'=>$attUnits,
      'att_losses'=>[],
      'def_losses_local'=>$defLocal,
      'def_losses_supports'=>$defSupportsGrouped,
      'def_losses_total'=>$defAll,
      'winner'=>'att'
    ];
  }

  // Deterministinė supaprastinta formulė
  $sum = $attPower + $defPower;
  $attLossRatio = min(1.0, $defPower / $sum);
  $defLossRatio = min(1.0, $attPower / $sum);

  $attWins = $attPower > $defPower;

  if ($attWins) {
    // gynėjas žūsta 100%, puolėjas dalinai
    $attLosses = scale_losses($attUnits, $attLossRatio);
    $attSurv = subtract_units($attUnits, $attLosses);

    // visus gynėjus nuimam
    $defLossLocal = $defLocal;
    $defLossSupp  = $defSupportsGrouped;

    return [
      'att_power'=>$attPower,'def_power'=>$defPower,
      'att_survivors'=>$attSurv,
      'att_losses'=>$attLosses,
      'def_losses_local'=>$defLossLocal,
      'def_losses_supports'=>$defLossSupp,
      'def_losses_total'=>$defAll,
      'winner'=>'att'
    ];
  }

  // gynėjas laimi: puolėjas žūsta 100%, gynėjas dalinai
  $defLossesAll = scale_losses($defAll, $defLossRatio);

  // paskirstom gynėjų nuostolius proporcingai tarp vietinių ir palaikymų
  $defLossLocal = [];
  foreach ($defLocal as $k=>$amt) {
    $part = min((int)$amt, (int)($defLossesAll[$k] ?? 0));
    if ($part>0) $defLossLocal[$k] = $part;
  }

  $defLossSupp = [];
  foreach ($defSupportsGrouped as $fromUser=>$u) {
    $lossU = [];
    foreach ($u as $k=>$amt) {
      $remaining = (int)($defLossesAll[$k] ?? 0) - (int)($defLossLocal[$k] ?? 0);
      if ($remaining<=0) continue;
      $take = min((int)$amt, $remaining);
      if ($take>0) {
        $lossU[$k] = $take;
        $defLossesAll[$k] = (int)$defLossesAll[$k] - $take;
      }
    }
    if (!empty($lossU)) $defLossSupp[$fromUser] = $lossU;
  }

  return [
    'att_power'=>$attPower,'def_power'=>$defPower,
    'att_survivors'=>[],
    'att_losses'=>$attUnits,
    'def_losses_local'=>$defLossLocal,
    'def_losses_supports'=>$defLossSupp,
    'def_losses_total'=>$defLossesAll,
    'winner'=>'def'
  ];
}

function apply_defender_losses(mysqli $db, int $villageId, array $lossLocal, array $lossSupports) : void {
  // Vietiniai kariai
  if (!empty($lossLocal)) {
    $current = get_troops($db, $villageId);
    foreach ($lossLocal as $k=>$loss) {
      $current[$k] = max(0, (int)($current[$k] ?? 0) - (int)$loss);
    }
    set_troops($db, $villageId, $current);
  }

  // Palaikymai (village_support_troops: to_village_id, from_user_id, unit, amount)
  if (!empty($lossSupports)) {
    foreach ($lossSupports as $fromUserId => $lossU) {
      $fromUserId = (int)$fromUserId;
      if ($fromUserId <= 0 || !is_array($lossU)) continue;

      foreach ($lossU as $unitKey => $loss) {
        $loss = (int)$loss;
        if ($loss <= 0) continue;
        $unitKey = (string)$unitKey;

        // Atimame nuostolius
        $up = $db->prepare('UPDATE village_support_troops SET amount = GREATEST(0, amount - ?) WHERE to_village_id=? AND from_user_id=? AND unit=?');
        $up->bind_param('iiis', $loss, $villageId, $fromUserId, $unitKey);
        $up->execute();
        $up->close();

        // Išvalom nulius
        $del = $db->prepare('DELETE FROM village_support_troops WHERE to_village_id=? AND from_user_id=? AND unit=? AND amount<=0');
        $del->bind_param('iis', $villageId, $fromUserId, $unitKey);
        $del->execute();
        $del->close();
      }
    }
  }
}

function steal_resources(mysqli $db, int $toVillageId, int $capacity) : array {
  $v = village_row($db, $toVillageId);
  if (!$v) return ['wood'=>0,'clay'=>0,'iron'=>0,'crop'=>0];

  $wood = (int)$v['wood'];
  $clay = (int)$v['clay'];
  $iron = (int)$v['iron'];
  $crop = (int)$v['crop'];

  $take = ['wood'=>0,'clay'=>0,'iron'=>0,'crop'=>0];
  $left = max(0, (int)$capacity);

  foreach (['wood','clay','iron','crop'] as $r) {
    if ($left<=0) break;
    $avail = (int)${$r};
    $t = min($avail, $left);
    $take[$r] = $t;
    ${$r} -= $t;
    $left -= $t;
  }

  $st = $db->prepare('UPDATE villages SET wood=?, clay=?, iron=?, crop=? WHERE id=? LIMIT 1');
  $st->bind_param('iiiii', $wood, $clay, $iron, $crop, $toVillageId);
  $st->execute();
  $st->close();

  return $take;
}

function fmt_units_line(array $units) : string {
  if (empty($units)) return '-';
  $parts = [];
  foreach ($units as $k=>$v) {
    $a = (int)$v;
    if ($a<=0) continue;
    $parts[] = htmlspecialchars((string)$k) . ': ' . $a;
  }
  return empty($parts) ? '-' : implode(', ', $parts);
}

function build_battle_report_body(?array $from, ?array $to, array $attSent, array $attSurvivors, array $combat, array $loot, string $type) : string {
  $fromName = $from && !empty($from['name']) ? (string)$from['name'] : 'Kaimas';
  $toName   = $to && !empty($to['name']) ? (string)$to['name'] : 'Kaimas';

  $winner = ($combat['winner'] ?? 'def') === 'att' ? 'Puolėjas' : 'Gynėjas';
  $lootSum = (int)($loot['wood'] ?? 0) + (int)($loot['clay'] ?? 0) + (int)($loot['iron'] ?? 0) + (int)($loot['crop'] ?? 0);

  $html = '';
  $html .= '<h2>Kovos ataskaita</h2>';
  $html .= '<div><b>Puolėjas:</b> ' . htmlspecialchars($fromName) . '</div>';
  $html .= '<div><b>Gynėjas:</b> ' . htmlspecialchars($toName) . '</div>';
  $html .= '<div><b>Laimėtojas:</b> ' . htmlspecialchars($winner) . '</div>';

  $html .= '<hr>';
  $html .= '<h3>Puolėjo kariai</h3>';
  $html .= '<div><b>Siųsta:</b> ' . fmt_units_line($attSent) . '</div>';
  $html .= '<div><b>Išliko:</b> ' . fmt_units_line($attSurvivors) . '</div>';

  $html .= '<h3>Grobis</h3>';
  if ($lootSum <= 0) {
    $html .= '<div>-</div>';
  } else {
    $html .= '<div>Mediena: ' . (int)($loot['wood'] ?? 0) . ', Molis: ' . (int)($loot['clay'] ?? 0) . ', Geležis: ' . (int)($loot['iron'] ?? 0) . ', Grūdai: ' . (int)($loot['crop'] ?? 0) . '</div>';
  }

  return $html;
}
