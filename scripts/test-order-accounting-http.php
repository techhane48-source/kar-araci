<?php
declare(strict_types=1);

// Kendi kendine yeten HTTP testi: izole DB + geçici sunucu + örnek sağlayıcı yanıtları.
// Canlı mağaza/API çağrısı YAPMAZ; getOrderClaims ve getCargoInvoiceItems örnek yanıtla taklit edilir.

$dir = sys_get_temp_dir() . '/kf-oa-http-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
putenv('KARFIYAT_DB=' . $dir . '/app.sqlite');

$transportFile = $dir . '/transport.php';
file_put_contents($transportFile, <<<'PHP'
<?php
return static function (string $method, string $path, array $query, ?string $body): array {
    if (str_ends_with($path, '/claims')) {
        $on = (string)($query['orderNumber'] ?? '');
        if ($on === '456') {
            $rows = [['orderNumber' => '456', 'orderShipmentPackageId' => 'PKG-A', 'items' => [[
                'orderLine' => ['id' => 100, 'barcode' => 'BC-1'],
                'claimItems' => [['id' => 'claim-1', 'orderLineItemId' => 5, 'claimItemStatus' => ['name' => 'Accepted']]],
            ]]]];
            return ['http' => 200, 'body' => ['content' => $rows, 'totalPages' => 1, 'totalElements' => 1]];
        }
        if ($on === '457') { // eksiksiz ama boş iade yanıtı
            return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 0, 'totalElements' => 0]];
        }
        if ($on === '458') { // eksik sayfa: content boş ama totalElements 1
            return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 1, 'totalElements' => 1]];
        }
        return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 0, 'totalElements' => 0]];
    }
    if (str_contains($path, '/cargo-invoice/')) {
        if (str_contains($path, '/INV-EMPTY/')) {
            return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 0, 'totalElements' => 0]];
        }
        if (str_contains($path, '/INV-NOMATCH/')) {
            $rows = [['orderNumber' => '999', 'shipmentPackageType' => 'Gönderi Kargo Bedeli', 'parcelUniqueId' => 900, 'amount' => 12.5]];
            return ['http' => 200, 'body' => ['content' => $rows, 'totalPages' => 1, 'totalElements' => 1]];
        }
        if (str_contains($path, '/INV-CONFLICT/')) {
            $rows = [
                ['orderNumber' => '456', 'shipmentPackageType' => 'Gönderi Kargo Bedeli', 'parcelUniqueId' => 100, 'amount' => 34.24],
                ['orderNumber' => '456', 'shipmentPackageType' => 'Gönderi Kargo Bedeli', 'parcelUniqueId' => 100, 'amount' => 99.99],
            ];
            return ['http' => 200, 'body' => ['content' => $rows, 'totalPages' => 1, 'totalElements' => 2]];
        }
        // INV-A: aynı siparişte gönderi + iade kargosu
        $rows = [
            ['orderNumber' => '456', 'shipmentPackageType' => 'Gönderi Kargo Bedeli', 'parcelUniqueId' => 100, 'amount' => 34.24],
            ['orderNumber' => '456', 'shipmentPackageType' => 'İade Kargo Bedeli', 'parcelUniqueId' => 200, 'amount' => 34.24],
        ];
        return ['http' => 200, 'body' => ['content' => $rows, 'totalPages' => 1, 'totalElements' => 2]];
    }
    return ['http' => 200, 'body' => ['content' => [], 'totalPages' => 0, 'totalElements' => 0]];
};
PHP);
putenv('KARFIYAT_TEST_TRANSPORT=' . $transportFile);

require_once __DIR__ . '/../includes/functions.php';

$failed = 0;
function check(bool $ok, string $label): void { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $failed++; }

db()->prepare("INSERT INTO users(email, password_hash, plan, plan_expires) VALUES(?, ?, 'pro', '2050-01-01')")
    ->execute(['pro-oa@example.test', password_hash('Test-Password-2026!', PASSWORD_DEFAULT)]);
$uid = (int)db()->lastInsertId();
save_integration($uid, 'trendyol', '42', 'fake-key', 'fake-secret');
$lines = json_encode([['barcode' => 'BC-1', 'quantity' => 2, 'price' => 100, 'orderLineId' => '100',
    'discounts' => ['lineTotalDiscount' => 10]]]);
