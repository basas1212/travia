<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/stripe_config.php';

$activePage = 'gold';

require_login();
csrf_verify();

$pkgs = [
  'p5'  => ['name' => '100 Aukso',  'gold' => 100,  'eur' => 5],
  'p10' => ['name' => '250 Aukso',  'gold' => 250,  'eur' => 10],
  'p20' => ['name' => '600 Aukso',  'gold' => 600,  'eur' => 20],
  'p50' => ['name' => '1700 Aukso', 'gold' => 1700, 'eur' => 50],
];

?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Aukso parduotuvė</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php if (file_exists('ui_topbar.php')) include 'ui_topbar.php'; ?>

<div style="max-width:900px;margin:0 auto;padding:16px;">
  <h1>Aukso parduotuvė</h1>
  <p>Pasirink paketą. Apmokėjimas vyksta per Stripe. Režimas: <strong><?php echo h(STRIPE_MODE); ?></strong>.</p>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
    <?php foreach ($pkgs as $id => $p): ?>
      <div style="border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px;">
        <div style="font-size:18px;font-weight:700;"><?php echo h($p['name']); ?></div>
        <div style="opacity:.9;margin-top:6px;"><?php echo (int)$p['eur']; ?> €</div>
        <div style="opacity:.85;margin-top:6px;">+<?php echo (int)$p['gold']; ?> aukso</div>

        <button
          type="button"
          data-package="<?php echo h($id); ?>"
          style="margin-top:12px;width:100%;padding:10px 12px;border-radius:10px;border:none;cursor:pointer;">
          Pirkti
        </button>
      </div>
    <?php endforeach; ?>
  </div>

  <p style="margin-top:14px;opacity:.85;font-size:14px;">
    Pastaba: auksas pridedamas automatiškai gavus patvirtinimą iš Stripe (webhook). Testuojant tai įvyksta iškart.
  </p>
</div>

<script>
async function buy(pkgId){
  const form = new FormData();
  form.append('package_id', pkgId);
  form.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);

  const res = await fetch('/payments/stripe_create_session.php', { method:'POST', body: form });
  const data = await res.json();
  if(!data.ok){
    alert(data.error || 'Klaida');
    return;
  }
  if(data.url){
    window.location.href = data.url;
  } else {
    alert('Negauta Stripe nuoroda.');
  }
}

document.querySelectorAll('button[data-package]').forEach(btn=>{
  btn.addEventListener('click', ()=>buy(btn.getAttribute('data-package')));
});
</script>
</body>
</html>
