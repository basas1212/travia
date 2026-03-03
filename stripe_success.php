<?php
require_once __DIR__ . '/init.php';
$activePage = 'gold';
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mokėjimas sėkmingas</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .payOkWrap{max-width:900px;margin:0 auto;padding:16px;}
    .payCard{border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:16px;}
    .payOkIcon{font-size:44px;line-height:1;margin:6px 0 10px;}
    .payBtns{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    .payBtn{display:inline-block;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);text-decoration:none}
    .paySub{opacity:.85}
  </style>
</head>
<body>
<?php if (file_exists('ui_topbar.php')) include 'ui_topbar.php'; ?>
<div class="payOkWrap">
  <div class="payCard">
    <div class="payOkIcon">✅</div>
    <h1 style="margin:0 0 8px;">Mokėjimas sėkmingas</h1>
    <p class="paySub">Ačiū! Auksas jau turėtų būti pridėtas. Jei dar nematai – atnaujink puslapį po kelių sekundžių.</p>

    <div class="payBtns">
      <a class="payBtn" href="/game/game.php">Grįžti į žaidimą</a>
      <a class="payBtn" href="/gold_shop.php">Pirkti daugiau</a>
    </div>

    <p class="paySub" style="margin-top:14px;">Automatiškai grąžinsime į žaidimą po <span id="sec">5</span> s.</p>
  </div>
</div>

<script>
let s=5;
const el=document.getElementById('sec');
const t=setInterval(()=>{
  s--; if(el) el.textContent=String(s);
  if(s<=0){ clearInterval(t); window.location.href='/game/game.php'; }
},1000);
</script>
</body>
</html>
