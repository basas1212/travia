<?php
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/legal_pages_error.log');
error_reporting(E_ALL);

$support_email = 'support@' . preg_replace('/^www\./','', $_SERVER['HTTP_HOST']);
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kontaktai</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div style="max-width:900px;margin:0 auto;padding:20px;">
    <h1>Kontaktai</h1>
    <p>El. paštas: <strong><?php echo htmlspecialchars($support_email); ?></strong></p>
    <p>Šalis: Lietuva</p>
    <p><a href="index.php">Grįžti į pradžią</a></p>
  </div>
</body>
</html>
