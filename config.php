<?php

/* =========================
   ✅ DEBUG NUSTATYMAI
========================= */

define('APP_DEBUG', true);

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');


/* =========================
   ✅ TIMEZONE (LABAI SVARBU)
========================= */

// PHP laiko zona
date_default_timezone_set('Europe/Vilnius');


/* =========================
   ✅ DUOMENŲ BAZĖ
========================= */

// Jei nori – gali naudoti ENV, jei ne – veiks ir taip
$host = getenv('TRAVIA_DB_HOST') ?: "localhost";
$user = getenv('TRAVIA_DB_USER') ?: "travia_travia_new";
$pass = getenv('TRAVIA_DB_PASS') ?: "SARvas123456*";
$db   = getenv('TRAVIA_DB_NAME') ?: "travia_travia_new";

mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = new mysqli($host, $user, $pass, $db);
} catch (Throwable $e) {
    die(APP_DEBUG ? "Database connection failed: " . $e->getMessage() : "Database connection failed.");
}

if ($conn->connect_error) {
    die(APP_DEBUG ? "Database connection failed: " . $conn->connect_error : "Database connection failed.");
}

$conn->set_charset("utf8mb4");

// MySQL sesijos laiko zona (kad NOW() sutaptų su PHP)
$conn->query("SET time_zone = '+02:00'");


/* =========================
   ✅ SERVERIO STARTAS
========================= */

// 2026-03-28 20:00 Europe/Vilnius
define('LAUNCH_TS', 1774720800);

// Registracija atidaryta
define('REGISTRATION_OPEN_NOW', true);

// Admin vartotojai
$ADMIN_USERNAMES = [
    "mantas",
    "basas"
];


/* =========================
   ✅ SERVERIO GREITIS
========================= */

// Serverio greitis (X1, X3, X5, X10 ir t.t.)
define('TRAVIA_SPEED', 100.0);


/* =========================
   ✅ STATYBŲ BALANSAS
========================= */

// Pagrindinio pastato efektas (~5% greičiau už lygį)
define('TRAVIA_MAIN_BUILDING_POW', 0.95);

// Kiek sumažėja laikas per MB lygį (formulė 1 / (1 + lvl * X))
define('TRAVIA_MB_PER_LEVEL', 0.05);


/* =========================
   ✅ SANDĖLIO / KLĖTIES SISTEMA
========================= */

// Bazinė talpa
define('TRAVIA_STORAGE_BASE', 800);

// Augimas per level
define('TRAVIA_STORAGE_GROWTH', 1.28);