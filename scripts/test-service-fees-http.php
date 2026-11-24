<?php
declare(strict_types=1);

// Uçtan uca HTTP testi: izole DB + geçici sunucu + örnek sağlayıcı yanıtları.
// Normal senkron girişinden (magaza.php action=settlements) gerçek HTML çıktısına kadar
// platform hizmet bedeli akışını doğrular. Canlı mağaza/API çağrısı YAPMAZ.

$dir = sys_get_temp_dir() . '/kf-psf-http-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
putenv('KARFIYAT_DB=' . $dir . '/app.sqlite');

$transportFile = $dir . '/transport.php';
file_put_contents($transportFile, <<<'PHP'
<?php
return static function (string $method, string $path, array $query, ?string $body): array {
    if (str_ends_with($path, '/otherfinancials')) {
        if (is_file(__DIR__ . '/fail')) {
            // Eksik sayfa: content boş ama totalElements>0 → financeRows eksik sayar, senkron başarısız olur
            return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 1, 'totalElements' => 1]];
        }
        if (is_file(__DIR__ . '/empty')) {
            // Eksiksiz boş sonuç
            return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 0, 'totalElements' => 0]];
        }
        if (($query['transactionType'] ?? '') === 'DeductionInvoices' && ($query['transactionSubType'] ?? '') === 'PlatformServiceFee') {
            $rows = [
                ['id' => 'PSF-1001', 'transactionType' => 'DeductionInvoices', 'transactionDate' => 1700000000000,
                 'receiptId' => 9001, 'commissionInvoiceSerialNumber' => 'KOM-2024-001', 'description' => 'Platform hizmet bedeli',
                 'debt' => 13.19, 'credit' => 0, 'orderNumber' => 'ORDER-1', 'shipmentPackageId' => 77001, 'barcode' => 'BC-1'],
                ['id' => 'PSF-1002', 'transactionType' => 'DeductionInvoices', 'transactionDate' => 1700000000000,
                 'debt' => 0, 'credit' => 0],
            ];
            return ['http' => 200, 'body' => ['content' => $rows, 'totalPages' => 1, 'totalElements' => 2]];
        }
        return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 0, 'totalElements' => 0]];
    }
    return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 0, 'totalElements' => 0]];
};
PHP);
putenv('KARFIYAT_TEST_TRANSPORT=' . $transportFile);

require_once __DIR__ . '/../includes/functions.php';

$failed = 0;
function check(bool $ok, string $label): void { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $failed++; }

db()->prepare("INSERT INTO users(email, password_hash, plan, plan_expires) VALUES(?, ?, 'pro', '2050-01-01')")
    ->execute(['pro-psf@example.test', password_hash('Test-Password-2026!', PASSWORD_DEFAULT)]);
$uid = (int)db()->lastInsertId();
save_integration($uid, 'trendyol', '42', 'fake-key', 'fake-secret');
db()->prepare("INSERT INTO products(user_id,name,barcode,marketplace,category,rate,cost,price,cargo,kdv,service_fee,stopaj,stopaj_mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'manual')")
    ->execute([$uid, 'Ürün', 'BC-1', 'trendyol', 'Giyim', 20, 100, 200, 10, 20, 13.19, 1]);

$port = 8092;
$base = "http://127.0.0.1:$port";
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../public_html'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) { echo "FAIL sunucu başlatılamadı\n"; exit(1); }

$ready = false;
for ($i = 0; $i < 50; $i++) {
    $c = @curl_init($base . '/index.php');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
    if (@curl_exec($c) !== false && curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200) { $ready = true; curl_close($c); break; }
    curl_close($c);
    usleep(100000);
}

