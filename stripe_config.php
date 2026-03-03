<?php
/**
 * Stripe konfigūracija (TRAVIA)
 *
 * REKOMENDUOJAMA: raktus laikyti ENV kintamuosiuose (DirectAdmin/cPanel -> "Environment variables"
 * arba per PHP-FPM / .htaccess SetEnv).
 *
 * Reikalingi ENV:
 *   STRIPE_MODE=live
 *
 * TEST:
 *   STRIPE_TEST_PUBLISHABLE_KEY=pk_test_...
 *   STRIPE_TEST_SECRET_KEY=sk_test_...
 *   STRIPE_TEST_WEBHOOK_SECRET=...
 *
 * LIVE:
 *   STRIPE_LIVE_PUBLISHABLE_KEY=...
 *   STRIPE_LIVE_SECRET_KEY=...
 *   STRIPE_LIVE_WEBHOOK_SECRET=whsec_...
 *
 * Jei ENV nėra – paliekami placeholderiai (kad netyčia nepaliktum realių raktų faile).
 */

$mode = strtolower((string)(getenv('STRIPE_MODE') ?: 'live'));
if ($mode !== 'live' && $mode !== 'test') $mode = 'live';
define('STRIPE_MODE', $mode);

// --- Keys by mode ---
if (STRIPE_MODE === 'live') {
  define('STRIPE_PUBLISHABLE_KEY', getenv('STRIPE_LIVE_PUBLISHABLE_KEY') ?: 'pk_live_51T66RPDWGQvP0n5jr6Sp6Xu6p8hpq6dxNa1ZOh6TbdmtdmHQiYD0mpl9ru4MzUix6jYRRDzYtp747KGh48vYQTaB00yvRLQsV5');
  define('STRIPE_SECRET_KEY',      getenv('STRIPE_LIVE_SECRET_KEY')      ?: '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');
  define('STRIPE_WEBHOOK_SECRET',  getenv('STRIPE_LIVE_WEBHOOK_SECRET')  ?: 'whsec_Kr4wpZlriYrBVo9sI57sHQETlahuopC2');
} else {
  define('STRIPE_PUBLISHABLE_KEY', getenv('STRIPE_TEST_PUBLISHABLE_KEY') ?: 'PAKEISK_PK_TEST');
  define('STRIPE_SECRET_KEY',      getenv('STRIPE_TEST_SECRET_KEY')      ?: 'PAKEISK_SK_TEST');
  define('STRIPE_WEBHOOK_SECRET',  getenv('STRIPE_TEST_WEBHOOK_SECRET')  ?: 'PAKEISK_WHSEC_TEST');
}

// Kur grįžta po apmokėjimo
$host = 'https://' . preg_replace('/^www\./','', $_SERVER['HTTP_HOST'] ?? 'travia.lt');
define('STRIPE_SUCCESS_URL', $host . '/stripe_success.php?session_id={CHECKOUT_SESSION_ID}');
define('STRIPE_CANCEL_URL',  $host . '/stripe_cancel.php');
