<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function order_accounting_packages(int $userId, string $orderNumber): array
{
    $query = db()->prepare("SELECT package_number, order_number, order_date, status, total_price, lines, profit_inputs,
            marketplace, source_seller_id, source_stage, cargo_company FROM orders
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
        $amount = order_accounting_amount($row['amount'] ?? null);
        $key = $parcel . '|' . $type;
        if (isset($items[$key])) {
            // Aynı gönderi+tür iki kez geldi: tutar aynıysa gerçek mükerrer (atlanır),
            // farklıysa çelişkili — sessizce biri seçilmez, kalem toplam dışı bırakılır.
            if ($items[$key]['amount'] !== $amount) $items[$key]['conflict'] = true;
            continue;
        }
        $items[$key] = [
            'parcel' => $parcel,
            'type' => $type,
            'amount' => $amount,
            'conflict' => false,
        ];
    }
    return array_values($items);
}

function order_cargo_records(array $integration, string $orderNumber): array
{
    $query = db()->prepare('SELECT order_number AS orderNumber, parcel_id AS parcelUniqueId,
        cargo_type AS shipmentPackageType, amount, invoice_id FROM cargo_invoice_items
        WHERE user_id=? AND marketplace=? AND seller_id=? AND stage=? AND order_number=?
        ORDER BY invoice_id, line_number');
    $query->execute([$integration['user_id'], $integration['marketplace'], $integration['seller_id'],
        (int)$integration['stage'], $orderNumber]);
    $rows = $query->fetchAll();
    $invoiceByParcel = [];
    $conflicts = [];
    foreach ($rows as $row) {
        $key = $row['parcelUniqueId'] . '|' . $row['shipmentPackageType'];
        if (isset($invoiceByParcel[$key]) && $invoiceByParcel[$key] !== $row['invoice_id']) {
            $conflicts[$key] = true;
        }
        $invoiceByParcel[$key] = $row['invoice_id'];
    }
    $items = order_accounting_cargo($rows, $orderNumber);
    foreach ($items as &$item) {
        if (isset($conflicts[$item['parcel'] . '|' . $item['type']])) $item['conflict'] = true;
    }
    unset($item);
    return $items;
}

/** Null keeps an absent or contradictory expense distinct from a billed zero. */
function order_cargo_recorded_amount(array $items): ?float
{
    if (!$items) return null;
    $amount = 0.0;
    foreach ($items as $item) {
        if ($item['parcel'] === '' || $item['type'] === '' || $item['amount'] === null
            || $item['amount'] < 0 || $item['conflict']) return null;
        $amount += $item['amount'];
    }
    return $amount <= PROFIT_MAX_AMOUNT ? $amount : null;
}

function order_cargo_composition(string $json): ?array
{
    $lines = json_decode($json, true);
    if (!is_array($lines) || !$lines) return null;
    $composition = [];
    foreach ($lines as $line) {
        $barcode = $line['barcode'] ?? '';
        $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_string($barcode) || $barcode === '' || !$quantity) return null;
        $composition[$barcode] = ($composition[$barcode] ?? 0) + $quantity;
    }
    ksort($composition, SORT_STRING);
    return $composition;
}