$plain = json_encode([['barcode' => 'BC-1', 'quantity' => 1, 'price' => 100, 'orderLineId' => '100']]);
$ins = db()->prepare("INSERT INTO orders(user_id, marketplace, order_number, package_number, status, total_price, order_date, lines)
    VALUES(?, 'trendyol', ?, ?, 'Delivered', 200, 0, ?)");
$ins->execute([$uid, '456', 'PKG-A', $lines]);
$ins->execute([$uid, '457', 'PKG-B', $plain]);
$ins->execute([$uid, '458', 'PKG-C', $plain]);

$port = 8091;
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

    $jar = tempnam(sys_get_temp_dir(), 'kf-oa-cookie-');
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
    function post($ch, string $base, array $data): string {
        $r = req($ch, $base, 'siparis-muhasebe.php');
        return req($ch, $base, 'siparis-muhasebe.php', ['csrf' => csrf($r['body'])] + $data)['body'];
    }

    // Giriş
    $r = req($ch, $base, 'giris.php');
    $r = req($ch, $base, 'giris.php', ['csrf' => csrf($r['body']), 'email' => 'pro-oa@example.test', 'password' => 'Test-Password-2026!']);
    check($r['status'] === 302, 'Pro kullanıcı giriş yapar');

    // Senaryo A: sipariş 456 — indirim + eşleşen iade
    $html = post($ch, $base, ['order' => '456']);
    check(str_contains($html, 'Birim toplam indirim ₺10,00'), 'HTML: Birim toplam indirim ₺10,00');
    check(str_contains($html, 'Accepted'), 'HTML: iade durumu gösterilir');
    check(str_contains($html, 'PKG-A') && str_contains($html, 'BC-1'), 'HTML: iade doğru paket/barkoda eşleşir');

    // Senaryo B: sipariş 456 + fatura INV-A — gönderi ve iade kargosu ayrı, paket bağı belirsiz
    $html = post($ch, $base, ['order' => '456', 'invoice' => 'INV-A']);
    check(str_contains($html, 'paket eşleşmesi belirsizdir'), 'HTML: paket eşleşmesi belirsiz uyarısı');
    check(str_contains($html, 'Gönderi Kargo Bedeli'), 'HTML: gönderi kargosu gösterilir');
    check(str_contains($html, 'İade Kargo Bedeli'), 'HTML: iade kargosu ayrı gösterilir');
    check(str_contains($html, 'Gönderi 100') && str_contains($html, 'Gönderi 200'), 'HTML: gönderi kimlikleri ayrı görünür');

    // Senaryo C: fatura INV-EMPTY — eksiksiz boş fatura
    $html = post($ch, $base, ['order' => '456', 'invoice' => 'INV-EMPTY']);
    check(str_contains($html, 'Fatura boş (kalem içermiyor)'), 'HTML: boş fatura ayırt edilir');

    // Senaryo D: fatura INV-NOMATCH — siparişe ait kalem yok
    $html = post($ch, $base, ['order' => '456', 'invoice' => 'INV-NOMATCH']);
    check(str_contains($html, 'Faturada bu siparişe ait kalem yok'), 'HTML: siparişe ait kalem içermeyen fatura ayırt edilir');

    // Senaryo B2: fatura INV-CONFLICT — aynı gönderi+tür çelişkili tutar, toplam dışı
    $html = post($ch, $base, ['order' => '456', 'invoice' => 'INV-CONFLICT']);
    check(str_contains($html, 'Çelişkili tutar (toplam dışı)'), 'HTML: çelişkili tutar gösterilir');
    check(!str_contains($html, 'Bilinen kalemler toplamı: ₺34,24'), 'HTML: çelişkili kalem bilinen toplamına katılmaz');

    // Senaryo B3: INV-A toplamı — bilinen kalemler toplamı doğru
    $html = post($ch, $base, ['order' => '456', 'invoice' => 'INV-A']);
    check(str_contains($html, 'Bilinen kalemler toplamı: ₺68,48'), 'HTML: bilinen kalemler toplamı doğru (34,24×2)');

    // Senaryo E: sipariş 457 — eksiksiz boş iade → "iade yok" (hata DEĞİL)
    $html = post($ch, $base, ['order' => '457']);
    check(str_contains($html, 'Gösterilecek iade kalemi yok'), 'HTML: boş iade yanıtı iade yok gösterir');
    check(!str_contains($html, 'İade sorgusu başarısız'), 'HTML: boş iade hata gibi görünmez');

    // Senaryo F: sipariş 458 — eksik sayfalı iade → hata, "iade yok" izlenimi YOK
    $html = post($ch, $base, ['order' => '458']);
    check(str_contains($html, 'İade sorgusu başarısız oldu'), 'HTML: eksik sayfalı iade sorgusu hata gösterir');
    check(!str_contains($html, 'Gösterilecek iade kalemi yok'), 'HTML: hata durumunda iade yok izlenimi verilmez');

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
