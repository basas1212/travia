<?php
require_once __DIR__ . '/init.php';


// --- Kalba (LT/EN) per session + ?lang=lt|en ---
if (isset($_GET['lang'])) {
  $_SESSION['lang'] = ($_GET['lang'] === 'en') ? 'en' : 'lt';
}
$lang = $_SESSION['lang'] ?? 'lt';


$supportEmail = 'support@' . ($_SERVER['HTTP_HOST'] ?? 'example.com');
$T = [
  'lt' => [
    'page_title' => 'TRAVIA — moderni strategija telefone ir naršyklėje',
    'nav_features' => 'Kodėl Travia?',
    'nav_tribes' => 'Gentys',
    'nav_rules' => 'Taisyklės',
    'nav_faq' => 'DUK',

    'headline_1' => 'Naujos kartos strateginis žaidimas <b>telefone ir naršyklėje</b>.',
    'headline_2' => 'Kurk kaimą, rink resursus, treniruok kariuomenę, junkis į aljansus ir dominuok serveryje.',
    'btn_play' => 'Žaisti dabar',
    'btn_login' => 'Prisijungti',

    'reg_open' => 'Registracija atidaryta — gali kurti paskyrą.',
    'reg_closed' => 'Registracija atsidarys starto metu. Prisijungimas iki starto leidžiamas tik administracijai.',

    'count_title' => 'Serverio startas',
    'count_when' => 'Žaidimas startuos %s (LT)',
    'cd_days' => 'Dienos',
    'cd_hours' => 'Valandos',
    'cd_mins' => 'Minutės',
    'cd_secs' => 'Sekundės',

    'status_locked' => 'Užrakinta iki starto',
    'status_live' => 'Serveris LIVE',
    'note_reg_closed' => 'Registracija atsidarys starto metu. Prisijungimas iki starto leidžiamas tik administracijai.',
    'note_reg_open' => 'Registracija atidaryta (laikinai). Prisijungimas iki starto leidžiamas tik administracijai.',
    'note_started' => 'Serveris startavo — registruokis arba prisijunk!',

    'cta_more' => 'Sužinoti daugiau',
    'cta_pick' => 'Pasirink gentį',

    'prize' => 'Serverio prizas: 100€',

    'features_title' => 'Kodėl Travia.lt?',
    'features_p' => 'Travian tipo klasika, bet 2026 lygio: modernus dizainas, greitas valdymas telefone, įtraukiantis PvP, aljansai, herojus ir fortai. Jokio hard pay-to-win — laimi strategija.',

    'tribes_title' => '5 gentys',
    'tribes_p' => 'Pasirink savo žaidimo stilių — kiekviena gentis turi skirtingus pranašumus.',

    'tribe_roman' => 'Romėnai',
    'tribe_roman_s' => 'Balansas + greitesnė statyba',
    'tribe_gaul' => 'Galai',
    'tribe_gaul_s' => 'Gynyba + greitis',
    'tribe_german' => 'Germanai',
    'tribe_german_s' => 'Agresija + plėšimas',
    'tribe_hun' => 'Hunai',
    'tribe_hun_s' => 'Žaibiška kavalerija',
    'tribe_egypt' => 'Egiptiečiai',
    'tribe_egypt_s' => 'Ekonomika + ilgalaikė plėtra',

    'village_rules_title' => 'Kaimų steigimo taisyklė',
    'village_rules_p' => '<b>Maksimaliai 3 kaimai</b> (1 pradinis + 2 papildomi). Steigimas vyksta iš to paties kaimo.',
    'village_rule_10' => 'Rezidencija arba Valdovo rūmai → gali įkurti <b>1 papildomą kaimą</b>.',
    'village_rule_20' => 'Rezidencija arba Valdovo rūmai → gali įkurti <b>dar 1 kaimą</b> (ir tai maksimumas).',

    'open_rules' => 'Atidaryti serverio taisykles',

    'faq_title' => 'DUK',
    'faq_q1' => 'Ar žaidimas bus „pay to win“?',
    'faq_a1' => 'Ne. Premium orientuotas į patogumą ir kosmetiką — pergales lemia planas, aktyvumas ir aljansas.',
    'faq_q2' => 'Ar bus aplikacija?',
    'faq_a2' => 'Taip. UI kuriamas mobile-first, todėl žaidimas pilnai patogus telefone (naršyklėje ir aplikacijos formatu).',
    'faq_q3' => 'Kada atsidaro registracija?',
    'faq_a3' => 'Registracija atsidaro starto metu (nebent administratorius įjungia ankstesnį atidarymą).',

    'rules_modal_title' => 'Serverio taisyklės',
    'back' => 'Grįžti',
    'units' => 'Kariai',
  ],
  'en' => [
    'page_title' => 'TRAVIA — modern strategy for mobile & web',
    'nav_features' => 'Why Travia?',
    'nav_tribes' => 'Tribes',
    'nav_rules' => 'Rules',
    'nav_faq' => 'FAQ',

    'headline_1' => 'Next-gen strategy game for <b>mobile and web</b>.',
    'headline_2' => 'Build villages, gather resources, train armies, join alliances and dominate the server.',
    'btn_play' => 'Play now',
    'btn_login' => 'Login',

    'reg_open' => 'Registration is open — you can create an account.',
    'reg_closed' => 'Registration opens at server start. Before start, only admins can log in.',

    'count_title' => 'Server start',
    'count_when' => 'Server starts %s (LT)',
    'cd_days' => 'Days',
    'cd_hours' => 'Hours',
    'cd_mins' => 'Minutes',
    'cd_secs' => 'Seconds',

    'status_locked' => 'Locked until start',
    'status_live' => 'Server is LIVE',
    'note_reg_closed' => 'Registration opens at server start. Before start, only admins can log in.',
    'note_reg_open' => 'Registration is temporarily open. Before start, only admins can log in.',
    'note_started' => 'Server is live — register or log in!',

    'cta_more' => 'Learn more',
    'cta_pick' => 'Pick a tribe',

    'prize' => 'Server prize: €100',

    'features_title' => 'Why Travia.lt?',
    'features_p' => 'Travian-like classic, upgraded for 2026: modern design, fast mobile UI, engaging PvP, alliances, hero and forts. No hard pay-to-win — strategy wins.',

    'tribes_title' => '5 tribes',
    'tribes_p' => 'Choose your playstyle — each tribe has unique strengths.',

    'tribe_roman' => 'Romans',
    'tribe_roman_s' => 'Balanced + faster building',
    'tribe_gaul' => 'Gauls',
    'tribe_gaul_s' => 'Defense + speed',
    'tribe_german' => 'Teutons',
    'tribe_german_s' => 'Aggression + raiding',
    'tribe_hun' => 'Huns',
    'tribe_hun_s' => 'Lightning cavalry',
    'tribe_egypt' => 'Egyptians',
    'tribe_egypt_s' => 'Economy + long-term growth',

    'village_rules_title' => 'Village founding rule',
    'village_rules_p' => '<b>Maximum 3 villages</b> (1 starting + 2 additional). Founding is done from the same village.',
    'village_rule_10' => 'Residence or Palace → you can found <b>1 additional village</b>.',
    'village_rule_20' => 'Residence or Palace → you can found <b>one more village</b> (this is the maximum).',

    'open_rules' => 'Open server rules',

    'faq_title' => 'FAQ',
    'faq_q1' => 'Is it pay to win?',
    'faq_a1' => 'No. Premium focuses on convenience and cosmetics — victories come from planning, activity and alliance play.',
    'faq_q2' => 'Will there be an app?',
    'faq_a2' => 'Yes. The UI is mobile-first, so it works great on phones (browser and app wrapper).',
    'faq_q3' => 'When does registration open?',
    'faq_a3' => 'Registration opens at server start (unless admins enable early registration).',

    'rules_modal_title' => 'Server rules',
    'back' => 'Back',
    'units' => 'Units',
    // Legal links
    'footer_privacy' => "Privacy Policy",
    'footer_terms' => "Terms of Service",
    'footer_contact' => "Contact",
    'footer_email' => "Email",

  ],
];

