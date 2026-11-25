<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/profit.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/sync_coverage.php';
require_once __DIR__ . '/claims.php';
require_once __DIR__ . '/order_evidence.php';
require_once __DIR__ . '/cost_history.php';
require_once __DIR__ . '/profit_targets.php';
require_once __DIR__ . '/buybox_decision.php';
require_once __DIR__ . '/catalog_alerts.php';
require_once __DIR__ . '/cargo_audit.php';
require_once __DIR__ . '/payout_receipts.php';
require_once __DIR__ . '/catalog_cost_import.php';
require_once __DIR__ . '/period_finance.php';
require_once __DIR__ . '/return_inventory.php';
require_once __DIR__ . '/auto_sync.php';
require_once __DIR__ . '/auto_market_checks.php';
require_once __DIR__ . '/product_finance.php';
require_once __DIR__ . '/product_cost_evidence.php';
require_once __DIR__ . '/cargo_estimate.php';
require_once __DIR__ . '/commission_evidence.php';

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

/** All syncs and connection changes for one seller share a local nonblocking lock. */
function with_marketplace_lock(int $userId,string $marketplace,callable $fn): array
{
    $dir=dirname(DB_PATH).DIRECTORY_SEPARATOR.'locks';
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return ['ok'=>false,'error'=>'Senkron kilidi dizini oluşturulamadı.'];
    $path=$dir.DIRECTORY_SEPARATOR.hash('sha256',$userId.':'.$marketplace).'.lock';
    $fh=@fopen($path,'c');
    if(!$fh)return ['ok'=>false,'error'=>'Senkron kilidi açılamadı.'];
    if(!flock($fh,LOCK_EX|LOCK_NB)){fclose($fh);return ['ok'=>false,'error'=>'Bu mağazada başka bir işlem devam ediyor.'];}
    try{return $fn();}finally{flock($fh,LOCK_UN);fclose($fh);}
}

