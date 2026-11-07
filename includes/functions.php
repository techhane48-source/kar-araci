<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crypto.php';

// ---- İş mantığı: komisyon oranları + kâr hesabı ----

/**
 * Kategori -> her pazaryeri için komisyon oranı (% , KDV dahil).
 * Anahtarlar: t=Trendyol, h=Hepsiburada, a=Amazon TR, n=n11.
 */
function categories(): array
{
    return [
        ['name' => 'Giyim',                 't' => 21.36, 'h' => 18,    'a' => 15,   'n' => 20.34],
        ['name' => 'Ayakkabı & Çanta',      't' => 21.36, 'h' => 19.49, 'a' => 15,   'n' => 18],
        ['name' => 'Aksesuar / Bijuteri',   't' => 22.37, 'h' => 18,    'a' => 15,   'n' => 18],
        ['name' => 'Kol Saati',             't' => 21.36, 'h' => 18,    'a' => 15,   'n' => 18],
        ['name' => 'Cep Telefonu',          't' => 7,      'h' => 7,     'a' => 10,   'n' => 12],
        ['name' => 'Telefon Aksesuarı',     't' => 16.5,   'h' => 25,    'a' => 11,   'n' => 15],
        ['name' => 'Bilgisayar',            't' => 7.5,    'h' => 12,    'a' => 6,    'n' => 12],
        ['name' => 'Televizyon',            't' => 8,      'h' => 6.8,   'a' => 10,   'n' => 10],
        ['name' => 'Elektronik (genel)',    't' => 15.5,   'h' => 12,    'a' => 6,    'n' => 12],
        ['name' => 'Beyaz Eşya',            't' => 11,     'h' => 10,    'a' => 7,    'n' => 12],
        ['name' => 'Küçük Ev Aletleri',     't' => 15,     'h' => 15,    'a' => 11,   'n' => 12],
        ['name' => 'Mobilya',               't' => 20.34,  'h' => 20,    'a' => 20,   'n' => 18],
        ['name' => 'Ev Tekstili',           't' => 20.34,  'h' => 18,    'a' => 15,   'n' => 18],
        ['name' => 'Mutfak / Züccaciye',    't' => 19.32,  'h' => 18,    'a' => 15,   'n' => 18],
        ['name' => 'Anne & Bebek',          't' => 16.5,   'h' => 14,    'a' => 10,   'n' => 16],
        ['name' => 'Oyuncak',               't' => 16.5,   'h' => 18,    'a' => 10,   'n' => 18],
        ['name' => 'Kozmetik / Parfüm',     't' => 16.5,   'h' => 17,    'a' => 12.7,  'n' => 18],
        ['name' => 'Spor Ürünleri',         't' => 21.36,  'h' => 12,    'a' => 10,   'n' => 16],
        ['name' => 'Petshop',               't' => 15.25,  'h' => 15,    'a' => 12.7,  'n' => 15],
        ['name' => 'Kitap / Kırtasiye',     't' => 15,     'h' => 12,    'a' => 10.2,  'n' => 15],
        ['name' => 'Altın',                 't' => 15,     'h' => 6,     'a' => 10,   'n' => 10],
        ['name' => 'Gıda',                  't' => 15,     'h' => 12,    'a' => 12.7,  'n' => 15],
        ['name' => 'Diğer',                 't' => 15,     'h' => 18,    'a' => 10,   'n' => 18],
    ];
}

function marketplace_key(string $code): string
{
    $map = ['trendyol' => 't', 'hepsiburada' => 'h', 'amazon' => 'a', 'n11' => 'n',
            'aliexpress' => 'ae', 'ciceksepeti' => 'cs', 'epttavm' => 'et'];
    return $map[$code] ?? 't';
}

function marketplace_label(string $code): string
{
    $map = ['trendyol' => 'Trendyol', 'hepsiburada' => 'Hepsiburada', 'amazon' => 'Amazon TR', 'n11' => 'N11',
            'aliexpress' => 'AliExpress', 'ciceksepeti' => 'ÇiçekSepeti', 'epttavm' => 'EPTTAVM'];
    return $map[$code] ?? $code;
}

function marketplaces(): array
{
    return [
        'trendyol' => 'Trendyol', 'hepsiburada' => 'Hepsiburada', 'n11' => 'N11', 'amazon' => 'Amazon TR',
        'aliexpress' => 'AliExpress', 'ciceksepeti' => 'ÇiçekSepeti', 'epttavm' => 'EPTTAVM',
    ];
}

