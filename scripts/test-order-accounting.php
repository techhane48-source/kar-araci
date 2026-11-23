<?php
declare(strict_types=1);

$directory = sys_get_temp_dir() . '/kf-accounting-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('KARFIYAT_DB=' . $directory . '/app.sqlite');
register_shutdown_function(static function () use ($directory): void {
    foreach (glob($directory . '/trendyol-rate/*') ?: [] as $file) unlink($file);
    if (is_dir($directory . '/trendyol-rate')) rmdir($directory . '/trendyol-rate');
    foreach (glob($directory . '/app.sqlite*') ?: [] as $file) unlink($file);
    rmdir($directory);
});

require_once __DIR__ . '/../includes/order_accounting.php';
require_once __DIR__ . '/../includes/trendyol.php';

function check(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}

// Sipariş kullanıcıya göre ayrılır (boş satırlar)
$insert = db()->prepare("INSERT INTO orders (user_id, order_number, package_number) VALUES (?, '123', ?)");
$insert->execute([1, 'own']);
$insert->execute([2, 'foreign']);
check(array_column(order_accounting_packages(1, '123'), 'package_number') === ['own'], 'Sipariş kullanıcıya göre ayrılır');

// Kabul senaryosu: aynı sipariş '456', aynı barkod BC-1, farklı satır kimlikleri, iki paket.
$insertOrder = db()->prepare("INSERT INTO orders (user_id, marketplace, order_number, package_number, status, total_price, order_date, lines)
    VALUES (1, 'trendyol', '456', ?, 'Delivered', ?, 0, ?)");
$linesA = json_encode([['barcode' => 'BC-1', 'quantity' => 3, 'price' => 100, 'orderLineId' => '100']]);
$linesB = json_encode([['barcode' => 'BC-1', 'quantity' => 1, 'price' => 100, 'orderLineId' => '200']]);
$insertOrder->execute(['PKG-A', 300, $linesA]);
$insertOrder->execute(['PKG-B', 100, $linesB]);
$packages = order_accounting_packages(1, '456');

// İade kalemi → satır eşleşmesi
$claim = ['orderNumber' => '456', 'orderShipmentPackageId' => 'PKG-A', 'items' => [[
    'orderLine' => ['id' => 100, 'barcode' => 'BC-1'],
    'claimItems' => [['id' => 'claim-1', 'orderLineItemId' => 5, 'claimItemStatus' => ['name' => 'Accepted']]],
]]];
$r = order_accounting_claims([$claim], '456', $packages);
check(count($r) === 1 && $r[0]['match'] === 'matched' && $r[0]['package'] === 'PKG-A' && $r[0]['barcode'] === 'BC-1', 'İade doğru satır/pakete eşleşir; aynı barkodlu diğer paket etkilenmez');

// Aynı barkod farklı satır kimliği → yalnızca kimlik eşleşmesi kullanılır
$claimB = ['orderNumber' => '456', 'orderShipmentPackageId' => 'PKG-B', 'items' => [[
    'orderLine' => ['id' => 200, 'barcode' => 'BC-1'],
    'claimItems' => [['id' => 'claim-2', 'orderLineItemId' => 6, 'claimItemStatus' => ['name' => 'Created']]],
]]];
$r = order_accounting_claims([$claimB], '456', $packages);
check(count($r) === 1 && $r[0]['match'] === 'matched' && $r[0]['package'] === 'PKG-B', 'Aynı barkodda farklı satır kimliği doğru paketi bulur');

// Satır kimliği yerelde yok → not_found
$claimMissing = ['orderNumber' => '456', 'items' => [[
    'orderLine' => ['id' => 999, 'barcode' => 'BC-1'],
    'claimItems' => [['id' => 'claim-3', 'claimItemStatus' => ['name' => 'Created']]],
]]];
$r = order_accounting_claims([$claimMissing], '456', $packages);
check($r[0]['match'] === 'not_found', 'Yerelde bulunamayan satır kimliği eşleşme eksikliği olarak görünür');

// Aynı satır kimliği birden fazla yerel satırda → ambiguous
$dupLines = json_encode([['barcode' => 'X', 'quantity' => 1, 'price' => 1, 'orderLineId' => '777'], ['barcode' => 'Y', 'quantity' => 1, 'price' => 1, 'orderLineId' => '777']]);
$insertDup = db()->prepare("INSERT INTO orders (user_id, marketplace, order_number, package_number, status, total_price, order_date, lines) VALUES (1,'trendyol','789','PKG-DUP','Delivered',0,0,?)");
$insertDup->execute([$dupLines]);
$claimDup = ['orderNumber' => '789', 'items' => [['orderLine' => ['id' => 777], 'claimItems' => [['id' => 'claim-4', 'claimItemStatus' => ['name' => 'Created']]]]]];
$r = order_accounting_claims([$claimDup], '789', order_accounting_packages(1, '789'));
check($r[0]['match'] === 'ambiguous', 'Aynı kimlik birden fazla satırda → belirsiz');

// Paket bilgisi çelişkisi → ambiguous
$claimConflict = ['orderNumber' => '456', 'orderShipmentPackageId' => 'PKG-B', 'items' => [[
    'orderLine' => ['id' => 100, 'barcode' => 'BC-1'],
    'claimItems' => [['id' => 'claim-5', 'claimItemStatus' => ['name' => 'Created']]],
]]];
$r = order_accounting_claims([$claimConflict], '456', $packages);
check($r[0]['match'] === 'ambiguous', 'Paket bilgisi çelişkili → belirsiz');

// Eski (kimliksiz) satır eşleştirilemez → not_found, hata yok
$legacyLines = json_encode([['barcode' => 'LEGACY', 'quantity' => 1, 'price' => 1]]);
$insertLegacy = db()->prepare("INSERT INTO orders (user_id, marketplace, order_number, package_number, status, total_price, order_date, lines) VALUES (1,'trendyol','999','PKG-LEG','Delivered',0,0,?)");
$insertLegacy->execute([$legacyLines]);
$claimLegacy = ['orderNumber' => '999', 'items' => [['orderLine' => ['id' => 100], 'claimItems' => [['id' => 'claim-6', 'claimItemStatus' => ['name' => 'Created']]]]]];
$r = order_accounting_claims([$claimLegacy], '999', order_accounting_packages(1, '999'));
check($r[0]['match'] === 'not_found', 'Kimliksiz eski satır okunur ve eşleşme eksikliği görünür');

$client = new TrendyolClient('123', 'fixture', 'fixture', false,
    static function ($method, $path, $query): array {
        if ($method !== 'GET') throw new RuntimeException('Beklenmeyen yazma isteği.');
        if (str_ends_with($path, '/claims') && $query['orderNumber'] === '123') {
            $rows = [['orderNumber' => '123', 'items' => [['claimItems' => [
                ['id' => 'claim-1', 'orderLineItemId' => 5, 'claimItemStatus' => ['name' => 'Accepted']],
            ]]]]];
        } elseif (str_ends_with($path, '/cargo-invoice/TEST-1/items')) {
            $rows = [['orderNumber' => '123', 'parcelUniqueId' => 100, 'shipmentPackageType' => 'İade Kargo Bedeli', 'amount' => 34.24],
                ['orderNumber' => '456', 'parcelUniqueId' => 200, 'shipmentPackageType' => 'Gönderi Kargo Bedeli', 'amount' => 12]];
        } else {
            throw new RuntimeException('Beklenmeyen uç nokta: ' . $path);
        }
        return ['http' => 200, 'body' => ['content' => $rows, 'totalPages' => 1, 'totalElements' => count($rows)]];
    }, true);

$claims = $client->getOrderClaims('123');
check($claims['complete'] && count(order_accounting_claims($claims['rows'], '123', order_accounting_packages(1, '123'))) === 1, 'İade kalemi kaydı ayrışır');
$cargo = $client->getCargoInvoiceItems('TEST-1');
check($cargo['complete'] && array_column(order_accounting_cargo($cargo['rows'], '123'), 'amount') === [34.24], 'Diğer siparişin kargosu dışarıda kalır');

// Kargo: paket bağı yok → sipariş düzeyi; tutar bilinmiyor/0 ayrılır; mükerrer toplanmaz
$cargoRows = [
    ['orderNumber' => '123', 'shipmentPackageType' => 'Gönderi Kargo Bedeli', 'parcelUniqueId' => 100, 'amount' => 34.24],
    ['orderNumber' => '123', 'shipmentPackageType' => 'İade Kargo Bedeli', 'parcelUniqueId' => 200, 'amount' => 0],
    ['orderNumber' => '123', 'shipmentPackageType' => 'Gönderi Kargo Bedeli', 'parcelUniqueId' => 300],           // tutar eksik
    ['orderNumber' => '123', 'shipmentPackageType' => 'Gönderi Kargo Bedeli', 'parcelUniqueId' => 100, 'amount' => 99.99], // mükerrer (aynı parcel+tür)
];
$cargoItems = order_accounting_cargo($cargoRows, '123');
check(count($cargoItems) === 3, 'Aynı gönderi+tür iki kez sayılmaz (mükerrer atlanır)');
check($cargoItems[1]['amount'] === 0.0, 'Gerçek sıfır tutar korunur');
check($cargoItems[2]['amount'] === null, 'Eksik tutar Bilinmiyor kalır, sıfıra çevrilmez');
check($cargoItems[2]['parcel'] === '300', 'Kimliksiz gönderi hata vermez, kimlik korunur');
check(order_accounting_cargo($cargoRows, '999') === [], 'Siparişe ait kalem içermeyen fatura boş döner');

$bad = new TrendyolClient('123', 'fixture', 'fixture', false,
    static fn() => ['http' => 200, 'body' => ['content' => [], 'totalPages' => 1, 'totalElements' => 1]], true);
check(!$bad->getOrderClaims('123')['ok'], 'Eksik sayfada başarılı sonuç yok');

$ledger = order_accounting_ledger([
    ['orderNumber' => '123', 'transactionType' => 'Satış', 'id' => 'FIN-1', 'source' => 'settlements', 'debt' => 0, 'credit' => 100],
    ['orderNumber' => '123', 'transactionType' => 'İade', 'id' => 'FIN-2', 'source' => 'settlements', 'debt' => 25, 'credit' => 0, 'commissionAmount' => 0],
    ['source' => 'otherfinancials', 'transactionType' => 'Kargo Faturası', 'id' => 'TEST-1'],
], '123');
check(array_column($ledger['rows'], 'commission') === [null, 0.0], 'Bilinmeyen komisyon sıfırdan ayrılır');
check($ledger['invoices'] === ['TEST-1'], 'Ödeme emrindeki kargo faturası adayı bulunur');

// Finans hareketi mükerrerliği: cari (provider_id) ile ödeme emri (id) aynı sağlayıcı kaydı.
$settlements = [['provider_id' => 'FIN-1', 'transaction_type' => 'Satış', 'shipment_package_id' => 'PKG', 'debt' => 0, 'credit' => 100]];
$flag = order_accounting_flag_duplicates($ledger['rows'], $settlements);
check($flag[0]['duplicate'] === true && $flag[1]['duplicate'] === false, 'Aynı sağlayıcı hareketi cari ve ödeme emrinde mükerrer işaretlenir');
check($flag[0]['id'] === 'FIN-1', 'Mükerrer kayıt kimliği korunur');

// Doğrulanamayan kimlik mükerrer sayılmaz, birleşik tutar üretilmez.
$flag2 = order_accounting_flag_duplicates([
    ['source' => 'settlements', 'id' => 'UNKNOWN-1', 'debt' => 5, 'credit' => 0],
    ['source' => 'otherfinancials', 'id' => 'UNKNOWN-1', 'debt' => 5, 'credit' => 0],
], $settlements);
check($flag2[0]['duplicate'] === false && $flag2[1]['duplicate'] === false, 'Doğrulanamayan kimlik mükerrer sayılmaz');

check(order_accounting_discounts(['discounts' => ['lineTotalDiscount' => 10.00, 'quantity' => 2]]) === ['seller' => null, 'trendyol' => null, 'api' => 10.0], 'API indirimi ayrı gösterilir; birim türetilmez');
check(order_accounting_discounts(['discounts' => ['lineSellerDiscount' => 3, 'lineTyDiscount' => 0]]) === ['seller' => 3.0, 'trendyol' => 0.0, 'api' => null], 'Satıcı ve sıfır Trendyol indirimi ayrı korunur');
check(order_accounting_discounts(['discounts' => ['lineSellerDiscount' => null, 'lineTyDiscount' => 'abc', 'lineTotalDiscount' => '']]) === ['seller' => null, 'trendyol' => null, 'api' => null], 'Eksik/geçersiz indirim bilinmiyor kalır');
check(order_accounting_discounts([]) === ['seller' => null, 'trendyol' => null, 'api' => null], 'discounts alanı yokken hata verilmez');
check(order_accounting_discounts(['discounts' => ['discount' => 2.5, 'tyDiscount' => 1.5]]) === ['seller' => 2.5, 'trendyol' => 1.5, 'api' => null], 'Eski alan adları (discount/tyDiscount) çalışır');

check(order_accounting_discount_text(['discounts' => ['lineTotalDiscount' => 10.00, 'quantity' => 2]]) === 'Satıcı indirimi Bilinmiyor · Trendyol indirimi Bilinmiyor · Birim toplam indirim ₺10,00', 'HTML: yalnız lineTotalDiscount=10 → birim 10,00, paylar bilinmiyor');
check(order_accounting_discount_text(['discounts' => ['lineTotalDiscount' => 0]]) === 'Satıcı indirimi Bilinmiyor · Trendyol indirimi Bilinmiyor · Birim toplam indirim ₺0,00', 'HTML: sıfır birim indirim 0,00 olarak görünür');
check(order_accounting_discount_text([]) === 'Satıcı indirimi Bilinmiyor · Trendyol indirimi Bilinmiyor · Birim toplam indirim Bilinmiyor', 'HTML: discounts yok → birim indirim bilinmiyor');
check(order_accounting_discount_text(['discounts' => ['lineSellerDiscount' => 3, 'lineTyDiscount' => 0, 'lineTotalDiscount' => 3]]) === 'Satıcı indirimi ₺3,00 · Trendyol indirimi ₺0,00 · Birim toplam indirim ₺3,00', 'HTML: satıcı/Trendyol payları ayrı ve sıfır korunur');

trendyol_rate_slot('shop-1', 0, microtime(true) + 60);
$blocked = false;
try {
    trendyol_rate_slot('shop-1', 0);
} catch (RuntimeException) {
    $blocked = true;
}
trendyol_rate_slot('shop-2', 0);
check($blocked, 'Kota beklemesi mağazaya göre ayrılır');
