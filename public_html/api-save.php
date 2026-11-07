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

db()->prepare("INSERT INTO products (user_id, name, marketplace, category, rate, cost, price, cargo, cargo_type, extra_fee, kdv, service_fee, stopaj, return_rate)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
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
    ]);

echo json_encode(['ok' => true]);