/**
 * Kategori + pazaryeri için önerilen komisyon oranı (%).
 * Trendyol/Hepsiburada/Amazon/N11 kategori bazlı; AliExpress/ÇiçekSepeti/EPTTAVM genel ortalamadır
 * (bu 3 platformun kategori bazlı tam listesi değişkendir — kullanıcı komisyonu elle düzeltebilir).
 * n11'e %2 hizmet bedeli eklenir.
 */
function category_rate(string $categoryName, string $marketplace): float
{
    $general = ['aliexpress' => 8.0, 'ciceksepeti' => 15.0, 'epttavm' => 10.0];
    if (isset($general[$marketplace])) {
        return $general[$marketplace];
    }
    $key = marketplace_key($marketplace);
    foreach (categories() as $c) {
        if ($c['name'] === $categoryName) {
            $r = (float) $c[$key];
            if ($marketplace === 'n11') $r += 2;
            return $r;
        }
    }
    return 15.0; // bilinmeyen kategori için güvenli varsayılan
}

/**
 * Net kâr hesabı (komisyon hesaplama modeli).
 * Kâr = Satış − Alış − Komisyon − Kargo − Ek Bedel (ihracat/aynı gün) − KDV (ödenen)
 * Tüm tutarlar KDV dahil TL. KDV = (Satış − Alış − Komisyon − Kargo − Ek Bedel) × KDV/(1+KDV).
 */
function calc_profit(float $cost, float $price, float $cargo, float $rate, float $kdvRate = 20.0, float $extraFee = 0.0, float $serviceFee = 0.0, float $stopaj = 0.0, float $returnRate = 0.0): array
{
    $commission = $price * ($rate / 100);
    $returnLoss = $price * ($returnRate / 100);   // iade oranı kadar satış tutarı kaybı (tutucu tahmin)
    $kdvFrac = $kdvRate / (100 + $kdvRate);
    // Net KDV (Melontik modeli) = Satış KDV − Maliyet KDV − Komisyon KDV − Kargo KDV − Hizmet KDV.
    // Stopaj ayrı bir tevkifat kalemidir, KDV tabanına girmez. Negatif çıkarsa 0 alınır (floor).
    $netKdv = ($price - $cost - $commission - $cargo - $extraFee - $serviceFee) * $kdvFrac;
    if ($netKdv < 0) $netKdv = 0.0;
    $profit = $price - $cost - $commission - $cargo - $extraFee - $serviceFee - $stopaj - $netKdv - $returnLoss;
    $margin = $price > 0 ? ($profit / $price) * 100 : 0.0;   // kâr marjı = kâr / satış
    $ratio = $cost > 0 ? ($profit / $cost) * 100 : 0.0;      // kâr oranı = kâr / maliyet
    return [
        'profit' => $profit,
        'margin' => $margin,
        'ratio' => $ratio,
        'commission' => $commission,
        'net_kdv' => $netKdv,
        'extra_fee' => $extraFee,
        'service_fee' => $serviceFee,
        'stopaj' => $stopaj,
        'return_loss' => $returnLoss,
        'totalCuts' => $commission + $cargo + $extraFee + $serviceFee + $stopaj + $netKdv + $returnLoss,
    ];
}

/**
 * Stopaj (tevkifat) — Melontik modeli: KDV-hariç satış tutarının belirli oranı.
 * Trendyol için %1 (0.01). $stopajRate yüzde olarak verilir (örn. 1.0).
 */
function auto_stopaj(float $price, float $kdvRate = 20.0, float $stopajRate = 1.0): float
{
    if ($price <= 0) return 0.0;
    return $price / (1 + $kdvRate / 100) * ($stopajRate / 100);
}

/** Trendyol sabit hizmet bedeli (₺). */
function trendyol_service_fee(): float
{
    return 13.19;
}

/**
 * Ters hesap motoru — Melontik'in "Fiyat Oluştur" çekirdeği.
 * Hedef net kâr (₺) veya hedef kâr marjı (%) ver; gerekli satış fiyatını kuruşuna çöz.
 * Komisyon, hizmet bedeli, kargo, stopaj (KDV-hariç × oran), Net KDV (floor 0) ve iade kaybı
 * tam olarak modele dahildir. Binary search ile Net KDV floor'unu da doğru ele alır.
 *
 * @param float $targetProfit  hedef net kâr (₺). 0 ise $targetMargin kullanılır.
 * @param float $targetMargin  hedef kâr marjı (%) = kâr / satış. 0 ise $targetProfit kullanılır.
 * @param float $stopajRate    stopaj oranı (%) — Trendyol %1.
 */
