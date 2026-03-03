<?php
// payments/stripe_create_session.php
require_once __DIR__ . '/_stripe_helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  csrf_verify();

  $uid = current_user_id();
  if (!$uid) throw new Exception('Neprisijungęs.');

  $pkgId = (string)($_POST['package_id'] ?? $_GET['package_id'] ?? '');
  $pkgs = stripe_packages();
  if (!isset($pkgs[$pkgId])) {
    throw new Exception('Neteisingas paketas.');
  }
  $pkg = $pkgs[$pkgId];

  stripe_ensure_tables(db());

  // Stripe Checkout Session
  $amount_cents = (int)round($pkg['amount_eur'] * 100);

  $params = [
    'mode' => 'payment',
    'success_url' => STRIPE_SUCCESS_URL,
    'cancel_url'  => STRIPE_CANCEL_URL,
    'client_reference_id' => (string)$uid,

    'line_items[0][quantity]' => 1,
    'line_items[0][price_data][currency]' => 'eur',
    'line_items[0][price_data][unit_amount]' => $amount_cents,
    'line_items[0][price_data][product_data][name]' => $pkg['name'],
    'line_items[0][price_data][product_data][description]' => 'Virtuali žaidimo valiuta. Skaitmeninė prekė, pristatoma iškart.',
  ];

  // metadata (checkout session + payment_intent)
  $params['metadata[user_id]'] = (string)$uid;
  $params['metadata[package_id]'] = $pkgId;
  $params['metadata[gold]'] = (string)$pkg['gold'];

  $params['payment_intent_data[metadata][user_id]'] = (string)$uid;
  $params['payment_intent_data[metadata][package_id]'] = $pkgId;
  $params['payment_intent_data[metadata][gold]'] = (string)$pkg['gold'];

  // Jei turėsi email users lentelėje – Stripe galės išsiųsti receipt.
  $email = stripe_user_email(db(), (int)$uid);
  if ($email) {
    $params['customer_email'] = $email;
  }

  $session = stripe_api_request('POST', '/v1/checkout/sessions', $params);

  // Įrašom į DB (idempotentiškumas pagal session id)
  $db = db();
  $stmt = $db->prepare("INSERT IGNORE INTO stripe_payments (user_id, package_id, amount_eur, currency, checkout_session_id, status) VALUES (?,?,?,?,?, 'created')");
  $currency = 'eur';
  $sid = (string)$session['id'];
  $amt = (float)$pkg['amount_eur'];
  $stmt->bind_param('isdss', $uid, $pkgId, $amt, $currency, $sid);
  $stmt->execute();
  $stmt->close();

  echo json_encode([
    'ok' => true,
    'id' => $session['id'],
    'url' => $session['url'] ?? null
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