function save_integration(int $userId, string $marketplace, string $sellerId, string $apiKey, string $apiSecret, bool $stage = false): void
{
    $res=with_marketplace_lock($userId,$marketplace,function()use($userId,$marketplace,$sellerId,$apiKey,$apiSecret,$stage){
        $sellerId=trim($sellerId);$stageInt=(int)$stage;$db=db();
        $q=$db->prepare("SELECT seller_id,stage FROM integration_bindings WHERE user_id=? AND marketplace=?");
        $q->execute([$userId,$marketplace]);$binding=$q->fetch();
        if($binding&&($binding['seller_id']!==$sellerId||(int)$binding['stage']!==$stageInt))
            return ['ok'=>false,'error'=>'Bu hesaba farklı mağaza veya test/üretim ortamı bağlanamaz. Ayrı hesap kullanın.'];
        if(!$binding){
            foreach(['orders','settlements'] as $table){
                $q=$db->prepare("SELECT 1 FROM $table WHERE user_id=? AND marketplace=? LIMIT 1");
                $q->execute([$userId,$marketplace]);
                if($q->fetchColumn())return ['ok'=>false,'error'=>'Eski mağaza verilerinin sahibi doğrulanamadı. Destek ile görüşün.'];
            }
            $q=$db->prepare("SELECT 1 FROM products WHERE user_id=? AND marketplace=? AND barcode!='' LIMIT 1");
            $q->execute([$userId,$marketplace]);
            if($q->fetchColumn())return ['ok'=>false,'error'=>'Barkodlu eski ürünler mevcut. Mağaza verisi karışmaması için destek ile görüşün.'];
        }
        $encKey=encrypt_string($apiKey);$encSecret=encrypt_string($apiSecret);
        $db->beginTransaction();
        try {
            $db->prepare("INSERT OR IGNORE INTO integration_bindings(user_id,marketplace,seller_id,stage) VALUES(?,?,?,?)")
                ->execute([$userId,$marketplace,$sellerId,$stageInt]);
            $db->prepare("INSERT INTO integrations(user_id,marketplace,seller_id,api_key,api_secret,stage) VALUES(?,?,?,?,?,?)
                ON CONFLICT(user_id,marketplace) DO UPDATE SET api_key=excluded.api_key,api_secret=excluded.api_secret")
                ->execute([$userId,$marketplace,$sellerId,$encKey,$encSecret,$stageInt]);
            $db->commit();
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        return ['ok'=>true];
    });
    if(!$res['ok'])throw new RuntimeException($res['error']);
}

function delete_integration(int $userId,string $marketplace): void
{
    $r=with_marketplace_lock($userId,$marketplace,function()use($userId,$marketplace){
        db()->prepare("DELETE FROM integrations WHERE user_id=? AND marketplace=?")->execute([$userId,$marketplace]);
        return ['ok'=>true];
    });
    if(!$r['ok'])throw new RuntimeException($r['error']);
}

/** Record success only after a complete job. Failure never advances the success timestamp. */
function run_sync_job(int $userId,string $marketplace,string $job,callable $fn): array
{
    return with_marketplace_lock($userId,$marketplace,function()use($userId,$marketplace,$job,$fn){
        $db=db();$now=time();
        $db->prepare("INSERT INTO sync_status(user_id,marketplace,job,last_attempt,state) VALUES(?,?,?,?,'running')
            ON CONFLICT(user_id,marketplace,job) DO UPDATE SET last_attempt=excluded.last_attempt,state='running'")
            ->execute([$userId,$marketplace,$job,$now]);
        try{$r=$fn();}catch(Throwable $e){$r=['ok'=>false,'error'=>'Senkron tamamlanamadı; sunucu yapılandırmasını kontrol edin.'];}
        if(!empty($r['ok']))$db->prepare("UPDATE sync_status SET state='success',last_success=? WHERE user_id=? AND marketplace=? AND job=?")->execute([time(),$userId,$marketplace,$job]);
        else $db->prepare("UPDATE sync_status SET state='failed' WHERE user_id=? AND marketplace=? AND job=?")->execute([$userId,$marketplace,$job]);
        return $r;
    });
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
    return run_sync_job($userId,$marketplace,'products',static fn()=>sync_products_unlocked($userId,$marketplace));
}

function sync_products_unlocked(int $userId, string $marketplace): array
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
    // V2 geçişinde eski checkpoint ve stok/fiyat değişikliklerinin kaçmaması için tam tarama.
    if ($marketplace !== 'trendyol' && !empty($int['last_sync'])) {
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
    $updated = 0;

    // Mevcut barkodları TEK sorguda al -> HashSet (O(1) arama, 10k+ ürün için hızlı)
    $existing = [];
    $stmt = $db->prepare("SELECT id, barcode, name FROM products WHERE user_id = ? AND marketplace = ?");
    $stmt->execute([$userId, $marketplace]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['barcode'] !== '') $existing['b:' . $r['barcode']] = (int)$r['id'];
        $existing['n:' . $r['name']] = (int)$r['id'];
    }

    $ins = $db->prepare("INSERT INTO products (user_id, name, barcode, marketplace, category, rate, cost, price, quantity, cargo, service_fee, stopaj, return_rate, kdv)
                          VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, 0, 0, ?)");

    // Only refresh marketplace fields; never overwrite user costs, cargo or commission settings.
    $upd = $db->prepare("UPDATE products SET price = COALESCE(?, price), quantity = COALESCE(?, quantity), cost_vat = COALESCE(cost_vat, kdv), kdv = COALESCE(?, kdv)
                          WHERE id = ? AND user_id = ? AND marketplace = ?");
    $observation=$db->prepare("UPDATE products SET stock_observed_at=?,sale_price_observed_at=? WHERE id=? AND user_id=? AND marketplace='trendyol'");
    $now=time();
    $db->beginTransaction();
    try {
        foreach ($res['products'] as $p) {
            $barcode = (string)($p['barcode'] ?? '');
            $name = $p['title'] ?: ($barcode ?: 'Ürün');
            $cat = $p['category'] ?: 'Diğer';
            $rate = isset($p['commission']) && is_numeric($p['commission']) && $p['commission']>=0 && $p['commission']<=100
                ? (float)$p['commission'] : category_rate($cat, $marketplace);
            $price = ($p['salePrice'] ?? 0) > 0 ? (float)$p['salePrice'] : (isset($p['listPrice']) && $p['listPrice'] > 0 ? (float)$p['listPrice'] : null);
            $quantity = isset($p['quantity']) && is_numeric($p['quantity']) && (float)$p['quantity']>=0 && floor((float)$p['quantity'])===(float)$p['quantity'] ? (int)$p['quantity'] : null;
            $stockAt=$quantity===null?null:$now;
            $saleAt=isset($p['salePrice'])&&is_numeric($p['salePrice'])&&is_finite((float)$p['salePrice'])&&(float)$p['salePrice']>0?$now:null;
            if($marketplace==='trendyol'&&$saleAt===null)$price=null;
            $vat = isset($p['vatRate']) && is_numeric($p['vatRate']) && $p['vatRate'] >= 0 && $p['vatRate'] <= 100 ? (float)$p['vatRate'] : null;

            // Tekil anahtar: barkod (Trendyol'da benzersiz). Barkod yoksa isim'e düş.
            $key = ($barcode !== '') ? ('b:' . $barcode) : ('n:' . $name);
            if (isset($existing[$key])) {
                if($marketplace==='trendyol')$observation->execute([$stockAt,$saleAt,$existing[$key],$userId]);
                if ($barcode !== '' && ($price !== null || $quantity !== null || $vat !== null)) {
                    $upd->execute([$price, $quantity, $vat, $existing[$key], $userId, $marketplace]);
                    $updated++;
                } else $skipped++;
                continue;
            }

            $ins->execute([$userId, $name, $barcode, $marketplace, $cat, $rate, $price ?? 0.0, $quantity ?? 0, $marketplace === 'trendyol' ? trendyol_service_fee() : 0.0, $vat ?? 20.0]);
            $existing[$key] = (int)$db->lastInsertId();
            if($marketplace==='trendyol')$observation->execute([$stockAt,$saleAt,$existing[$key],$userId]);
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

    return ['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'total' => $res['count']];
}

/**
 * Entegrasyondan siparişleri çekip orders tablosuna aktarır (Canlı Performans için).
 * Trendyol orderDate milisaniye döner; saniyeye çevrilir. Çoğu pazaryerinde getOrders() yoksa null döner.
 */
function sync_orders(int $userId, string $marketplace, int $days = 7): array
{
    return run_sync_job($userId,$marketplace,'orders',static fn()=>sync_orders_unlocked($userId,$marketplace,$days));
}

function sync_orders_unlocked(int $userId, string $marketplace, int $days = 7): array
{
    $int = get_integration($userId, $marketplace);
    if (!$int) return ['ok' => false, 'error' => 'Entegrasyon bulunamadı. Önce bağlantı kurun.'];
    $client = integration_client($int);
    if (!method_exists($client, 'getOrders')) {
        return ['ok' => false, 'error' => marketplace_label($marketplace) . ' için sipariş senkronu desteklenmiyor.'];
    }

    $started=time()-1; // query only completed seconds
    $windowStart=$started-max(1,min(89,$days))*86400;
    if($marketplace==='trendyol'){
        try{[$windowStart,$started]=sync_order_window(sync_read_ranges(db(),$userId,$marketplace,'orders_modified'),$started,$days);}
        catch(RuntimeException $e){return ['ok'=>false,'error'=>$e->getMessage()];}
    }
    $res = $client->getOrders('', date('c',$windowStart), date('c',$started));
    if (!$res['ok']) return $res;

    foreach ($res['orders'] as $o) {
        $rawDate = $o['orderDate'] ?? null;
        if (!is_numeric($rawDate) || (float)$rawDate <= 0 || trim((string)($o['packageNumber'] ?? '')) === '') {
            return ['ok'=>false,'error'=>'Sipariş kimliği veya tarihi eksik; veri kaydedilmedi.'];
        }
    }
    $ins = db()->prepare("INSERT INTO orders (user_id, marketplace, order_number, package_number, status, customer_name, total_price, order_date, lines, currency_code, country_code, last_modified_ms,cargo_estimate,cargo_estimate_json,cargo_provider,cargo_first_estimate,cargo_first_estimate_json,cargo_api_desi,cargo_quote_context)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                          ON CONFLICT(user_id, marketplace, package_number) DO UPDATE SET
                            status = excluded.status, total_price = excluded.total_price, order_date = excluded.order_date, lines = excluded.lines,
                            currency_code=excluded.currency_code,country_code=excluded.country_code,last_modified_ms=excluded.last_modified_ms,
                            cargo_first_estimate=COALESCE(orders.cargo_first_estimate,excluded.cargo_first_estimate),
                            cargo_first_estimate_json=COALESCE(orders.cargo_first_estimate_json,excluded.cargo_first_estimate_json),
                            cargo_estimate=excluded.cargo_estimate,cargo_estimate_json=excluded.cargo_estimate_json,cargo_provider=excluded.cargo_provider,cargo_api_desi=excluded.cargo_api_desi,cargo_quote_context=excluded.cargo_quote_context
                          WHERE excluded.last_modified_ms >= orders.last_modified_ms");

    $count = 0;
    $db = db();
    $products = $db->prepare("SELECT barcode,MAX(cost) AS cost,MAX(COALESCE(cost_vat,kdv)) AS purchase_vat FROM products WHERE user_id=? AND marketplace=? AND barcode!='' GROUP BY barcode HAVING COUNT(*)=1 AND MAX(cost)>0");
    $products->execute([$userId,$marketplace]);
    $costs=[];foreach($products->fetchAll() as $row) $costs[$row['barcode']]=$row;
    $tariffs=$marketplace==='trendyol'?cargo_tariffs():[];$cargoProducts=[];
    if($marketplace==='trendyol'){$q=$db->prepare("SELECT barcode,MAX(expected_desi) AS expected_desi,MAX(en) AS en,MAX(boy) AS boy,MAX(yukseklik) AS yukseklik FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode!='' GROUP BY barcode HAVING COUNT(*)=1");$q->execute([$userId]);while($v=$q->fetch())$cargoProducts[$v['barcode']]=$v;}
    $snapIns=$db->prepare("INSERT OR IGNORE INTO order_cost_snapshots(user_id,marketplace,package_number,line_key,barcode,cost,cost_vat,observed_at) VALUES(?,?,?,?,?,?,?,?)");
    $snapGet=$db->prepare("SELECT barcode,cost,cost_vat,observed_at FROM order_cost_snapshots WHERE user_id=? AND marketplace=? AND package_number=? AND line_key=?");
    $db->beginTransaction();
    try {
    foreach ($res['orders'] as $o) {
        $ts = $o['orderDate'] ?? 0;
        if (is_string($ts) && !is_numeric($ts)) $ts = strtotime($ts) ?: 0;
        $ts = (int)$ts;
        if ($ts > 100000000000) $ts = (int)($ts / 1000); // milisaniye -> saniye
        if ($ts <= 0) throw new RuntimeException('Geçersiz sipariş tarihi.');
        $lines=$o['lines']??[];
        foreach ($lines as &$line) {
            $key=(string)($line['line_id']??'');
            $barcode=(string)($line['barcode']??'');
            if ($key==='' || $barcode==='') continue;
            $c=$costs[$barcode]??null;
            $args=[$userId,$marketplace,(string)$o['packageNumber'],$key];
            if ($c) $snapIns->execute(array_merge($args,[$barcode,(float)$c['cost'],(float)$c['purchase_vat'],time()]));
            $snapGet->execute($args);$snap=$snapGet->fetch();
            if($snap && $snap['barcode']===$barcode) {
                $line['cost_snapshot']=(float)$snap['cost'];
                $line['cost_vat_snapshot']=(float)$snap['cost_vat'];
                $line['cost_observed_at']=(int)$snap['observed_at'];
            }
        }
        unset($line);
        $context=$marketplace==='trendyol'?cargo_quote_context($o,$cargoProducts):null;
        $quote=$context!==null?cargo_quote_from_context($context,$tariffs):null;
        $quoteJson=$quote?json_encode($quote,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):null;
        $initial=$quote&&!cargo_has_shipped($o)?$quote['amount']:null;
        $ins->execute([
            $userId, $marketplace,
            (string)($o['orderNumber'] ?? ''),
            (string)($o['packageNumber'] ?? ''),
            (string)($o['status'] ?? ''),
            '', // Financial analytics do not require customer names.
            (float)($o['totalPrice'] ?? 0),
            $ts,
            json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            isset($o['currencyCode']) ? strtoupper((string)$o['currencyCode']) : null,
            isset($o['orderCountryCode']) ? strtoupper((string)$o['orderCountryCode']) : null,
            (int)($o['lastModifiedDate'] ?? 0),
            $quote['amount']??null,$quoteJson,(string)($o['cargoCompany']??''),$initial,$initial!==null?$quoteJson:null,cargo_positive_desi($o['cargoDeci']??null),$context?json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):null,
        ]);
        $count++;
    }
    if($marketplace==='trendyol')sync_record_range($db,$userId,$marketplace,'orders_modified',$windowStart,$started+1);
    $db->prepare("UPDATE integrations SET last_orders_sync = ? WHERE user_id = ? AND marketplace = ?")
        ->execute([time(), $userId, $marketplace]);
    $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok'=>false,'error'=>'Sipariş verileri kaydedilemedi.'];
    }
    return ['ok' => true, 'count' => $count];
}

/**
 * Siparişin tahmini net kârını hesaplar (Canlı Performans).
 * Satırları barkod ile kayıtlı ürünlerle eşleştirir; maliyet + komisyon oranı + kargo + hizmet + stopaj + Net KDV.
 * Maliyeti girilmemiş bir satır varsa ['ok'=>false,'missing'=>true] döner (Melontik: "kâr hesaplanamadı").
 */



/**
 * Cari Hesap Ekstresi'ni (settlements) çekip settlements tablosuna yazar.
 * Ayrıca Sale kayıtlarındaki GERÇEK komisyon oranını barkod eşleşmesiyle products.rate'e işler
 * (hardcoded category_rate tahminini resmi değerle düzeltir).
 * API tarih aralığı ≤15 gün olduğundan dilimlere bölünür.
 */
function sync_settlements(int $userId, string $marketplace, int $days = 30): array
{
    return run_sync_job($userId,$marketplace,'settlements',static fn()=>sync_settlements_unlocked($userId,$marketplace,$days));
}

function sync_settlements_unlocked(int $userId, string $marketplace, int $days = 30): array
{
    $int = get_integration($userId, $marketplace);
    if (!$int) return ['ok' => false, 'error' => 'Entegrasyon bulunamadı. Önce bağlantı kurun.'];
    $client = integration_client($int);
    if (!method_exists($client, 'getSettlements')) {
        return ['ok' => false, 'error' => marketplace_label($marketplace) . ' için cari hesap ekstresi desteklenmiyor.'];
    }

    if ($marketplace !== 'trendyol') return ['ok' => false, 'error' => 'Finansal senkron yalnızca Trendyol için desteklenir.'];
    $end = time()-1;
    $start = $end - max(1,min(90,$days)) * 86400+1;
    $types = ['Sale','Return','Discount','DiscountCancel','Coupon','CouponCancel',
        'ProvisionPositive','ProvisionNegative','ManualRefund','ManualRefundCancel',
        'TyDiscount','TyDiscountCancel','TyCoupon','TyCouponCancel',
        'SellerRevenuePositive','SellerRevenueNegative','CommissionPositive','CommissionNegative',
        'SellerRevenuePositiveCancel','SellerRevenueNegativeCancel','CommissionPositiveCancel',
        'CommissionNegativeCancel','DeliveryFee','DeliveryFeeCancel','PayByLink'];

    // Each time slice is complete before any writes for that slice.
    $db = db();
    $ins = $db->prepare("INSERT INTO settlements
        (user_id, marketplace, external_id, query_type, payment_order_id, barcode, transaction_type,
         commission_rate, commission_amount, seller_revenue, debt, credit, order_number,
         shipment_package_id, payment_period, transaction_date, order_date,raw_json,finance_scope)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON CONFLICT(user_id, marketplace, external_id) DO UPDATE SET
           query_type=excluded.query_type, payment_order_id=excluded.payment_order_id,
           barcode=excluded.barcode, transaction_type=excluded.transaction_type,
           commission_rate=excluded.commission_rate, commission_amount=excluded.commission_amount,
           seller_revenue=excluded.seller_revenue, debt=excluded.debt, credit=excluded.credit,
           order_number=excluded.order_number, shipment_package_id=excluded.shipment_package_id,
           payment_period=excluded.payment_period, transaction_date=excluded.transaction_date,
           order_date=excluded.order_date,raw_json=excluded.raw_json,finance_scope=excluded.finance_scope");
    $total = 0;
    foreach ($types as $type) {
        for ($s = $start; $s <= $end; $s += 14 * 86400) {
            $e = min($s + 14 * 86400 - 1, $end);
            $res = $client->getSettlements($type, date('c', $s), date('c', $e));
            if (!$res['ok']) {
                return ['ok' => false, 'records' => $total, 'error' => "$type: " . ($res['error'] ?? 'Eksik finansal senkron')];
            }
            $db->beginTransaction();
            try {
                foreach ($res['settlements'] as $st) {
                    $td = (int)($st['transactionDate'] ?? 0);
                    $od = (int)($st['orderDate'] ?? 0);
                    if ($td > 100000000000) $td = intdiv($td, 1000);
                    if ($od > 100000000000) $od = intdiv($od, 1000);
                    $ins->execute([$userId, $marketplace, $st['externalId'], $st['queryType'],
                        $st['paymentOrderId'], $st['barcode'], $st['transactionType'],
                        $st['commissionRate'], $st['commissionAmount'], $st['sellerRevenue'],
                        $st['debt'], $st['credit'], $st['orderNumber'], $st['shipmentPackageId'],
                        $st['paymentPeriod'], $td, $od,json_encode($st['raw'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),finance_is_domestic($st['raw'])?'domestic':'unverified']);
                    $total++;
                }
                $db->commit();
            } catch (Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                return ['ok' => false, 'records' => $total, 'error' => 'Finansal kayıtlar veritabanına yazılamadı.'];
            }
        }
    }
    // Record coverage only once every requested type and slice has succeeded.
    $db->beginTransaction();
    try{sync_record_range($db,$userId,$marketplace,'settlements_transaction',$start,$end+1);$db->commit();}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();return ['ok'=>false,'error'=>'Senkron kapsamı kaydedilemedi.'];}
    return ['ok' => true, 'records' => $total, 'rates_updated' => 0, 'errors' => [],'range_start'=>$start,'range_end'=>$end+1];
}

/** Separate otherfinancials and invoice data from settlement sales to avoid double counting. */
function sync_trendyol_documents(int $userId, int $days = 30): array
{
    return run_sync_job($userId,'trendyol','documents',static fn()=>sync_trendyol_documents_unlocked($userId,$days));
}

function sync_trendyol_documents_unlocked(int $userId, int $days = 30): array
{
    $int = get_integration($userId, 'trendyol');
    if (!$int) return ['ok'=>false,'error'=>'Trendyol mağazası bağlı değil.'];
    $client = integration_client($int);
    $db = db();
    $up = $db->prepare("INSERT INTO trendyol_financial_documents
        (user_id,external_id,query_type,transaction_type,payment_order_id,transaction_date,raw_json)
        VALUES(?,?,?,?,?,?,?) ON CONFLICT(user_id,external_id) DO UPDATE SET
        query_type=excluded.query_type,transaction_type=excluded.transaction_type,
        payment_order_id=excluded.payment_order_id,transaction_date=excluded.transaction_date,raw_json=excluded.raw_json,service_fee_verified=NULL");
    $end = time()-1;
    $start = $end - max(1,min(90,$days))*86400+1;
    $count = 0; $invoices = [];
    foreach (['Stoppage','CashAdvance','WireTransfer','IncomingTransfer','ReturnInvoice',
        'CommissionAgreementInvoice','PaymentOrder','DeductionInvoices','FinancialItem'] as $type) {
        for ($s=$start;$s<=$end;$s+=14*86400) {
            $e=min($s+14*86400-1,$end);
            $res=$client->getSettlements($type,date('c',$s),date('c',$e),2000,'otherfinancials');
            if (!$res['ok']) return ['ok'=>false,'error'=>$type.': '.($res['error']??'Eksik finansal veri')];
            $serviceIds=[];
            if($type==='DeductionInvoices'){
                $filtered=$client->getSettlements($type,date('c',$s),date('c',$e),2000,'otherfinancials',null,'PlatformServiceFee');
                if(!$filtered['ok'])return ['ok'=>false,'error'=>'Platform hizmet bedeli: '.($filtered['error']??'Eksik veri')];
                try{$serviceIds=service_fee_ids($res['settlements'],$filtered['settlements'],(string)$int['seller_id']);}
                catch(InvalidArgumentException $ex){return ['ok'=>false,'error'=>$ex->getMessage()];}
            }
            $db->beginTransaction();
            try {
                foreach ($res['settlements'] as $row) {
                    $ts=(int)$row['transactionDate']; if($ts>100000000000)$ts=intdiv($ts,1000);
                    $up->execute([$userId,$row['externalId'],$type,$row['transactionType'],$row['paymentOrderId'],$ts,
                        json_encode($row['raw'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
                    if($type==='DeductionInvoices')$db->prepare("UPDATE trendyol_financial_documents SET service_fee_verified=? WHERE user_id=? AND external_id=?")->execute([isset($serviceIds[$row['externalId']])?1:0,$userId,$row['externalId']]);
                    if ($type==='DeductionInvoices' && in_array($row['transactionType'],['Kargo Faturası','Kargo Fatura'],true))
                        $invoices[$row['externalId']]=true;
                    $count++;
                }
                if($type==='DeductionInvoices')sync_record_range($db,$userId,'trendyol','service_fee_transaction',$s,$e+1);
                $db->commit();
            } catch (Throwable $ex) {
                if($db->inTransaction())$db->rollBack();
                return ['ok'=>false,'error'=>'Finansal belge kaydedilemedi.'];
            }
        }
    }
    $cargoCount=0;
    foreach (array_keys($invoices) as $serial) {
        $serial=(string)$serial;
        $res=$client->getCargoInvoiceItems($serial);
        if(!$res['ok'])return ['ok'=>false,'error'=>'Kargo faturası '.$serial.': '.$res['error']];
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM trendyol_cargo_items WHERE user_id=? AND invoice_serial=?")->execute([$userId,$serial]);
            $ins=$db->prepare("INSERT INTO trendyol_cargo_items
                (user_id,invoice_serial,row_number,parcel_id,order_number,shipment_type,amount,desi) VALUES(?,?,?,?,?,?,?,?)");
            foreach($res['items'] as $i=>$row) {
                $ins->execute([$userId,$serial,$i,(string)$row['parcelUniqueId'],(string)$row['orderNumber'],
                    (string)$row['shipmentPackageType'],(float)$row['amount'],(float)$row['desi']]);
                $cargoCount++;
            }
            $db->commit();
        } catch(Throwable $ex) {
            if($db->inTransaction())$db->rollBack();
            return ['ok'=>false,'error'=>'Kargo faturası kaydedilemedi.'];
        }
    }
    $db->beginTransaction();
    try{sync_record_range($db,$userId,'trendyol','documents_transaction',$start,$end+1);$db->commit();}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();return ['ok'=>false,'error'=>'Belge sorgu kapsamı kaydedilemedi.'];}
    return ['ok'=>true,'documents'=>$count,'invoices'=>count($invoices),'cargo_items'=>$cargoCount,'range_start'=>$start,'range_end'=>$end+1];
}

/** Fetch one payout page and persist it under the connected seller. */
function sync_trendyol_payout_page(int $userId,int $page=0): array
{
    return run_sync_job($userId,'trendyol','payout_list',static fn()=>sync_trendyol_payout_page_unlocked($userId,$page));
}

function sync_trendyol_payout_page_unlocked(int $userId,int $page=0): array
{
    $int=get_integration($userId,'trendyol');
    if(!$int)return ['ok'=>false,'error'=>'Trendyol mağazası bağlı değil.'];
    $res=integration_client($int)->getPaymentOrders($page);
    if(!$res['ok'])return $res;
    $st=db()->prepare("INSERT INTO trendyol_payouts(user_id,seller_id,payment_order_id,payout_date,amount,raw_json)
        VALUES(?,?,?,?,?,?) ON CONFLICT(user_id,seller_id,payment_order_id) DO UPDATE SET
        payout_date=excluded.payout_date,amount=excluded.amount,raw_json=excluded.raw_json");
    foreach($res['items'] as $row) $st->execute([$userId,$int['seller_id'],(string)$row['id'],intdiv((int)$row['payoutDate'],1000),
        (float)$row['amount'],json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    return ['ok'=>true,'count'=>count($res['items']),'totalPages'=>$res['totalPages']];
}
function check_trendyol_payout(int $userId,string $id): array
{
    return run_sync_job($userId,'trendyol','payout_detail',static fn()=>check_trendyol_payout_unlocked($userId,$id));
}

function check_trendyol_payout_unlocked(int $userId,string $id): array
{
    $int=get_integration($userId,'trendyol');
    if(!$int)return ['ok'=>false,'error'=>'Trendyol mağazası bağlı değil.'];
    $q=db()->prepare("SELECT * FROM trendyol_payouts WHERE user_id=? AND seller_id=? AND payment_order_id=?");
    $q->execute([$userId,$int['seller_id'],$id]);$payout=$q->fetch();
    if(!$payout)return ['ok'=>false,'error'=>'Ödeme emri bulunamadı.'];
    if (date('Y-m-d',(int)$payout['payout_date'])>=date('Y-m-d'))
        return ['ok'=>false,'error'=>'Trendyol finans kayıtlarının güncellenmesi için ödeme gününün geçmesi gerekir.'];
    $client=integration_client($int);$rows=['settlements'=>[],'otherfinancials'=>[]];
    $types=['settlements'=>['Sale','Return','Discount','DiscountCancel','Coupon','CouponCancel','ProvisionPositive','ProvisionNegative',
        'ManualRefund','ManualRefundCancel','TyDiscount','TyDiscountCancel','TyCoupon','TyCouponCancel','SellerRevenuePositive','SellerRevenueNegative',
        'CommissionPositive','CommissionNegative','SellerRevenuePositiveCancel','SellerRevenueNegativeCancel','CommissionPositiveCancel',
        'CommissionNegativeCancel','DeliveryFee','DeliveryFeeCancel','PayByLink'],
        'otherfinancials'=>['Stoppage','CashAdvance','WireTransfer','IncomingTransfer','ReturnInvoice','CommissionAgreementInvoice','PaymentOrder','DeductionInvoices','FinancialItem']];
    foreach($types as $source=>$list) foreach($list as $type) {
        $res=$client->getSettlements($type,'','',2000,$source,$id);
        if(!$res['ok'])return ['ok'=>false,'error'=>$type.': '.($res['error']??'Veri eksik')];
        foreach($res['settlements'] as $r) $rows[$source][]=$r;
        usleep(650000);
    }
    try {$summary=payout_components($rows['settlements'],$rows['otherfinancials'],$id);
        $summary['candidate_check']=finance_payout_candidate($rows['settlements'],$rows['otherfinancials'],$id,(float)$payout['amount']);}
    catch(InvalidArgumentException $e){return ['ok'=>false,'error'=>$e->getMessage()];}
    db()->prepare("UPDATE trendyol_payouts SET summary_json=?,checked_at=? WHERE user_id=? AND seller_id=? AND payment_order_id=?")
        ->execute([json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time(),$userId,$int['seller_id'],$id]);
    return ['ok'=>true,'summary'=>$summary];
}

/** Full read-only slice imports; no automatic acceptance or refund. */
function sync_trendyol_claims(int $uid,int $days=30): array
{
    return run_sync_job($uid,'trendyol','claims',static function()use($uid,$days){
        $int=get_integration($uid,'trendyol');
        if(!$int)return ['ok'=>false,'error'=>'Trendyol mağazası bağlı değil.'];
        $client=integration_client($int);$db=db();$end=time()-1;
        $start=$end-max(1,min(89,$days))*86400+1;
        $ranges=sync_read_ranges($db,$uid,'trendyol','claims_query');
        if($ranges){
            $last=max(array_column($ranges,1));
            if($last<$end-89*86400)return ['ok'=>false,'error'=>'İade sorgu boşluğu uygulama sınırını aşıyor; destek kontrolü gerekli.'];
            $start=min($start,max($end-89*86400,$last-300));
        }
        $count=0;
        for($a=$start;$a<=$end;$a+=14*86400){
            $b=min($a+14*86400-1,$end);
            $res=$client->getClaims($a,$b);
            if(!$res['ok'])return $res;
            $db->beginTransaction();
            try{
                $count+=persist_claims($db,$uid,$res['items']);
                sync_record_range($db,$uid,'trendyol','claims_query',$a,$b+1);
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return ['ok'=>false,'error'=>'İade kayıtları yazılamadı.'];}
        }
        return ['ok'=>true,'count'=>$count];
    });
}
