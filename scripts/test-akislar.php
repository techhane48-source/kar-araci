<?php
// Kâr&Fiyat — uçtan uca akış testi (kendi sunucu süreci, HTTP üzerinden)
// Kayıt -> giriş -> ürün kaydet -> dashboard -> ödeme bildir -> admin onay -> Pro aktif -> IDOR kontrolü
declare(strict_types=1);

$base = 'http://127.0.0.1:8090';
$fail = 0;

function req(string $method, string $url, array $opts = []): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", array_merge(
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: text/html'],
            $opts['headers'] ?? []
        )),
        'content' => $opts['body'] ?? null,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => (string)$body, 'headers' => $http_response_header ?? []];
}

function cookies(array $headers): string
{
    $c = [];
    foreach ($headers as $h) {
        if (preg_match('#^Set-Cookie:\s*([^=;]+)=([^;]*)#', $h, $m)) {
            $c[$m[1]] = $m[2];
        }
    }
    $out = [];
    foreach ($c as $k => $v) $out[] = "$k=$v";
    return implode('; ', $out);
}

// Cookie jar'ı birleştir (Set-Cookie dönen cevaplarda güncelle, dönmeyende koru)
function merge_jar(string $old, array $headers): string
{
    $new = cookies($headers);
    if ($new === '') return $old;
    $map = [];
    foreach (explode('; ', $old) as $pair) {
        if (str_contains($pair, '=')) {
            [$k, $v] = explode('=', $pair, 2);
            $map[$k] = $v;
        }
    }
    foreach (explode('; ', $new) as $pair) {
        if (str_contains($pair, '=')) {
            [$k, $v] = explode('=', $pair, 2);
            $map[$k] = $v;
        }
    }
    $out = [];
    foreach ($map as $k => $v) $out[] = "$k=$v";
    return implode('; ', $out);
}

function check(bool $cond, string $label): void
{
    global $fail;
    echo ($cond ? "PASS" : "FAIL") . "  $label\n";
    if (!$cond) $fail++;
}

function extract_csrf(string $body): string
{
    if (preg_match('/name="csrf" value="([^"]+)"/', $body, $m)) return $m[1];
    return '';
}

// --- Not: DB temizliği test DIŞINDA yapılır (sunucu yeniden başlatılarak).
//     Test kendi PDO bağlantısını kurarken WAL dosyalarını da temizlememek için buraya dokunma. ---
$dbPath = dirname(__DIR__) . '/data/app.sqlite';

// 1. Ana sayfa (DB kurulumunu tetikler)
$r = req('GET', "$base/");
check($r['status'] === 200, "Ana sayfa 200 (DB ilk istekte kurulur)");
check(!str_contains($r['body'], 'Fatal error') && !str_contains($r['body'], 'Warning:'), "Ana sayfa temiz (hata yok)");

// 2. Kayıt
$r = req('GET', "$base/kayit.php");
check($r['status'] === 200, "Kayıt sayfası 200");
$csrf = extract_csrf($r['body']);
check($csrf !== '', "Kayıt CSRF tokenı üretildi");
$jar = cookies($r['headers']);
check($jar !== '', "Kayıt sayfası cookie kurdu");

$r = req('POST', "$base/kayit.php", [
    'body' => http_build_query(['csrf' => $csrf, 'name' => 'Test Kullanıcı', 'email' => 'test@example.com', 'password' => 'secret123']),
    'headers' => ["Cookie: $jar"],
]);
check($r['status'] === 302, "Kayıt başarılı -> 302 yönlendirme");
$jar = merge_jar($jar, $r['headers']);

// 3. Ürün kaydet (API)
$r = req('GET', "$base/index.php", ['headers' => ["Cookie: $jar"]]);
check($r['status'] === 200, "Giriş yapılmış ana sayfa 200");

$r = req('POST', "$base/api-save.php", [
    'body' => json_encode(['csrf' => $csrf, 'marketplace' => 'trendyol', 'category' => 'Giyim', 'name' => 'Kadın Bot', 'cost' => 200, 'price' => 400, 'cargo' => 50, 'rate' => 21.36]),
    'headers' => ["Cookie: $jar", 'Content-Type: application/json'],
]);
check($r['status'] === 200, "api-save 200");
$j = json_decode($r['body'], true);
check(($j['ok'] ?? false) === true, "Ürün kaydedildi: " . ($j['error'] ?? 'ok'));

// 4. Dashboard kârlılık
$r = req('GET', "$base/dashboard.php", ['headers' => ["Cookie: $jar"]]);
check($r['status'] === 200, "Dashboard 200");
check(str_contains($r['body'], 'Kadın Bot'), "Dashboard'da ürün görünüyor");
check(str_contains($r['body'], '₺53,80'), "Net kâr 53,80 TL hesaplandı (Giyim %21,36)");

// 5. Ödeme bildir (önce IBAN ayarla admin olarak)
// Admin girişi için admin.php'ye gidip şifre değişimi gerekli
$r = req('GET', "$base/giris.php");
$csrf = extract_csrf($r['body']);
$adminJar = cookies($r['headers']);
$r = req('POST', "$base/giris.php", [
    'body' => http_build_query(['csrf' => $csrf, 'email' => 'admin@karfiyat.local', 'password' => '1234', 'next' => '']),
    'headers' => ["Cookie: $adminJar"],
]);
check($r['status'] === 302, "Admin girişi 302");
$adminJar = cookies($r['headers']);

// Admin ilk giriş -> şifre değiştirmeye zorlanır
$r = req('GET', "$base/admin.php", ['headers' => ["Cookie: $adminJar"]]);
check(str_contains($r['body'], 'Varsayılan şifre'), "Admin varsayılan şifreyle kilitlenir");