try {
    check($ready, 'Yerel sunucu hazır');

    $jar = tempnam(sys_get_temp_dir(), 'kf-psf-cookie-');
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);

    function req($ch, string $base, string $page, ?array $data = null): array {
        curl_setopt($ch, CURLOPT_URL, $base . '/' . $page);
        curl_setopt($ch, CURLOPT_POST, $data !== null);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data === null ? null : http_build_query($data));
        $body = curl_exec($ch);
        return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body === false ? '' : $body];
    }
    function csrf(string $html): string { preg_match('/name="csrf" value="([^"]+)"/', $html, $m); return $m[1] ?? ''; }

    // Giriş
    $r = req($ch, $base, 'giris.php');
    $r = req($ch, $base, 'giris.php', ['csrf' => csrf($r['body']), 'email' => 'pro-psf@example.test', 'password' => 'Test-Password-2026!']);
    check($r['status'] === 302, 'Pro kullanıcı giriş yapar');

    // Senkron öncesi: henüz çekilmedi
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    check(str_contains($r['body'], 'Henüz platform hizmet bedeli çekilmedi'), 'HTML: senkron öncesi "henüz çekilmedi" görünür');
    check(str_contains($r['body'], 'Son başarılı çekim') && str_contains($r['body'], 'Henüz çekilmedi'), 'HTML: son başarılı çekim "Henüz çekilmedi"');

    check(str_contains($r['body'], 'Finans Kayıtlarını Çek') && !str_contains($r['body'], 'Hizmet Bedellerini Çek'),
        'HTML: hizmet bedeli için ayrı düğme yok');
    // Normal senkron girişi: action=settlements
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    $r = req($ch, $base, 'magaza.php?mp=trendyol', ['csrf' => csrf($r['body']), 'action' => 'settlements']);
    check(str_contains($r['body'], 'Cari hesap ekstresi çekildi: 0 kayıt')
        && str_contains($r['body'], 'Platform hizmet bedeli çekildi: 2 hareket'),
        'HTML: tek finans işlemi iki kaynağı da çeker');
    check(str_contains($r['body'], 'Platform hizmet bedeli çekildi: 2 hareket'), 'HTML: senkron 2 hareket çekti');

    // Senkron sonrası: hareketler görünür
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    check(str_contains($r['body'], 'PSF-1001') && str_contains($r['body'], 'KOM-2024-001'), 'HTML: işlem ID ve fatura seri no görünür');
    check(str_contains($r['body'], 'ORDER-1') && str_contains($r['body'], '77001'), 'HTML: sipariş/paket numarası referans olarak görünür');
    check(str_contains($r['body'], 'para birimi sağlayıcı tarafından açıkça belirtilmiyor'), 'HTML: para birimi belirtilmiyor notu görünür');
    check(str_contains($r['body'], '13,19') && !str_contains($r['body'], '₺13,19'), 'HTML: borç ₺ etiketi olmadan gösterilir');
    check(str_contains($r['body'], '0,00'), 'HTML: geçerli sıfır tutar gösterilir');
    check(!str_contains($r['body'], 'Toplam (dönem)'), 'HTML: doğrulanmamış para birimiyle dönem toplamı üretilmez');
    check(str_contains($r['body'], 'kâr hesabındaki tahminin yerine kullanılmaz'), 'HTML: tahmin değiştirilmez notu görünür');

    // Kâr/tahmin değişmedi (senkron sonrası service_fee aynı)
    $fee = (float)db()->query("SELECT service_fee FROM products WHERE barcode='BC-1'")->fetchColumn();
    check($fee === 13.19, 'HTML ötesi: ürün hizmet bedeli tahmini (13,19) senkrondan etkilenmez');

    // Aynı dönem tekrar çekilince kayıt çoğalmaz (idempotent)
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    $r = req($ch, $base, 'magaza.php?mp=trendyol', ['csrf' => csrf($r['body']), 'action' => 'settlements']);
    $count = (int)db()->query('SELECT COUNT(*) FROM platform_service_fees')->fetchColumn();
    check($count === 2, 'HTML ötesi: aynı dönem tekrar çekilince kayıt çoğalmaz');

    // Başarısız ikinci çekim: önceki kayıtlar + son başarı zamanı korunur, ekran hatayı + önceki çekimi belirtir
    $lastSync = (int)db()->query("SELECT last_service_fees_sync FROM integrations WHERE user_id=$uid AND marketplace='trendyol'")->fetchColumn();
    touch($dir . '/fail');
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    $r = req($ch, $base, 'magaza.php?mp=trendyol', ['csrf' => csrf($r['body']), 'action' => 'settlements']);
    check(str_contains($r['body'], 'Platform hizmet bedeli hatası'), 'HTML: başarısız çekim hata mesajı gösterir');
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    check(str_contains($r['body'], 'önceki başarılı çekime ait'), 'HTML: gösterilen kayıtların önceki çekime ait olduğu belirtilir');
    check(str_contains($r['body'], 'PSF-1001'), 'HTML: önceki başarılı kayıtlar korunur');
    check((int)db()->query("SELECT last_service_fees_sync FROM integrations WHERE user_id=$uid AND marketplace='trendyol'")->fetchColumn() === $lastSync, 'HTML ötesi: başarısız çekimde son başarı zamanı ilerlemez');
    unlink($dir . '/fail');

    // Eksiksiz boş dönem: ikinci kullanıcı boş sonuç çeker → "eksiksiz boş sonuç" (henüz çekilmedi DEĞİL)
    db()->prepare("INSERT INTO users(email, password_hash, plan, plan_expires) VALUES(?, ?, 'pro', '2050-01-01')")
        ->execute(['pro-empty@example.test', password_hash('Test-Password-2026!', PASSWORD_DEFAULT)]);
    $uid2 = (int)db()->lastInsertId();
    save_integration($uid2, 'trendyol', '43', 'fk', 'fs');
    touch($dir . '/empty');
    // İkinci kullanıcı olarak giriş + çekim (ayrı oturum)
    $jar2 = tempnam(sys_get_temp_dir(), 'kf-psf-cookie2-');
    $ch2 = curl_init();
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar2, CURLOPT_COOKIEJAR => $jar2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
    $r = req($ch2, $base, 'giris.php');
    $r = req($ch2, $base, 'giris.php', ['csrf' => csrf($r['body']), 'email' => 'pro-empty@example.test', 'password' => 'Test-Password-2026!']);
    $r = req($ch2, $base, 'magaza.php?mp=trendyol');
    $r = req($ch2, $base, 'magaza.php?mp=trendyol', ['csrf' => csrf($r['body']), 'action' => 'settlements']);
    $r = req($ch2, $base, 'magaza.php?mp=trendyol');
    check(str_contains($r['body'], 'Bu dönemde platform hizmet bedeli hareketi yok (eksiksiz boş sonuç)'), 'HTML: eksiksiz boş dönem açıkça gösterilir');
    check(!str_contains($r['body'], 'Henüz platform hizmet bedeli çekilmedi'), 'HTML: boş dönem "henüz çekilmedi" ile karışmaz');
    curl_close($ch2);
    if (is_file($jar2)) unlink($jar2);
    unlink($dir . '/empty');

    curl_close($ch);
    if (is_file($jar)) unlink($jar);
} finally {
    proc_terminate($proc);
    foreach ($pipes as $p) if (is_resource($p)) fclose($p);
    proc_close($proc);
}

foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
@rmdir($dir);

exit($failed ? 1 : 0);
