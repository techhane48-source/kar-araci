<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$u = require_login();

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Geçersiz istek']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify(is_string($data['csrf'] ?? null) ? $data['csrf'] : null)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Geçersiz oturum']); exit;
}
foreach (['cost','price','cargo','rate','extra_fee','kdv','cost_vat','service_fee','stopaj','stopaj_rate','return_rate'] as $key) {
    if (isset($data[$key]) && (!is_numeric($data[$key]) || !is_finite((float)$data[$key]) || (float)$data[$key]<0 ||
        (in_array($key,['rate','kdv','cost_vat','stopaj_rate','return_rate'],true) && (float)$data[$key]>100))) {
        http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Geçersiz değer: '.$key]); exit;
    }
}
$marketplace = $data['marketplace'] ?? '';
$category = $data['category'] ?? '';
$name = trim($data['name'] ?? 'Ürün');

if (!array_key_exists($marketplace, marketplaces())) {
    echo json_encode(['ok' => false, 'error' => 'Geçersiz pazaryeri']);
    exit;
}

// Komisyon: kullanıcı elle girdiyse onu kullan, yoksa kategoriye göre öner
$rate = (isset($data['rate']) && is_numeric($data['rate']))
    ? (float) $data['rate']
    : category_rate($category, $marketplace);

// Ücretsiz plan limiti
if (!is_pro($u)) {
    $stmt = db()->prepare("SELECT COUNT(*) c FROM products WHERE user_id = ?");
    $stmt->execute([$u['id']]);
    $count = (int) $stmt->fetch()['c'];
    if ($count >= FREE_PRODUCT_LIMIT) {
        echo json_encode(['ok' => false, 'error' => 'Ücretsiz plan 5 ürünle sınırlıdır. Daha fazlası için Pro plana geçin.', 'redirect' => 'odeme.php']);
        exit;
    }
}

db()->prepare("INSERT INTO products (user_id, name, marketplace, category, rate, cost, price, cargo, cargo_type, extra_fee, kdv, service_fee, stopaj, return_rate, cost_vat, stopaj_mode, stopaj_rate, allow_negative_vat)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([
        $u['id'],
        mb_substr($name, 0, 120),
        $marketplace,
        $category,
        $rate,
        (float) ($data['cost'] ?? 0),
        (float) ($data['price'] ?? 0),
        (float) ($data['cargo'] ?? 0),
        in_array($data['cargo_type'] ?? '', ['seller', 'export', 'sameday'], true) ? ($data['cargo_type'] ?? 'seller') : 'seller',
        (float) ($data['extra_fee'] ?? 0),
        (float) ($data['kdv'] ?? 20),
        (float) ($data['service_fee'] ?? 0),
        (float) ($data['stopaj'] ?? 0),
        (float) ($data['return_rate'] ?? 0),
        (float) ($data['cost_vat'] ?? $data['kdv'] ?? 20),
        ($data['stopaj_mode'] ?? '') === 'manual' ? 'manual' : 'auto',
        (float) ($data['stopaj_rate'] ?? 1),
        !empty($data['allow_negative_vat']) ? 1 : 0,
    ]);

echo json_encode(['ok' => true]);
