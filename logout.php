<?php
require_once __DIR__ . '/init.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// simple success page
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Atsijungta - TRAVIA</title>
  <link rel="stylesheet" href="/style.css">
</head>
<body class="auth-page">
  <div class="auth-bg"></div>
  <div class="auth-wrap">
    <div class="auth-card">
      <div class="auth-title">Sėkmingai atsijungei ✅</div>
      <div style="text-align:center;margin-top:12px;">
        <a class="auth-link" href="login.php">Prisijungti iš naujo</a>
      </div>
    </div>
    <div class="auth-footer">&copy; <?php echo date('Y'); ?> TRAVIA</div>
  </div>
</body>
</html>
