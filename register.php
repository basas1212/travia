<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/functions.php';

if (is_logged_in()) redirect('/game/game.php');

if (isset($_GET['lang'])) {
  $_SESSION['lang'] = ($_GET['lang'] === 'en') ? 'en' : 'lt';
}
$lang = function_exists('current_lang') ? current_lang() : ($_SESSION['lang'] ?? 'lt');
$isEn = ($lang === 'en');

$err = '';
$ok = '';
$tribeKeys = ['roman','teuton','gaul','egyptian','hun'];

// UI tekstai gentims (tik UI)
$tribesUi = [
  'roman' => [
    'flag'=>'🇮🇹',
    'name_lt'=>'Romėnai','name_en'=>'Romans',
    'tag_lt'=>'Balansas + greitesnė statyba','tag_en'=>'Balance + faster building',
    'desc_lt'=>'Subalansuota gentis. Tinka tiek ekonomikai, tiek kovai. Stabilus progresas ir tvirta kariuomenė.',
    'desc_en'=>'Balanced tribe. Great for both economy and combat. Stable progress and reliable army.',
    'units_lt'=>['Legionierius','Pretorionas','Imperatorius','Raitelis','Taranuotojas','Katapulta'],
    'units_en'=>['Legionnaire','Praetorian','Imperian','Equites','Ram','Catapult'],
  ],
  'gaul' => [
    'flag'=>'🇫🇷',
    'name_lt'=>'Galai','name_en'=>'Gauls',
    'tag_lt'=>'Gynyba + greitis','tag_en'=>'Defense + speed',
    'desc_lt'=>'Gynybinė ir greita gentis. Patogi pradžia, greiti vienetai, gerai ginasi nuo reidų.',
    'desc_en'=>'Defensive and fast. Comfortable early game, quick units and strong protection versus raids.',
    'units_lt'=>['Falangas','Kalavijuotis','Žvalgas','Theutates raitelis','Druidas raitelis','Katapulta'],
    'units_en'=>['Phalanx','Swordsman','Scout','Theutates Thunder','Druidrider','Catapult'],
  ],
  'teuton' => [
    'flag'=>'🇩🇪',
    'name_lt'=>'Germanai','name_en'=>'Teutons',
    'tag_lt'=>'Agresija + plėšimas','tag_en'=>'Aggression + raiding',
    'desc_lt'=>'Agresyvi gentis. Pigi puolimo armija ir stiprus reidinimas. Tinka aktyviam žaidimui.',
    'desc_en'=>'Aggressive tribe. Cost-effective offense and strong raiding. Best for active play.',
    'units_lt'=>['Kovotojas su kuoka','Ietininkas','Kovos kirvis','Skautas','Paladinas','Katapulta'],
    'units_en'=>['Clubswinger','Spearman','Axeman','Scout','Paladin','Catapult'],
  ],
  'hun' => [
    'flag'=>'🏹',
    'name_lt'=>'Hunai','name_en'=>'Huns',
    'tag_lt'=>'Žaibiška kavalerija','tag_en'=>'Lightning cavalry',
    'desc_lt'=>'Mobilumo gentis. Greitos atakos, reidai ir greitas reagavimas. Stiprybė — greitis.',
    'desc_en'=>'Mobility tribe. Fast attacks, raids and quick reactions. Strength = speed.',
    'units_lt'=>['Stepės raitelis','Lankininkas raitelis','Sunkus raitelis','Skautas','Taranuotojas','Katapulta'],
    'units_en'=>['Steppe Rider','Mounted Archer','Heavy Cavalry','Scout','Ram','Catapult'],
  ],
  'egyptian' => [
    'flag'=>'🐪',
    'name_lt'=>'Egiptiečiai','name_en'=>'Egyptians',
    'tag_lt'=>'Ekonomika + ilgalaikė plėtra','tag_en'=>'Economy + long-term growth',
    'desc_lt'=>'Ekonominė gentis. Stabilus augimas ir stiprus vėlyvas žaidimas. Tinka kantriems strategams.',
    'desc_en'=>'Economic tribe. Stable growth and strong late game. Great for patient strategists.',
    'units_lt'=>['Ietininkas','Kardo karys','Šaulys','Kupranugarių raitelis','Taranuotojas','Katapulta'],
    'units_en'=>['Spearman','Swordsman','Archer','Camel Rider','Ram','Catapult'],
  ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $password2 = (string)($_POST['password2'] ?? '');
  $tribe = (string)($_POST['tribe'] ?? 'roman');

  if ($username === '' || $password === '' || $password2 === '') {
    $err = $isEn ? 'Fill all fields.' : 'Užpildyk visus laukus.';
  } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    $err = $isEn ? 'Username: 3-20 chars, letters/numbers/_' : 'Vartotojo vardas: 3-20 simbolių, tik raidės/skaičiai/_';
  } elseif (strlen($password) < 6) {
    $err = $isEn ? 'Password too short (min 6).' : 'Slaptažodis per trumpas (min 6).';
  } elseif ($password !== $password2) {
    $err = $isEn ? 'Passwords do not match.' : 'Slaptažodžiai nesutampa.';
  } elseif (!in_array($tribe, $tribeKeys, true)) {
    $err = $isEn ? 'Invalid tribe.' : 'Neteisinga gentis.';
  } else {
    $stmt = $mysqli->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();

    if ($exists) {
      $err = $isEn ? 'Username already taken.' : 'Toks vartotojo vardas jau užimtas.';
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $ip = $_SERVER['REMOTE_ADDR'] ?? null;

      $mysqli->begin_transaction();
      try {
        $stmt = $mysqli->prepare('INSERT INTO users (username,password,tribe,gold,register_ip,last_ip) VALUES (?,?,?,?,?,?)');
        $gold = 0;
        $stmt->bind_param('sssiss', $username, $hash, $tribe, $gold, $ip, $ip);
        $stmt->execute();
        $uid = (int)$stmt->insert_id;
        $stmt->close();

        $vname = $isEn ? 'Capital' : 'Sostinė';
        $wood = 750; $clay = 750; $iron = 750; $crop = 750;
        $xy = allocate_start_coordinates($mysqli, 75);
        $x = (int)$xy['x'];
        $y = (int)$xy['y'];

        $stmt = $mysqli->prepare('INSERT INTO villages (user_id,name,wood,clay,iron,crop,x,y,is_capital) VALUES (?,?,?,?,?,?,?,?,1)');
        $stmt->bind_param('isiiiiii', $uid, $vname, $wood, $clay, $iron, $crop, $x, $y);
        $stmt->execute();
        $vid = (int)$stmt->insert_id;
        $stmt->close();

        occupy_world_tile($mysqli, $x, $y, $vid);

        $types = array_merge(
          array_fill(0, 4, 'wood'),
          array_fill(0, 4, 'clay'),
          array_fill(0, 4, 'iron'),
          array_fill(0, 6, 'crop')
        );
        for ($i = 0; $i < 18; $i++) {
          $field_id = $i + 1;
          $type = $types[$i];
          $level = 0;
          $stmt = $mysqli->prepare('INSERT INTO resource_fields (village_id, field_id, type, level) VALUES (?,?,?,?)');
          $stmt->bind_param('iisi', $vid, $field_id, $type, $level);
          $stmt->execute();
          $stmt->close();
        }

        for ($slot = 1; $slot <= 24; $slot++) {
          if ($slot === 1) { $type = 'main_building'; $lvl = 1; }
          else { $type = 'empty'; $lvl = 0; }
          $stmt = $mysqli->prepare('INSERT INTO village_buildings (village_id, slot, type, level) VALUES (?,?,?,?)');
          $stmt->bind_param('iisi', $vid, $slot, $type, $lvl);
          $stmt->execute();
          $stmt->close();
        }

        init_storage_caps_raw($mysqli, $vid);

        $mysqli->commit();

        $_SESSION['user_id'] = $uid;
        $_SESSION['village_id'] = $vid;
        redirect('/game/game.php');
      } catch (Throwable $e) {
        $mysqli->rollback();
        $err = APP_DEBUG ? (($isEn ? 'Registration error: ' : 'Registracijos klaida: ') . $e->getMessage()) : ($isEn ? 'Registration error.' : 'Registracijos klaida.');
      }
    }
  }
}


