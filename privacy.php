<?php
// Minimal legal page (no dependencies) — avoids blank pages if header/nav includes fail.
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
  <title>Privatumo politika</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div style="max-width:900px;margin:0 auto;padding:20px;">
    <h1>Privatumo politika</h1>

    <p>Mes valdome internetinį strateginį naršyklinį žaidimą TRAVIA. Renkame tik būtinus duomenis (pvz., el. pašto adresą, prisijungimo informaciją ir žaidimo duomenis), kad galėtume teikti paslaugą, užtikrinti saugumą ir palaikyti paskyras.</p>

    <p>Asmens duomenų neparduodame ir neperduodame tretiesiems asmenims rinkodaros tikslais. Mokėjimus apdoroja saugūs mokėjimų paslaugų teikėjai (pvz., Stripe), todėl kortelių duomenys mūsų serveryje nesaugomi.</p>

    <p>Jei turite klausimų dėl privatumo ar duomenų, susisiekite: <strong><?php echo htmlspecialchars($support_email); ?></strong></p>

    <p><a href="index.php">Grįžti į pradžią</a></p>
  </div>
</body>
</html>
