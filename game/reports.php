<?php
require_once __DIR__ . '/../init.php';

require_login();
$user = current_user($mysqli);
if (!$user) redirect('/login.php');

$uid = (int)$user['id'];

$viewId = (int)($_GET['id'] ?? 0);

// pažymėti perskaityta + gauti vieną
$report = null;
if ($viewId > 0) {
  $st = $mysqli->prepare('SELECT * FROM reports WHERE id=? AND user_id=? LIMIT 1');
  $st->bind_param('ii', $viewId, $uid);
  $st->execute();
  $report = $st->get_result()->fetch_assoc();
  $st->close();

  if ($report && (int)$report['is_read'] === 0) {
    $st = $mysqli->prepare('UPDATE reports SET is_read=1 WHERE id=? AND user_id=? LIMIT 1');
    $st->bind_param('ii', $viewId, $uid);
    $st->execute();
    $st->close();
    $report['is_read'] = 1;
  }
}

// sąrašas
$st = $mysqli->prepare('SELECT id, type, title, is_read, created_at FROM reports WHERE user_id=? ORDER BY id DESC LIMIT 200');
$st->bind_param('i', $uid);
$st->execute();
$res = $st->get_result();
$rows = [];
while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
$st->close();

$activePage = 'reports';
?>
<!doctype html>
<html lang="<?php echo h(current_lang()); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h(t('nav_reports')); ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css?v=20260301">
</head>
<body>
  <div class="page">
    <div class="shell">

      <?php include __DIR__ . '/../ui_topbar.php'; ?>

      <div class="panel">
        <div class="panelHeader">
          <div class="panelTitle"><?php echo h(t('nav_reports')); ?></div>
          <div class="panelSub">Paskutinės 200 ataskaitų</div>
        </div>

        <?php if ($report): ?>
          <div class="card" style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
              <div>
                <div style="font-weight:800;font-size:16px;"><?php echo h($report['title']); ?></div>
                <div class="hint"><?php echo h($report['created_at']); ?></div>
              </div>
              <a class="btn" href="reports.php">Atgal</a>
            </div>
            <div style="margin-top:10px;white-space:pre-wrap;"><?php echo h($report['body']); ?></div>
          </div>
        <?php endif; ?>

        <div class="list">
          <?php foreach ($rows as $r): ?>
            <div class="listRow">
              <div class="listMain">
                <div class="listTitle">
                  <?php if ((int)$r['is_read'] === 0): ?>
                    <span class="badge">Nauja</span>
                  <?php endif; ?>
                  <?php echo h($r['title']); ?>
                </div>
                <div class="listMeta"><?php echo h($r['created_at']); ?> · <?php echo h($r['type']); ?></div>
              </div>
              <div class="listAct"><a class="upBtn" href="reports.php?id=<?php echo (int)$r['id']; ?>"><span class="ico">→</span></a></div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <div class="hint" style="padding:12px;">Ataskaitų nėra.</div>
          <?php endif; ?>
        </div>

      </div>

      <div class="panelFooter">
        <a class="btn" href="game.php"><?php echo h(t('nav_menu')); ?></a>
      </div>

    </div>
  </div>
</body>
</html>
