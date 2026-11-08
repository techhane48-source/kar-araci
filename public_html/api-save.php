<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$u = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST isteği gerekli']);
    exit;
}
if (!csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Oturum süresi doldu. Sayfayı yenileyin.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Geçersiz istek']);
    exit;
}

$marketplace = $data['marketplace'] ?? '';
$category = $data['category'] ?? '';
if (!is_string($marketplace) || !is_string($category) || !is_string($data['name'] ?? 'Ürün')) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Geçersiz metin alanı']);
    exit;
}
$name = trim($data['name'] ?? 'Ürün');

if (!array_key_exists($marketplace, marketplaces())) {
    echo json_encode(['ok' => false, 'error' => 'Geçersiz pazaryeri']);
    exit;
}

// Komisyon: kullanıcı elle girdiyse onu kullan, yoksa kategoriye göre öner
$rate = (isset($data['rate']) && is_numeric($data['rate']))
    ? (float) $data['rate']
    : category_rate($category, $marketplace);

try {
    if (isset($data['rate']) && !is_numeric($data['rate'])) throw new InvalidArgumentException('Geçersiz komisyon oranı.');
    $amounts = array_map(static fn($key) => $data[$key] ?? 0, ['cost','price','cargo','extra_fee','service_fee','stopaj']);
    profit_validate($amounts, [$rate,$data['kdv']??20,$data['return_rate']??0]);
    if (!in_array($data['stopaj_mode']??'manual',['manual','auto'],true)) throw new InvalidArgumentException('Geçersiz stopaj seçimi.');
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}

// Ücretsiz plan limiti
$pdo=db();$pdo->exec('BEGIN IMMEDIATE');
try {
if (!is_pro($u) || !empty($u['is_demo'])) {
    $stmt = db()->prepare("SELECT COUNT(*) c FROM products WHERE user_id = ?");
    $stmt->execute([$u['id']]);
    $count = (int) $stmt->fetch()['c'];
    if ($count >= (!empty($u['is_demo'])?50:FREE_PRODUCT_LIMIT)) {
        $pdo->exec('ROLLBACK');
        echo json_encode(['ok' => false, 'error' => !empty($u['is_demo'])?'Demo hesabı 50 ürünle sınırlıdır.':'Ücretsiz plan 5 ürünle sınırlıdır. Daha fazlası için Pro plana geçin.', 'redirect' => 'odeme.php']);
        exit;
    }
}

db()->prepare("INSERT INTO products (user_id, name, marketplace, category, rate, cost, price, cargo, cargo_type, extra_fee, kdv, service_fee, stopaj, return_rate, stopaj_mode)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
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
        $data['stopaj_mode'] ?? 'manual',
    ]);
$pdo->exec('COMMIT');
}catch(Throwable $e){$pdo->exec('ROLLBACK');echo json_encode(['ok'=>false,'error'=>'Ürün kaydedilemedi.']);exit;}

echo json_encode(['ok' => true]);