function calc_price(float $cost, float $cargo, float $rate, float $kdvRate = 20.0,
                    float $extraFee = 0.0, float $serviceFee = 13.19, float $stopajRate = 1.0,
                    float $returnRate = 0.0, float $targetProfit = 0.0, float $targetMargin = 0.0): array
{
    $rateFrac = $rate / 100;
    $returnFrac = $returnRate / 100;
    $kdvFrac = $kdvRate / (100 + $kdvRate);
    $kdvDiv = 1 + $kdvRate / 100;
    $stopajFrac = $stopajRate / 100;

    $profitAt = function (float $P) use ($cost, $cargo, $rateFrac, $kdvFrac, $extraFee, $serviceFee, $stopajFrac, $kdvDiv, $returnFrac): float {
        $commission = $P * $rateFrac;
        $stopaj = $P / $kdvDiv * $stopajFrac;
        $netKdv = ($P - $cost - $commission - $cargo - $extraFee - $serviceFee) * $kdvFrac;
        if ($netKdv < 0) $netKdv = 0.0;
        $returnLoss = $P * $returnFrac;
        return $P - $cost - $commission - $cargo - $extraFee - $serviceFee - $stopaj - $netKdv - $returnLoss;
    };

    $f = $targetMargin > 0
        ? (function (float $P) use ($profitAt, $targetMargin): float {
            return $profitAt($P) - $P * $targetMargin / 100;
        })
        : (function (float $P) use ($profitAt, $targetProfit): float {
            return $profitAt($P) - $targetProfit;
        });

    $lo = 0.0;
    $hi = ($cost + $cargo + $extraFee + $serviceFee) * 4 + 1000.0;
    $guard = 0;
    while ($f($hi) < 0 && $guard++ < 60) { $hi *= 2; }

    for ($i = 0; $i < 200; $i++) {
        $mid = ($lo + $hi) / 2;
        if ($f($mid) > 0) $hi = $mid; else $lo = $mid;
    }
    $P = ($lo + $hi) / 2;
    if (!is_finite($P) || $P <= 0) $P = 0.0;

    $r = calc_profit($cost, $P, $cargo, $rate, $kdvRate, $extraFee, $serviceFee, auto_stopaj($P, $kdvRate, $stopajRate), $returnRate);

    return [
        'price' => $P,
        'profit' => $r['profit'],
        'margin' => $r['margin'],
        'ratio' => $r['ratio'],
        'commission' => $r['commission'],
        'net_kdv' => $r['net_kdv'],
        'service_fee' => $serviceFee,
        'stopaj' => $r['stopaj'],
        'cargo' => $cargo,
        'extra_fee' => $extraFee,
        'return_loss' => $r['return_loss'],
    ];
}

/** Desi hesabı: en × boy × yükseklik (cm) ÷ 3000. */
function desi_from_dims(float $enCm, float $boyCm, float $yukseklikCm): float
{
    return ($enCm * $boyCm * $yukseklikCm) / 3000.0;
}

function tl(float $v): string
{
    if (!is_finite($v)) return '—';
    $s = $v < 0 ? '-' : '';
    return $s . '₺' . number_format(abs($v), 2, ',', '.');
}

// ---- Pazar yeri entegrasyonu (satıcı kendi API bilgilerini girer) ----

function get_integration(int $userId, string $marketplace): ?array
{
    $stmt = db()->prepare("SELECT * FROM integrations WHERE user_id = ? AND marketplace = ?");
    $stmt->execute([$userId, $marketplace]);
    $r = $stmt->fetch();
    if (!$r) return null;
    // Gizli alanları çöz
    $r['api_key'] = decrypt_string($r['api_key']);
    $r['api_secret'] = decrypt_string($r['api_secret']);
    return $r;
}

