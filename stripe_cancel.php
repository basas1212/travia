<?php
require_once __DIR__ . '/init.php';
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mokėjimas atšauktas</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php if (file_exists('ui_topbar.php')) include 'ui_topbar.php'; ?>
<div style="max-width:900px;margin:0 auto;padding:16px;">
  <h1>Mokėjimas atšauktas</h1>
  <p>Mokėjimas nebuvo atliktas.</p>
  <p><a href="/gold_shop.php">Grįžti į parduotuvę</a></p>
</div>
</body>
</html>
