<?php
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']);
  exit;
}

$user = current_user($mysqli);
$uid = (int)($user['id'] ?? 0);
$v = current_village($mysqli, $uid);
if (!$v) {
  echo json_encode(['ok'=>false,'error'=>'no_village']);
  exit;
}
$vid = (int)$v['id'];

update_village_resources($mysqli, $vid);
process_village_queue($mysqli, $vid);

$v = village_row($mysqli, $vid);
$prod = village_production($mysqli, $vid);
$queue = village_queue($mysqli, $vid);

echo json_encode([
  'ok'=>true,
  'village'=>[
    'id'=>(int)$v['id'],
    'wood'=>(int)$v['wood'],
    'clay'=>(int)$v['clay'],
    'iron'=>(int)$v['iron'],
    'crop'=>(int)$v['crop'],
    'warehouse'=>(int)$v['warehouse'],
    'granary'=>(int)$v['granary'],
    'last_update'=>$v['last_update'] ?? null,
  ],
  'production'=>$prod,
  'ts'=>[
    'serverNow'=>time(),
    'lastUpdate'=>isset($v['last_update']) ? (int)$v['last_update'] : time(),
  ],
  'resources'=>[
    'wood'=>(int)$v['wood'],
    'clay'=>(int)$v['clay'],
    'iron'=>(int)$v['iron'],
    'crop'=>(int)$v['crop'],
  ],
  'cap'=>[
    'warehouse'=>(int)effective_warehouse_cap($v),
    'granary'=>(int)effective_granary_cap($v),
  ],
  'prod'=>[
    'wood'=>(int)($prod['wood'] ?? 0),
    'clay'=>(int)($prod['clay'] ?? 0),
    'iron'=>(int)($prod['iron'] ?? 0),
    'crop_gross'=>(int)($prod['crop'] ?? 0),
    'crop_upkeep'=>(int)village_crop_upkeep($mysqli, $vid),
    'crop_net'=>(int)(($prod['crop'] ?? 0) - village_crop_upkeep($mysqli, $vid)),
    'pop'=>(int)village_population($mysqli, $vid),
  ],
  'queue'=>array_map(function($q){
    return [
      'id'=>(int)$q['id'],
      'type'=>$q['type'],
      'target_level'=>(int)$q['target_level'],
      'finish_at'=>$q['finish_at'],
    ];
  }, $queue),
]);