function init_storage_caps_raw(mysqli $db, int $villageId): void {
  // Pradinės talpos pagal serverio greitį (SPEED_STORE=TRAVIA_SPEED)
  $base = defined('TRAVIA_STORAGE_BASE') ? (int)TRAVIA_STORAGE_BASE : 800;
  if ($base <= 0) $base = 800;
  $growth = defined('TRAVIA_STORAGE_GROWTH') ? (float)TRAVIA_STORAGE_GROWTH : 1.28;
  if ($growth <= 1.0) $growth = 1.28;

  // Start: lvl 0 (jei vėliau užkelsi warehouse/granary – queue procesorius perskaičiuos)
  $cap = (int)max($base, round(($base * ($growth ** 0))));

  $stmt = $db->prepare('UPDATE villages SET warehouse=?, granary=? WHERE id=?');
  $stmt->bind_param('iii', $cap, $cap, $villageId);
  $stmt->execute();
  $stmt->close();
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
[$bgWebp,$bgJpg] = auth_bg_urls();
?>
<!doctype html>
<html lang="<?= h(current_lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $isEn ? 'Register' : 'Registruotis' ?> - TRAVIA</title>
  <link rel="stylesheet" href="/style.css">
  <style>
    body.auth-page{background:#000;min-height:100vh;}
    body.auth-page::before{content:"";position:fixed;inset:0;z-index:-4;background:
      <?php if ($bgWebp): ?>url('<?= hh($bgWebp) ?>') center/cover no-repeat,<?php endif; ?>
      <?php if ($bgJpg): ?>url('<?= hh($bgJpg) ?>') center/cover no-repeat<?php else: ?>linear-gradient(180deg,#111,#000)<?php endif; ?>;
      transform: scale(1.02); filter:saturate(1.08) contrast(1.06) brightness(.90);
    }
    body.auth-page::after{content:"";position:fixed;inset:0;z-index:-3;background:
      radial-gradient(1200px 680px at 50% 20%, rgba(255,215,0,.14), transparent 55%),
      radial-gradient(1200px 900px at 50% 95%, rgba(0,0,0,.55), rgba(0,0,0,.90)),
      linear-gradient(to bottom, rgba(0,0,0,.45), rgba(0,0,0,.86));
    }

    .auth-top{width:min(920px,92vw);margin:14px auto 0;display:flex;gap:10px;align-items:center;justify-content:space-between;}
    .auth-pill{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-weight:900;font-size:12px;color:rgba(255,255,255,.82);padding:10px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);} 
    .auth-pill.active{border-color: rgba(255,215,0,.35); box-shadow: 0 0 0 2px rgba(255,215,0,.10) inset;}

    /* gentys */
    .tribesGrid{display:grid;grid-template-columns: repeat(2, minmax(0,1fr));gap:12px;margin-top:10px;}
    .tribeCard{cursor:pointer;user-select:none;-webkit-tap-highlight-color: transparent; padding:14px;border-radius:18px;background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.12);} 
    .tribeCard:active{transform:translateY(1px);} 
    .tribeCard.active{border-color: rgba(255,215,0,.35); box-shadow: 0 0 0 2px rgba(255,215,0,.10) inset;}
    .tribeHead{display:flex;gap:10px;align-items:center;}
    .tribeFlag{font-size:20px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:12px;background: rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.18);} 
    .tribeName{font-weight:1000;font-size:16px;margin:2px 0 2px;color:rgba(255,255,255,.92);} 
    .tribeTag{color: rgba(255,255,255,.68); font-weight:800; font-size:13px;} 
    .auth-hint{color: rgba(255,255,255,.65); font-weight:800; margin-top: 8px; font-size: 13px;}

    .modalOverlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.70);z-index:999;}
    .modalOverlay.open{display:flex;}
    .modal{width:min(720px,100%);background:rgba(10,10,12,.74);border:1px solid rgba(255,255,255,.14);border-radius:22px;box-shadow:0 40px 120px rgba(0,0,0,.75);overflow:hidden;}
    .modalHead{padding:14px 14px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.22);backdrop-filter:blur(10px);} 
    .modalTitle{font-family: Cinzel, serif;font-weight:900;letter-spacing:.8px;font-size:18px;margin:0;background: linear-gradient(180deg,#fff6b0 0%, #ffd700 25%, #ffb300 55%, #8b5a00 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;} 
    .modalBody{padding:16px 16px 18px;color:rgba(255,255,255,.86);} 
    .unitList{list-style:none;padding:0;margin:10px 0 0;display:grid;gap:10px;} 
    .unit{display:flex;gap:10px;align-items:center;padding:10px 12px;border-radius:16px;background:rgba(0,0,0,.28);border:1px solid rgba(255,255,255,.10);} 
    .uIcon{width:34px;height:34px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.22);font-size:16px;} 
    .uTxt{font-weight:900;font-size:13px;color:rgba(255,255,255,.90);} 
    .modalBtns{display:flex;gap:10px;margin-top:14px;} 
    .modalBtns .auth-btn{flex:1;} 
    .modalBtns .auth-btn.secondary{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.88);} 

    @media (max-width:420px){ .tribesGrid{grid-template-columns: 1fr;} }
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
      <div class="auth-sub"><?= $isEn ? 'Register' : 'Registruotis' ?></div>
    </div>

    <div class="auth-card">
      <div class="auth-title"><?= $isEn ? 'Register' : 'Registruotis' ?></div>

      <?php if ($err): ?>
        <div class="auth-alert"><?php echo h($err); ?></div>
      <?php endif; ?>

      <form class="auth-form" method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

        <div class="auth-field">
          <label><?= $isEn ? 'Username' : 'Vartotojo vardas' ?></label>
          <input name="username" value="<?php echo h($_POST['username'] ?? ''); ?>" placeholder="<?= $isEn ? '3-20 characters' : '3-20 simbolių' ?>" required>
        </div>

        <div class="auth-field" style="margin-top:6px;">
          <label><?= $isEn ? 'Tribe' : 'Gentis' ?></label>
          <input type="hidden" name="tribe" id="tribeInput" value="<?php echo h($_POST['tribe'] ?? 'roman'); ?>">

          <div class="tribesGrid" id="tribesGrid">
            <?php foreach ($tribeKeys as $k):
              $tr = $tribesUi[$k] ?? null;
              if (!$tr) continue;
              $name = $isEn ? $tr['name_en'] : $tr['name_lt'];
              $tag  = $isEn ? $tr['tag_en']  : $tr['tag_lt'];
            ?>
              <div class="tribeCard" data-tribe="<?= hh($k) ?>">
                <div class="tribeHead">
                  <div class="tribeFlag"><?= hh($tr['flag']) ?></div>
                  <div>
                    <div class="tribeName"><?= hh($name) ?></div>
                    <div class="tribeTag"><?= hh($tag) ?></div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="auth-hint"><?= $isEn ? 'Tap a tribe to see details, then choose.' : 'Paspausk gentį — matysi aprašymą, tada pasirink.' ?></div>
        </div>

        <div class="auth-field">
          <label><?= $isEn ? 'Password' : 'Slaptažodis' ?></label>
          <input type="password" name="password" placeholder="min 6" required>
        </div>

        <div class="auth-field">
          <label><?= $isEn ? 'Repeat password' : 'Pakartok slaptažodį' ?></label>
          <input type="password" name="password2" placeholder="min 6" required>
        </div>

        <button class="auth-btn" type="submit"><?= $isEn ? 'Register' : 'Registruotis' ?></button>
      </form>

      <div class="auth-bottom" style="text-align:center;margin-top:12px;">
        <a class="auth-link" href="login.php"><?= $isEn ? 'Already have an account? Login' : 'Jau turi paskyrą? Prisijungti' ?></a>
      </div>
    </div>

    <div class="auth-footer">&copy; <?php echo date('Y'); ?> TRAVIA</div>
  </div>

  <!-- Tribe Modal -->
  <div class="modalOverlay" id="tribeOverlay">
    <div class="modal">
      <div class="modalHead">
        <h3 class="modalTitle" id="tribeTitle">&nbsp;</h3>
        <a href="#" class="auth-pill" id="closeTribe" style="padding:10px 12px;">✕</a>
      </div>
      <div class="modalBody">
        <p id="tribeDesc" style="margin:0 0 10px;"></p>
        <div style="font-weight:1000;color:rgba(255,215,0,.92);margin-top:10px;">
          <?= $isEn ? 'Units' : 'Kariai' ?>
        </div>
        <ul class="unitList" id="tribeUnits"></ul>
        <div class="modalBtns">
          <a href="#" class="auth-btn" id="pickTribeBtn"><?= $isEn ? 'Choose' : 'Pasirinkti' ?></a>
          <a href="#" class="auth-btn secondary" id="backBtn"><?= $isEn ? 'Back' : 'Grįžti' ?></a>
        </div>
      </div>
    </div>
  </div>

  <script>
    const TRIBES = <?= json_encode($tribesUi, JSON_UNESCAPED_UNICODE) ?>;
    const lang = <?= json_encode($lang) ?>;

    const overlay = document.getElementById('tribeOverlay');
    const titleEl = document.getElementById('tribeTitle');
    const descEl = document.getElementById('tribeDesc');
    const unitsEl = document.getElementById('tribeUnits');
    const tribeInput = document.getElementById('tribeInput');
    const grid = document.getElementById('tribesGrid');

    let openKey = null;

    function setActiveCard(key){
      document.querySelectorAll('.tribeCard').forEach(c=>{
        c.classList.toggle('active', c.getAttribute('data-tribe') === key);
      });
      tribeInput.value = key;
    }

    function openTribe(key){
      const tr = TRIBES[key];
      if(!tr) return;
      openKey = key;
      titleEl.textContent = (lang === 'en') ? tr.name_en : tr.name_lt;
      descEl.textContent = (lang === 'en') ? tr.desc_en : tr.desc_lt;
      const units = (lang === 'en') ? tr.units_en : tr.units_lt;
      unitsEl.innerHTML = '';
      units.forEach(u=>{
        const li = document.createElement('li');
        li.className = 'unit';
        li.innerHTML = `<div class="uIcon">⚔️</div><div class="uTxt">${u}</div>`;
        unitsEl.appendChild(li);
      });
      overlay.classList.add('open');
    }
    function closeModal(){ overlay.classList.remove('open'); openKey = null; }

    grid.addEventListener('click', (e)=>{
      const card = e.target.closest('.tribeCard');
      if(!card) return;
      openTribe(card.getAttribute('data-tribe'));
    });

    document.getElementById('closeTribe').addEventListener('click', (e)=>{ e.preventDefault(); closeModal(); });
    document.getElementById('backBtn').addEventListener('click', (e)=>{ e.preventDefault(); closeModal(); });
    overlay.addEventListener('click', (e)=>{ if(e.target === overlay) closeModal(); });

    document.getElementById('pickTribeBtn').addEventListener('click', (e)=>{
      e.preventDefault();
      if(openKey){ setActiveCard(openKey); }
      closeModal();
    });

    document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') closeModal(); });

    setActiveCard(tribeInput.value || 'roman');
  </script>

</body>
</html>
