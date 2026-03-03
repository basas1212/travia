<?php
date_default_timezone_set('Europe/Vilnius'); // ✅ vienoda laiko zona (LT)
/**
 * TRAVIA init.php (RC02 FIX)
 * Tikslas: 1 failas, kuris stabiliai veikia shared hostinge (PHP 8.3), be redirect kilpų.
 * - Sesija: TRAVIASESSID (host-only cookie)
 * - DB: per config.php -> $conn (mysqli)
 * - Helperiai: redirect, require_login, current_user, csrf, current_lang, t(), ir bendros game funkcijos.
 */

// Apsauga nuo netyčinio output (BOM/tarpai kituose failuose)
if (!ob_get_level()) {
  ob_start();
}

// (optional) Debug — reguliuok per config.php (APP_DEBUG)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

/* =========================
   1) SESIJA
========================= */

session_name('TRAVIASESSID');

if (session_status() !== PHP_SESSION_ACTIVE) {
  // Host-only cookie (domain='') — mažiau problemų su www/non-www ir proxy.
  // secure=false — kad cookie neiškristų, jei hostingas/proxy neteisingai nustato HTTPS.
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}

/* =========================
   2) DB
========================= */

require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo 'DB klaida: config.php nesukūrė $conn (mysqli).';
  exit;
}

$mysqli = $conn;

// === Migracijos (RC045) ===
require_once __DIR__ . '/engine/migrator.php';
run_migrations($conn, __DIR__ . '/migrations');

$GLOBALS['mysqli'] = $mysqli;

// Patogus helperis (daug failu naudoja db())
function db() : mysqli {
  return $GLOBALS["mysqli"];
}

// Aiškios mysqli klaidos (bet negriaunam visko, jei hostingas riboja)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $mysqli->set_charset('utf8mb4');
} catch (Throwable $e) {
  // ignore
}

/* =========================
   3) BASIC HELPERS
========================= */

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void {
  // Tik relative redirect, kad nebūtų http/https/www kilpų
  $to = trim($to);
  if ($to === '') $to = 'index.php';
  if ($to[0] !== '/') $to = '/' . ltrim($to, '/');
  header('Location: ' . $to, true, 302);
  exit;
}

function is_logged_in(): bool {
  return !empty($_SESSION['user_id']);
}

function current_user_id(): ?int {
  return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Legacy suderinamumas: dalis failų kviečia current_user($mysqli) ir tikisi masyvo.
 */
function current_user(mysqli $db): ?array {
  $uid = current_user_id();
  if (!$uid) return null;

  // Saugiausia: SELECT * (kad nelūžtų, jei kažkur tikisi papildomų laukų)
  $stmt = $db->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
  $stmt->bind_param('i', $uid);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ?: null;
}

function require_login(): void {
  if (is_logged_in()) return;

  // Nekuriam kilpos: jei jau login/register – paliekam
  $self = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if ($self === 'login.php' || $self === 'register.php') return;

  // Pagal dabartinę struktūrą žaidimo "hub" yra /game/game.php
  $next = (string)($_SERVER['REQUEST_URI'] ?? '/game/game.php');
  if ($next === '' || $next[0] !== '/') $next = '/game/game.php';
  redirect('login.php?next=' . urlencode($next));
}

/* =========================
   4) CSRF (login/register + formoms)
========================= */

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function csrf_input(): string {
  $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
  return '<input type="hidden" name="csrf_token" value="' . $t . '">';
}

function csrf_verify(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;

  // palaikom abu pavadinimus (csrf_token ir _csrf)
  $token = (string)($_POST['csrf_token'] ?? ($_POST['_csrf'] ?? ''));
  if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
    http_response_code(400);
    exit('Netinkama užklausa (CSRF).');
  }
}

/* =========================
   5) KALBA / VERTIMAI
========================= */

// Jei serveryje yra lang.php – naudojam jį (pilnas i18n su cookie/session).
// Jei jo nėra (pvz., įkėlei tik kelis failus) – naudojam fallback į /lang/lt.php ir /lang/en.php.
if (file_exists(__DIR__ . '/lang.php')) {
  require_once __DIR__ . '/lang.php';
} else {
  // --- Minimalus fallback i18n ---
  $supported = ['lt','en'];

  $q = strtolower((string)($_GET['lang'] ?? ''));
  if ($q && in_array($q, $supported, true)) {
    $_SESSION['lang'] = $q;
    setcookie('travia_lang', $q, time()+60*60*24*365, '/', '', false, true);
  }

  $lang = strtolower((string)($_SESSION['lang'] ?? ($_COOKIE['travia_lang'] ?? 'lt')));
  if (!in_array($lang, $supported, true)) $lang = 'lt';

  $langFile = __DIR__ . '/lang/' . $lang . '.php';
  $TRAVIA_I18N = file_exists($langFile) ? include $langFile : [];

  function current_lang(): string {
    $supported = ['lt','en'];
    $lang = strtolower((string)($_SESSION['lang'] ?? ($_COOKIE['travia_lang'] ?? 'lt')));
    if (!in_array($lang, $supported, true)) $lang = 'lt';
    return $lang;
  }

  function t(string $key, array $vars = []): string {
    global $TRAVIA_I18N;
    $txt = $TRAVIA_I18N[$key] ?? $key;
    if ($vars) {
      foreach ($vars as $k => $v) {
        $txt = str_replace('{' . $k . '}', (string)$v, $txt);
      }
    }
    return (string)$txt;
  }
}

/* =========================
   6) BENDRA ŽAIDIMO LOGIKA
========================= */

require_once __DIR__ . '/functions.php';
