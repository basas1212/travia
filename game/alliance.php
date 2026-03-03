<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
if (!$user) redirect('/login.php');

$uid = (int)$user['id'];

function get_my_alliance(mysqli $db, int $uid): ?array {
  if (!table_exists($db, 'alliance_members') || !table_exists($db, 'alliances')) return null;
  $st = $db->prepare("SELECT a.*, am.role
                      FROM alliance_members am
                      JOIN alliances a ON a.id=am.alliance_id
                      WHERE am.user_id=? LIMIT 1");
  $st->bind_param('i', $uid);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row ?: null;
}

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  if (!table_exists($mysqli, 'alliances') || !table_exists($mysqli, 'alliance_members')) {
    $flash = 'Klaida: aljansų sistema dar nesukurta (paleisk migracijas).';
  } else {

    if ($action === 'create') {
      $tag = strtoupper(trim((string)($_POST['tag'] ?? '')));
      $name = trim((string)($_POST['name'] ?? ''));
      $desc = trim((string)($_POST['description'] ?? ''));

      if ($tag === '' || $name === '' || strlen($tag) > 10) {
        $flash = 'Klaida: įvesk TAG (iki 10) ir pavadinimą.';
      } else {
        $my = get_my_alliance($mysqli, $uid);
        if ($my) {
          $flash = 'Tu jau esi aljanse.';
        } else {
          try {
            $st = $mysqli->prepare('INSERT INTO alliances (tag, name, description, created_by) VALUES (?,?,?,?)');
            $st->bind_param('sssi', $tag, $name, $desc, $uid);
            $st->execute();
            $aid = (int)$mysqli->insert_id;
            $st->close();

            $st = $mysqli->prepare("INSERT INTO alliance_members (alliance_id, user_id, role) VALUES (?,?, 'leader')");
            $st->bind_param('ii', $aid, $uid);
            $st->execute();
            $st->close();

            $flash = 'Aljansas sukurtas.';
          } catch (Throwable $e) {
            $flash = 'Klaida: nepavyko sukurti (gal TAG jau užimtas).';
          }
        }
      }
    }

    if ($action === 'join') {
      $tag = strtoupper(trim((string)($_POST['tag'] ?? '')));
      $my = get_my_alliance($mysqli, $uid);
      if ($my) {
        $flash = 'Tu jau esi aljanse.';
      } else if ($tag === '') {
        $flash = 'Klaida: įvesk TAG.';
      } else {
        $st = $mysqli->prepare('SELECT id FROM alliances WHERE tag=? LIMIT 1');
        $st->bind_param('s', $tag);
        $st->execute();
        $a = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$a) {
          $flash = 'Klaida: aljansas nerastas.';
        } else {
          $aid = (int)$a['id'];
          try {
            $st = $mysqli->prepare("INSERT INTO alliance_members (alliance_id, user_id, role) VALUES (?,?, 'member')");
            $st->bind_param('ii', $aid, $uid);
            $st->execute();
            $st->close();
            $flash = 'Prisijungei prie aljanso.';
          } catch (Throwable $e) {
            $flash = 'Klaida: nepavyko prisijungti.';
          }
        }
      }
    }

    if ($action === 'leave') {
      $my = get_my_alliance($mysqli, $uid);
      if (!$my) {
        $flash = 'Tu nesi aljanse.';
      } else {
        $aid = (int)$my['id'];
        $role = (string)$my['role'];
        if ($role === 'leader') {
          $flash = 'Lyderis negali išeiti. Paskirk kitą lyderį (vėliau įdėsim).';
        } else {
          $st = $mysqli->prepare('DELETE FROM alliance_members WHERE alliance_id=? AND user_id=? LIMIT 1');
          $st->bind_param('ii', $aid, $uid);
          $st->execute();
          $st->close();
          $flash = 'Išėjai iš aljanso.';
        }
      }
    }
  }

  redirect('alliance.php?flash=' . urlencode($flash));
}

