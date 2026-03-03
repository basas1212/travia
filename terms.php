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
  <title>Naudojimo taisyklės</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div style="max-width:900px;margin:0 auto;padding:20px;">
    <h1>Naudojimo taisyklės</h1>

    <p>Ši svetainė teikia internetinio strateginio žaidimo paslaugą.</p>

    <p>Mokami pirkimai yra skirti virtualiai žaidimo valiutai ir (ar) premium funkcijoms. Tai skaitmeninės prekės, kurios pristatomos iškart žaidime po sėkmingo apmokėjimo.</p>

    <p>Paskyra yra asmeninė. Draudžiama bandyti apeiti žaidimo taisykles, piktnaudžiauti klaidomis ar naudoti automatizavimo priemones. Administracija pasilieka teisę laikinai sustabdyti ar užblokuoti paskyrą už taisyklių pažeidimus.</p>

    <p>Dėl skaitmeninių prekių pobūdžio visi pardavimai laikomi galutiniais, nebent įstatymai numato kitaip. Jei mokėjimas atliktas per klaidą arba susidūrėte su technine problema – parašykite: <strong><?php echo htmlspecialchars($support_email); ?></strong></p>

    <p><a href="index.php">Grįžti į pradžią</a></p>
  </div>
</body>
</html>
