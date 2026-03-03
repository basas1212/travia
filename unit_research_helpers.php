<?php

// Naudojama tiek Akademijoje (tyrimams), tiek Kareivinėse/Arklidėse/Dirbtuvėse (mokymui).
// Svarbu: akademijos reikalavimai taikomi tik TYRIMAMS, o ne MOKYMUI.

if (!function_exists('_ur_unit_column')) {
function _ur_unit_column(mysqli $conn): string {
  static $cached = null;
  if ($cached !== null) return $cached;
  $res = $conn->query("SHOW COLUMNS FROM unit_research");
  if (!$res) return $cached = 'unit';
  $cols = [];
  while ($row = $res->fetch_assoc()) {
    $cols[strtolower($row['Field'])] = true;
  }
  if (isset($cols['unit'])) return $cached = 'unit';
  if (isset($cols['unit_key'])) return $cached = 'unit_key';
  return $cached = 'unit';
}
}

if (!function_exists('_ur_has_researched_flag')) {
function _ur_has_researched_flag(mysqli $conn): bool {
  static $cached = null;
  if ($cached !== null) return $cached;
  $res = $conn->query("SHOW COLUMNS FROM unit_research LIKE 'researched'");
  return $cached = ($res && $res->num_rows > 0);
}
}

// Normalizuojam unit_key (kai DB naudoja EN raktą, o UI gali pateikti LT sinonimą).
if (!function_exists('canonical_unit_key')) {
function canonical_unit_key(string $unitKey): string {
  $unitKey = trim($unitKey);
  if ($unitKey === '') return $unitKey;

  // Pvz. UI / senesni duomenys gali turėti "*_falanga", o unit_definitions turi "*_phalanx".
  if (str_ends_with($unitKey, '_falanga')) {
    return substr($unitKey, 0, -strlen('_falanga')) . '_phalanx';
  }

  return $unitKey;
}
}

if (!function_exists('get_unit_def')) {
function get_unit_def(mysqli $conn, string $unitKey): ?array {
  $unitKey = canonical_unit_key($unitKey);
  $stmt = $conn->prepare("SELECT * FROM unit_definitions WHERE unit_key=? LIMIT 1");
  $stmt->bind_param("s", $unitKey);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ?: null;
}
}

if (!function_exists('is_unit_researched')) {
function is_unit_researched(mysqli $conn, int $villageId, string $unitKey): bool {
  $unitKey = canonical_unit_key($unitKey);
  $col = _ur_unit_column($conn);
  $hasFlag = _ur_has_researched_flag($conn);
  $sql = $hasFlag
    ? "SELECT 1 FROM unit_research WHERE village_id=? AND {$col}=? AND researched=1 LIMIT 1"
    : "SELECT 1 FROM unit_research WHERE village_id=? AND {$col}=? LIMIT 1";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("is", $villageId, $unitKey);
  $stmt->execute();
  $res = $stmt->get_result();
  $ok = ($res && $res->num_rows > 0);
  $stmt->close();
  return $ok;
}

}

if (!function_exists('set_unit_researched')) {
function set_unit_researched(mysqli $conn, int $villageId, string $unitKey): void {
  $unitKey = canonical_unit_key($unitKey);
  $col = _ur_unit_column($conn);
  $hasFlag = _ur_has_researched_flag($conn);

  if ($hasFlag) {
    $sql = "INSERT INTO unit_research (village_id, {$col}, researched, researched_at)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE researched=1, researched_at=VALUES(researched_at)";
  } else {
    $sql = "INSERT INTO unit_research (village_id, {$col}, researched_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE researched_at=VALUES(researched_at)";
  }
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("is", $villageId, $unitKey);
  $stmt->execute();
  $stmt->close();
}
}

if (!function_exists('unit_requirements_met')) {
function unit_requirements_met(mysqli $conn, int $villageId, string $unitKey): array {
  // Suderinamumui paliekam seną pavadinimą: tai TYRIMO (Akademijos) reikalavimai.
  return unit_research_requirements_met($conn, $villageId, $unitKey);
}
}

// Reikalavimai TYRIMUI (Akademijoje): akademija + papildomas pastatas.
if (!function_exists('unit_research_requirements_met')) {
function unit_research_requirements_met(mysqli $conn, int $villageId, string $unitKey): array {
  $def = get_unit_def($conn, $unitKey);
  if (!$def) return [false, "Unit nerastas"];

  $needAcademy = (int)($def['req_academy'] ?? 0);
  if ($needAcademy > 0) {
    $academyLvl = get_building_level($conn, $villageId, 'academy');
    if ($academyLvl < $needAcademy) {
      return [false, "Reikia Akademijos {$needAcademy} lygio"];
    }
  }

  $reqType = trim((string)($def['req_building'] ?? ''));
  $reqLvl  = (int)($def['req_building_level'] ?? 0);
  if ($reqType !== '' && $reqLvl > 0) {
    $haveLvl = get_building_level($conn, $villageId, $reqType);
    if ($haveLvl < $reqLvl) {
      return [false, "Reikia pastato '{$reqType}' {$reqLvl} lygio"];
    }
  }

  return [true, ""];
}
}

// Reikalavimai MOKYMUI (Kareivinėse/Arklidėse/Dirbtuvėse): tik papildomas pastatas (be akademijos).
if (!function_exists('unit_train_requirements_met')) {
function unit_train_requirements_met(mysqli $conn, int $villageId, string $unitKey): array {
  $def = get_unit_def($conn, $unitKey);
  if (!$def) return [false, "Unit nerastas"];

  $reqType = trim((string)($def['req_building'] ?? ''));
  $reqLvl  = (int)($def['req_building_level'] ?? 0);
  if ($reqType !== '' && $reqLvl > 0) {
    $haveLvl = get_building_level($conn, $villageId, $reqType);
    if ($haveLvl < $reqLvl) {
      return [false, "Reikia pastato '{$reqType}' {$reqLvl} lygio"];
    }
  }
  return [true, ""];
}
}

if (!function_exists('unit_can_train')) {
function unit_can_train(mysqli $conn, int $villageId, string $unitKey, string $buildingType): array {
  $def = get_unit_def($conn, $unitKey);
  if (!$def) return [false, "Unit nerastas"];

  if ($def['train_building'] !== $buildingType) {
    return [false, "Šis unit treniruojamas ne čia"];
  }

  if (!is_unit_researched($conn, $villageId, $unitKey)) {
    return [false, "Pirma ištirk Akademijoje"];
  }

  // Mokymui akademijos reikalavimų netaikom.
  return unit_train_requirements_met($conn, $villageId, $unitKey);
}
}

// Suderinamumas su senesniais train.php failais: kai kur tikimasi, kad egzistuoja
// funkcija format_requirements_missing($missing).
// Mūsų helper'iuose $missing dažniausiai yra string, bet paliekam palaikymą ir masyvui.
if (!function_exists('format_requirements_missing')) {
function format_requirements_missing($missing): string {
  if ($missing === null) return '';
  if (is_string($missing)) return trim($missing);
  if (is_array($missing)) {
    $parts = [];
    foreach ($missing as $k => $v) {
      if (is_string($v) && trim($v) !== '') $parts[] = trim($v);
      elseif (is_string($k) && is_scalar($v)) $parts[] = $k . ': ' . $v;
    }
    return implode(', ', $parts);
  }
  return '';
}
}