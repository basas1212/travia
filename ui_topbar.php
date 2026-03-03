<?php
// ui_topbar.php - common mobile-friendly top navigation
// Expected variables: $activePage = 'village'|'city'|'map'|'stats'|'gold'|'menu'

if (!isset($activePage)) $activePage = '';

$lang = function_exists('current_lang') ? current_lang() : (string)($_SESSION['lang'] ?? 'lt');

function ui_topbar_btn(string $href, string $icon, string $titleKey, bool $active): string {
  $cls = 'dockBtn' . ($active ? ' active' : '');
  $title = function_exists('t') ? t($titleKey) : $titleKey;
  return '<a class="' . $cls . '" href="' . h($href) . '" title="' . h($title) . '">' . $icon . '</a>';
}

?>
<div class="topDock">
  <!-- Naudojam absoliučias nuorodas, kad veiktų tiek root puslapiuose (gold_shop.php, admin.php),
       tiek /game/ puslapiuose. -->
  <?php echo ui_topbar_btn('/game/village.php', '🏡', 'nav_village', $activePage === 'village'); ?>
  <?php echo ui_topbar_btn('/game/city.php',    '🏰', 'nav_city',    $activePage === 'city'); ?>
  <?php echo ui_topbar_btn('/game/map.php',     '🗺️', 'nav_map',     $activePage === 'map'); ?>
  <?php echo ui_topbar_btn('/game/stats.php',   '📊', 'nav_stats',   $activePage === 'stats'); ?>
  <?php echo ui_topbar_btn('/gold_shop.php',    '💰', 'nav_gold',    $activePage === 'gold'); ?>
  <?php echo ui_topbar_btn('/game/game.php',    '☰', 'nav_menu',    $activePage === 'menu'); ?>

  <div class="dockSpacer"></div>

  <?php if (function_exists('is_logged_in') && is_logged_in() && isset($GLOBALS['mysqli'])) { $u = current_user($GLOBALS['mysqli']); $g = (int)($u['gold'] ?? 0); echo '<div class="dockGold" title="Auksas">💰 ' . $g . '</div>'; } ?>

  <div class="langToggle" aria-label="Language">
    <a class="langBtn <?php echo ($lang === 'lt') ? 'active' : ''; ?>" href="?lang=lt">LT</a>
    <a class="langBtn <?php echo ($lang === 'en') ? 'active' : ''; ?>" href="?lang=en">EN</a>
  </div>
</div>
