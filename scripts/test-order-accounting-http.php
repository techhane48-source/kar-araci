<?php
declare(strict_types=1);

// Kendi kendine yeten HTTP testi: izole DB + geçici sunucu + örnek sağlayıcı yanıtları.
// Canlı mağaza/API çağrısı YAPMAZ; getOrderClaims örnek yanıtla taklit edilir.

$dir = sys_get_temp_dir() . '/kf-oa-http-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
putenv('KARFIYAT_DB=' . $dir . '/app.sqlite');

$transportFile = $dir . '/transport.php';
file_put_contents($transportFile, <<<'PHP'
<?php
return static function (string $method, string $path, array $query, ?string $body): array {
    if (str_ends_with($path, '/claims')) {
        $rows = [['orderNumber' => '456', 'orderShipmentPackageId' => 'PKG-A', 'items' => [[
            'orderLine' => ['id' => 100, 'barcode' => 'BC-1'],
            'claimItems' => [['id' => 'claim-1', 'orderLineItemId' => 5, 'claimItemStatus' => ['name' => 'Accepted']]],
        ]]]];
        return ['http' => 200, 'body' => ['content' => $rows, 'totalPages' => 1, 'totalElements' => 1]];
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
db()->prepare("INSERT INTO orders(user_id, marketplace, order_number, package_number, status, total_price, order_date, lines)
    VALUES(?, 'trendyol', '456', 'PKG-A', 'Delivered', 200, 0, ?)")->execute([$uid, $lines]);

$port = 8091;
$base = "http://127.0.0.1:$port";
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../public_html'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) { echo "FAIL sunucu başlatılamadı\n"; exit(1); }

// Sunucu hazır olana kadar bekle
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

    // Giriş
    $r = req($ch, $base, 'giris.php');
    $r = req($ch, $base, 'giris.php', ['csrf' => csrf($r['body']), 'email' => 'pro-oa@example.test', 'password' => 'Test-Password-2026!']);
    check($r['status'] === 302, 'Pro kullanıcı giriş yapar');

    // Sipariş muhasebesi sayfasını POST ile aç
    $r = req($ch, $base, 'siparis-muhasebe.php');
    $r = req($ch, $base, 'siparis-muhasebe.php', ['csrf' => csrf($r['body']), 'order' => '456']);
    $html = $r['body'];
    check($r['status'] === 200, 'Sipariş muhasebesi sayfası 200 döner');

    // İndirim HTML'i
    check(str_contains($html, 'Birim toplam indirim ₺10,00'), 'HTML: Birim toplam indirim ₺10,00 görünür');
    check(str_contains($html, 'Satıcı indirimi Bilinmiyor'), 'HTML: satıcı payı bilinmiyor');
    check(str_contains($html, 'Trendyol indirimi Bilinmiyor'), 'HTML: Trendyol payı bilinmiyor');

    // İade eşleşmesi HTML'i
    check(str_contains($html, 'İade kalemleri'), 'HTML: iade kalemleri bölümü var');
    check(str_contains($html, 'Accepted'), 'HTML: iade durumu gösterilir');
    check(str_contains($html, 'PKG-A'), 'HTML: iade doğru pakete eşleşir');
    check(str_contains($html, 'BC-1'), 'HTML: iade doğru barkodu gösterir');

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
