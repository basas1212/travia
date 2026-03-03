<?php
require_once __DIR__ . '/init.php';
require_login();

$db = db();
$me = current_user($db);
$isAdmin = !empty($me['is_admin']);

if (!$isAdmin) {
  http_response_code(403);
  echo '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>Admin</title><link rel="stylesheet" href="/style.css"></head><body class="appBg">';
  echo '<div class="pageWrap"><div class="card"><h2>Admin only</h2><a class="btn" href="/game/game.php">Atgal</a></div></div></body></html>';
  exit;
}

/** ---------------- Helpers ---------------- */
function tr_fallback(string $key, ?string $fallback=null): string {
  if (function_exists('t')) {
    $v = t($key);
    if ($fallback !== null && $v === $key) return $fallback;
    return $v;
  }
  return $fallback ?? $key;
}
function h2($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function db_is_pdo($db): bool { return ($db instanceof PDO); }

function mysqli_stmt_execute_params(mysqli_stmt $st, array $params): void {
  if (!$params) { $st->execute(); return; }
  $types = '';
  $bind = [];
  foreach ($params as $p) {
    if (is_int($p)) $types .= 'i';
    elseif (is_float($p)) $types .= 'd';
    elseif (is_null($p)) $types .= 's'; // send NULL as string then set to null via bind reference
    else $types .= 's';
    $bind[] = $p;
  }
  // mysqli bind_param requires references
  $refs = [];
  foreach ($bind as $k => $v) $refs[$k] = &$bind[$k];
  array_unshift($refs, $types);
  $st->bind_param(...$refs);
  // Fix NULLs after binding
  foreach ($bind as $k => $v) { if (is_null($v)) { $bind[$k] = null; } }
  $st->execute();
}

function db_scalar($db, string $sql, array $params=[]){
  if (db_is_pdo($db)) {
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
  }
  // mysqli
  $st = $db->prepare($sql);
  if (!$st) return null;
  mysqli_stmt_execute_params($st, $params);
  $res = $st->get_result();
  if ($res) {
    $row = $res->fetch_row();
    return $row ? $row[0] : null;
  }
  // fallback if mysqlnd is missing
  $st->bind_result($val);
  return $st->fetch() ? $val : null;
}

function db_all($db, string $sql, array $params=[]){
  if (db_is_pdo($db)) {
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
  $st = $db->prepare($sql);
  if (!$st) return [];
  mysqli_stmt_execute_params($st, $params);
  $res = $st->get_result();
  if (!$res) return [];
  $out = [];
  while ($row = $res->fetch_assoc()) $out[] = $row;
  return $out;
}

function db_one($db, string $sql, array $params=[]){
  $rows = db_all($db, $sql, $params);
  return $rows[0] ?? null;
}

function table_exists2($db, string $table): bool {
  $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?";
  return (int)db_scalar($db, $sql, [$table]) > 0;
}
function column_exists2($db, string $table, string $col): bool {
  $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  return (int)db_scalar($db, $sql, [$table, $col]) > 0;
}

/** ---------------- CSRF (no dependency) ---------------- */
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    $t = $_SESSION['csrf_token'] ?? '';
    return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'">';
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(): void {
    $ok = isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
    if (!$ok) { http_response_code(400); die('Bad CSRF'); }
  }
}

/** ---------------- Routing ---------------- */
$tab = $_GET['tab'] ?? 'dash';
$action = $_POST['action'] ?? null;

function add_admin_log($db, int $adminId, string $action, string $details=''): void {
  if (!table_exists2($db, 'admin_logs')) return;
  $st = $db->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, ?, ?)");
  $st->execute([$adminId, $action, $details]);
}

function upsert_user_note($db, int $userId, int $adminId, string $note): void {
  if (!table_exists2($db, 'user_admin_notes')) return;
  $st = $db->prepare("INSERT INTO user_admin_notes (user_id, admin_id, note) VALUES (?,?,?)");
  $st->execute([$userId,$adminId,$note]);
}

function log_security_event($db, string $type, ?int $userId, ?string $ip, ?string $ua, ?string $device, string $details=''): void {
  if (!table_exists2($db, 'security_events')) return;
  $st = $db->prepare("INSERT INTO security_events (event_type, user_id, ip, ua_hash, device_hash, details) VALUES (?,?,?,?,?,?)");
  $st->execute([$type, $userId, $ip, $ua, $device, $details]);
}

/** ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
  csrf_verify();
  try {
    if ($action === 'user_set_admin') {
      $uid = (int)($_POST['user_id'] ?? 0);
      $val = (int)($_POST['value'] ?? 0);
      $db->prepare("UPDATE users SET is_admin=? WHERE id=?")->execute([$val, $uid]);
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'user_set_admin', "user_id=$uid val=$val");
      header("Location: admin.php?tab=users&u=".$uid); exit;
    }

    if ($action === 'user_set_gold') {
      $uid = (int)($_POST['user_id'] ?? 0);
      $gold = (int)($_POST['gold'] ?? 0);
      if (column_exists2($db,'users','gold')) {
        $db->prepare("UPDATE users SET gold=? WHERE id=?")->execute([$gold, $uid]);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'user_set_gold', "user_id=$uid gold=$gold");
      header("Location: admin.php?tab=users&u=".$uid); exit;
    }

    if ($action === 'user_add_gold') {
      $uid = (int)($_POST['user_id'] ?? 0);
      $delta = (int)($_POST['delta'] ?? 0);
      if ($uid && $delta !== 0 && column_exists2($db,'users','gold')) {
        $db->prepare("UPDATE users SET gold = GREATEST(0, gold + ?) WHERE id=?")->execute([$delta, $uid]);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'user_add_gold', "user_id=$uid delta=$delta");
      header("Location: admin.php?tab=users&u=".$uid); exit;
    }

    if ($action === 'village_add_resources') {
      $vid = (int)($_POST['village_id'] ?? 0);
      $wood = (int)($_POST['wood'] ?? 0);
      $clay = (int)($_POST['clay'] ?? 0);
      $iron = (int)($_POST['iron'] ?? 0);
      $crop = (int)($_POST['crop'] ?? 0);

      if ($vid && ( $wood!==0 || $clay!==0 || $iron!==0 || $crop!==0 )
          && column_exists2($db,'villages','wood') && column_exists2($db,'villages','clay') && column_exists2($db,'villages','iron') && column_exists2($db,'villages','crop')
          && column_exists2($db,'villages','warehouse') && column_exists2($db,'villages','granary')) {

        $db->prepare("UPDATE villages
          SET wood = LEAST(warehouse, GREATEST(0, wood + ?)),
              clay = LEAST(warehouse, GREATEST(0, clay + ?)),
              iron = LEAST(warehouse, GREATEST(0, iron + ?)),
              crop = LEAST(granary,  GREATEST(0, crop + ?))
          WHERE id=?")->execute([$wood,$clay,$iron,$crop,$vid]);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'village_add_resources', "village_id=$vid w=$wood c=$clay i=$iron cr=$crop");
      header("Location: admin.php?tab=villages&v=".$vid); exit;
    }

    if ($action === 'village_add_troops') {
      $vid = (int)($_POST['village_id'] ?? 0);
      $unit = trim((string)($_POST['unit'] ?? ''));
      $qty  = (int)($_POST['qty'] ?? 0);

      if ($vid && $unit !== '' && $qty !== 0 && table_exists2($db,'village_troops')) {
        // Ensure row exists / increment qty
        $db->prepare("INSERT INTO village_troops (village_id, unit, qty)
          VALUES (?,?,?)
          ON DUPLICATE KEY UPDATE qty = GREATEST(0, qty + VALUES(qty))")->execute([$vid, $unit, $qty]);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'village_add_troops', "village_id=$vid unit=$unit qty=$qty");
      header("Location: admin.php?tab=villages&v=".$vid); exit;
    }


    if ($action === 'user_flag_suspect') {
      $uid = (int)($_POST['user_id'] ?? 0);
      $val = (int)($_POST['value'] ?? 0);
      if (column_exists2($db,'users','suspected_multiacc')) {
        $db->prepare("UPDATE users SET suspected_multiacc=? WHERE id=?")->execute([$val, $uid]);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'user_flag_suspect', "user_id=$uid val=$val");
      header("Location: admin.php?tab=users&u=".$uid); exit;
    }

    if ($action === 'user_note') {
      $uid = (int)($_POST['user_id'] ?? 0);
      $note = trim((string)($_POST['note'] ?? ''));
      if ($note !== '') {
        upsert_user_note($db, $uid, (int)$GLOBALS['me']['id'], $note);
        add_admin_log($db, (int)$GLOBALS['me']['id'], 'user_note', "user_id=$uid note=".mb_substr($note,0,200));
      }
      header("Location: admin.php?tab=users&u=".$uid); exit;
    }

    if ($action === 'user_ban') {
      $uid = (int)($_POST['user_id'] ?? 0);
      $reason = trim((string)($_POST['reason'] ?? ''));
      $days = (int)($_POST['days'] ?? 0);
      $until = $days > 0 ? (new DateTimeImmutable('now'))->modify("+$days days")->format('Y-m-d H:i:s') : null;

      if (column_exists2($db,'users','is_banned')) {
        $cols = [];
        $sql = "UPDATE users SET is_banned=1";
        if (column_exists2($db,'users','ban_reason')) { $sql .= ", ban_reason=?"; $cols[]=$reason; }
        if (column_exists2($db,'users','banned_until')) { $sql .= ", banned_until=?"; $cols[]=$until; }
        if (column_exists2($db,'users','banned_by')) { $sql .= ", banned_by=?"; $cols[]=(int)$GLOBALS['me']['id']; }
        $sql .= " WHERE id=?"; $cols[]=$uid;
        $db->prepare($sql)->execute($cols);
      }
      if (table_exists2($db,'user_bans_history')) {
        $db->prepare("INSERT INTO user_bans_history (user_id, admin_id, action, reason, banned_until) VALUES (?,?,?,?,?)")
          ->execute([$uid,(int)$GLOBALS['me']['id'],'ban',$reason,$until]);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'user_ban', "user_id=$uid days=$days reason=$reason");
      header("Location: admin.php?tab=users&u=".$uid); exit;
    }

    if ($action === 'user_unban') {
      $uid = (int)($_POST['user_id'] ?? 0);
      if (column_exists2($db,'users','is_banned')) {
        $sql = "UPDATE users SET is_banned=0";
        $params = [];
        if (column_exists2($db,'users','ban_reason')) { $sql .= ", ban_reason=NULL"; }
        if (column_exists2($db,'users','banned_until')) { $sql .= ", banned_until=NULL"; }
        if (column_exists2($db,'users','banned_by')) { $sql .= ", banned_by=NULL"; }
        $sql .= " WHERE id=?";
        $db->prepare($sql)->execute([$uid]);
      }
      if (table_exists2($db,'user_bans_history')) {
        $db->prepare("INSERT INTO user_bans_history (user_id, admin_id, action, reason, banned_until) VALUES (?,?,?,?,NULL)")
          ->execute([$uid,(int)$GLOBALS['me']['id'],'unban','']);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'user_unban', "user_id=$uid");
      header("Location: admin.php?tab=users&u=".$uid); exit;
    }

    if ($action === 'ip_ban') {
      $ip = trim((string)($_POST['ip'] ?? ''));
      $reason = trim((string)($_POST['reason'] ?? ''));
      $days = (int)($_POST['days'] ?? 0);
      $expires = $days > 0 ? (new DateTimeImmutable('now'))->modify("+$days days")->format('Y-m-d H:i:s') : null;
      if ($ip !== '' && table_exists2($db,'ip_bans')) {
        $db->prepare("INSERT INTO ip_bans (ip, reason, created_by, expires_at) VALUES (?,?,?,?)")
          ->execute([$ip,$reason,(int)$GLOBALS['me']['id'],$expires]);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'ip_ban', "ip=$ip days=$days reason=$reason");
      header("Location: admin.php?tab=security"); exit;
    }

    if ($action === 'whitelist_add') {
      $ip = trim((string)($_POST['ip'] ?? ''));
      $device = trim((string)($_POST['device_hash'] ?? ''));
      $note = trim((string)($_POST['note'] ?? ''));
      if (table_exists2($db,'security_whitelist')) {
        $db->prepare("INSERT INTO security_whitelist (ip, device_hash, note, created_by) VALUES (?,?,?,?)")
          ->execute([$ip?:null, $device?:null, $note, (int)$GLOBALS['me']['id']]);
      }
      add_admin_log($db, (int)$GLOBALS['me']['id'], 'whitelist_add', "ip=$ip device=$device");
      header("Location: admin.php?tab=security"); exit;
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

/** ---------------- UI helpers ---------------- */
function pill_tab($name, $label, $current){
  $active = $current===$name ? ' tabActive' : '';
  return '<a class="tabBtn'.$active.'" href="admin.php?tab='.urlencode($name).'">'.h2($label).'</a>';
}

$err = $err ?? null;

