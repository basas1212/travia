<?php
require_once __DIR__ . '/../init.php';
require_login();

$db = db();
ensure_game_schema($db);

$me = current_user($db);
$vid = (int)($me['active_village_id'] ?? 0);
$v = $vid > 0 ? village_row($db, $vid) : null;
if (!$v) {
  redirect('/game/game.php');
}

$type = 'market';
$marketLevel = (int)building_level_by_type($db, (int)$v['id'], $type);

// Paprastos formulės (galėsim vėliau suderinti su Travian):
$merchantsTotal = max(0, $marketLevel * 2);
$capPerMerchant = 1000 + ($marketLevel * 250); // didėja su lygiu
$availableMerchants = $merchantsTotal;

// Užimti pirkliai (siuntos kelyje)
if ($merchantsTotal > 0 && table_exists($db, 'market_transfers')) {
  $now = date('Y-m-d H:i:s');
  $st = $db->prepare("SELECT COALESCE(SUM(merchants),0) s FROM market_transfers WHERE from_village_id=? AND status='enroute' AND arrive_at > ?");
  $st->bind_param('is', $vid, $now);
  $st->execute();
  $busy = (int)($st->get_result()->fetch_assoc()['s'] ?? 0);
  $st->close();
  $availableMerchants = max(0, $merchantsTotal - $busy);
}

$flash = '';

function market_travel_seconds(int $x1, int $y1, int $x2, int $y2): int {
  $d = distance($x1, $y1, $x2, $y2);
  // 16 laukų/val (Travian pirkliai dažnai panašiai). Konvertuojam į sekundes.
  $tilesPerHour = 16.0;
  $sec = (int)ceil(($d / $tilesPerHour) * 3600);
  // Serverio greitis (TRAVIA_SPEED) trumpina prekybininkų kelionės laiką.
  $sec = (int)ceil($sec / max(0.1, (float)game_speed()));
  return max(60, $sec);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'send') {
    if ($marketLevel <= 0) {
      $flash = 'Turgus nepastatytas.';
    } else {
      $toX = (int)($_POST['to_x'] ?? 0);
      $toY = (int)($_POST['to_y'] ?? 0);

      $wood = max(0, (int)($_POST['wood'] ?? 0));
      $clay = max(0, (int)($_POST['clay'] ?? 0));
      $iron = max(0, (int)($_POST['iron'] ?? 0));
      $crop = max(0, (int)($_POST['crop'] ?? 0));
      $total = $wood + $clay + $iron + $crop;

      if ($total <= 0) {
        $flash = 'Įvesk bent vieną resursą.';
      } else {
        // Gavėjo kaimas pagal koordinates
        $st = $db->prepare('SELECT id, user_id FROM villages WHERE x=? AND y=? LIMIT 1');
        $st->bind_param('ii', $toX, $toY);
        $st->execute();
        $to = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$to) {
          $flash = 'Kaimas su tokiomis koordinatėmis nerastas.';
        } else {
          $toVillageId = (int)$to['id'];

          // Perskaičiuojam turimus resursus (kad būtų tikslu)
          $vFresh = village_row($db, $vid);
          $haveW = (int)($vFresh['wood'] ?? 0);
          $haveC = (int)($vFresh['clay'] ?? 0);
          $haveI = (int)($vFresh['iron'] ?? 0);
          $haveCr = (int)($vFresh['crop'] ?? 0);

          if ($wood > $haveW || $clay > $haveC || $iron > $haveI || $crop > $haveCr) {
            $flash = 'Nepakanka resursų.';
          } else {
            $needMerchants = (int)ceil($total / max(1, $capPerMerchant));
            if ($needMerchants <= 0) $needMerchants = 1;

            // Perkraunam užimtus pirklius
            $now = date('Y-m-d H:i:s');
            $busy = 0;
            if (table_exists($db, 'market_transfers')) {
              $stB = $db->prepare("SELECT COALESCE(SUM(merchants),0) s FROM market_transfers WHERE from_village_id=? AND status='enroute' AND arrive_at > ?");
              $stB->bind_param('is', $vid, $now);
              $stB->execute();
              $busy = (int)($stB->get_result()->fetch_assoc()['s'] ?? 0);
              $stB->close();
            }
            $available = max(0, $merchantsTotal - $busy);

            if ($needMerchants > $available) {
              $flash = 'Nepakanka laisvų pirklių.';
            } else {
              $sec = market_travel_seconds((int)$vFresh['x'], (int)$vFresh['y'], $toX, $toY);
              $depart = date('Y-m-d H:i:s');
              $arrive = date('Y-m-d H:i:s', time() + $sec);

              // Nuskaitom resursus dabar, įrašom siuntą
              $db->begin_transaction();
              try {
                $stU = $db->prepare('UPDATE villages SET wood=wood-?, clay=clay-?, iron=iron-?, crop=crop-? WHERE id=? LIMIT 1');
                $stU->bind_param('iiiii', $wood, $clay, $iron, $crop, $vid);
                $stU->execute();
                $stU->close();

                $stI = $db->prepare("INSERT INTO market_transfers (from_village_id, to_village_id, to_x, to_y, wood, clay, iron, crop, merchants, depart_at, arrive_at, status)
                                     VALUES (?,?,?,?,?,?,?,?,?,?,?, 'enroute')");
                $stI->bind_param('iiiiiiiiiss', $vid, $toVillageId, $toX, $toY, $wood, $clay, $iron, $crop, $needMerchants, $depart, $arrive);
                $stI->execute();
                $stI->close();

                $db->commit();
                $flash = 'Siunta išsiųsta.';
              } catch (Throwable $e) {
                $db->rollback();
                $flash = 'Klaida siunčiant.';
              }
            }
          }
        }
      }
    }
  }

  redirect('market.php?flash=' . urlencode($flash));
}

