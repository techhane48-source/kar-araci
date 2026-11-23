<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function order_accounting_packages(int $userId, string $orderNumber): array
{
    $query = db()->prepare("SELECT package_number, status, total_price, lines FROM orders
        WHERE user_id = ? AND marketplace = 'trendyol' AND order_number = ? ORDER BY package_number");
    $query->execute([$userId, $orderNumber]);
    return $query->fetchAll();
}

function order_accounting_settlements(int $userId, string $orderNumber): array
{
    $query = db()->prepare("SELECT transaction_type, shipment_package_id, provider_id, barcode, debt, credit
        FROM settlements WHERE user_id = ? AND marketplace = 'trendyol' AND order_number = ?
        AND provider_id IS NOT NULL ORDER BY transaction_date, id");
    $query->execute([$userId, $orderNumber]);
    return $query->fetchAll();
}

/**
 * İade kalemlerini sipariş satırlarıyla sağlayıcı kimliğiyle eşleştirir.
 * Eşleşme anahtarı: claim `items[].orderLine.id` (resmî "Sipariş satır ID") ↔
 * yerel satır `orderLineId` (akış `lines[].lineId`). Barkod/adet/tutar
 * benzerliğinden eşleşme ÜRETİLMEZ; yalnızca belgelenmiş kimlik kullanılır.
 * `orderShipmentPackageId` paket düzeyinde çapraz doğrulama içindir.
 * Sonuç `match`: matched | ambiguous | not_found.
 */
function order_accounting_claims(array $rows, string $orderNumber, array $packages): array
{
    $byLineId = [];
    foreach ($packages as $package) {
        $packageNumber = (string)($package['package_number'] ?? '');
        foreach (json_decode($package['lines'] ?? '[]', true) ?: [] as $line) {
            if (!is_array($line)) continue;
            $lineId = (string)($line['orderLineId'] ?? '');
            if ($lineId === '') continue; // kimliksiz (eski) satır eşleştirilemez
            $byLineId[$lineId][] = ['package' => $packageNumber, 'barcode' => (string)($line['barcode'] ?? '')];
        }
    }

    $items = [];
    foreach ($rows as $row) {
        if ((string)($row['orderNumber'] ?? '') !== $orderNumber || !is_array($row['items'] ?? null)) {
            throw new UnexpectedValueException('İade kaydı siparişle eşleşmedi veya eksik.');
        }
        $claimPackage = (string)($row['orderShipmentPackageId'] ?? '');
        foreach ($row['items'] as $group) {
            if (!is_array($group['claimItems'] ?? null)) throw new UnexpectedValueException('İade kalemleri eksik.');
            $lineId = (string)($group['orderLine']['id'] ?? '');
            $claimBarcode = (string)($group['orderLine']['barcode'] ?? '');
            foreach ($group['claimItems'] as $item) {
                $id = (string)($item['id'] ?? '');
                $status = $item['claimItemStatus']['name'] ?? '';
                if ($id === '' || isset($items[$id]) || !is_string($status) || $status === '') {
                    throw new UnexpectedValueException('İade kalemi kimliği veya durumu geçersiz.');
                }
                $candidates = $byLineId[$lineId] ?? [];
                if ($lineId === '' || !$candidates) {
                    $match = 'not_found'; $package = null; $barcode = null;
                } elseif (count($candidates) > 1) {
                    $match = 'ambiguous'; $package = null; $barcode = null;
                } else {
                    $candidate = $candidates[0];
                    if ($claimPackage !== '' && $candidate['package'] !== '' && $claimPackage !== $candidate['package']) {
                        $match = 'ambiguous'; $package = null; $barcode = null;
                    } else {
                        $match = 'matched';
                        $package = $candidate['package'] !== '' ? $candidate['package'] : ($claimPackage !== '' ? $claimPackage : null);
                        $barcode = $candidate['barcode'] !== '' ? $candidate['barcode'] : ($claimBarcode !== '' ? $claimBarcode : null);
                    }
                }
                $items[$id] = [
                    'id' => $id,
                    'line_id' => $lineId,
                    'status' => $status,
                    'package' => $package,
                    'barcode' => $barcode,
                    'match' => $match,
                ];
            }
        }
    }
    return array_values($items);
}

function order_accounting_ledger(array $rows, string $orderNumber): array
{
    $matching = [];
    $invoiceIds = [];
    foreach ($rows as $row) {
        $type = (string)($row['transactionType'] ?? '');
        $source = (string)($row['source'] ?? '');
        if ($source === 'otherfinancials' && in_array($type, ['Kargo Faturası', 'Kargo Fatura'], true)) {
            $id = (string)($row['id'] ?? '');
            if ($id !== '') $invoiceIds[$id] = $id;
        }
        if ((string)($row['orderNumber'] ?? '') !== $orderNumber) continue;
        $matching[] = [
            'type' => $type,
            'source' => $source,
            'id' => (string)($row['id'] ?? ''),
            'package' => (string)($row['shipmentPackageId'] ?? ''),
            'debt' => (float)$row['debt'],
            'credit' => (float)$row['credit'],
            'commission' => order_accounting_amount($row['commissionAmount'] ?? null),
        ];
    }
    return ['rows' => $matching, 'invoices' => array_values($invoiceIds)];
}

/**
 * Ödeme emri hareketlerinden, senkronize cari kayıtlarla aynı sağlayıcı
 * işlem kimliğini (`id` == `provider_id`) taşıyanları mükerrer olarak
 * işaretler. Kimlik ilişkisi doğrulanamayan kayıtlar ayrı kalır; birleşik
 * tutar üretilmez.
 */
function order_accounting_flag_duplicates(array $ledgerRows, array $settlements): array
{
    $providerIds = [];
    foreach ($settlements as $s) {
        $pid = (string)($s['provider_id'] ?? '');
        if ($pid !== '') $providerIds[$pid] = true;
    }
    foreach ($ledgerRows as &$row) {
        $row['duplicate'] = ($row['source'] ?? '') === 'settlements'
            && isset($providerIds[(string)($row['id'] ?? '')]);
    }
    unset($row);
    return $ledgerRows;
}

/**
 * Test ortamında sağlayıcı yanıtlarını taklit etmek için kullanılan taşıyıcı.
 * Üretimde KARFIYAT_TEST_TRANSPORT tanımsızdır ve canlı istemci kullanılır.
 */
function order_accounting_transport(): ?callable
{
    $file = getenv('KARFIYAT_TEST_TRANSPORT');
    if (!is_string($file) || $file === '' || !is_file($file)) return null;
    $transport = require $file;
    return is_callable($transport) ? $transport : null;
}

function order_accounting_amount(mixed $value): ?float
{
    return is_numeric($value) && is_finite((float)$value) && abs((float)$value) <= 1e12 ? (float)$value : null;
}

/**
 * Ekranın gösterdiği indirim değerlerini bir satırın `discounts` alanından çıkarır.
 * Eksik/null/geçersiz alanlar null ("Bilinmiyor") döner; geçerli 0 tutar olarak korunur.
 * `lineTotalDiscount` resmî akış belgesinde "Birim toplam indirim
 * (lineSellerDiscount + lineTyDiscount)" olarak tanımlıdır; birim değerdir.
 */
function order_accounting_discounts(array $line): array
{
    $d = is_array($line['discounts'] ?? null) ? $line['discounts'] : [];
    return [
        'seller' => order_accounting_amount($d['lineSellerDiscount'] ?? $d['discount'] ?? null),
        'trendyol' => order_accounting_amount($d['lineTyDiscount'] ?? $d['tyDiscount'] ?? null),
        'api' => order_accounting_amount($d['lineTotalDiscount'] ?? null),
    ];
}

/**
 * Ekranın satır başına gösterdiği indirim metnini (HTML) üretir.
 * "Birim toplam indirim" her zaman gösterilir; değer sağlayıcıdan geldiği
 * gibi (birim) basılır, adetle çarpılmaz/bölünmez, satıcı/Trendyol paylarından
 * yeniden hesaplanmaz.
 */
function order_accounting_discount_text(array $line): string
{
    $d = order_accounting_discounts($line);
    $fmt = static fn(?float $v): string => $v === null ? 'Bilinmiyor' : tl($v);
    return 'Satıcı indirimi ' . $fmt($d['seller'])
        . ' · Trendyol indirimi ' . $fmt($d['trendyol'])
        . ' · Birim toplam indirim ' . $fmt($d['api']);
}

/**
 * Kargo faturası kalemlerini sipariş numarasıyla (sipariş düzeyinde) eşleştirir.
 * Resmî Kargo Faturası Detayları belgesinde kalemi bir sipariş PAKETİNE bağlayan
 * alan YOKTUR: `parcelUniqueId` "Kargo paketi benzersiz kimliği"dir (kargo gönderisi),
 * `shipmentPackageId`/`cargoTrackingNumber` değildir. Bu yüzden paket eşleşmesi
 * belirsizdir; gider sipariş geneline yazılır, paketlere paylaştırılmaz.
 * Tutar eksik/geçersizse null (Bilinmiyor) kalır, 0'a çevrilmez. Aynı gönderi+tür
 * iki kez sayılmaz.
 */
function order_accounting_cargo(array $rows, string $orderNumber): array
{
    $items = [];
    foreach ($rows as $row) {
        if (!isset($row['orderNumber']) || (string)$row['orderNumber'] === '') {
            throw new UnexpectedValueException('Kargo faturası satırında sipariş numarası eksik.');
        }
        if ((string)$row['orderNumber'] !== $orderNumber) continue;
        $parcel = (string)($row['parcelUniqueId'] ?? '');
        $type = (string)($row['shipmentPackageType'] ?? '');
        $key = $parcel . '|' . $type;
        if (isset($items[$key])) continue; // mükerrer satır iki kez toplanmaz
        $items[$key] = [
            'parcel' => $parcel,
            'type' => $type,
            'amount' => order_accounting_amount($row['amount'] ?? null),
        ];
    }
    return array_values($items);
}