function tt($k){
  global $T, $lang;
  return $T[$lang][$k] ?? $k;
}


if (isset($_SESSION['user_id'])) {
  header('Location: /game/game.php');
  exit;
}

// Launch time
$launchTs = defined('LAUNCH_TS') ? (int)LAUNCH_TS : strtotime('2026-03-28 20:00:00');
$now = time();

// Registracija: jei REGISTRATION_OPEN_NOW=true -> visada atidaryta
// kitaip atsidaro tik po starto
$regOpen = (defined('REGISTRATION_OPEN_NOW') && REGISTRATION_OPEN_NOW === true) || ($now >= $launchTs);

// Flash žinutė (pvz po logout)
$flash = '';
if (!empty($_SESSION['flash_success'])) {
  $flash = (string)$_SESSION['flash_success'];
  unset($_SESSION['flash_success']);
}

$launchHuman = date('Y-m-d H:i', $launchTs);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title><?php echo htmlspecialchars(tt('page_title')); ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">

  <style>
    :root{
      --glass: rgba(10,10,12,.58);
      --glass2: rgba(10,10,12,.42);
      --stroke: rgba(255,255,255,.12);
      --stroke2: rgba(255,215,0,.24);
      --text: rgba(255,255,255,.92);
      --muted: rgba(255,255,255,.72);
      --gold1: rgba(255,215,0,.98);
      --gold2: rgba(255,160,0,.95);
      --red1: rgba(139,0,0,.95);
      --red2: rgba(255,0,0,.78);
      --radius: 22px;
      --shadow: 0 30px 90px rgba(0,0,0,.72);
    }

    *{ box-sizing:border-box; }
    html, body{ height:100%; width:100%; overflow-x:hidden; }
    body{
      margin:0;
      font-family: Inter, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--text);
      background:#000;
      overflow-x:hidden;
      -webkit-overflow-scrolling: touch;
    }

    /* Background image with fallback */
    body::before{
      content:"";
      position:fixed;
      inset:0;
      background:
        url('hero_battle.webp') center/cover no-repeat,
        url('hero_battle.jpg') center/cover no-repeat;
      transform: scale(1.02);
      filter: saturate(1.08) contrast(1.06) brightness(.90);
      z-index:-4;
    }

    /* Cinematic overlays */
    body::after{
      content:"";
      position:fixed;
      inset:0;
      background:
        radial-gradient(1200px 680px at 50% 20%, rgba(255,215,0,.14), transparent 55%),
        radial-gradient(1200px 900px at 50% 95%, rgba(0,0,0,.55), rgba(0,0,0,.90)),
        linear-gradient(to bottom, rgba(0,0,0,.45), rgba(0,0,0,.86));
      z-index:-3;
    }

    /* subtle moving embers */
    .embers{
      position:fixed;
      inset:0;
      z-index:-2;
      pointer-events:none;
      opacity:.28;
      background-image:
        radial-gradient(circle at 10% 80%, rgba(255,140,0,.85) 0 2px, transparent 3px),
        radial-gradient(circle at 25% 90%, rgba(255,215,0,.75) 0 1px, transparent 2px),
        radial-gradient(circle at 60% 78%, rgba(255,90,0,.75) 0 2px, transparent 3px),
        radial-gradient(circle at 78% 92%, rgba(255,215,0,.65) 0 1px, transparent 2px),
        radial-gradient(circle at 88% 76%, rgba(255,120,0,.7) 0 2px, transparent 3px);
      filter: blur(.2px);
      animation: drift 10s linear infinite;
    }
    @keyframes drift{ 0%{ transform: translateY(10px);} 100%{ transform: translateY(-80px);} }

    a{ color:inherit; }

    .topbar{
      position:sticky;
      top:0;
      z-index:5;
      backdrop-filter: blur(14px);
      background: rgba(0,0,0,.28);
      border-bottom: 1px solid rgba(255,255,255,.08);
    }
    .topbarInner{
      max-width: 1100px;
      margin: 0 auto;
      padding: 12px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
    }
    .brand{
      display:flex;
      align-items:center;
      gap: 12px;
      text-decoration:none;
    }
    .brandMark{
      font-family: Cinzel, serif;
      font-weight: 900;
      letter-spacing: 6px;
      font-size: 22px;
      background: linear-gradient(180deg, #fff6b0 0%, #ffd700 25%, #ffb300 55%, #8b5a00 100%);
      -webkit-background-clip:text;
      -webkit-text-fill-color:transparent;
      text-shadow: 0 0 12px rgba(255,180,0,.35);
    }
    .brandTag{
      font-size: 12px;
      color: rgba(255,255,255,.68);
      display:none;
    }
    .nav{
      display:flex;
      align-items:center;
      gap: 10px;
      flex-wrap:wrap;
      justify-content:flex-end;
    }
    .nav a{
      text-decoration:none;
      font-weight: 800;
      font-size: 12px;
      color: rgba(255,255,255,.78);
      padding: 8px 10px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.05);
    }
    .nav a:hover{ transform: translateY(-1px); }
    .nav a.activeLang{
      border-color: rgba(255,215,0,.45);
      box-shadow: 0 0 0 2px rgba(255,215,0,.10) inset;
      color: rgba(255,255,255,.92);
    }

    .wrap{
      max-width: 1100px;
      margin: 0 auto;
      padding: 22px 16px 60px;
    }

    .flash{
      margin: 14px auto 0;
      padding: 14px 16px;
      border-radius: 16px;
      background: rgba(80,255,170,.10);
      border: 1px solid rgba(80,255,170,.35);
      color: #45ffb8;
      font-weight: 900;
      text-align:center;
      box-shadow: 0 10px 30px rgba(0,0,0,.45);
      max-width: 1100px;
    }

    .heroGrid{
      display:grid;
      grid-template-columns: 1.2fr .8fr;
      gap: 16px;
      margin-top: 18px;
      align-items:stretch;
    }

    .card{
      background: var(--glass);
      border: 1px solid var(--stroke);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow:hidden;
      position:relative;
    }
    .card::before{
      content:"";
      position:absolute;
      inset:-1px;
      border-radius: var(--radius);
      padding:1px;
      background: linear-gradient(135deg, rgba(255,215,0,.32), rgba(255,255,255,.10), rgba(255,0,0,.12));
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor;
              mask-composite: exclude;
      pointer-events:none;
      opacity:.85;
    }

    .hero{
      padding: 26px 22px;
    }

    .logoBig{
      font-family: Cinzel, serif;
      font-weight: 900;
      letter-spacing: 10px;
      font-size: clamp(40px, 6vw, 64px);
      margin: 2px 0 8px;
      background: linear-gradient(180deg, #fff6b0 0%, #ffd700 25%, #ffb300 55%, #8b5a00 100%);
      -webkit-background-clip:text;
      -webkit-text-fill-color:transparent;
      text-shadow: 0 0 10px rgba(255,215,0,.55), 0 0 26px rgba(255,120,0,.35), 3px 3px 10px rgba(0,0,0,.95);
    }
    .headline{
      margin: 0;
      font-size: 16px;
      color: rgba(255,255,255,.86);
      line-height: 1.5;
    }
    .badges{
      display:flex;
      gap: 10px;
      flex-wrap:wrap;
      margin-top: 14px;
    }
    .badge{
      display:inline-flex;
      gap: 8px;
      align-items:center;
      padding: 8px 10px;
      border-radius: 999px;
      background: rgba(0,0,0,.35);
      border: 1px solid rgba(255,255,255,.12);
      font-weight: 800;
      font-size: 12px;
      color: rgba(255,255,255,.82);
    }
    .dot{ width:8px; height:8px; border-radius:999px; background: rgba(255,80,80,.95); box-shadow: 0 0 10px rgba(255,80,80,.55); }
    .dot.live{ background: rgba(80,255,170,.95); box-shadow: 0 0 12px rgba(80,255,170,.60); }

    .actions{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 16px;
    }
    .btn{
      display:flex;
      justify-content:center;
      align-items:center;
      min-height: 52px;
      border-radius: 16px;
      font-size: 15px;
      font-weight: 900;
      text-decoration:none;
      transition: .22s ease;
      user-select:none;
      -webkit-tap-highlight-color: transparent;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.92);
    }
    .btn:hover{ transform: translateY(-1px); box-shadow: 0 14px 30px rgba(0,0,0,.45); }
    .btn:active{ transform: translateY(1px); }
    .btn.gold{ border-color: rgba(255,215,0,.28); background: linear-gradient(45deg, var(--gold1), var(--gold2)); color:#120b00; }
    .btn.red{ border-color: rgba(255,0,0,.22); background: linear-gradient(45deg, var(--red1), var(--red2)); color:#fff; }
    .btn.ghost{ background: rgba(0,0,0,.28); }
    .btn.disabled{ pointer-events:none; opacity:.55; filter: grayscale(.35); transform:none !important; box-shadow:none !important; }

    .note{
      margin-top: 12px;
      font-size: 13px;
      color: rgba(255,255,255,.74);
      line-height: 1.45;
    }

    .countCard{ padding: 22px 18px; }
    .countTitle{
      font-size: 12px;
      letter-spacing: .6px;
      color: rgba(255,215,0,.92);
      font-weight: 900;
      text-transform: uppercase;
    }
    .countWhen{ margin-top: 6px; font-size: 15px; font-weight: 900; color: rgba(255,255,255,.92); }
    .countdown{ display:grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 14px; }
    .cdBox{ background: rgba(0,0,0,.40); border: 1px solid rgba(255,255,255,.12); border-radius: 16px; padding: 12px 8px; box-shadow: inset 0 0 0 1px rgba(255,215,0,.05); text-align:center; }
    .cdNum{ font-size: 26px; font-weight: 1000; letter-spacing: 1px; }
    .cdLbl{ margin-top: 4px; font-size: 12px; color: rgba(255,255,255,.70); }

    .statusLine{ margin-top: 12px; display:flex; justify-content:center; }

    .section{
      margin-top: 18px;
      padding: 20px;
    }
    .sectionTitle{
      font-family: Cinzel, serif;
      margin:0 0 10px;
      font-size: 20px;
      letter-spacing: 1px;
    }
    .sectionP{ margin:0; color: var(--muted); line-height: 1.55; font-size: 14px; }

    .grid3{ display:grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 14px; }
    .mini{
      background: rgba(0,0,0,.28);
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 18px;
      padding: 14px;
      min-height: 92px;
    }
    .mini b{ display:block; margin-bottom: 6px; font-size: 13px; }
    .mini span{ color: rgba(255,255,255,.72); font-size: 13px; line-height: 1.35; }

    .tribes{ display:grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-top: 14px; }
    .tribe{
      border-radius: 18px;
      padding: 12px 12px 10px;
      background: rgba(0,0,0,.28);
      border: 1px solid rgba(255,255,255,.10);
      text-align:center;
    }
    .tribe .flag{ font-size: 18px; }
    .tribe .name{ font-weight: 1000; margin-top: 6px; font-size: 13px; }
    .tribe .desc{ margin-top: 6px; font-size: 12px; color: rgba(255,255,255,.72); line-height: 1.35; }

    .ruleBox{
      display:grid;
      grid-template-columns: 1fr;
      gap: 10px;
      margin-top: 12px;
    }
    .rule{
      display:flex;
      gap: 10px;
      align-items:flex-start;
      padding: 12px;
      border-radius: 18px;
      background: rgba(0,0,0,.28);
      border: 1px solid rgba(255,255,255,.10);
    }
    .rule .k{
      flex:0 0 auto;
      font-weight: 1000;
      color: rgba(255,215,0,.95);
      border: 1px solid rgba(255,215,0,.22);
      background: rgba(255,215,0,.06);
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 12px;
    }
    .rule .t{ font-size: 13px; color: rgba(255,255,255,.80); line-height: 1.4; }

    details{
      border-radius: 18px;
      background: rgba(0,0,0,.28);
      border: 1px solid rgba(255,255,255,.10);
      padding: 12px 14px;
    }
    details + details{ margin-top: 10px; }
    summary{ cursor:pointer; font-weight: 900; color: rgba(255,255,255,.90); }
    details p{ margin: 10px 0 0; color: rgba(255,255,255,.74); font-size: 13px; line-height: 1.5; }

    .footer{
      margin-top: 18px;
      text-align:center;
      color: rgba(255,255,255,.60);
      font-size: 13px;
      padding: 18px 10px 0;
    }

    @media (max-width: 980px){
      .tribes{ grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 860px){
      .heroGrid{ grid-template-columns: 1fr; }
      .actions{ grid-template-columns: 1fr; }
      .countdown{ grid-template-columns: repeat(2, 1fr); }
      .brandTag{ display:none; }
    }
    @media (max-width: 560px){
      .nav a{ font-size: 11px; padding: 7px 9px; }
      .grid3{ grid-template-columns: 1fr; }
      .tribes{ grid-template-columns: 1fr 1fr; }
      .hero{ padding: 22px 16px; }
      .countCard{ padding: 18px 14px; }
    }

    /* reduce motion */
    @media (prefers-reduced-motion: reduce){
      .embers{ animation: none; }
      .btn, .nav a{ transition: none; }
    }

    .tribe{ cursor:pointer; user-select:none; -webkit-tap-highlight-color: transparent; }
    .tribe:active{ transform: translateY(1px); }

    /* Modals */
    .modalOverlay{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; padding:16px; background: rgba(0,0,0,.70); z-index: 999; }
    .modalOverlay.open{ display:flex; }
    .modal{ width: min(760px, 100%); background: var(--glass); border: 1px solid var(--stroke); border-radius: var(--radius); box-shadow: var(--shadow); overflow:hidden; position:relative; }
    .modalHead{ padding: 14px 14px 12px; display:flex; align-items:center; justify-content:space-between; gap:10px; border-bottom: 1px solid rgba(255,255,255,.10); background: rgba(0,0,0,.18); backdrop-filter: blur(12px); }
    .modalTitle{ margin:0; font-family: Cinzel, serif; font-weight: 900; letter-spacing: .8px; font-size: 18px; }
    .modalBody{ padding: 16px 16px 18px; color: var(--muted); }
    .unitList{ list-style:none; padding:0; margin:10px 0 0; display:grid; gap:10px; }
    .unit{ display:flex; gap:10px; align-items:center; padding:10px 12px; border-radius: 16px; background: rgba(0,0,0,.22); border: 1px solid rgba(255,255,255,.10); }
    .uIcon{ width:34px; height:34px; border-radius: 12px; display:flex; align-items:center; justify-content:center; background: rgba(255,215,0,.08); border: 1px solid rgba(255,215,0,.22); font-size:16px; }
    .uTxt{ font-weight: 900; font-size: 13px; color: rgba(255,255,255,.90); }
    .modalBtns{ display:flex; gap:10px; margin-top: 14px; }
    .modalBtns .btn{ min-height: 46px; border-radius: 14px; }

  </style>
</head>

<body>
  <div class="embers" aria-hidden="true"></div>

  <header class="topbar">
    <div class="topbarInner">
      <a class="brand" href="#top">
        <span class="brandMark">TRAVIA</span>
        <span class="brandTag">Strateginis imperijų karo žaidimas</span>
      </a>
      <nav class="nav" aria-label="Pagrindinė navigacija">
                <a href="#features"><?php echo htmlspecialchars(tt('nav_features')); ?></a>
        <a href="#tribes"><?php echo htmlspecialchars(tt('nav_tribes')); ?></a>
        <a href="#rules"><?php echo htmlspecialchars(tt('nav_rules')); ?></a>
        <a href="#faq"><?php echo htmlspecialchars(tt('nav_faq')); ?></a>
        <a href="?lang=lt" class="<?php echo ($lang==='lt')?'activeLang':''; ?>">LT</a>
        <a href="?lang=en" class="<?php echo ($lang==='en')?'activeLang':''; ?>">EN</a>
      </nav>
    </div>
  </header>

  <?php if($flash): ?>
    <div class="flash"><?php echo htmlspecialchars($flash); ?></div>
  <?php endif; ?>

  <main id="top" class="wrap">

    <section class="heroGrid">
      <div class="card hero">
        <div class="logoBig">TRAVIA</div>
        <p class="headline">
          <?php echo tt('headline_1'); ?>
          <?php echo htmlspecialchars(tt('headline_2')); ?>
        </p>

        <div class="badges" aria-label="Pagrindiniai privalumai">
          <span class="badge">📱 Mobile-first</span>
          <span class="badge">🌍 LT / EN</span>
          <span class="badge">⚡ Serveriai x1 / x3 / x5</span>
        </div>

        <div class="actions">
          <a href="register.php" class="btn gold <?php echo $regOpen ? '' : 'disabled'; ?>" id="btnReg"><?php echo htmlspecialchars(tt('btn_play')); ?></a>
          <a href="login.php" class="btn red" id="btnLogin"><?php echo htmlspecialchars(tt('btn_login')); ?></a>
        </div>

        <div class="note" id="note">
                    <?php if($regOpen): ?>
            <?php echo htmlspecialchars(tt('reg_open')); ?>
          <?php else: ?>
            <?php echo htmlspecialchars(tt('reg_closed')); ?>
          <?php endif; ?>

        </div>
      </div>

      <aside class="card countCard" aria-label="Serverio startas ir atgalinis skaičiavimas">
        <div class="countTitle"><?php echo htmlspecialchars(tt('count_title')); ?></div>
        <div class="countWhen"><?php echo sprintf(htmlspecialchars(tt('count_when')), htmlspecialchars($launchHuman)); ?></div>

        <div class="countdown" aria-live="polite">
          <div class="cdBox"><div class="cdNum" id="cdD">--</div><div class="cdLbl"><?php echo htmlspecialchars(tt('cd_days')); ?></div></div>
          <div class="cdBox"><div class="cdNum" id="cdH">--</div><div class="cdLbl"><?php echo htmlspecialchars(tt('cd_hours')); ?></div></div>
          <div class="cdBox"><div class="cdNum" id="cdM">--</div><div class="cdLbl"><?php echo htmlspecialchars(tt('cd_mins')); ?></div></div>
          <div class="cdBox"><div class="cdNum" id="cdS">--</div><div class="cdLbl"><?php echo htmlspecialchars(tt('cd_secs')); ?></div></div>
        </div>

        <div class="statusLine" style="margin-top:12px;">
          <span class="badge" style="justify-content:center; width:100%;">🏆 <?php echo htmlspecialchars(tt('prize')); ?></span>
        </div>

        <div class="statusLine">
          <span class="badge" style="justify-content:center;">
            <span class="dot" id="dot"></span>
            <span id="statusTxt"><?php echo htmlspecialchars(tt('status_locked')); ?></span>
          </span>
        </div>

        <div style="margin-top:12px; display:grid; gap:10px;">
          <a href="#features" class="btn ghost"><?php echo htmlspecialchars(tt('cta_more')); ?></a>
          <a href="#tribes" class="btn ghost"><?php echo htmlspecialchars(tt('cta_pick')); ?></a>
        </div>
      </aside>
    </section>

    <section id="features" class="card section">
      <h2 class="sectionTitle"><?php echo htmlspecialchars(tt('features_title')); ?></h2>
      <p class="sectionP"><?php echo htmlspecialchars(tt('features_p')); ?></p>

      <div class="grid3" role="list">
        <div class="mini" role="listitem"><b>⚔️ Tikras PvP</b><span>Atakos, plėšimai, sustiprinimai, aljansų koordinacija.</span></div>
        <div class="mini" role="listitem"><b>🧙 Herojus</b><span>Progresija ir įgūdžiai: puolimas / gynyba / ekonomika.</span></div>
        <div class="mini" role="listitem"><b>🏰 Fortai</b><span>NPC tvirtovės su subalansuota gynyba — endgame dinamika.</span></div>
      </div>
    </section>

    <section id="tribes" class="card section">
      <h2 class="sectionTitle"><?php echo htmlspecialchars(tt('tribes_title')); ?></h2>
      <p class="sectionP"><?php echo htmlspecialchars(tt('tribes_p')); ?></p>

      <div class="tribes" role="list">
        <div class="tribe" role="listitem" data-tribe="roman"><div class="flag">🇮🇹</div><div class="name"><?php echo htmlspecialchars(tt('tribe_roman')); ?></div><div class="desc"><?php echo htmlspecialchars(tt('tribe_roman_s')); ?></div></div>
        <div class="tribe" role="listitem" data-tribe="gaul"><div class="flag">🇫🇷</div><div class="name"><?php echo htmlspecialchars(tt('tribe_gaul')); ?></div><div class="desc"><?php echo htmlspecialchars(tt('tribe_gaul_s')); ?></div></div>
        <div class="tribe" role="listitem" data-tribe="german"><div class="flag">🇩🇪</div><div class="name"><?php echo htmlspecialchars(tt('tribe_german')); ?></div><div class="desc"><?php echo htmlspecialchars(tt('tribe_german_s')); ?></div></div>
        <div class="tribe" role="listitem" data-tribe="hun"><div class="flag">🏹</div><div class="name"><?php echo htmlspecialchars(tt('tribe_hun')); ?></div><div class="desc"><?php echo htmlspecialchars(tt('tribe_hun_s')); ?></div></div>
        <div class="tribe" role="listitem" data-tribe="egypt"><div class="flag">🐫</div><div class="name"><?php echo htmlspecialchars(tt('tribe_egypt')); ?></div><div class="desc"><?php echo htmlspecialchars(tt('tribe_egypt_s')); ?></div></div>
      </div>
    </section>

    <section id="rules" class="card section">
      <h2 class="sectionTitle"><?php echo htmlspecialchars(tt('village_rules_title')); ?></h2>
      <p class="sectionP"><?php echo tt('village_rules_p'); ?></p>

      <div class="ruleBox">
        <div class="rule"><div class="k">10 lygis</div><div class="t"><?php echo tt('village_rule_10'); ?></div></div>
        <div class="rule"><div class="k">20 lygis</div><div class="t"><?php echo tt('village_rule_20'); ?></div></div>
      </div>

      <div style="margin-top:12px; display:grid; gap:10px;">
        <a href="#" class="btn gold" id="openRulesBtn"><?php echo htmlspecialchars(tt('open_rules')); ?></a>
      </div>
    </section>


<section id="faq" class="card section">
      <h2 class="sectionTitle"><?php echo htmlspecialchars(tt('faq_title')); ?></h2>
      <details>
        <summary><?php echo htmlspecialchars(tt('faq_q1')); ?></summary>
        <p><?php echo htmlspecialchars(tt('faq_a1')); ?></p>
      </details>
      <details>
        <summary><?php echo htmlspecialchars(tt('faq_q2')); ?></summary>
        <p><?php echo htmlspecialchars(tt('faq_a2')); ?></p>
      </details>
      <details>
        <summary><?php echo htmlspecialchars(tt('faq_q3')); ?></summary>
        <p><?php echo htmlspecialchars(tt('faq_a3')); ?></p>
      </details>
    </section>

    <div class="footer">© <?php echo date('Y'); ?> TRAVIA. Visos teisės saugomos.</div>
  </main>


  <!-- Tribe Modal -->
  <div class="modalOverlay" id="tribeOverlay" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Tribe details">
      <div class="modalHead">
        <h3 class="modalTitle" id="tribeTitle"> </h3>
        <a href="#" class="btn ghost" id="closeTribeBtn" style="min-height:38px; padding:0 12px; border-radius:12px; font-size:13px;">✕</a>
      </div>
      <div class="modalBody">
        <p id="tribeDesc"></p>
        <div style="font-weight:1000; color: rgba(255,215,0,.92); margin-top:10px;"><?php echo htmlspecialchars(tt('units')); ?></div>
        <ul class="unitList" id="tribeUnits"></ul>
        <div class="modalBtns">
          <a href="#" class="btn gold" id="tribeBackBtn"><?php echo htmlspecialchars(tt('back')); ?></a>
        </div>
      </div>
    </div>
  </div>

  <!-- Rules Modal -->
  <div class="modalOverlay" id="rulesOverlay" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Server rules">
      <div class="modalHead">
        <h3 class="modalTitle"><?php echo htmlspecialchars(tt('rules_modal_title')); ?></h3>
        <a href="#" class="btn ghost" id="closeRulesBtn" style="min-height:38px; padding:0 12px; border-radius:12px; font-size:13px;">✕</a>
      </div>
      <div class="modalBody">
        <div class="ruleBox" style="margin-top:0;">
          <div class="rule"><div class="k">1</div><div class="t"><?php echo ($lang==='en'?'One player — one account per server.':'Vienas žaidėjas — viena paskyra serveryje.'); ?></div></div>
          <div class="rule"><div class="k">2</div><div class="t"><?php echo ($lang==='en'?'Bots/scripts/macro are forbidden.':'Botai / skriptai / makro — draudžiama.'); ?></div></div>
          <div class="rule"><div class="k">3</div><div class="t"><?php echo ($lang==='en'?'Bug abuse is forbidden. Report bugs.':'Bug abuse draudžiama. Radęs bug – pranešk.'); ?></div></div>
          <div class="rule"><div class="k">4</div><div class="t"><?php echo ($lang==='en'?'Insults/threats/hate speech are forbidden.':'Įžeidimai / grasinimai / neapykanta — draudžiama.'); ?></div></div>
          <div class="rule"><div class="k">5</div><div class="t"><?php echo ($lang==='en'?'Admins may apply sanctions.':'Administracija gali taikyti sankcijas.'); ?></div></div>
        </div>
        <div class="modalBtns">
          <a href="#" class="btn gold" id="rulesBackBtn"><?php echo htmlspecialchars(tt('back')); ?></a>
        </div>
      </div>
    </div>
  </div>


  <script>
    (function(){
      const launchTs = <?php echo (int)$launchTs; ?> * 1000;
      const regOpen = <?php echo $regOpen ? 'true' : 'false'; ?>;
      const I18N = <?php echo json_encode($T[$lang], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      const elD = document.getElementById('cdD');
      const elH = document.getElementById('cdH');
      const elM = document.getElementById('cdM');
      const elS = document.getElementById('cdS');

      const btnReg = document.getElementById('btnReg');
      const dot = document.getElementById('dot');
      const statusTxt = document.getElementById('statusTxt');
      const note = document.getElementById('note');

      function pad2(n){ return (n < 10 ? '0' + n : '' + n); }

      function setState(beforeStart){
        if(beforeStart){
          dot.classList.remove('live');
          statusTxt.textContent = I18N.status_locked || 'Užrakinta iki starto';
          if(!regOpen){
            note.textContent = I18N.note_reg_closed || 'Registracija atsidarys starto metu.';
          } else {
            note.textContent = I18N.note_reg_open || 'Registracija atidaryta (laikinai).';
          }
        } else {
          dot.classList.add('live');
          statusTxt.textContent = I18N.status_live || 'Serveris LIVE';
          note.textContent = I18N.note_started || 'Serveris startavo — registruokis arba prisijunk!';
          if(btnReg) btnReg.classList.remove('disabled');
        }
      }

      function tick(){
        const now = Date.now();
        const diff = launchTs - now;

        if(diff <= 0){
          elD.textContent = '00';
          elH.textContent = '00';
          elM.textContent = '00';
          elS.textContent = '00';
          setState(false);
          return;
        }

        setState(true);

        const sec = Math.floor(diff / 1000);
        const days = Math.floor(sec / 86400);
        const hours = Math.floor((sec % 86400) / 3600);
        const mins = Math.floor((sec % 3600) / 60);
        const s = sec % 60;

        elD.textContent = pad2(days);
        elH.textContent = pad2(hours);
        elM.textContent = pad2(mins);
        elS.textContent = pad2(s);
      }

      tick();
      setInterval(tick, 1000);


      // --- Tribes modal ---
      const tribes = {
        roman: {
          name_lt: "Romėnai",
          name_en: "Romans",
          desc_lt: "Subalansuota gentis. Tinka tiek naujiems, tiek patyrusiems žaidėjams. Stabilus progresas ir patikima kariuomenė.",
          desc_en: "A balanced tribe for both new and experienced players. Stable progress and reliable army.",
          units_lt: ["Legionierius","Pretorionas","Imperatorius","Raitelis","Taranuotojas","Katapulta"],
          units_en: ["Legionnaire","Praetorian","Imperian","Equites","Ram","Catapult"]
        },
        gaul: {
          name_lt: "Galai",
          name_en: "Gauls",
          desc_lt: "Gynybinė ir greita gentis. Puikiai ginasi ankstyvame žaidime, o greiti vienetai tinka kontraatakoms.",
          desc_en: "Defensive and fast. Great early survival and quick units for counter-attacks.",
          units_lt: ["Falangas","Kalavijuotis","Žvalgas","Theutates raitelis","Druidas raitelis","Katapulta"],
          units_en: ["Phalanx","Swordsman","Scout","Theutates Thunder","Druidrider","Catapult"]
        },
        german: {
          name_lt: "Germanai",
          name_en: "Teutons",
          desc_lt: "Agresyvi gentis su stipriu plėšimu ir pigiomis puolimo pajėgomis. Geriausia aktyviems žaidėjams.",
          desc_en: "Aggressive raiders with cost-effective offense. Best for active players.",
          units_lt: ["Kovotojas su kuoka","Ietininkas","Kovos kirvis","Skautas","Paladinas","Katapulta"],
          units_en: ["Clubswinger","Spearman","Axeman","Scout","Paladin","Catapult"]
        },
        hun: {
          name_lt: "Hunai",
          name_en: "Huns",
          desc_lt: "Greita kavalerijos gentis. Ideali žaibiškoms atakoms, reidams ir greitam reagavimui.",
          desc_en: "Fast cavalry faction. Ideal for lightning attacks, raids and quick reactions.",
          units_lt: ["Stepės raitelis","Lankininkas raitelis","Sunkus raitelis","Skautas","Taranuotojas","Katapulta"],
          units_en: ["Steppe Rider","Mounted Archer","Heavy Cavalry","Scout","Ram","Catapult"]
        },
        egypt: {
          name_lt: "Egiptiečiai",
          name_en: "Egyptians",
          desc_lt: "Ekonominė gentis su stabiliu grūdų balansu. Patogus augimas ir stiprus vėlyvas žaidimas.",
          desc_en: "Economic tribe with stable crop balance. Comfortable growth and strong late game.",
          units_lt: ["Ietininkas","Kardo karys","Šaulys","Kupranugarių raitelis","Taranuotojas","Katapulta"],
          units_en: ["Spearman","Swordsman","Archer","Camel Rider","Ram","Catapult"]
        }
      };

      const pageLang = <?php echo json_encode($lang); ?>;
      const tribeOverlay = document.getElementById('tribeOverlay');
      const tribeTitle = document.getElementById('tribeTitle');
      const tribeDesc = document.getElementById('tribeDesc');
      const tribeUnits = document.getElementById('tribeUnits');

      function openTribe(key){
        const tr = tribes[key];
        if(!tr) return;
        tribeTitle.textContent = (pageLang === 'en') ? tr.name_en : tr.name_lt;
        tribeDesc.textContent = (pageLang === 'en') ? tr.desc_en : tr.desc_lt;
        const units = (pageLang === 'en') ? tr.units_en : tr.units_lt;

        tribeUnits.innerHTML = '';
        units.forEach(u => {
          const li = document.createElement('li');
          li.className = 'unit';
          li.innerHTML = '<div class="uIcon">⚔️</div><div class="uTxt">' + u + '</div>';
          tribeUnits.appendChild(li);
        });

        tribeOverlay.classList.add('open');
      }
      function closeTribe(){ tribeOverlay.classList.remove('open'); }

      document.querySelectorAll('.tribe[data-tribe]').forEach(el => {
        el.addEventListener('click', () => openTribe(el.getAttribute('data-tribe')));
      });

      const closeTribeBtn = document.getElementById('closeTribeBtn');
      const tribeBackBtn = document.getElementById('tribeBackBtn');
      if(closeTribeBtn) closeTribeBtn.addEventListener('click', (e)=>{ e.preventDefault(); closeTribe(); });
      if(tribeBackBtn) tribeBackBtn.addEventListener('click', (e)=>{ e.preventDefault(); closeTribe(); });
      if(tribeOverlay) tribeOverlay.addEventListener('click', (e)=>{ if(e.target === tribeOverlay) closeTribe(); });

      // --- Rules modal ---
      const rulesOverlay = document.getElementById('rulesOverlay');
      function openRules(){ if(rulesOverlay) rulesOverlay.classList.add('open'); }
      function closeRules(){ if(rulesOverlay) rulesOverlay.classList.remove('open'); }

      const openRulesBtn = document.getElementById('openRulesBtn');
      const closeRulesBtn = document.getElementById('closeRulesBtn');
      const rulesBackBtn = document.getElementById('rulesBackBtn');

      if(openRulesBtn) openRulesBtn.addEventListener('click', (e)=>{ e.preventDefault(); openRules(); });
      if(closeRulesBtn) closeRulesBtn.addEventListener('click', (e)=>{ e.preventDefault(); closeRules(); });
      if(rulesBackBtn) rulesBackBtn.addEventListener('click', (e)=>{ e.preventDefault(); closeRules(); });
      if(rulesOverlay) rulesOverlay.addEventListener('click', (e)=>{ if(e.target === rulesOverlay) closeRules(); });

      // ESC closes modals
      document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape'){ closeTribe(); closeRules(); } });


      // prevent horizontal scroll
      document.documentElement.style.overflowX = 'hidden';
      document.body.style.overflowX = 'hidden';
    })();
  </script>

  

<!-- Legal links -->
<div style="text-align:center; padding:12px 0; font-size:14px;">
  <a href="privacy.php">Privatumo politika</a> |
  <a href="terms.php">Naudojimo taisyklės</a> |
  <a href="contact.php">Kontaktai</a>
  <br>
  support@travia.lt
</div>

</body>
</html>