/** ---------------- Page head ---------------- */
?><!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h2(tr_fallback('admin_panel','VALDYMAS'))?></title>
  <link rel="stylesheet" href="/style.css">
  <style>
    .adminWrap{max-width:1100px;margin:0 auto;padding:14px}
    .adminCard{backdrop-filter: blur(10px); background: rgba(10,12,16,.72); border:1px solid rgba(255,255,255,.08); border-radius:18px; padding:14px;}
    .adminTop{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
    .adminBrand{display:flex;flex-direction:column;gap:2px}
    .adminTitle{font-weight:900;letter-spacing:.12em;color:#d6b15b}
    .adminUser{opacity:.85}
    .tabs{display:flex;flex-wrap:wrap;gap:10px;margin:12px 0}
    .tabBtn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.18);color:#fff;text-decoration:none}
    .tabBtn.tabActive{border-color:rgba(214,177,91,.55);box-shadow:0 0 0 2px rgba(214,177,91,.15) inset}
    .row{display:flex;gap:12px;flex-wrap:wrap}
    .box{flex:1 1 320px;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:12px;background:rgba(0,0,0,.14)}
    .muted{opacity:.75}
    .btn2{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);color:#fff;text-decoration:none;cursor:pointer}
    .btnPrimary{background: linear-gradient(180deg,#ffd36c,#d4a53a); color:#1b1406; font-weight:800; border:0}
    .btnDanger{background: linear-gradient(180deg,#ff6b6b,#b32020); border:0; font-weight:800}
    .inp{width:100%;padding:12px 14px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.20);color:#fff;outline:none}
    .mini{font-size:12px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}
    th{opacity:.75;font-size:12px;letter-spacing:.06em;text-transform:uppercase}
    .badge{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12);font-size:12px}
    .badgeWarn{background:rgba(255,196,0,.12);border-color:rgba(255,196,0,.25)}
    .badgeBad{background:rgba(255,0,0,.12);border-color:rgba(255,0,0,.25)}
    .grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    @media (max-width:720px){.grid2{grid-template-columns:1fr}}
    .hr{height:1px;background:rgba(255,255,255,.08);margin:12px 0}
    .err{background:rgba(255,0,0,.12);border:1px solid rgba(255,0,0,.25);padding:10px 12px;border-radius:14px}
  </style>
</head>
<body class="appBg">
<div class="adminWrap">
  <div class="adminCard">
    <div class="adminTop">
      <div class="adminBrand">
        <div class="adminTitle"><?=h2(tr_fallback('admin_panel','VALDYMAS'))?></div>
        <div class="adminUser"><?=h2($me['username'] ?? '')?></div>
      </div>
      <a class="btn2" href="/game/game.php"><?=h2(tr_fallback('back_to_game','Atgal į žaidimą'))?></a>
    </div>

    <div class="tabs">
      <?=pill_tab('dash','Suvestinė',$tab)?>
      <?=pill_tab('users','Vartotojai',$tab)?>
      <?=pill_tab('villages','Kaimai',$tab)?>
      <?=pill_tab('alliances','Aljansai',$tab)?>
      <?=pill_tab('troops','Kariai',$tab)?>
      <?=pill_tab('queues','Eilės',$tab)?>
      <?=pill_tab('world','Pasaulis',$tab)?>
      <?=pill_tab('economy','Ekonomika',$tab)?>
      <?=pill_tab('chat','Chat',$tab)?>
      <?=pill_tab('security','Saugumas',$tab)?>
      <?=pill_tab('logs','Logai',$tab)?>
      <?=pill_tab('seasons','Sezonai',$tab)?>
    </div>

    <?php if ($err): ?><div class="err"><?=h2($err)?></div><?php endif; ?>

<?php
/** ---------------- Tab content ---------------- */
if ($tab === 'dash') {
  $uCnt = (int)db_scalar($db, "SELECT COUNT(*) FROM users");
  $vCnt = table_exists2($db,'villages') ? (int)db_scalar($db, "SELECT COUNT(*) FROM villages") : 0;
  $aCnt = table_exists2($db,'alliances') ? (int)db_scalar($db, "SELECT COUNT(*) FROM alliances") : 0;
  $susCnt = column_exists2($db,'users','suspected_multiacc') ? (int)db_scalar($db, "SELECT COUNT(*) FROM users WHERE suspected_multiacc=1") : 0;

  echo '<div class="row">';
  echo '<div class="box"><div class="muted">Vartotojai</div><div style="font-size:34px;font-weight:900">'.$uCnt.'</div></div>';
  echo '<div class="box"><div class="muted">Kaimai</div><div style="font-size:34px;font-weight:900">'.$vCnt.'</div></div>';
  echo '<div class="box"><div class="muted">Aljansai</div><div style="font-size:34px;font-weight:900">'.$aCnt.'</div></div>';
  echo '<div class="box"><div class="muted">Įtariami multi</div><div style="font-size:34px;font-weight:900">'.$susCnt.'</div></div>';
  echo '</div>';

  echo '<div class="hr"></div>';

  // DB health
  $need = ['users','villages','world_tiles','village_buildings','village_troops','troop_movements','admin_logs','security_events'];
  $ok = [];
  foreach ($need as $t) $ok[] = $t.'='.(table_exists2($db,$t)?'OK':'—');
  echo '<div class="box"><div class="muted">DB</div><div>'.h2(implode(', ', $ok)).'</div><div class="mini muted">Jei kažkuris modulis nerodo duomenų – greičiausiai lentelės nėra tavo DB (tuomet funkcija automatiškai praleidžiama).</div></div>';

}

if ($tab === 'users') {
  $q = trim((string)($_GET['q'] ?? ''));
  $uid = (int)($_GET['u'] ?? 0);

  echo '<div class="box">';
  echo '<form method="get" class="row" style="align-items:flex-end">';
  echo '<input type="hidden" name="tab" value="users">';
  echo '<div style="flex:1 1 340px"><div class="muted mini">Paieška (username / id / IP / device)</div><input class="inp" name="q" value="'.h2($q).'" placeholder="Pvz: basas, 12, 84.15..., device_hash..."></div>';
  echo '<div><button class="btn2 btnPrimary" type="submit">Ieškoti</button></div>';
  echo '</form>';
  echo '</div>';

  if ($uid > 0) {
    $u = db_one($db, "SELECT * FROM users WHERE id=?", [$uid]);
    if (!$u) { echo '<div class="box">Nerasta</div>'; }
    else {
      echo '<div class="box">';
      echo '<div class="row" style="justify-content:space-between;align-items:center">';
      echo '<div><div style="font-size:22px;font-weight:900">'.h2($u['username']).' <span class="badge">#'.(int)$u['id'].'</span></div>';
      echo '<div class="muted mini">tribe: '.h2($u['tribe'] ?? '—').' • gold: '.h2($u['gold'] ?? '0').' • lang: '.h2($u['lang'] ?? '—').' • created: '.h2($u['created_at'] ?? '').'</div>';
      echo '</div>';
      echo '<a class="btn2" href="admin.php?tab=users&q='.urlencode($q).'">← atgal</a>';
      echo '</div>';

      echo '<div class="hr"></div>';

      echo '<div class="grid2">';
      echo '<div class="box">';
      echo '<div style="font-weight:800;margin-bottom:8px">Teisės</div>';
      echo '<form method="post" class="row" style="gap:10px;align-items:center">';
      echo csrf_field();
      echo '<input type="hidden" name="action" value="user_set_admin">';
      echo '<input type="hidden" name="user_id" value="'.(int)$u['id'].'">';
      $isA = !empty($u['is_admin']);
      echo '<input type="hidden" name="value" value="'.($isA?0:1).'">';
      echo '<button class="btn2 '.($isA?'btnDanger':'btnPrimary').'" type="submit">'.($isA?'Nuimti admin':'Padaryti admin').'</button>';
      echo '</form>';

      if (column_exists2($db,'users','suspected_multiacc')) {
        $sus = !empty($u['suspected_multiacc']);
        echo '<div style="height:10px"></div>';
        echo '<form method="post">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="user_flag_suspect">';
        echo '<input type="hidden" name="user_id" value="'.(int)$u['id'].'">';
        echo '<input type="hidden" name="value" value="'.($sus?0:1).'">';
        echo '<button class="btn2" type="submit">'.($sus?'Nuimti įtarimą':'Pažymėti įtariamu multi').'</button>';
        echo '</form>';
      }

      echo '</div>';

      echo '<div class="box">';
      echo '<div style="font-weight:800;margin-bottom:8px">Valdymas</div>';

      if (column_exists2($db,'users','gold')) {
        echo '<form method="post" class="row" style="align-items:flex-end">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="user_set_gold">';
        echo '<input type="hidden" name="user_id" value="'.(int)$u['id'].'">';
        echo '<div style="flex:1 1 160px"><div class="muted mini">Gold</div><input class="inp" type="number" name="gold" value="'.(int)($u['gold'] ?? 0).'"></div>';
        echo '<div><button class="btn2 btnPrimary" type="submit">Išsaugoti</button></div>';

        echo '</form>';
        echo '<form method="post" class="inlineForm" style="gap:10px;margin-top:10px">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="user_add_gold">';
        echo '<input type="hidden" name="user_id" value="'.(int)$u['id'].'">';
        echo '<div style="flex:1 1 160px"><div class="muted mini">Pridėti / atimti gold</div><input class="inp" type="number" name="delta" value="0" placeholder="pvz. 100"></div>';
        echo '<div><button class="btn2" type="submit">Pridėti</button></div>';
        echo '</form>';
        echo '<div style="height:10px"></div>';

      }

      $hasBanCols = column_exists2($db,'users','is_banned');
      if ($hasBanCols) {
        $banned = !empty($u['is_banned']);
        if ($banned) {
          echo '<div class="badge badgeBad">BAN</div> ';
          if (column_exists2($db,'users','ban_reason')) echo '<span class="muted mini">priežastis: '.h2($u['ban_reason'] ?? '').'</span>';
          echo '<div style="height:10px"></div>';
          echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="user_unban"><input type="hidden" name="user_id" value="'.(int)$u['id'].'"><button class="btn2 btnPrimary" type="submit">Unban</button></form>';
        } else {
          echo '<form method="post" class="grid2" style="align-items:end">';
          echo csrf_field();
          echo '<input type="hidden" name="action" value="user_ban">';
          echo '<input type="hidden" name="user_id" value="'.(int)$u['id'].'">';
          echo '<div><div class="muted mini">Dienų</div><input class="inp" type="number" name="days" value="7"></div>';
          echo '<div><div class="muted mini">Priežastis</div><input class="inp" name="reason" value="multiaccount"></div>';
          echo '<div style="grid-column:1/-1"><button class="btn2 btnDanger" type="submit">BAN</button></div>';
          echo '</form>';
        }
      } else {
        echo '<div class="muted mini">BAN stulpelių users lentelėje nėra – naudojam tik istoriją (user_bans_history) jei reikia.</div>';
      }

      echo '</div>';
      echo '</div>'; // grid2

      echo '<div class="hr"></div>';
      echo '<div class="grid2">';
      echo '<div class="box"><div style="font-weight:800;margin-bottom:6px">IP / device</div>';
      $pairs = [
        ['register_ip','Registracijos IP'],
        ['last_ip','Paskutinis IP'],
        ['register_device_hash','Reg device'],
        ['last_device_hash','Last device'],
        ['register_ua_hash','Reg UA'],
        ['last_ua_hash','Last UA'],
      ];
      foreach ($pairs as [$k,$label]) {
        if (!array_key_exists($k,$u)) continue;
        $val = $u[$k];
        if (!$val) continue;
        echo '<div class="mini"><span class="muted">'.h2($label).':</span> <span style="word-break:break-all">'.h2($val).'</span></div>';
      }
      echo '</div>';

      echo '<div class="box"><div style="font-weight:800;margin-bottom:6px">Admin pastabos</div>';
      echo '<form method="post">';
      echo csrf_field();
      echo '<input type="hidden" name="action" value="user_note">';
      echo '<input type="hidden" name="user_id" value="'.(int)$u['id'].'">';
      echo '<textarea class="inp" name="note" rows="3" placeholder="Pastaba..."></textarea>';
      echo '<div style="height:8px"></div><button class="btn2 btnPrimary" type="submit">Pridėti</button>';
      echo '</form>';

      if (table_exists2($db,'user_admin_notes')) {
        $notes = db_all($db, "SELECT n.*, u.username AS admin_name FROM user_admin_notes n LEFT JOIN users u ON u.id=n.admin_id WHERE n.user_id=? ORDER BY n.id DESC LIMIT 20", [$u['id']]);
        if ($notes) {
          echo '<div class="hr"></div>';
          foreach ($notes as $n) {
            echo '<div class="mini" style="margin-bottom:8px"><span class="badge">'.h2($n['admin_name'] ?? ('#'.$n['admin_id'])).'</span> <span class="muted">'.h2($n['created_at'] ?? '').'</span><div style="word-break:break-word">'.h2($n['note'] ?? '').'</div></div>';
          }
        }
      }
      echo '</div></div>';

      // Recent villages
      if (table_exists2($db,'villages')) {
        $vs = db_all($db, "SELECT id,name,x,y,is_capital,wood,clay,iron,crop FROM villages WHERE user_id=? ORDER BY id ASC LIMIT 20", [$u['id']]);
        echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Kaimai</div>';
        if (!$vs) echo '<div class="muted">Nėra</div>';
        else {
          echo '<table><thead><tr><th>ID</th><th>Kaimas</th><th>Koordinatės</th><th>Resursai</th></tr></thead><tbody>';
          foreach ($vs as $v) {
            echo '<tr>';
            echo '<td><a class="btn2 mini" href="admin.php?tab=villages&v='.(int)$v['id'].'">#'.(int)$v['id'].'</a></td>';
            echo '<td>'.h2($v['name']).($v['is_capital']?' <span class="badge">CAP</span>':'').'</td>';
            echo '<td>'.(int)$v['x'].'|'.(int)$v['y'].'</td>';
            echo '<td class="mini muted">W '.(int)$v['wood'].' / C '.(int)$v['clay'].' / I '.(int)$v['iron'].' / Cr '.(int)$v['crop'].'</td>';
            echo '</tr>';
          }
          echo '</tbody></table>';
        }
        echo '</div>';
      }

      echo '</div>';
    }
  } else {
    // list
    $where = "1=1";
    $params = [];
    if ($q !== '') {
      if (ctype_digit($q)) { $where = "id=?"; $params[]=(int)$q; }
      else {
        $like = "%$q%";
        $conds = ["username LIKE ?"];
        $params[]=$like;
        foreach (['register_ip','last_ip','register_device_hash','last_device_hash','register_ua_hash','last_ua_hash'] as $col) {
          if (column_exists2($db,'users',$col)) { $conds[] = "$col LIKE ?"; $params[]=$like; }
        }
        $where = "(".implode(" OR ", $conds).")";
      }
    }
    $rows = db_all($db, "SELECT id, username, is_admin, tribe, gold, created_at, suspected_multiacc, last_ip, last_device_hash, lang"
      .(column_exists2($db,'users','is_banned')?", is_banned":"")
      ." FROM users WHERE $where ORDER BY id DESC LIMIT 60", $params);

    echo '<div class="box">';
    echo '<table><thead><tr><th>ID</th><th>Vartotojas</th><th>Info</th><th>Statusas</th></tr></thead><tbody>';
    foreach ($rows as $r) {
      $badges = [];
      if (!empty($r['is_admin'])) $badges[]='<span class="badge">ADMIN</span>';
      if (!empty($r['suspected_multiacc'])) $badges[]='<span class="badge badgeWarn">SUS</span>';
      if (isset($r['is_banned']) && !empty($r['is_banned'])) $badges[]='<span class="badge badgeBad">BAN</span>';
      echo '<tr>';
      echo '<td><a class="btn2 mini" href="admin.php?tab=users&u='.(int)$r['id'].'">#'.(int)$r['id'].'</a></td>';
      echo '<td style="font-weight:800">'.h2($r['username']).'</td>';
      echo '<td class="mini muted">tribe: '.h2($r['tribe']).' • gold: '.h2($r['gold']).' • lang: '.h2($r['lang']).'<br>last_ip: '.h2($r['last_ip'] ?? '').'<br>device: '.h2($r['last_device_hash'] ?? '').'</td>';
      echo '<td>'.implode(' ', $badges).'</td>';
      echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="4" class="muted">Nėra</td></tr>';
    echo '</tbody></table></div>';
  }
}

if ($tab === 'villages') {
  if (!table_exists2($db,'villages')) { echo '<div class="box">villages lentelės nėra</div>'; }
  else {
    $q = trim((string)($_GET['q'] ?? ''));
    $vid = (int)($_GET['v'] ?? 0);

    echo '<div class="box">';
    echo '<form method="get" class="row" style="align-items:flex-end">';
    echo '<input type="hidden" name="tab" value="villages">';
    echo '<div style="flex:1 1 340px"><div class="muted mini">Paieška (kaimo pavadinimas / id / savininkas)</div><input class="inp" name="q" value="'.h2($q).'" placeholder="Pvz: Sostinė, 11, basas"></div>';
    echo '<div><button class="btn2 btnPrimary" type="submit">Ieškoti</button></div>';
    echo '</form>';
    echo '</div>';

    if ($vid > 0) {
      $v = db_one($db, "SELECT v.*, u.username FROM villages v LEFT JOIN users u ON u.id=v.user_id WHERE v.id=?", [$vid]);
      if (!$v) { echo '<div class="box">Nerasta</div>'; }
      else {
        echo '<div class="box">';
        echo '<div class="row" style="justify-content:space-between;align-items:center">';
        echo '<div><div style="font-size:22px;font-weight:900">'.h2($v['name']).' <span class="badge">#'.(int)$v['id'].'</span></div>';
        echo '<div class="muted mini">Savininkas: <b>'.h2($v['username'] ?? ('#'.$v['user_id'])).'</b> • '.(int)$v['x'].'|'.(int)$v['y'].' • W '.(int)$v['wood'].' / C '.(int)$v['clay'].' / I '.(int)$v['iron'].' / Cr '.(int)$v['crop'].'</div>';
        echo '</div><a class="btn2" href="admin.php?tab=villages&q='.urlencode($q).'">← atgal</a></div>';
        echo '</div>';


        // Admin: give resources / troops
        echo '<div class="box"><div style="font-weight:800;margin-bottom:10px">'.h2(t('admin_cheats') ?? 'Valdymas').'</div>';
        echo '<div class="grid2">';

        // Add resources
        echo '<div class="card" style="padding:14px">';
        echo '<div style="font-weight:800;margin-bottom:8px">Pridėti resursų</div>';
        echo '<form method="post" class="grid2" style="gap:10px">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="village_add_resources">';
        echo '<input type="hidden" name="village_id" value="'.(int)$v['id'].'">';
        echo '<div><div class="muted mini">Mediena</div><input class="input" name="wood" type="number" value="0"></div>';
        echo '<div><div class="muted mini">Molis</div><input class="input" name="clay" type="number" value="0"></div>';
        echo '<div><div class="muted mini">Geležis</div><input class="input" name="iron" type="number" value="0"></div>';
        echo '<div><div class="muted mini">Grūdai</div><input class="input" name="crop" type="number" value="0"></div>';
        echo '<div style="grid-column:1/-1"><button class="btn" type="submit">Pridėti</button><div class="muted mini" style="margin-top:6px">Gali būti ir neigiami skaičiai (atimti). Resursai automatiškai apribojami sandėlio / kluono talpa.</div></div>';
        echo '</form>';
        echo '</div>';

        // Add troops
        echo '<div class="card" style="padding:14px">';
        echo '<div style="font-weight:800;margin-bottom:8px">Pridėti karių</div>';
        $unitOptions = [];
        if (function_exists('unit_catalog')) {
          foreach (['roman','gaul','german','hun','egypt'] as $tt) {
            $cat = unit_catalog($tt);
            foreach ($cat as $k => $_v) $unitOptions[$k] = true;
          }
        }
        echo '<datalist id="unit_list">';
        foreach (array_keys($unitOptions) as $uk) echo '<option value="'.h2($uk).'"></option>';
        echo '</datalist>';

        echo '<form method="post" style="display:grid;gap:10px">';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="village_add_troops">';
        echo '<input type="hidden" name="village_id" value="'.(int)$v['id'].'">';
        echo '<div><div class="muted mini">Unit</div><input class="input" name="unit" list="unit_list" placeholder="roman_legionnaire"></div>';
        echo '<div><div class="muted mini">Kiekis</div><input class="input" name="qty" type="number" value="0"></div>';
        echo '<button class="btn" type="submit">Pridėti</button>';
        echo '<div class="muted mini">Neigiamas kiekis = atimti.</div>';
        echo '</form>';
        echo '</div>';

        echo '</div></div>';


        // Buildings
        if (table_exists2($db,'village_buildings')) {
          $b = db_all($db, "SELECT slot,type,level FROM village_buildings WHERE village_id=? ORDER BY slot ASC", [$v['id']]);
          echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Pastatai</div>';
          if (!$b) echo '<div class="muted">Nėra</div>';
          else {
            echo '<table><thead><tr><th>Slot</th><th>Tipas</th><th>Lvl</th></tr></thead><tbody>';
            foreach ($b as $x) echo '<tr><td>'.(int)$x['slot'].'</td><td>'.h2($x['type']).'</td><td>'.(int)$x['level'].'</td></tr>';
            echo '</tbody></table>';
          }
          echo '</div>';
        }

        // Resource fields
        if (table_exists2($db,'resource_fields')) {
          $rf = db_all($db, "SELECT field_id,type,level FROM resource_fields WHERE village_id=? ORDER BY field_id ASC", [$v['id']]);
          echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Resursų laukai</div>';
          if (!$rf) echo '<div class="muted">Nėra</div>';
          else {
            echo '<table><thead><tr><th>ID</th><th>Tipas</th><th>Lvl</th></tr></thead><tbody>';
            foreach ($rf as $x) echo '<tr><td>'.(int)$x['field_id'].'</td><td>'.h2($x['type']).'</td><td>'.(int)$x['level'].'</td></tr>';
            echo '</tbody></table>';
          }
          echo '</div>';
        }

        // Troops
        if (table_exists2($db,'village_troops')) {
          $t = db_all($db, "SELECT unit,amount FROM village_troops WHERE village_id=? ORDER BY unit ASC", [$v['id']]);
          echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Kariai kaime</div>';
          if (!$t) echo '<div class="muted">Nėra</div>';
          else {
            echo '<table><thead><tr><th>Unit</th><th>Kiekis</th></tr></thead><tbody>';
            foreach ($t as $x) echo '<tr><td>'.h2($x['unit']).'</td><td>'.(int)$x['amount'].'</td></tr>';
            echo '</tbody></table>';
          }
          echo '</div>';
        }

        // Queues (build/training/troop/tech)
        echo '<div class="row">';
        foreach ([
          ['village_queue','Statybų eilė','SELECT id,action,type,target_level,finish_at FROM village_queue WHERE village_id=? ORDER BY finish_at ASC LIMIT 20'],
          ['training_queue','Mokymų eilė','SELECT id,unit_type,amount,finish_at FROM training_queue WHERE village_id=? ORDER BY finish_at ASC LIMIT 20'],
          ['troop_queue','Karių eilė','SELECT id,unit,amount,finish_at FROM troop_queue WHERE village_id=? ORDER BY finish_at ASC LIMIT 20'],
          ['tech_queue','Tyrimų eilė','SELECT id,action,unit,target_level,finish_at FROM tech_queue WHERE village_id=? ORDER BY finish_at ASC LIMIT 20'],
        ] as $cfg) {
          [$tbl,$title,$sqlq] = $cfg;
          if (!table_exists2($db,$tbl)) continue;
          $rows = db_all($db,$sqlq,[$v['id']]);
          echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">'.h2($title).'</div>';
          if (!$rows) echo '<div class="muted">Tuščia</div>';
          else {
            echo '<table><thead><tr>';
            foreach (array_keys($rows[0]) as $k) echo '<th>'.h2($k).'</th>';
            echo '</tr></thead><tbody>';
            foreach ($rows as $r) {
              echo '<tr>';
              foreach ($r as $val) echo '<td class="mini">'.h2($val).'</td>';
              echo '</tr>';
            }
            echo '</tbody></table>';
          }
          echo '</div>';
        }
        echo '</div>';

        // Movements
        if (table_exists2($db,'troop_movements')) {
          $mv = db_all($db, "SELECT id,move_type,state,from_village_id,to_village_id,to_x,to_y,arrive_at,return_at,created_at FROM troop_movements WHERE from_village_id=? OR to_village_id=? ORDER BY id DESC LIMIT 30", [$v['id'],$v['id']]);
          echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Judėjimai</div>';
          if (!$mv) echo '<div class="muted">Nėra</div>';
          else {
            echo '<table><thead><tr><th>ID</th><th>Tipas</th><th>Būsena</th><th>Iš</th><th>Į</th><th>Koord</th><th>Atvyksta</th><th>Grįžta</th></tr></thead><tbody>';
            foreach ($mv as $r) {
              echo '<tr><td>#'.(int)$r['id'].'</td><td>'.h2($r['move_type']).'</td><td>'.h2($r['state']).'</td><td>#'.(int)$r['from_village_id'].'</td><td>#'.(int)$r['to_village_id'].'</td><td>'.h2($r['to_x']).'|'.h2($r['to_y']).'</td><td class="mini">'.h2($r['arrive_at']).'</td><td class="mini">'.h2($r['return_at']).'</td></tr>';
            }
            echo '</tbody></table>';
          }
          echo '</div>';
        }
      }
    } else {
      $params=[];
      $where="1=1";
      if ($q!=='') {
        if (ctype_digit($q)) { $where="v.id=?"; $params[]=(int)$q; }
        else { $where="(v.name LIKE ? OR u.username LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; }
      }
      $rows = db_all($db, "SELECT v.id,v.name,v.x,v.y,v.wood,v.clay,v.iron,v.crop,v.is_capital,u.username FROM villages v LEFT JOIN users u ON u.id=v.user_id WHERE $where ORDER BY v.id DESC LIMIT 60", $params);
      echo '<div class="box"><table><thead><tr><th>ID</th><th>Kaimas</th><th>Savininkas</th><th>Koordinatės</th><th>Resursai</th></tr></thead><tbody>';
      foreach ($rows as $r) {
        echo '<tr>';
        echo '<td><a class="btn2 mini" href="admin.php?tab=villages&v='.(int)$r['id'].'">#'.(int)$r['id'].'</a></td>';
        echo '<td>'.h2($r['name']).($r['is_capital']?' <span class="badge">CAP</span>':'').'</td>';
        echo '<td>'.h2($r['username'] ?? '—').'</td>';
        echo '<td>'.(int)$r['x'].'|'.(int)$r['y'].'</td>';
        echo '<td class="mini muted">W '.(int)$r['wood'].' / C '.(int)$r['clay'].' / I '.(int)$r['iron'].' / Cr '.(int)$r['crop'].'</td>';
        echo '</tr>';
      }
      if (!$rows) echo '<tr><td colspan="5" class="muted">Nėra</td></tr>';
      echo '</tbody></table></div>';
    }
  }
}

if ($tab === 'alliances') {
  if (!table_exists2($db,'alliances')) { echo '<div class="box">alliances lentelės nėra</div>'; }
  else {
    $rows = db_all($db, "SELECT a.*, u.username AS leader FROM alliances a LEFT JOIN users u ON u.id=a.created_by ORDER BY a.id DESC LIMIT 80");
    echo '<div class="box"><table><thead><tr><th>ID</th><th>TAG</th><th>Pavadinimas</th><th>Lyderis</th><th>Sukurta</th></tr></thead><tbody>';
    foreach ($rows as $a) {
      $members = table_exists2($db,'alliance_members') ? (int)db_scalar($db,"SELECT COUNT(*) FROM alliance_members WHERE alliance_id=?",[$a['id']]) : 0;
      echo '<tr><td>#'.(int)$a['id'].'</td><td><span class="badge">'.h2($a['tag']).'</span></td><td>'.h2($a['name']).' <span class="muted mini">('.$members.')</span></td><td>'.h2($a['leader'] ?? '').'</td><td class="mini">'.h2($a['created_at']).'</td></tr>';
    }
    echo '</tbody></table></div>';
  }
}

if ($tab === 'troops') {
  echo '<div class="row">';
  if (table_exists2($db,'village_troops')) {
    $top = db_all($db, "SELECT unit, SUM(amount) AS total FROM village_troops GROUP BY unit ORDER BY total DESC LIMIT 50");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Karių kiekiai (viso serveryje)</div>';
    if ($top) {
      echo '<table><thead><tr><th>Unit</th><th>Total</th></tr></thead><tbody>';
      foreach ($top as $r) echo '<tr><td>'.h2($r['unit']).'</td><td>'.(int)$r['total'].'</td></tr>';
      echo '</tbody></table>';
    } else echo '<div class="muted">Nėra</div>';
    echo '</div>';
  }
  if (table_exists2($db,'troop_movements')) {
    $mv = db_all($db, "SELECT tm.id, tm.move_type, tm.state, u.username, tm.from_village_id, tm.to_village_id, tm.to_x, tm.to_y, tm.arrive_at FROM troop_movements tm LEFT JOIN villages v ON v.id=tm.from_village_id LEFT JOIN users u ON u.id=v.user_id ORDER BY tm.id DESC LIMIT 50");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Paskutiniai judėjimai</div>';
    if ($mv) {
      echo '<table><thead><tr><th>ID</th><th>Tipas</th><th>Būsena</th><th>Kas</th><th>Iš</th><th>Į</th><th>Koord</th><th>Atvyksta</th></tr></thead><tbody>';
      foreach ($mv as $r) {
        echo '<tr><td>#'.(int)$r['id'].'</td><td>'.h2($r['move_type']).'</td><td>'.h2($r['state']).'</td><td>'.h2($r['username'] ?? '').'</td><td>#'.(int)$r['from_village_id'].'</td><td>#'.(int)$r['to_village_id'].'</td><td>'.h2($r['to_x']).'|'.h2($r['to_y']).'</td><td class="mini">'.h2($r['arrive_at']).'</td></tr>';
      }
      echo '</tbody></table>';
    } else echo '<div class="muted">Nėra</div>';
    echo '</div>';
  }
  echo '</div>';
}

if ($tab === 'queues') {
  echo '<div class="row">';
  $qcfg = [
    ['village_queue','Statybos','SELECT q.id, q.village_id, v.name AS village, u.username, q.action, q.type, q.target_level, q.finish_at FROM village_queue q LEFT JOIN villages v ON v.id=q.village_id LEFT JOIN users u ON u.id=v.user_id ORDER BY q.finish_at ASC LIMIT 80'],
    ['training_queue','Mokymai','SELECT q.id, q.village_id, v.name AS village, u.username, q.unit_type, q.amount, q.finish_at FROM training_queue q LEFT JOIN villages v ON v.id=q.village_id LEFT JOIN users u ON u.id=v.user_id ORDER BY q.finish_at ASC LIMIT 80'],
    ['troop_queue','Kariai','SELECT q.id, q.village_id, v.name AS village, u.username, q.unit, q.amount, q.finish_at FROM troop_queue q LEFT JOIN villages v ON v.id=q.village_id LEFT JOIN users u ON u.id=v.user_id ORDER BY q.finish_at ASC LIMIT 80'],
    ['tech_queue','Tyrimai','SELECT q.id, q.village_id, v.name AS village, u.username, q.action, q.unit, q.target_level, q.finish_at FROM tech_queue q LEFT JOIN villages v ON v.id=q.village_id LEFT JOIN users u ON u.id=v.user_id ORDER BY q.finish_at ASC LIMIT 80'],
  ];
  foreach ($qcfg as $c) {
    [$tbl,$title,$sqlq] = $c;
    if (!table_exists2($db,$tbl)) continue;
    $rows = db_all($db,$sqlq);
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">'.h2($title).'</div>';
    if (!$rows) { echo '<div class="muted">Tuščia</div>'; }
    else {
      echo '<table><thead><tr>';
      foreach (array_keys($rows[0]) as $k) echo '<th>'.h2($k).'</th>';
      echo '</tr></thead><tbody>';
      foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $k=>$v) {
          $cell = ($k==='village_id') ? '<a class="btn2 mini" href="admin.php?tab=villages&v='.(int)$v.'">#'.(int)$v.'</a>' : h2($v);
          echo '<td class="mini">'.$cell.'</td>';
        }
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
  }
  echo '</div>';
}

if ($tab === 'world') {
  echo '<div class="row">';
  if (table_exists2($db,'world_tiles')) {
    $cnt = (int)db_scalar($db, "SELECT COUNT(*) FROM world_tiles");
    $occ = (int)db_scalar($db, "SELECT COUNT(*) FROM world_tiles WHERE occupied_village_id IS NOT NULL");
    $oasis = (int)db_scalar($db, "SELECT COUNT(*) FROM world_tiles WHERE tile_type='oasis'");
    echo '<div class="box"><div class="muted">World tiles</div><div style="font-size:34px;font-weight:900">'.$cnt.'</div><div class="mini muted">occupied: '.$occ.' • oases: '.$oasis.'</div></div>';
  }
  if (table_exists2($db,'weekly_snapshots')) {
    $last = db_one($db, "SELECT * FROM weekly_snapshots ORDER BY id DESC LIMIT 1");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Paskutinis weekly snapshot</div>';
    if ($last) {
      echo '<div class="mini muted">#'.(int)$last['id'].' • '.h2($last['week_start'] ?? '').' → '.h2($last['week_end'] ?? '').'</div>';
    } else echo '<div class="muted">Nėra</div>';
    echo '</div>';
  }
  echo '</div>';

  if (table_exists2($db,'world_tiles')) {
    $rows = db_all($db, "SELECT x,y,tile_type,oasis_bonus,occupied_village_id FROM world_tiles ORDER BY id DESC LIMIT 80");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Paskutinės plytelės</div>';
    echo '<table><thead><tr><th>X|Y</th><th>Tipas</th><th>Bonus</th><th>Occupied</th></tr></thead><tbody>';
    foreach ($rows as $r) {
      echo '<tr><td>'.(int)$r['x'].'|'.(int)$r['y'].'</td><td>'.h2($r['tile_type']).'</td><td>'.h2($r['oasis_bonus']).'</td><td>'.h2($r['occupied_village_id']).'</td></tr>';
    }
    echo '</tbody></table></div>';
  }
}

if ($tab === 'economy') {
  echo '<div class="row">';
  if (table_exists2($db,'gold_transactions')) {
    $sum = (int)db_scalar($db, "SELECT COALESCE(SUM(amount),0) FROM gold_transactions");
    $cnt = (int)db_scalar($db, "SELECT COUNT(*) FROM gold_transactions");
    echo '<div class="box"><div class="muted">Gold transakcijos</div><div style="font-size:34px;font-weight:900">'.$cnt.'</div><div class="mini muted">sum amount: '.$sum.'</div></div>';
  }
  if (table_exists2($db,'gold_purchases')) {
    $cnt = (int)db_scalar($db, "SELECT COUNT(*) FROM gold_purchases");
    echo '<div class="box"><div class="muted">Pirkimai</div><div style="font-size:34px;font-weight:900">'.$cnt.'</div></div>';
  }
  echo '</div>';

  if (table_exists2($db,'gold_transactions')) {
    $rows = db_all($db, "SELECT gt.id, u.username, gt.amount, gt.reason, gt.created_at FROM gold_transactions gt LEFT JOIN users u ON u.id=gt.user_id ORDER BY gt.id DESC LIMIT 80");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Paskutinės transakcijos</div>';
    echo '<table><thead><tr><th>ID</th><th>Kas</th><th>Amount</th><th>Reason</th><th>Laikas</th></tr></thead><tbody>';
    foreach ($rows as $r) echo '<tr><td>#'.(int)$r['id'].'</td><td>'.h2($r['username']).'</td><td>'.(int)$r['amount'].'</td><td>'.h2($r['reason']).'</td><td class="mini">'.h2($r['created_at']).'</td></tr>';
    echo '</tbody></table></div>';
  }
}

if ($tab === 'chat') {
  echo '<div class="row">';
  if (table_exists2($db,'global_chat')) {
    $rows = db_all($db, "SELECT gc.id,u.username,gc.message,gc.created_at FROM global_chat gc LEFT JOIN users u ON u.id=gc.user_id ORDER BY gc.id DESC LIMIT 60");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Global chat</div>';
    if (!$rows) echo '<div class="muted">Nėra</div>';
    else {
      foreach ($rows as $m) {
        echo '<div class="mini" style="margin-bottom:10px"><span class="badge">'.h2($m['username'] ?? '—').'</span> <span class="muted">'.h2($m['created_at']).'</span><div style="word-break:break-word">'.h2($m['message']).'</div></div>';
      }
    }
    echo '</div>';
  }
  if (table_exists2($db,'private_messages')) {
    $rows = db_all($db, "SELECT pm.id, u1.username AS `from`, u2.username AS `to`, pm.subject, pm.created_at FROM private_messages pm LEFT JOIN users u1 ON u1.id=pm.from_user_id LEFT JOIN users u2 ON u2.id=pm.to_user_id ORDER BY pm.id DESC LIMIT 60");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">PM (antraštės)</div>';
    if (!$rows) echo '<div class="muted">Nėra</div>';
    else {
      echo '<table><thead><tr><th>ID</th><th>Nuo</th><th>Kam</th><th>Tema</th><th>Laikas</th></tr></thead><tbody>';
      foreach ($rows as $r) echo '<tr><td>#'.(int)$r['id'].'</td><td>'.h2($r['from']).'</td><td>'.h2($r['to']).'</td><td>'.h2($r['subject']).'</td><td class="mini">'.h2($r['created_at']).'</td></tr>';
      echo '</tbody></table>';
    }
    echo '</div>';
  }
  echo '</div>';
}

if ($tab === 'security') {
  echo '<div class="row">';

  // Suspects group by last_device_hash
  if (column_exists2($db,'users','suspected_multiacc')) {
    $sus = db_all($db, "SELECT id,username,last_ip,last_device_hash,last_ua_hash,register_ip,register_device_hash,register_ua_hash FROM users WHERE suspected_multiacc=1 ORDER BY id DESC LIMIT 200");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Įtariami multi-account</div>';
    if (!$sus) echo '<div class="muted">Įtariamų nėra.</div>';
    else {
      echo '<table><thead><tr><th>ID</th><th>Vartotojas</th><th>IP</th><th>Device</th><th>UA</th></tr></thead><tbody>';
      foreach ($sus as $u) {
        echo '<tr><td><a class="btn2 mini" href="admin.php?tab=users&u='.(int)$u['id'].'">#'.(int)$u['id'].'</a></td><td>'.h2($u['username']).'</td><td class="mini">'.h2($u['last_ip'] ?? $u['register_ip'] ?? '').'</td><td class="mini" style="word-break:break-all">'.h2($u['last_device_hash'] ?? $u['register_device_hash'] ?? '').'</td><td class="mini" style="word-break:break-all">'.h2($u['last_ua_hash'] ?? $u['register_ua_hash'] ?? '').'</td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
  }

  // IP ban form
  echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">IP Ban</div>';
  if (table_exists2($db,'ip_bans')) {
    echo '<form method="post" class="grid2" style="align-items:end">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="ip_ban">';
    echo '<div><div class="muted mini">IP</div><input class="inp" name="ip" placeholder="84.15.x.x"></div>';
    echo '<div><div class="muted mini">Dienų</div><input class="inp" type="number" name="days" value="7"></div>';
    echo '<div style="grid-column:1/-1"><div class="muted mini">Priežastis</div><input class="inp" name="reason" value="multiaccount"></div>';
    echo '<div style="grid-column:1/-1"><button class="btn2 btnDanger" type="submit">Užbaninti IP</button></div>';
    echo '</form>';
    $ips = db_all($db, "SELECT * FROM ip_bans ORDER BY id DESC LIMIT 30");
    echo '<div class="hr"></div><div class="muted mini">Paskutiniai IP ban</div>';
    foreach ($ips as $r) {
      echo '<div class="mini"><span class="badge">'.h2($r['ip']).'</span> <span class="muted">'.h2($r['created_at']).'</span> • '.h2($r['reason']).' • exp: '.h2($r['expires_at']).'</div>';
    }
  } else echo '<div class="muted">ip_bans lentelės nėra</div>';
  echo '</div>';

  // Whitelist
  echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Whitelist</div>';
  if (table_exists2($db,'security_whitelist')) {
    echo '<form method="post" class="grid2" style="align-items:end">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="whitelist_add">';
    echo '<div><div class="muted mini">IP (nebūtina)</div><input class="inp" name="ip"></div>';
    echo '<div><div class="muted mini">Device hash (nebūtina)</div><input class="inp" name="device_hash"></div>';
    echo '<div style="grid-column:1/-1"><div class="muted mini">Pastaba</div><input class="inp" name="note"></div>';
    echo '<div style="grid-column:1/-1"><button class="btn2 btnPrimary" type="submit">Pridėti</button></div>';
    echo '</form>';
    $wl = db_all($db, "SELECT * FROM security_whitelist ORDER BY id DESC LIMIT 30");
    echo '<div class="hr"></div>';
    foreach ($wl as $r) {
      echo '<div class="mini"><span class="badge">'.h2($r['ip'] ?: '—').'</span> <span class="badge">'.h2($r['device_hash'] ?: '—').'</span> <span class="muted">'.h2($r['note']).'</span></div>';
    }
  } else echo '<div class="muted">security_whitelist lentelės nėra</div>';
  echo '</div>';

  echo '</div>'; // row

  // Security events
  if (table_exists2($db,'security_events')) {
    $events = db_all($db, "SELECT se.id,se.event_type,u.username,se.ip,se.device_hash,se.created_at,se.details FROM security_events se LEFT JOIN users u ON u.id=se.user_id ORDER BY se.id DESC LIMIT 120");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Security įvykiai</div>';
    if (!$events) echo '<div class="muted">Įvykių nėra.</div>';
    else {
      echo '<table><thead><tr><th>ID</th><th>Tipas</th><th>Kas</th><th>IP</th><th>Device</th><th>Laikas</th><th>Detalės</th></tr></thead><tbody>';
      foreach ($events as $e) {
        echo '<tr><td>#'.(int)$e['id'].'</td><td>'.h2($e['event_type']).'</td><td>'.h2($e['username'] ?? '').'</td><td class="mini">'.h2($e['ip']).'</td><td class="mini" style="word-break:break-all">'.h2($e['device_hash']).'</td><td class="mini">'.h2($e['created_at']).'</td><td class="mini">'.h2(mb_substr((string)$e['details'],0,120)).'</td></tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
  }
}

if ($tab === 'logs') {
  echo '<div class="row">';
  if (table_exists2($db,'admin_logs')) {
    $rows = db_all($db, "SELECT al.id,u.username AS admin,al.action,al.details,al.created_at FROM admin_logs al LEFT JOIN users u ON u.id=al.admin_id ORDER BY al.id DESC LIMIT 200");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Admin logai</div>';
    if (!$rows) echo '<div class="muted">Nėra</div>';
    else {
      echo '<table><thead><tr><th>ID</th><th>Admin</th><th>Action</th><th>Details</th><th>Laikas</th></tr></thead><tbody>';
      foreach ($rows as $r) echo '<tr><td>#'.(int)$r['id'].'</td><td>'.h2($r['admin'] ?? '').'</td><td>'.h2($r['action']).'</td><td class="mini">'.h2(mb_substr((string)$r['details'],0,140)).'</td><td class="mini">'.h2($r['created_at']).'</td></tr>';
      echo '</tbody></table>';
    }
    echo '</div>';
  }
  if (table_exists2($db,'reports')) {
    $rows = db_all($db, "SELECT r.id,u.username,r.type,r.title,r.created_at FROM reports r LEFT JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 120");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Reportai</div>';
    if (!$rows) echo '<div class="muted">Nėra</div>';
    else {
      echo '<table><thead><tr><th>ID</th><th>Kas</th><th>Tipas</th><th>Pavadinimas</th><th>Laikas</th></tr></thead><tbody>';
      foreach ($rows as $r) echo '<tr><td>#'.(int)$r['id'].'</td><td>'.h2($r['username']).'</td><td>'.h2($r['type']).'</td><td>'.h2($r['title']).'</td><td class="mini">'.h2($r['created_at']).'</td></tr>';
      echo '</tbody></table>';
    }
    echo '</div>';
  }
  echo '</div>';
}

if ($tab === 'seasons') {
  echo '<div class="row">';
  if (table_exists2($db,'seasons')) {
    $rows = db_all($db, "SELECT * FROM seasons ORDER BY id DESC LIMIT 20");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Sezonai</div>';
    if (!$rows) echo '<div class="muted">Nėra</div>';
    else {
      echo '<table><thead><tr><th>ID</th><th>Pavadinimas</th><th>Start</th><th>End</th><th>Status</th></tr></thead><tbody>';
      foreach ($rows as $s) echo '<tr><td>#'.(int)$s['id'].'</td><td>'.h2($s['name']).'</td><td class="mini">'.h2($s['start_at']).'</td><td class="mini">'.h2($s['end_at']).'</td><td>'.h2($s['status']).'</td></tr>';
      echo '</tbody></table>';
    }
    echo '</div>';
  }
  if (table_exists2($db,'season_results')) {
    $rows = db_all($db, "SELECT sr.id, sr.season_id, u.username, sr.score, sr.rank, sr.created_at FROM season_results sr LEFT JOIN users u ON u.id=sr.user_id ORDER BY sr.id DESC LIMIT 50");
    echo '<div class="box"><div style="font-weight:800;margin-bottom:8px">Rezultatai</div>';
    if (!$rows) echo '<div class="muted">Nėra</div>';
    else {
      echo '<table><thead><tr><th>ID</th><th>Season</th><th>Kas</th><th>Score</th><th>Rank</th><th>Laikas</th></tr></thead><tbody>';
      foreach ($rows as $r) echo '<tr><td>#'.(int)$r['id'].'</td><td>#'.(int)$r['season_id'].'</td><td>'.h2($r['username']).'</td><td>'.h2($r['score']).'</td><td>'.h2($r['rank']).'</td><td class="mini">'.h2($r['created_at']).'</td></tr>';
      echo '</tbody></table>';
    }
    echo '</div>';
  }
  echo '</div>';
}
?>

  </div>
</div>
</body>
</html>