function order_cargo_reference(array $integration, array $package): ?float
{
    $carrier = $package['cargo_company'];
    $composition = order_cargo_composition($package['lines']);
    if ($carrier === '' || $composition === null) return null;
    $query = db()->prepare("SELECT o.order_number, o.lines FROM orders o
        WHERE o.user_id=? AND o.marketplace=? AND o.source_seller_id=? AND o.source_stage=?
        AND o.order_number<>'' AND o.order_number<>? AND o.cargo_company=? AND o.order_date<? AND o.order_date>=?
        AND LOWER(o.status)='delivered'
        AND EXISTS (SELECT 1 FROM cargo_invoice_items c WHERE c.user_id=o.user_id AND c.marketplace=o.marketplace
            AND c.seller_id=o.source_seller_id AND c.stage=o.source_stage AND c.order_number=o.order_number)
        AND NOT EXISTS (SELECT 1 FROM orders other WHERE other.user_id=o.user_id AND other.marketplace=o.marketplace
            AND other.order_number=o.order_number AND other.id<>o.id
            AND (other.source_seller_id IS NULL OR (other.source_seller_id=o.source_seller_id AND other.source_stage=o.source_stage)))
        ORDER BY o.order_date DESC, o.id DESC LIMIT 100");
    $query->execute([$integration['user_id'], $integration['marketplace'], $integration['seller_id'], (int)$integration['stage'],
        $package['order_number'], $carrier, $package['order_date'], (int)$package['order_date'] - 90 * 86400]);
    foreach ($query->fetchAll() as $reference) {
        if (order_cargo_composition($reference['lines']) !== $composition) continue;
        $items = order_cargo_records($integration, $reference['order_number']);
        if (count($items) !== 1 || $items[0]['type'] !== 'Gönderi Kargo Bedeli') continue;
        $amount = order_cargo_recorded_amount($items);
        if ($amount !== null) return $amount;
    }
    return null;
}

/** Published standard TEX domestic tariff, KDV excluded. Barem shipments need separate inputs. */
function order_cargo_tariff(array $integration, array $package): ?array
{
    $carrier = strtolower(trim($package['cargo_company']));
    if (!in_array($carrier, ['trendyol express', 'tex', 'tex mp'], true)
        || (int)$integration['stage'] !== 0 || (float)$package['total_price'] < 350
        || !in_array(strtolower($package['status']), ['readytoship', 'created', 'picking', 'invoiced', 'shipped', 'delivered'], true)) {
        return null;
    }

    $day = (new DateTimeImmutable('@' . (int)$package['order_date']))
        ->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
    if ($day < '2026-08-10' || $day > '2026-11-30') return null;

    $composition = order_cargo_composition($package['lines']);
    if ($composition === null || array_values($composition) !== [1]) return null;
    $query = db()->prepare('SELECT kesilen_desi, en, boy, yukseklik FROM products
        WHERE user_id=? AND marketplace=? AND barcode=? LIMIT 2');
    $query->execute([$integration['user_id'], 'trendyol', array_key_first($composition)]);
    $products = $query->fetchAll();
    if (count($products) !== 1) return null;
    $product = $products[0];
    $desi = (float)$product['kesilen_desi'];
    if ($desi <= 0 && min($product['en'], $product['boy'], $product['yukseklik']) > 0) {
        $desi = desi_from_dims((float)$product['en'], (float)$product['boy'], (float)$product['yukseklik']);
    }
    if (!is_finite($desi) || $desi <= 0 || $desi > 30) return null;

    $rates = [1 => 81.95, 81.95, 100.20, 107.20, 114.10, 125.60, 133.41, 142.32,
        150.64, 164.18, 173.42, 182.20, 190.46, 198.10, 206.03, 213.82, 224.32,
        233.82, 243.28, 252.77, 263.34, 272.61, 283.24, 293.35, 303.36, 315.98,
        327.76, 338.64, 350.65, 360.55];
    $billableDesi = (int)ceil($desi);
    return ['amount' => round($rates[$billableDesi] * 1.20, 2), 'desi' => $billableDesi, 'carrier' => 'Trendyol Express'];
}

function order_cargo_tariff_text(array $tariff): string
{
    return 'Desi tarifesi · ' . $tariff['carrier'] . ' · ' . $tariff['desi'] . ' desi · Kargo faturası bekleniyor';
}

function order_panel_shipping(array $integration, string $order): ?float
{
    $query = db()->prepare('SELECT amount_cents FROM order_panel_shipping
        WHERE user_id=? AND marketplace=? AND seller_id=? AND stage=? AND order_number=?');
    $query->execute([$integration['user_id'], $integration['marketplace'], $integration['seller_id'],
        (int)$integration['stage'], $order]);
    $value = $query->fetchColumn();
    return $value === false ? null : (float)$value / 100;
}

function save_order_panel_shipping(array $integration, string $order, mixed $value): bool
{
    $raw = is_string($value) ? trim($value) : '';
    if (!preg_match('/^[0-9]{1,10}(?:[.,][0-9]{1,2})?$/D', $raw)) return false;
    $amount = (float)str_replace(',', '.', $raw);
    if ($amount > PROFIT_MAX_AMOUNT) return false;

    $query = db()->prepare('INSERT INTO order_panel_shipping
        (user_id, marketplace, seller_id, stage, order_number, amount_cents)
        SELECT i.user_id, i.marketplace, i.seller_id, i.stage, ?, ? FROM integrations i
        WHERE i.id=? AND i.revision=? AND i.user_id=?
        AND EXISTS (SELECT 1 FROM orders o WHERE o.user_id=i.user_id AND o.marketplace=i.marketplace
            AND o.order_number=? AND o.source_seller_id=i.seller_id AND o.source_stage=i.stage)
        AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id=i.user_id AND o.marketplace=i.marketplace
            AND o.order_number=? AND (o.source_seller_id IS NOT i.seller_id OR o.source_stage IS NOT i.stage))
        ON CONFLICT(user_id, marketplace, seller_id, stage, order_number)
        DO UPDATE SET amount_cents=excluded.amount_cents');
    $query->execute([$order, (int)round($amount * 100), $integration['id'], $integration['revision'],
        $integration['user_id'], $order, $order]);
    return $query->rowCount() === 1;
}

function order_cargo_profit_view(array $integration, array $packages, array $items): array
{
    $result = ['source' => 'pending', 'amount' => null, 'profit' => null, 'scope_matches' => false];
    foreach ($packages as $package) {
        if ($package['source_seller_id'] !== $integration['seller_id'] || $package['source_stage'] === null
            || (int)$package['source_stage'] !== (int)$integration['stage']) return $result;
    }
    if (!$packages) return $result;
    $result['scope_matches'] = true;

    $tariff = null;
    $amount = order_cargo_recorded_amount($items);
    if ($amount !== null) {
        $source = 'invoice';
    } elseif (($amount = order_panel_shipping($integration, $packages[0]['order_number'])) !== null) {
        $source = 'panel';
    } elseif (!$items && count($packages) === 1 && ($tariff = order_cargo_tariff($integration, $packages[0])) !== null) {
        $amount = $tariff['amount'];
        $source = 'tariff';
    } elseif (!$items && count($packages) === 1) {
        $amount = order_cargo_reference($integration, $packages[0]);
        $source = 'history';
    }
    if ($amount === null) return $result;
    $result = ['source' => $source, 'amount' => $amount, 'profit' => null, 'scope_matches' => true, 'tariff' => $tariff];

    // Package allocation would imply a link the invoice API does not provide.
    if (count($packages) !== 1 || $packages[0]['profit_inputs'] === null) return $result;
    $package = $packages[0];
    $package['lines'] = json_decode($package['lines'], true) ?: [];
    $profit = calc_order_profit($package, [], $amount);
    $result['profit'] = $profit['ok'] ? $profit['profit'] : null;
    $result['calculation'] = $profit;
    return $result;
}

/** Recent delivered orders whose cargo invoice lines came from the same store. */
function invoiced_orders_for_store(array $integration): array
{
    $query = db()->prepare("SELECT o.order_number, MAX(o.order_date) AS order_date
        FROM orders o
        WHERE o.user_id=? AND o.marketplace=? AND o.source_seller_id=? AND o.source_stage=?
          AND o.order_number<>'' AND o.order_date>0 AND LOWER(o.status)='delivered'
          AND EXISTS (SELECT 1 FROM cargo_invoice_items c
              WHERE c.user_id=o.user_id AND c.marketplace=o.marketplace
                AND c.seller_id=o.source_seller_id AND c.stage=o.source_stage AND c.order_number=o.order_number
                AND c.amount IS NOT NULL AND c.parcel_id<>'')
        GROUP BY o.order_number ORDER BY order_date DESC, o.order_number DESC LIMIT 20");
    $query->execute([$integration['user_id'], $integration['marketplace'], $integration['seller_id'], (int)$integration['stage']]);
    return $query->fetchAll();
}
