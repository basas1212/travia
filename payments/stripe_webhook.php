<?php
// payments/stripe_webhook.php
require_once __DIR__ . '/../stripe_config.php';
require_once __DIR__ . '/_stripe_helpers.php';

function stripe_log($msg) {
  $logFile = __DIR__ . '/stripe_webhook.log';
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
  @file_put_contents($logFile, $line, FILE_APPEND);
}

// GET / manual open
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(200);
  echo 'ok';
  exit;
}

$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

function stripe_parse_sig_header(string $sig_header) : array {
  $parts = [];
  foreach (explode(',', $sig_header) as $item) {
    $kv = explode('=', trim($item), 2);
    if (count($kv) === 2) {
      $k = $kv[0];
      $v = $kv[1];
      if (!isset($parts[$k])) $parts[$k] = [];
      $parts[$k][] = $v;
    }
  }
  return $parts;
}

function stripe_verify_signature(string $payload, string $sig_header, string $secret, int $tolerance = 600) : bool {
  if (!$secret || strpos($secret, 'whsec_') !== 0) return false;

  $parts = stripe_parse_sig_header($sig_header);
  $t = isset($parts['t'][0]) ? (int)$parts['t'][0] : 0;
  $v1s = $parts['v1'] ?? [];

  if ($t <= 0 || empty($v1s)) return false;

  // tolerance
  $delta = abs(time() - $t);
  if ($delta > $tolerance) {
    stripe_log('Signature timestamp out of tolerance. delta=' . $delta);
    return false;
  }

  $signed_payload = $t . '.' . $payload;
  $expected = hash_hmac('sha256', $signed_payload, $secret);

  foreach ($v1s as $v1) {
    if (hash_equals($expected, $v1)) return true;
  }
  return false;
}

if (!stripe_verify_signature($payload, $sig_header, (string)STRIPE_WEBHOOK_SECRET)) {
  stripe_log('Invalid signature. mode=' . STRIPE_MODE);
  http_response_code(400);
  echo 'Invalid signature';
  exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
  stripe_log('Invalid payload JSON');
  http_response_code(400);
  echo 'Invalid payload';
  exit;
}

$db = db();
stripe_ensure_tables($db);

$event_id = (string)($event['id'] ?? '');
$type = (string)($event['type'] ?? '');
$obj  = $event['data']['object'] ?? null;

if ($event_id === '' || $type === '' || !is_array($obj)) {
  stripe_log('Missing event fields');
  http_response_code(200);
  echo 'ok';
  exit;
}

// Anti-duplicate: if event already processed -> return 200
try {
  $stmt = $db->prepare("INSERT INTO stripe_events (event_id, event_type) VALUES (?,?)");
  $stmt->bind_param('ss', $event_id, $type);
  $stmt->execute();
  $stmt->close();
} catch (Throwable $e) {
  // Duplicate key -> already processed
  stripe_log('Duplicate event: ' . $event_id . ' type=' . $type);
  http_response_code(200);
  echo 'duplicate';
  exit;
}

if ($type === 'checkout.session.completed') {
  $session_id = (string)($obj['id'] ?? '');
  $payment_intent = (string)($obj['payment_intent'] ?? '');

  $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
  $user_id = (int)($meta['user_id'] ?? 0);
  $package_id = (string)($meta['package_id'] ?? '');
  $gold = (int)($meta['gold'] ?? 0);

  // Update stripe_events record with ids
  try {
    $stmt = $db->prepare("UPDATE stripe_events SET checkout_session_id=?, payment_intent_id=? WHERE event_id=?");
    $stmt->bind_param('sss', $session_id, $payment_intent, $event_id);
    $stmt->execute();
    $stmt->close();
  } catch (Throwable $e) {}

  if ($session_id && $payment_intent && $user_id > 0 && $gold > 0) {
    $db->begin_transaction();
    try {
      // Ensure payment row exists
      $stmt = $db->prepare("INSERT IGNORE INTO stripe_payments (user_id, package_id, amount_eur, currency, checkout_session_id, status) VALUES (?,?,?,?,?, 'created')");
      $currency = 'eur';
      $amt = 0.00;
      $stmt->bind_param('isdss', $user_id, $package_id, $amt, $currency, $session_id);
      $stmt->execute();
      $stmt->close();

      // Mark paid + set PI
      $stmt = $db->prepare("UPDATE stripe_payments SET payment_intent_id=?, status='paid', updated_at=NOW() WHERE checkout_session_id=?");
      $stmt->bind_param('ss', $payment_intent, $session_id);
      $stmt->execute();
      $stmt->close();

      // If already credited -> stop
      $stmt = $db->prepare("SELECT id FROM stripe_payments WHERE payment_intent_id=? AND status='credited' LIMIT 1");
      $stmt->bind_param('s', $payment_intent);
      $stmt->execute();
      $res = $stmt->get_result();
      $already = $res && $res->fetch_assoc();
      $stmt->close();

      if (!$already) {
        // Add gold
        $stmt = $db->prepare("UPDATE users SET gold = gold + ? WHERE id=?");
        $stmt->bind_param('ii', $gold, $user_id);
        $stmt->execute();
        $stmt->close();

        // Mark credited
        $stmt = $db->prepare("UPDATE stripe_payments SET status='credited', updated_at=NOW() WHERE payment_intent_id=?");
        $stmt->bind_param('s', $payment_intent);
        $stmt->execute();
        $stmt->close();
      }

      $db->commit();
      stripe_log("OK session={$session_id} pi={$payment_intent} uid={$user_id} gold={$gold} already=" . ($already? '1':'0'));
    } catch (Throwable $e) {
      $db->rollback();
      stripe_log('DB error: ' . $e->getMessage());
      http_response_code(500);
      echo 'DB error';
      exit;
    }
  } else {
    stripe_log('Missing metadata fields in checkout.session.completed');
  }
}

http_response_code(200);
echo 'ok';
