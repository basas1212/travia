<?php
// payments/_stripe_helpers.php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../stripe_config.php';

function stripe_api_request(string $method, string $path, array $params = []) : array {
  $url = 'https://api.stripe.com' . $path;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . STRIPE_SECRET_KEY,
    'Content-Type: application/x-www-form-urlencoded'
  ]);

  if (strtoupper($method) !== 'GET') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
  }

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) {
    throw new Exception('Stripe API klaida: ' . $err);
  }

  $data = json_decode($resp, true);
  if (!is_array($data)) {
    throw new Exception('Stripe API neteisingas atsakas (HTTP ' . $code . '): ' . $resp);
  }
  if ($code >= 400) {
    $msg = $data['error']['message'] ?? ('HTTP ' . $code);
    throw new Exception('Stripe API klaida: ' . $msg);
  }

  return $data;
}

function stripe_packages() : array {
  // Paketai (galėsi pakeisti vėliau)
  return [
'p1' => [
    'name' => 'Test 1€',
    'gold' => 10,
    'amount_eur' => 1.00
],
    'p5'  => ['name' => '100 Aukso',  'gold' => 100,  'amount_eur' => 5.00],
    'p10' => ['name' => '250 Aukso',  'gold' => 250,  'amount_eur' => 10.00],
    'p20' => ['name' => '600 Aukso',  'gold' => 600,  'amount_eur' => 20.00],
    'p50' => ['name' => '1700 Aukso', 'gold' => 1700, 'amount_eur' => 50.00],
  ];
}

function stripe_ensure_tables(mysqli $db) : void {
  // Mokėjimų lentelė
  $db->query("CREATE TABLE IF NOT EXISTS stripe_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    package_id VARCHAR(50) NOT NULL,
    amount_eur DECIMAL(10,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'eur',
    checkout_session_id VARCHAR(255) NOT NULL,
    payment_intent_id VARCHAR(255) DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'created',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uniq_session (checkout_session_id),
    UNIQUE KEY uniq_pi (payment_intent_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Įvykių lentelė (anti-duplicate)
  $db->query("CREATE TABLE IF NOT EXISTS stripe_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    checkout_session_id VARCHAR(255) DEFAULT NULL,
    payment_intent_id VARCHAR(255) DEFAULT NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event (event_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function stripe_user_email(mysqli $db, int $user_id) : ?string {
  // Jei ateityje pridėsi email stulpelį – čia automatiškai pradės veikti.
  // Dabar users lentelėje email nėra, todėl grąžins null.
  $res = $db->query("SHOW COLUMNS FROM users LIKE 'email'");
  if (!$res || $res->num_rows === 0) return null;

  $stmt = $db->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $user_id);
  $stmt->execute();
  $r = $stmt->get_result();
  $row = $r ? $r->fetch_assoc() : null;
  $stmt->close();

  $email = $row['email'] ?? null;
  if (!$email) return null;
  $email = trim((string)$email);
  return $email !== '' ? $email : null;
}
