<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
if (!$user) redirect('/login.php');

$uid = (int)$user['id'];

if (!table_exists($mysqli, 'heroes')) {
  // jei migracija dar neįvykdyta
  $activePage = 'hero';
  ?>
  <!doctype html>
  <html lang="<?php echo h(current_lang()); ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h(t('nav_hero')); ?> - TRAVIA</title>
    <link rel="stylesheet" href="/style.css?v=20260301">
  </head>
  <body>
    <div class="page"><div class="shell">
      <?php include __DIR__ . '/../ui_topbar.php'; ?>
      <div class="panel">
        <div class="panelHeader"><div class="panelTitle"><?php echo h(t('nav_hero')); ?></div></div>
        <div class="card">Herojų lentelė nerasta. Paleisk migracijas (init.php dabar jas vykdo automatiškai).</div>
      </div>
      <div class="panelFooter"><a class="btn" href="game.php"><?php echo h(t('nav_menu')); ?></a></div>
    </div></div>
  </body>
  </html>
  <?php
  exit;
}

function get_or_create_hero(mysqli $db, int $uid): array {
  $st = $db->prepare('SELECT * FROM heroes WHERE user_id=? LIMIT 1');
  $st->bind_param('i', $uid);
  $st->execute();
  $h = $st->get_result()->fetch_assoc();
  $st->close();
  if ($h) return $h;

  // MVP: start level 1, 0 xp, 5 free points
  $free = 5;
  $st = $db->prepare('INSERT INTO heroes (user_id, free_points) VALUES (?,?)');
  $st->bind_param('ii', $uid, $free);
  $st->execute();
  $st->close();

  $st = $db->prepare('SELECT * FROM heroes WHERE user_id=? LIMIT 1');
  $st->bind_param('i', $uid);
  $st->execute();
  $h = $st->get_result()->fetch_assoc();
  $st->close();
  return $h ?: ['user_id'=>$uid,'level'=>1,'xp'=>0,'free_points'=>$free,'stat_attack'=>0,'stat_defense'=>0,'stat_production'=>0];
}

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $stat = (string)($_POST['stat'] ?? '');
  $hero = get_or_create_hero($mysqli, $uid);
  $free = (int)($hero['free_points'] ?? 0);

  if ($free <= 0) {
    $flash = 'Neturi laisvų taškų.';
  } else if (!in_array($stat, ['attack','defense','production'], true)) {
    $flash = 'Klaida.';
  } else {
    $col = $stat === 'attack' ? 'stat_attack' : ($stat === 'defense' ? 'stat_defense' : 'stat_production');
    $mysqli->query("UPDATE heroes SET {$col} = {$col} + 1, free_points = GREATEST(0, free_points - 1) WHERE user_id=".(int)$uid." LIMIT 1");
    $flash = 'Atlikta.';
  }

  redirect('hero.php?flash=' . urlencode($flash));
}

if ($flash === '' && isset($_GET['flash'])) $flash = (string)$_GET['flash'];

$hero = get_or_create_hero($mysqli, $uid);

$activePage = 'hero';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('nav_hero')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
  <div class="page">
    <div class="shell">

      <?php include __DIR__ . '/../ui_topbar.php'; ?>

      <div class="panel">
        <div class="panelHeader">
          <div class="panelTitle"><?php echo h(t('nav_hero')); ?></div>
          <div class="panelSub">MVP: taškai (ataka / gynyba / produkcija)</div>
        </div>

        <?php if ($flash !== ''): ?>
          <div class="card" style="margin-bottom:10px;"><b><?php echo h($flash); ?></b></div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:10px;">
          <div class="grid2">
            <div class="stat"><div class="statNum"><?php echo (int)$hero['level']; ?></div><div class="statLbl">Lygis</div></div>
            <div class="stat"><div class="statNum"><?php echo (int)$hero['xp']; ?></div><div class="statLbl">XP</div></div>
            <div class="stat"><div class="statNum"><?php echo (int)$hero['free_points']; ?></div><div class="statLbl">Laisvi taškai</div></div>
          </div>
          <div class="hint" style="margin-top:8px;">Produkcijos taškai duoda +1% visai gamybai (MVP).</div>
        </div>

        <div class="card">
          <h3 style="margin:0 0 10px 0;">Atributai</h3>
          <div class="tableWrap">
            <table class="miniTable">
              <thead><tr><th>Atributas</th><th>Vertė</th><th>+1</th></tr></thead>
              <tbody>
                <tr>
                  <td>Ataka</td>
                  <td><?php echo (int)$hero['stat_attack']; ?></td>
                  <td>
                    <form method="post"><?php echo csrf_input(); ?>
                      <input type="hidden" name="stat" value="attack">
                      <button class="btn" type="submit">+1</button>
                    </form>
                  </td>
                </tr>
                <tr>
                  <td>Gynyba</td>
                  <td><?php echo (int)$hero['stat_defense']; ?></td>
                  <td>
                    <form method="post"><?php echo csrf_input(); ?>
                      <input type="hidden" name="stat" value="defense">
                      <button class="btn" type="submit">+1</button>
                    </form>
                  </td>
                </tr>
                <tr>
                  <td>Produkcija</td>
                  <td><?php echo (int)$hero['stat_production']; ?></td>
                  <td>
                    <form method="post"><?php echo csrf_input(); ?>
                      <input type="hidden" name="stat" value="production">
                      <button class="btn" type="submit">+1</button>
                    </form>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <div class="panelFooter">
        <a class="btn" href="game.php"><?php echo h(t('nav_menu')); ?></a>
      </div>

    </div>
  </div>
</body>
</html>