if ($flash === '' && isset($_GET['flash'])) $flash = (string)$_GET['flash'];

// Sąrašai
$outgoing = [];
$incoming = [];
if (table_exists($db, 'market_transfers')) {
  $resO = $db->query("SELECT mt.*, v2.name to_name FROM market_transfers mt LEFT JOIN villages v2 ON v2.id=mt.to_village_id WHERE mt.from_village_id={$vid} ORDER BY mt.id DESC LIMIT 50");
  while ($resO && ($r = $resO->fetch_assoc())) $outgoing[] = $r;

  $resI = $db->query("SELECT mt.*, v1.name from_name FROM market_transfers mt LEFT JOIN villages v1 ON v1.id=mt.from_village_id WHERE mt.to_village_id={$vid} ORDER BY mt.id DESC LIMIT 50");
  while ($resI && ($r = $resI->fetch_assoc())) $incoming[] = $r;
}

?><!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Turgus</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body class="appBg">
  <div class="pageWrap">
    <div class="topbar">
      <a class="brand" href="/game/game.php">TRAVIA</a>
      <div class="row" style="gap:8px;">
        <a class="btn" href="/game/city.php">Miestas</a>
        <a class="btn" href="/game/game.php">Žaidimas</a>
      </div>
    </div>

    <div class="card" style="margin-top:10px;">
      <h2 style="margin:0 0 6px 0;">Turgus</h2>
      <div class="hint">Kaimas: <b><?php echo h($v['name']); ?></b> (<?php echo (int)$v['x']; ?>|<?php echo (int)$v['y']; ?>) · Turgus lvl: <b><?php echo (int)$marketLevel; ?></b></div>
      <?php if ($marketLevel > 0): ?>
        <div class="hint" style="margin-top:6px;">Pirkliai: <b><?php echo (int)$availableMerchants; ?></b> / <?php echo (int)$merchantsTotal; ?> · Talpa/pirklys: <b><?php echo (int)$capPerMerchant; ?></b></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($flash)): ?>
      <div class="card" style="margin-top:10px;"><b><?php echo h($flash); ?></b></div>
    <?php endif; ?>

    <div class="card" style="margin-top:10px;">
      <h3 style="margin:0 0 10px 0;">Siųsti resursus</h3>

      <?php if ($marketLevel <= 0): ?>
        <div class="hint">Pastatyk <b>Turgų</b>, kad galėtum siųsti resursus.</div>
      <?php else: ?>
        <form method="post" class="grid2" style="gap:10px;">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="send">

          <div class="card" style="padding:12px;">
            <div class="row" style="gap:8px; flex-wrap:wrap;">
              <input class="in" type="number" name="to_x" placeholder="X" style="width:110px;" required>
              <input class="in" type="number" name="to_y" placeholder="Y" style="width:110px;" required>
            </div>
            <div class="hint" style="margin-top:6px;">Gavėjo koordinatės</div>

            <div class="row" style="gap:8px; flex-wrap:wrap; margin-top:10px;">
              <input class="in" type="number" name="wood" placeholder="Mediena" style="width:140px;" min="0" value="0">
              <input class="in" type="number" name="clay" placeholder="Molis" style="width:140px;" min="0" value="0">
              <input class="in" type="number" name="iron" placeholder="Geležis" style="width:140px;" min="0" value="0">
              <input class="in" type="number" name="crop" placeholder="Javai" style="width:140px;" min="0" value="0">
            </div>
            <div class="hint" style="margin-top:6px;">Įvesk kiekį kiekvieno resurso</div>

            <button class="btn" type="submit" style="margin-top:10px;">Siųsti</button>
          </div>

          <div class="card" style="padding:12px;">
            <h4 style="margin:0 0 6px 0;">Turimi resursai</h4>
            <div class="hint">Mediena: <b><?php echo (int)$v['wood']; ?></b></div>
            <div class="hint">Molis: <b><?php echo (int)$v['clay']; ?></b></div>
            <div class="hint">Geležis: <b><?php echo (int)$v['iron']; ?></b></div>
            <div class="hint">Javai: <b><?php echo (int)$v['crop']; ?></b></div>
            <div class="hint" style="margin-top:8px;">Pastaba: talpos ir prekybos limitus dar galim patobulinti pagal Travian (pirkliai, greitis, sandėliai, t.t.).</div>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:10px;">
      <h3 style="margin:0 0 10px 0;">Išsiųstos siuntos</h3>
      <?php if (empty($outgoing)): ?>
        <div class="hint">Nėra siuntų.</div>
      <?php else: ?>
        <div class="tableWrap">
          <table class="miniTable">
            <thead>
              <tr>
                <th>ID</th><th>Į</th><th>Koord.</th><th>Resursai</th><th>Pirkliai</th><th>Išvyko</th><th>Atvyks</th><th>Statusas</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($outgoing as $o): ?>
                <tr>
                  <td><?php echo (int)$o['id']; ?></td>
                  <td><?php echo h((string)($o['to_name'] ?? '')); ?></td>
                  <td>(<?php echo (int)$o['to_x']; ?>|<?php echo (int)$o['to_y']; ?>)</td>
                  <td><?php echo (int)$o['wood']; ?>/<?php echo (int)$o['clay']; ?>/<?php echo (int)$o['iron']; ?>/<?php echo (int)$o['crop']; ?></td>
                  <td><?php echo (int)$o['merchants']; ?></td>
                  <td><?php echo h($o['depart_at']); ?></td>
                  <td><?php echo h($o['arrive_at']); ?></td>
                  <td><?php echo h($o['status']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:10px;">
      <h3 style="margin:0 0 10px 0;">Gautos siuntos</h3>
      <?php if (empty($incoming)): ?>
        <div class="hint">Nėra siuntų.</div>
      <?php else: ?>
        <div class="tableWrap">
          <table class="miniTable">
            <thead>
              <tr>
                <th>ID</th><th>Iš</th><th>Resursai</th><th>Pirkliai</th><th>Išvyko</th><th>Atvyks</th><th>Statusas</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($incoming as $o): ?>
                <tr>
                  <td><?php echo (int)$o['id']; ?></td>
                  <td><?php echo h((string)($o['from_name'] ?? '')); ?></td>
                  <td><?php echo (int)$o['wood']; ?>/<?php echo (int)$o['clay']; ?>/<?php echo (int)$o['iron']; ?>/<?php echo (int)$o['crop']; ?></td>
                  <td><?php echo (int)$o['merchants']; ?></td>
                  <td><?php echo h($o['depart_at']); ?></td>
                  <td><?php echo h($o['arrive_at']); ?></td>
                  <td><?php echo h($o['status']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</body>
</html>
