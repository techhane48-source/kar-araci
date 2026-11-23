<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/billing.php';

// PHP 8.4+ için CSV kaçış biçimi açıkça seçilir; okuyucularla aynı davranış korunur.
function write_csv($stream, array $fields, string $separator = ','): int|false
{
    return fputcsv($stream, $fields, $separator, '"', '\\');
}

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

require_once __DIR__ . '/profit.php';

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
    $q=db()->prepare('SELECT is_demo FROM users WHERE id=?');$q->execute([$userId]);
    if($q->fetchColumn())throw new RuntimeException('Demo hesabı gerçek mağaza bağlantısı kuramaz.');
    $encKey = encrypt_string($apiKey);
    $encSecret = encrypt_string($apiSecret);
    db()->prepare("INSERT INTO integrations (user_id, marketplace, seller_id, api_key, api_secret, stage)
                   VALUES (?, ?, ?, ?, ?, ?)
                   ON CONFLICT(user_id, marketplace) DO UPDATE SET
                     seller_id = excluded.seller_id,
                     api_key = excluded.api_key,
                     api_secret = excluded.api_secret,
                     stage = excluded.stage,
                     revision = integrations.revision + 1,
                     last_sync = NULL, last_orders_sync = NULL, last_settlements_sync = NULL, orders_coverage_from = NULL,
                     last_sync_error = NULL")
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

    // Test ortamında sağlayıcı yanıtlarını taklit etmek için (üretimde env tanımsız → canlı istemci).
    $transport = null;
    $tf = getenv('KARFIYAT_TEST_TRANSPORT');
    if (is_string($tf) && $tf !== '' && is_file($tf)) {
        $t = require $tf;
        if (is_callable($t)) $transport = $t;
    }

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
            return new TrendyolClient($sellerId, $apiKey, $apiSecret, $stage, $transport);
    }
}

require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/inventory.php';
