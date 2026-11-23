<?php
declare(strict_types=1);

// Uçtan uca HTTP testi: izole DB + geçici sunucu + örnek sağlayıcı yanıtları.
// Normal senkron girişinden (magaza.php action=service_fees) gerçek HTML çıktısına kadar
// platform hizmet bedeli akışını doğrular. Canlı mağaza/API çağrısı YAPMAZ.

$dir = sys_get_temp_dir() . '/kf-psf-http-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
putenv('KARFIYAT_DB=' . $dir . '/app.sqlite');

$transportFile = $dir . '/transport.php';
file_put_contents($transportFile, <<<'PHP'
<?php
return static function (string $method, string $path, array $query, ?string $body): array {
    if (str_ends_with($path, '/otherfinancials')) {
        if (($query['transactionType'] ?? '') === 'DeductionInvoices' && ($query['transactionSubType'] ?? '') === 'PlatformServiceFee') {
            $rows = [
                ['id' => 'PSF-1001', 'transactionType' => 'DeductionInvoices', 'transactionDate' => 1700000000000,
                 'receiptId' => 9001, 'commissionInvoiceSerialNumber' => 'KOM-2024-001', 'description' => 'Platform hizmet bedeli',
                 'debt' => 13.19, 'credit' => 0, 'orderNumber' => 'ORDER-1', 'shipmentPackageId' => 77001, 'barcode' => 'BC-1'],
                ['id' => 'PSF-1002', 'transactionType' => 'DeductionInvoices', 'transactionDate' => 1700000000000,
                 'debt' => 0, 'credit' => 5.0],
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

    // Normal senkron girişi: action=service_fees
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    $r = req($ch, $base, 'magaza.php?mp=trendyol', ['csrf' => csrf($r['body']), 'action' => 'service_fees']);
    check(str_contains($r['body'], 'Platform hizmet bedeli çekildi: 2 hareket'), 'HTML: senkron 2 hareket çekti');

    // Senkron sonrası: hareketler görünür
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    check(str_contains($r['body'], 'PSF-1001') && str_contains($r['body'], 'KOM-2024-001'), 'HTML: işlem ID ve fatura seri no görünür');
    check(str_contains($r['body'], 'ORDER-1') && str_contains($r['body'], '77001'), 'HTML: sipariş/paket numarası referans olarak görünür');
    check(str_contains($r['body'], '₺13,19') && str_contains($r['body'], '₺5,00'), 'HTML: borç ve alacak ayrı gösterilir');
    check(str_contains($r['body'], 'Toplam (dönem)'), 'HTML: dönem toplamı ayrı gösterilir');
    check(str_contains($r['body'], 'kâr hesabındaki tahminin yerine kullanılmaz'), 'HTML: tahmin değiştirilmez notu görünür');

    // Kâr/tahmin değişmedi (senkron sonrası service_fee aynı)
    $fee = (float)db()->query("SELECT service_fee FROM products WHERE barcode='BC-1'")->fetchColumn();
    check($fee === 13.19, 'HTML ötesi: ürün hizmet bedeli tahmini (13,19) senkrondan etkilenmez');

    // Aynı dönem tekrar çekilince kayıt çoğalmaz (idempotent)
    $r = req($ch, $base, 'magaza.php?mp=trendyol');
    $r = req($ch, $base, 'magaza.php?mp=trendyol', ['csrf' => csrf($r['body']), 'action' => 'service_fees']);
    $count = (int)db()->query('SELECT COUNT(*) FROM platform_service_fees')->fetchColumn();
    check($count === 2, 'HTML ötesi: aynı dönem tekrar çekilince kayıt çoğalmaz');

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
