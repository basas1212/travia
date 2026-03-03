<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>Debug start\n";

require_once __DIR__ . "/../init.php";
echo "init.php OK\n";

if (!isset($mysqli)) {
  echo "ERROR: \$mysqli not set\n";
  exit;
}

if ($mysqli->connect_errno) {
  echo "DB CONNECT ERROR: " . $mysqli->connect_error . "\n";
  exit;
}

echo "DB OK\n";

$res = $mysqli->query("SELECT 1 AS ok");
if (!$res) {
  echo "DB QUERY ERROR: " . $mysqli->error . "\n";
  exit;
}

echo "Query OK\n";
echo "</pre>";