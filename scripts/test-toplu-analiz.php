<?php
// Kâr&Fiyat — toplu-analiz.php uçtan uca testi (cURL ile)
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$base = 'http://127.0.0.1:8090';
$fail = 0;

function check(bool $c, string $l): void { global $fail; echo ($c ? "PASS" : "FAIL") . "  $l\n"; if (!$c) $fail++; }

function csrf(string $html): string {
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function status(string $resp): int {
    return preg_match('#^HTTP/\S+\s+(\d+)#', $resp, $m) ? (int)$m[1] : 0;
}

$jar = tempnam(sys_get_temp_dir(), 'kfjar');
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true,
]);

// 1. Giriş sayfası
curl_setopt($ch, CURLOPT_URL, "$base/giris.php");
$resp = curl_exec($ch);
$csrf = csrf($resp);

// 2. Giriş yap (test@example.com / secret123 — Pro)
curl_setopt($ch, CURLOPT_URL, "$base/giris.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['csrf'=>$csrf,'email'=>'test@example.com','password'=>'secret123','next'=>'']));
$resp = curl_exec($ch);
check(str_contains($resp, '302'), "Giriş başarılı (302)");

// 3. Test ürünleri ekle (barkodlu + maliyetsiz)
db()->prepare("DELETE FROM products WHERE user_id = 2 AND barcode IN ('TEST-BARKOD-123','TEST-COST-0')")->execute();
db()->prepare("INSERT INTO products (user_id, name, barcode, marketplace, category, rate, cost, price, cargo, service_fee, stopaj, return_rate)
               VALUES (2, 'Test Perde', 'TEST-BARKOD-123', 'trendyol', 'Ev Tekstili', 20.34, 200, 400, 50, 13.19, 0, 0)")->execute();
db()->prepare("INSERT INTO products (user_id, name, barcode, marketplace, category, rate, cost, price, cargo, service_fee, stopaj, return_rate)
               VALUES (2, 'Maliyetsiz Ürün', 'TEST-COST-0', 'trendyol', 'Diğer', 20, 0, 0, 0, 13.19, 0, 0)")->execute();

// 4. CSV oluştur
$csv = tempnam(sys_get_temp_dir(), 'kfcsv');
file_put_contents($csv, "barkod;fiyat;urun_adi\nTEST-BARKOD-123;400;Test Perde\nBILINMEYEN-999;300;Yok Urun\nTEST-COST-0;250;Maliyetsiz Urun\n");

// 5. GET toplu-analiz (yeni CSRF + form)
curl_setopt($ch, CURLOPT_URL, "$base/toplu-analiz.php");
curl_setopt($ch, CURLOPT_POST, false);
$resp = curl_exec($ch);
check(str_contains($resp, 'Toplu Kârlılık Analizi'), "Toplu analiz sayfası açıldı (Pro)");
$csrf = csrf($resp);

// 6. POST analyze (multipart)
curl_setopt($ch, CURLOPT_URL, "$base/toplu-analiz.php");
curl_setopt($ch, CURLOPT_POST, true);
$post = [
    'csrf' => $csrf,
    'action' => 'analyze',
    'mp' => 'trendyol',
    'csv' => new CURLFile($csv, 'text/csv', 'test.csv'),
];
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
$resp = curl_exec($ch);
$st = status($resp);
check($st === 302, "Analiz POST -> 302 (PRG, aldı: $st)");
if ($st !== 302) {
    $body = substr($resp, strpos($resp, "\r\n\r\n") + 4);
    echo "  [DEBUG] response: " . substr(strip_tags($body), 0, 400) . "\n";
}

// 7. GET sonuç sayfası
curl_setopt($ch, CURLOPT_URL, "$base/toplu-analiz.php");
curl_setopt($ch, CURLOPT_POST, false);
$resp = curl_exec($ch);
check(str_contains($resp, 'TEST-BARKOD-123'), "Eşleşen barkod görünüyor");
check(str_contains($resp, 'Test Perde'), "Ürün adı görünüyor");
check(str_contains($resp, 'BILINMEYEN-999'), "Eşleşmeyen barkod görünüyor");
check(str_contains($resp, 'bulunamadı'), "Eşleşmeyen üründe 'bulunamadı' uyarısı");
check(str_contains($resp, 'maliyet yok'), "Maliyetsiz üründe 'maliyet yok' uyarısı");
// Net kâr: cost=200, price=400, cargo=50, rate=20.34, service=13.19 -> hesapla
$r = calc_profit(200, 400, 50, 20.34, 20, 0, 13.19, 0, 0);
$expect = number_format($r['profit'], 2, ',', '.');
check(str_contains($resp, $expect), "Net kâr $expect görünüyor");

// 8. Download (seçimle)
curl_setopt($ch, CURLOPT_URL, "$base/toplu-analiz.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['csrf'=>$csrf,'action'=>'download','sel'=>['TEST-BARKOD-123']]));
$resp = curl_exec($ch);
check(str_contains($resp, 'karfiyat-secimler.csv') || str_contains($resp, 'secim'), "Download CSV başlığı");

curl_close($ch);

// Temizlik
db()->prepare("DELETE FROM products WHERE user_id = 2 AND barcode IN ('TEST-BARKOD-123','TEST-COST-0')")->execute();
@unlink($jar); @unlink($csv);

echo "\n" . ($fail === 0 ? "TÜM TESTLER GEÇTİ" : "$fail TEST BAŞARISIZ") . "\n";
exit($fail === 0 ? 0 : 1);