if ($flash === '' && isset($_GET['flash'])) $flash = (string)$_GET['flash'];

$my = get_my_alliance($mysqli, $uid);

$members = [];
if ($my) {
  $aid = (int)$my['id'];
  $st = $mysqli->prepare("SELECT u.id, u.username, u.tribe, am.role, am.joined_at
                          FROM alliance_members am
                          JOIN users u ON u.id=am.user_id
                          WHERE am.alliance_id=?
                          ORDER BY FIELD(am.role,'leader','officer','member'), u.username ASC");
  $st->bind_param('i', $aid);
  $st->execute();
  $res = $st->get_result();
  while ($res && ($r = $res->fetch_assoc())) $members[] = $r;
  $st->close();
}

$activePage = 'alliance';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('nav_alliance')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
  <div class="page">
    <div class="shell">

      <?php include __DIR__ . '/../ui_topbar.php'; ?>

      <div class="panel">
        <div class="panelHeader">
          <div class="panelTitle"><?php echo h(t('nav_alliance')); ?></div>
          <div class="panelSub">MVP: sukurti / prisijungti / nariai</div>
        </div>

        <?php if ($flash !== ''): ?>
          <div class="card" style="margin-bottom:10px;"><b><?php echo h($flash); ?></b></div>
        <?php endif; ?>

        <?php if (!$my): ?>
          <div class="card" style="margin-bottom:10px;">
            <h3 style="margin:0 0 10px 0;">Sukurti aljansą</h3>
            <form method="post" class="row" style="gap:8px;flex-wrap:wrap;">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="create">
              <input class="in" name="tag" placeholder="TAG (pvz. LT)" style="width:120px;">
              <input class="in" name="name" placeholder="Pavadinimas" style="min-width:220px;">
              <input class="in" name="description" placeholder="Aprašymas (nebūtina)" style="min-width:240px;">
              <button class="btn" type="submit">Sukurti</button>
            </form>
          </div>

          <div class="card">
            <h3 style="margin:0 0 10px 0;">Prisijungti prie aljanso</h3>
            <form method="post" class="row" style="gap:8px;flex-wrap:wrap;">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="join">
              <input class="in" name="tag" placeholder="TAG" style="width:120px;">
              <button class="btn" type="submit">Prisijungti</button>
            </form>
            <div class="hint" style="margin-top:8px;">Kitas žingsnis: kvietimai, prašymai, rangai, aljanso chat.</div>
          </div>
        <?php else: ?>
          <div class="card" style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
              <div>
                <div style="font-weight:900;font-size:16px;"><?php echo h($my['tag']); ?> — <?php echo h($my['name']); ?></div>
                <?php if (!empty($my['description'])): ?>
                  <div class="hint" style="margin-top:6px;"><?php echo h($my['description']); ?></div>
                <?php endif; ?>
                <div class="hint" style="margin-top:6px;">Tavo rolė: <b><?php echo h($my['role']); ?></b></div>
              </div>
              <form method="post">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="leave">
                <button class="btn" type="submit">Išeiti</button>
              </form>
            </div>
          </div>

          <div class="card">
            <h3 style="margin:0 0 10px 0;">Nariai</h3>
            <div class="tableWrap">
              <table class="miniTable">
                <thead><tr><th>ID</th><th>Vardas</th><th>Gentis</th><th>Rolė</th><th>Prisijungė</th></tr></thead>
                <tbody>
                  <?php foreach ($members as $m): ?>
                    <tr>
                      <td><?php echo (int)$m['id']; ?></td>
                      <td><?php echo h($m['username']); ?></td>
                      <td><?php echo h($m['tribe']); ?></td>
                      <td><?php echo h($m['role']); ?></td>
                      <td><?php echo h($m['joined_at']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

      </div>

      <div class="panelFooter">
        <a class="btn" href="game.php"><?php echo h(t('nav_menu')); ?></a>
      </div>

    </div>
  </div>
</body>
</html>
