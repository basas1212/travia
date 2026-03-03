<?php
require_once __DIR__ . '/init.php';
header('Content-Type: text/plain; charset=utf-8');

echo "PHP: " . PHP_VERSION . "\n";
echo "SESSION_NAME: " . session_name() . "\n";
echo "SID: " . session_id() . "\n";
$cookieName = session_name();
echo "COOKIE(".$cookieName."): " . ($_COOKIE[$cookieName] ?? '-') . "\n";
echo "SESSION user_id: " . ($_SESSION['user_id'] ?? '-') . "\n";
echo "HOST: " . ($_SERVER['HTTP_HOST'] ?? '-') . "\n";
echo "HTTPS: " . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? $_SERVER['HTTPS'] : '-') . "\n";
