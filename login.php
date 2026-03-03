<?php
require_once __DIR__ . '/init.php';

if (is_logged_in()) redirect('/game/game.php');

// lang switch (jei projekte nėra current_lang logikos per GET)
if (isset($_GET['lang'])) {
  $_SESSION['lang'] = ($_GET['lang'] === 'en') ? 'en' : 'lt';
}
$lang = function_exists('current_lang') ? current_lang() : ($_SESSION['lang'] ?? 'lt');
$isEn = ($lang === 'en');

$next = (string)($_GET['next'] ?? '');
if ($next === '' || $next[0] !== '/') $next = '/game/game.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $nextPost = (string)($_POST['next'] ?? '');
  if ($nextPost !== '' && $nextPost[0] === '/') $next = $nextPost;

  if ($username === '' || $password === '') {
    $err = $isEn ? 'Enter username and password.' : 'Įvesk vartotojo vardą ir slaptažodį.';
  } else {
    $stmt = $mysqli->prepare('SELECT id, password, is_admin FROM users WHERE username=? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || !password_verify($password, (string)$row['password'])) {
      $err = $isEn ? 'Invalid login details.' : 'Neteisingi prisijungimo duomenys.';
    } else {
      // Iki starto leidžiam tik adminams
      if (defined('LAUNCH_TS') && time() < (int)LAUNCH_TS && empty($row['is_admin'])) {
        $err = $isEn ? 'Login is available after server start.' : 'Prisijungimas galimas po serverio starto.';
      } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['id'];

        $uid = (int)$row['id'];
        $v = $mysqli->query("SELECT id FROM villages WHERE user_id={$uid} ORDER BY id ASC LIMIT 1");
        if ($v && ($vr = $v->fetch_assoc())) {
          $_SESSION['village_id'] = (int)$vr['id'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) {
          $stmt = $mysqli->prepare('UPDATE users SET last_ip=? WHERE id=?');
          $stmt->bind_param('si', $ip, $uid);
          $stmt->execute();
          $stmt->close();
        }

        redirect($next);
      }
    }
  }
}

function hh($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function auth_bg_urls(): array {
  $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  if ($base === '') $base = '';

  $sets = [
    ['webp'=>'hero_battle.webp','jpg'=>'hero_battle.jpg'],
    ['webp'=>'background.webp','jpg'=>'background.jpg'],
    ['webp'=>null,'jpg'=>'background.jpg'],
  ];

  foreach ($sets as $s) {
    $webp = null; $jpg = null; $ok = false;
    if (!empty($s['webp']) && file_exists(__DIR__ . '/' . $s['webp'])) { $webp = $base . '/' . $s['webp']; $ok = true; }
    if (!empty($s['jpg'])  && file_exists(__DIR__ . '/' . $s['jpg']))  { $jpg  = $base . '/' . $s['jpg'];  $ok = true; }
    if ($ok) return [$webp, $jpg];
  }
  return [null, null];
}

[$bgWebp, $bgJpg] = auth_bg_urls();
?>
<!doctype html>
<html lang="<?= hh($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $isEn ? 'Login' : 'Prisijungti' ?> - TRAVIA</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/style.css">

  <style>
    /* index-like background, bet nekeičiant tavo UI */
    body.auth-page{background:#000;min-height:100vh;}
    body.auth-page::before{
      content:"";
      position:fixed; inset:0; z-index:-4;
      background:
        <?php if ($bgWebp): ?>url('<?= hh($bgWebp) ?>') center/cover no-repeat,<?php endif; ?>
        <?php if ($bgJpg): ?>url('<?= hh($bgJpg) ?>') center/cover no-repeat<?php else: ?>linear-gradient(180deg, #111, #000)<?php endif; ?>;
      transform: scale(1.02);
      filter: saturate(1.08) contrast(1.06) brightness(.90);
    }
    body.auth-page::after{
      content:"";
      position:fixed; inset:0; z-index:-3;
      background:
        radial-gradient(1200px 680px at 50% 20%, rgba(255,215,0,.14), transparent 55%),
        radial-gradient(1200px 900px at 50% 95%, rgba(0,0,0,.55), rgba(0,0,0,.90)),
        linear-gradient(to bottom, rgba(0,0,0,.45), rgba(0,0,0,.86));
    }

    /* viršus kaip index */
    .auth-top{width:min(920px, 92vw);margin:14px auto 0;display:flex;gap:10px;align-items:center;justify-content:space-between;}
    .auth-pill{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-weight:900;font-size:12px;color:rgba(255,255,255,.82);padding:10px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);}
    .auth-pill.active{border-color: rgba(255,215,0,.35); box-shadow: 0 0 0 2px rgba(255,215,0,.10) inset;}

    /* suvienodinam brand */
    .auth-logo{font-family:Cinzel,serif;font-weight:900;letter-spacing:6px;background:linear-gradient(180deg,#fff6b0 0%,#ffd700 25%,#ffb300 55%,#8b5a00 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-shadow:0 0 12px rgba(255,180,0,.35);} 
  </style>
</head>
<body class="auth-page">

  <div class="auth-top">
    <a class="auth-pill" href="index.php">🏠 <?= $isEn ? 'Home' : 'Pagrindinis' ?></a>
    <div style="display:flex;gap:10px;">
      <a class="auth-pill <?= $lang==='lt'?'active':'' ?>" href="?lang=lt">LT</a>
      <a class="auth-pill <?= $lang==='en'?'active':'' ?>" href="?lang=en">EN</a>
    </div>
  </div>

  <div class="auth-wrap">
    <div class="auth-brand">
      <div class="auth-logo">TRAVIA</div>
      <div class="auth-sub"><?= $isEn ? 'Login' : 'Prisijungti' ?></div>
    </div>

    <div class="auth-card">
      <div class="auth-title"><?= $isEn ? 'Login' : 'Prisijungti' ?></div>

      <?php if ($err): ?>
        <div class="auth-alert"><?php echo h($err); ?></div>
      <?php endif; ?>

      <form class="auth-form" method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="next" value="<?php echo h($next); ?>">

        <div class="auth-field">
          <label><?= $isEn ? 'Username' : 'Vartotojo vardas' ?></label>
          <input name="username" value="<?php echo h($_POST['username'] ?? ''); ?>" placeholder="<?= $isEn ? 'e.g. player' : 'Pvz. basas' ?>" required>
        </div>

        <div class="auth-field">
          <label><?= $isEn ? 'Password' : 'Slaptažodis' ?></label>
          <input type="password" name="password" placeholder="••••••••" required>
        </div>

        <button class="auth-btn" type="submit"><?= $isEn ? 'Login' : 'Prisijungti' ?></button>
      </form>

      <div class="auth-bottom" style="text-align:center;margin-top:12px;">
        <a class="auth-link" href="register.php"><?= $isEn ? "Don't have an account? Register" : 'Neturi paskyros? Registruotis' ?></a>
      </div>
    </div>

    <div class="auth-footer">&copy; <?php echo date('Y'); ?> TRAVIA</div>
  </div>
</body>
</html>