// Şifre değiştir
$csrf = extract_csrf($r['body']);
$r = req('POST', "$base/admin.php", [
    'body' => http_build_query(['csrf' => $csrf, 'action' => 'password', 'current' => '1234', 'new' => 'adminpass99']),
    'headers' => ["Cookie: $adminJar"],
]);
$r = req('GET', "$base/admin.php", ['headers' => ["Cookie: $adminJar"]]);
check(str_contains($r['body'], 'Yönetim Paneli'), "Şifre değişince admin paneli açılır");

// IBAN ayarla
$csrf = extract_csrf($r['body']);
req('POST', "$base/admin.php", [
    'body' => http_build_query(['csrf' => $csrf, 'action' => 'settings', 'iban' => 'TR00 0001 2345 6789', 'account_name' => 'Ümit Aslan', 'pro_price' => '149']),
    'headers' => ["Cookie: $adminJar"],
]);

// 6. Kullanıcı ödeme bildirir
$r = req('GET', "$base/odeme.php", ['headers' => ["Cookie: $jar"]]);
check($r['status'] === 200, "Ödeme sayfası 200");
check(str_contains($r['body'], 'TR00 0001 2345 6789'), "IBAN kullanıcıya görünüyor");
$csrf = extract_csrf($r['body']);
$r = req('POST', "$base/odeme.php", [
    'body' => http_build_query(['csrf' => $csrf, 'action' => 'report']),
    'headers' => ["Cookie: $jar"],
]);
check($r['status'] === 302, "Ödeme bildirimi 302");

// 7. Admin onaylar
$r = req('GET', "$base/admin.php", ['headers' => ["Cookie: $adminJar"]]);
check(str_contains($r['body'], 'test@example.com'), "Bekleyen ödeme panelde görünüyor");
$csrf = extract_csrf($r['body']);
// pid'i bul
$pdo = new PDO('sqlite:' . $dbPath);
$payId = $pdo->query("SELECT id FROM payments WHERE user_id = (SELECT id FROM users WHERE email='test@example.com') AND status='pending' ORDER BY id DESC LIMIT 1")->fetchColumn();
check((int)$payId > 0, "Bekleyen ödeme kaydı var (id=$payId)");

$r = req('POST', "$base/admin.php", [
    'body' => http_build_query(['csrf' => $csrf, 'action' => 'approve', 'pid' => $payId]),
    'headers' => ["Cookie: $adminJar"],
]);
$plan = $pdo->query("SELECT plan, plan_expires FROM users WHERE email='test@example.com'")->fetch(PDO::FETCH_ASSOC);
check($plan['plan'] === 'pro', "Ödeme onayı -> kullanıcı Pro oldu");
check(!empty($plan['plan_expires']), "Pro bitiş tarihi atandı: {$plan['plan_expires']}");

// 8. IDOR kontrolü — başka kullanıcının ürünü silinemez
$r2 = req('GET', "$base/kayit.php");
$csrf2 = extract_csrf($r2['body']);
$jar2 = cookies($r2['headers']);
req('POST', "$base/kayit.php", [
    'body' => http_build_query(['csrf' => $csrf2, 'name' => 'Başkası', 'email' => 'other@example.com', 'password' => 'secret456']),
    'headers' => ["Cookie: $jar2"],
]);
$jar2 = cookies($r2['headers']);

// other@example.com, test@example.com'un ürününü silmeye çalışsın
$victimPid = $pdo->query("SELECT id FROM products WHERE user_id = (SELECT id FROM users WHERE email='test@example.com') LIMIT 1")->fetchColumn();
$r = req('GET', "$base/dashboard.php", ['headers' => ["Cookie: $jar2"]]);
$csrf = extract_csrf($r['body']);
req('POST', "$base/dashboard.php", [
    'body' => http_build_query(['csrf' => $csrf, 'action' => 'delete', 'pid' => $victimPid]),
    'headers' => ["Cookie: $jar2"],
]);
$still = $pdo->query("SELECT COUNT(*) FROM products WHERE id = $victimPid")->fetchColumn();
check((int)$still === 1, "IDOR engellendi (başkasının ürünü silinemedi)");

// 9. Ücretsiz plan 5 ürün limiti (other@example.com)
for ($i = 0; $i < 5; $i++) {
    req('POST', "$base/api-save.php", [
        'body' => json_encode(['csrf' => $csrf, 'marketplace' => 'trendyol', 'category' => 'Giyim', 'name' => "Ürün $i", 'cost' => 10, 'price' => 20, 'cargo' => 5, 'service_fee' => 1, 'stopaj' => 0, 'return_rate' => 1]),
        'headers' => ["Cookie: $jar2", 'Content-Type: application/json'],
    ]);
}
$r = req('POST', "$base/api-save.php", [
    'body' => json_encode(['csrf' => $csrf, 'marketplace' => 'trendyol', 'category' => 'Giyim', 'name' => '6. ürün', 'cost' => 10, 'price' => 20, 'cargo' => 5, 'service_fee' => 1, 'stopaj' => 0, 'return_rate' => 1]),
    'headers' => ["Cookie: $jar2", 'Content-Type: application/json'],
]);
$j = json_decode($r['body'], true);
check(($j['ok'] ?? false) === false, "Ücretsiz plan 6. ürünü reddeder: " . ($j['error'] ?? ''));

// --- Temizlik test DIŞINDA yapılır: sunucuyu durdur, data/*.sqlite* sil, yeniden başlat ---
echo "\n" . ($fail === 0 ? "TÜM TESTLER GEÇTİ" : "$fail TEST BAŞARISIZ") . "\n";
exit($fail === 0 ? 0 : 1);
