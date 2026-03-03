<?php
/**
 * engine/migrator.php
 * Paprasta migracijų sistema:
 * - Skaito /migrations/*.sql (abėcėliškai)
 * - Įvykdo tik tas, kurios dar nebuvo vykdytos
 * - Įrašo į lentelę `migrations`
 *
 * Pastaba: vengiam prepared statement su "SHOW ... LIKE ?",
 * nes kai kuriuose MariaDB shared hostinguose tai lūžta.
 */

function run_migrations(mysqli $db, string $dir) : void {
  // sukurti migrations lentelę
  try {
    $db->query("CREATE TABLE IF NOT EXISTS migrations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL UNIQUE,
      executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {
    // jei nepavyko - tyliai (bet tada migracijos neveiks)
    return;
  }

  if (!is_dir($dir)) return;

  $files = glob(rtrim($dir, '/\\') . '/*.sql');
  if (!$files) return;
  sort($files, SORT_STRING);

  foreach ($files as $file) {
    $name = basename($file);
    // ar jau vykdyta?
    $n = $db->real_escape_string($name);
    $chk = $db->query("SELECT id FROM migrations WHERE name='{$n}' LIMIT 1");
    if ($chk && $chk->num_rows > 0) continue;

    $sql = @file_get_contents($file);
    if ($sql === false) continue;

    // vykdyti multi_query (daugybiniai statementai)
    try {
      if ($db->multi_query($sql)) {
        do {
          // flush results
          if ($res = $db->store_result()) $res->free();
        } while ($db->more_results() && $db->next_result());
      }
      // pažymėti kaip vykdytą
      $db->query("INSERT INTO migrations (name) VALUES ('{$n}')");
    } catch (Throwable $e) {
      // jei migracija nepavyko - neįrašom, kad būtų galima pataisyti ir bandyti iš naujo
      // (bendram stabilumui - nekrashinam viso žaidimo)
    }
  }
}
