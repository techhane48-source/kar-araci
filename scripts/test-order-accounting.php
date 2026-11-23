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

$insert = db()->prepare("INSERT INTO orders (user_id, order_number, package_number) VALUES (?, '123', ?)");
$insert->execute([1, 'own']);
$insert->execute([2, 'foreign']);
check(array_column(order_accounting_packages(1, '123'), 'package_number') === ['own'], 'Sipariş kullanıcıya göre ayrılır');

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
check($claims['complete'] && order_accounting_claims($claims['rows'], '123') === [['line' => '5', 'status' => 'Accepted']], 'İade kalemi eşleşir');
$cargo = $client->getCargoInvoiceItems('TEST-1');
check($cargo['complete'] && array_column(order_accounting_cargo($cargo['rows'], '123'), 'amount') === [34.24], 'Diğer siparişin kargosu dışarıda kalır');

$bad = new TrendyolClient('123', 'fixture', 'fixture', false,
    static fn() => ['http' => 200, 'body' => ['content' => [], 'totalPages' => 1, 'totalElements' => 1]], true);
check(!$bad->getOrderClaims('123')['ok'], 'Eksik sayfada başarılı sonuç yok');

$ledger = order_accounting_ledger([
    ['orderNumber' => '123', 'transactionType' => 'Satış', 'debt' => 0, 'credit' => 100],
    ['orderNumber' => '123', 'transactionType' => 'İade', 'debt' => 25, 'credit' => 0, 'commissionAmount' => 0],
    ['source' => 'otherfinancials', 'transactionType' => 'Kargo Faturası', 'id' => 'TEST-1'],
], '123');
check(array_column($ledger['rows'], 'commission') === [null, 0.0], 'Bilinmeyen komisyon sıfırdan ayrılır');
check($ledger['invoices'] === ['TEST-1'], 'Ödeme emrindeki kargo faturası adayı bulunur');

trendyol_rate_slot('shop-1', 0, microtime(true) + 60);
$blocked = false;
try {
    trendyol_rate_slot('shop-1', 0);
} catch (RuntimeException) {
    $blocked = true;
}
trendyol_rate_slot('shop-2', 0);
check($blocked, 'Kota beklemesi mağazaya göre ayrılır');