function save_integration(int $userId, string $marketplace, string $sellerId, string $apiKey, string $apiSecret, bool $stage = false): void
{
    $encKey = encrypt_string($apiKey);
    $encSecret = encrypt_string($apiSecret);
    db()->prepare("INSERT INTO integrations (user_id, marketplace, seller_id, api_key, api_secret, stage)
                   VALUES (?, ?, ?, ?, ?, ?)
                   ON CONFLICT(user_id, marketplace) DO UPDATE SET
                     seller_id = excluded.seller_id,
                     api_key = excluded.api_key,
                     api_secret = excluded.api_secret,
                     stage = excluded.stage")
        ->execute([$userId, $marketplace, trim($sellerId), $encKey, $encSecret, $stage ? 1 : 0]);
}

function delete_integration(int $userId, string $marketplace): void
{
    db()->prepare("DELETE FROM integrations WHERE user_id = ? AND marketplace = ?")
        ->execute([$userId, $marketplace]);
}

function integration_client(array $int): object
{
    $mp = $int['marketplace'] ?? 'trendyol';
    $sellerId = $int['seller_id'] ?? '';
    $apiKey = $int['api_key'] ?? '';
    $apiSecret = $int['api_secret'] ?? '';
    $stage = (bool)($int['stage'] ?? false);

    switch ($mp) {
        case 'hepsiburada':
            require_once __DIR__ . '/hb.php';
            return new HbClient($sellerId, $apiKey, $apiSecret, $stage);
        case 'amazon':
            require_once __DIR__ . '/amazon.php';
            return new AmazonClient($sellerId, $apiKey, $apiSecret, $stage);
        case 'n11':
            require_once __DIR__ . '/n11.php';
            return new N11Client($sellerId, $apiKey, $apiSecret, $stage);
        case 'aliexpress':
            require_once __DIR__ . '/aliexpress.php';
            return new AliExpressClient($sellerId, $apiKey, $apiSecret, $stage);
        case 'ciceksepeti':
            require_once __DIR__ . '/ciceksepeti.php';
            return new CicekSepetiClient($sellerId, $apiKey, $apiSecret, $stage);
        case 'epttavm':
            require_once __DIR__ . '/epttavm.php';
            return new EpttavmClient($sellerId, $apiKey, $apiSecret, $stage);
        case 'trendyol':
        default:
            require_once __DIR__ . '/trendyol.php';
            return new TrendyolClient($sellerId, $apiKey, $apiSecret, $stage);
    }
}

/**
 * Entegrasyondan ürünleri çekip kullanıcının products tablosuna aktarır.
 * (Mevcut ürünler korunur; yeni barcode'lar eklenir. Maliyet alanı 0 kalır —
 *  kullanıcı sonradan girer çünkü API'den maliyet gelmez.)
 */
function sync_products(int $userId, string $marketplace): array
{
    $int = get_integration($userId, $marketplace);
    if (!$int) return ['ok' => false, 'error' => 'Entegrasyon bulunamadı. Önce bağlantı kurun.'];

    // Büyük kataloglar için: zaman/memory sınırını gevşet
    set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $client = integration_client($int);

    // Artımlı senkron: son senkron sonrası DEĞİŞEN ürünleri çek (büyük katalogda hızlı).
    // İlk senkron (last_sync boş) tam çeker.
    $startDateMs = 0;
    $dateQueryType = '';
    if (!empty($int['last_sync'])) {
        $ts = strtotime((string)$int['last_sync'] . ' UTC');
        if ($ts) {
            $startDateMs = $ts * 1000;
            $dateQueryType = 'LAST_MODIFIED_DATE';
        }
    }
    $res = $client->getProducts(500, '', $startDateMs, $dateQueryType);

    if (!$res['ok']) return $res;

    $db = db();
    $inserted = 0;
    $skipped = 0;

    // Mevcut barkodları TEK sorguda al -> HashSet (O(1) arama, 10k+ ürün için hızlı)
    $existing = [];
    $stmt = $db->prepare("SELECT barcode, name FROM products WHERE user_id = ? AND marketplace = ?");
    $stmt->execute([$userId, $marketplace]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['barcode'] !== '') $existing['b:' . $r['barcode']] = true;
        $existing['n:' . $r['name']] = true;
    }

    $ins = $db->prepare("INSERT INTO products (user_id, name, barcode, marketplace, category, rate, cost, price, quantity, cargo, service_fee, stopaj, return_rate)
                          VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, 0, 0, 0)");

    $db->beginTransaction();
    try {
        foreach ($res['products'] as $p) {
            $barcode = (string)($p['barcode'] ?? '');
            $name = $p['title'] ?: ($barcode ?: 'Ürün');
            $cat = $p['category'] ?: 'Diğer';
            $rate = category_rate($cat, $marketplace);
            $price = $p['salePrice'] > 0 ? $p['salePrice'] : $p['listPrice'];

            // Tekil anahtar: barkod (Trendyol'da benzersiz). Barkod yoksa isim'e düş.
            $key = ($barcode !== '') ? ('b:' . $barcode) : ('n:' . $name);
            if (isset($existing[$key])) { $skipped++; continue; }
            $existing[$key] = true;

            $ins->execute([$userId, $name, $barcode, $marketplace, $cat, $rate, $price, (int)($p['quantity'] ?? 0)]);
            $inserted++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'error' => 'DB hatası: ' . $e->getMessage()];
    }

    // son senkron zamanını güncelle
    $db->prepare("UPDATE integrations SET last_sync = datetime('now') WHERE user_id = ? AND marketplace = ?")
        ->execute([$userId, $marketplace]);

    return ['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'total' => $res['count']];
}

/**
 * Entegrasyondan siparişleri çekip orders tablosuna aktarır (Canlı Performans için).
 * Trendyol orderDate milisaniye döner; saniyeye çevrilir. Çoğu pazaryerinde getOrders() yoksa null döner.
 */
function sync_orders(int $userId, string $marketplace, int $days = 7): array
{
    $int = get_integration($userId, $marketplace);
    if (!$int) return ['ok' => false, 'error' => 'Entegrasyon bulunamadı. Önce bağlantı kurun.'];
    $client = integration_client($int);
    if (!method_exists($client, 'getOrders')) {
        return ['ok' => false, 'error' => marketplace_label($marketplace) . ' için sipariş senkronu desteklenmiyor.'];
    }

    $start = date('Y-m-d', time() - $days * 86400);
    $res = $client->getOrders('', $start, '');
    if (!$res['ok']) return $res;

    $ins = db()->prepare("INSERT INTO orders (user_id, marketplace, order_number, package_number, status, customer_name, total_price, order_date, lines)
                          VALUES (?,?,?,?,?,?,?,?,?)
                          ON CONFLICT(user_id, marketplace, package_number) DO UPDATE SET
                            status = excluded.status, total_price = excluded.total_price, order_date = excluded.order_date, lines = excluded.lines");

    $count = 0;
    foreach ($res['orders'] as $o) {
        $ts = $o['orderDate'] ?? 0;
        if (is_string($ts)) $ts = strtotime($ts) ?: 0;
        $ts = (int)$ts;
        if ($ts > 100000000000) $ts = (int)($ts / 1000); // milisaniye -> saniye
        if ($ts <= 0) $ts = time();
        $ins->execute([
            $userId, $marketplace,
            (string)($o['orderNumber'] ?? ''),
            (string)($o['packageNumber'] ?? ''),
            (string)($o['status'] ?? ''),
            (string)($o['customerName'] ?? ''),
            (float)($o['totalPrice'] ?? 0),
            $ts,
            json_encode($o['lines'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        $count++;
    }
    db()->prepare("UPDATE integrations SET last_orders_sync = ? WHERE user_id = ? AND marketplace = ?")
        ->execute([time(), $userId, $marketplace]);
    return ['ok' => true, 'count' => $count];
}

/**
 * Siparişin tahmini net kârını hesaplar (Canlı Performans).
 * Satırları barkod ile kayıtlı ürünlerle eşleştirir; maliyet + komisyon oranı + kargo + hizmet + stopaj + Net KDV.
 * Maliyeti girilmemiş bir satır varsa ['ok'=>false,'missing'=>true] döner (Melontik: "kâr hesaplanamadı").
 */
function calc_order_profit(array $order, array $byBarcode): array
{
    $sale = (float)($order['total_price'] ?? 0);
    $lines = $order['lines'] ?? [];
    if (!is_array($lines) || !$lines || $sale <= 0) {
        return ['ok' => false, 'missing' => true, 'sale' => $sale, 'profit' => null];
    }

    $cost = 0.0;
    $cargo = 0.0;
    $weightedRate = 0.0;
    $saleSum = 0.0;
    $missing = false;
    $kdv = 20.0;

    foreach ($lines as $line) {
        $qty = max(1, (int)($line['quantity'] ?? 1));
        $lineSale = (float)($line['price'] ?? 0) * $qty;
        $saleSum += $lineSale;
        $p = $byBarcode[$line['barcode'] ?? ''] ?? null;
        if ($p && (float)$p['cost'] > 0) {
            $cost += (float)$p['cost'] * $qty;
            $cargo += (float)$p['cargo'];
            $weightedRate += $lineSale * (float)$p['rate'];
            if (isset($p['kdv'])) $kdv = (float)$p['kdv'];
        } else {
            $missing = true;
            $weightedRate += $lineSale * 20.0;
        }
    }

    if ($missing) {
        return ['ok' => false, 'missing' => true, 'sale' => $sale, 'profit' => null];
    }

    $rate = $saleSum > 0 ? $weightedRate / $saleSum : 20.0;
    $service = trendyol_service_fee();
    $stopajTl = auto_stopaj($sale, $kdv, 1.0);
    $r = calc_profit($cost, $sale, $cargo, $rate, $kdv, 0.0, $service, $stopajTl, 0.0);

    return [
        'ok' => true,
        'sale' => $sale,
        'cost' => $cost,
        'cargo' => $cargo,
        'profit' => $r['profit'],
        'margin' => $r['margin'],
        'ratio' => $r['ratio'],
        'commission' => $r['commission'],
        'service_fee' => $r['service_fee'],
        'stopaj' => $r['stopaj'],
        'net_kdv' => $r['net_kdv'],
        'extra_fee' => $r['extra_fee'],
        'return_loss' => $r['return_loss'],
    ];
}

/**
 * Cari Hesap Ekstresi'ni (settlements) çekip settlements tablosuna yazar.
 * Ayrıca Sale kayıtlarındaki GERÇEK komisyon oranını barkod eşleşmesiyle products.rate'e işler
 * (hardcoded category_rate tahminini resmi değerle düzeltir).
 * API tarih aralığı ≤15 gün olduğundan dilimlere bölünür.
 */
function sync_settlements(int $userId, string $marketplace, int $days = 30): array
{
    $int = get_integration($userId, $marketplace);
    if (!$int) return ['ok' => false, 'error' => 'Entegrasyon bulunamadı. Önce bağlantı kurun.'];
    $client = integration_client($int);
    if (!method_exists($client, 'getSettlements')) {
        return ['ok' => false, 'error' => marketplace_label($marketplace) . ' için cari hesap ekstresi desteklenmiyor.'];
    }

    $chunks = [];
    $end = time();
    $start = $end - max(1, $days) * 86400;
    for ($s = $start; $s < $end; $s += 15 * 86400) {
        $chunks[] = [date('Y-m-d', $s), date('Y-m-d', min($s + 15 * 86400, $end))];
    }

    $ins = db()->prepare("INSERT INTO settlements (user_id, marketplace, barcode, transaction_type, commission_rate, commission_amount, seller_revenue, debt, credit, order_number, shipment_package_id, payment_period, transaction_date, order_date)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $updRate = db()->prepare("UPDATE products SET rate = ? WHERE user_id = ? AND marketplace = ? AND barcode = ? AND barcode != ''");

    $total = 0;
    $ratesUpdated = 0;
    $errors = [];

    foreach (['Sale', 'DeliveryFee', 'Return', 'Discount', 'Coupon'] as $type) {
        foreach ($chunks as [$s, $e]) {
            $res = $client->getSettlements($type, $s, $e);
            if (!$res['ok']) {
                if (isset($res['error'])) $errors[] = $res['error'];
                continue;
            }
            foreach ($res['settlements'] as $st) {
                $td = (int)($st['transactionDate'] ?? 0);
                $od = (int)($st['orderDate'] ?? 0);
                if ($td > 100000000000) $td = (int)($td / 1000);
                if ($od > 100000000000) $od = (int)($od / 1000);
                $ins->execute([
                    $userId, $marketplace,
                    $st['barcode'], $st['transactionType'],
                    $st['commissionRate'], $st['commissionAmount'], $st['sellerRevenue'],
                    $st['debt'], $st['credit'],
                    $st['orderNumber'], $st['shipmentPackageId'],
                    $st['paymentPeriod'], $td, $od,
                ]);
                $total++;
                if ($type === 'Sale' && $st['barcode'] !== '' && $st['commissionRate'] > 0) {
                    $updRate->execute([$st['commissionRate'], $userId, $marketplace, $st['barcode']]);
                    $ratesUpdated++;
                }
            }
        }
    }

    return ['ok' => true, 'records' => $total, 'rates_updated' => $ratesUpdated, 'errors' => $errors];
}
