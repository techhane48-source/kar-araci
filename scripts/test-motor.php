<?php
declare(strict_types=1);
/**
 * Kâr motoru (v2) uçtan uca testleri: geçici bir veritabanında ürün, sipariş, ekstre ve kargo faturası
 * oluşturur; tahmini → kesin geçişini, kargo tarifesi/baremi, iptal/iade ve hakediş kontrollerini doğrular.
 * Çalıştır: php scripts/test-motor.php
 */
$db = sys_get_temp_dir() . '/karfiyat-motor-' . getmypid() . '.sqlite';
@unlink($db);
putenv('KARFIYAT_DB=' . $db);
require_once __DIR__ . '/../includes/functions.php';

$fail = 0; $n = 0;
function ok(bool $c, string $m): void { global $fail, $n; $n++; echo ($c ? "  ✓ " : "  ✗ ") . $m . "\n"; if (!$c) $fail++; }
function near(?float $a, float $b, float $e = 0.02): bool { return $a !== null && abs($a - $b) <= $e; }

$pdo = db();
$pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('t@t', 'T', 'x')");
$uid = (int)$pdo->lastInsertId();
$mp = 'trendyol';
$rate = 293.44 / 2585.54 * 100;   // Melontik referans siparişindeki komisyon oranı

$insP = $pdo->prepare("INSERT INTO products (user_id, name, barcode, marketplace, category, rate, cost, price, cargo, kdv, desi, en, boy, yukseklik)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$insP->execute([$uid, 'Halı', 'HALI', $mp, 'Ev Tekstili', $rate, 1204.00, 2585.54, 0, 10, 4, 0, 0, 0]);
$insP->execute([$uid, 'Kupa', 'KUPA', $mp, 'Mutfak', 20, 60, 150, 0, 20, 1, 10, 10, 10]);
$insP->execute([$uid, 'Maliyetsiz', 'BOS', $mp, 'Diğer', 15, 0, 100, 0, 20, 1, 0, 0, 0]);

// TEX tarifesi (KDV hariç) — test verisi; 4 desi = 107,20 → KDV dahil 128,64 (referans siparişteki kargo)
$tar = $pdo->prepare("INSERT INTO cargo_tariffs (user_id, carrier, desi_from, desi_to, price_ex_vat) VALUES (?,?,?,?,?)");
foreach ([[1, 1, 70.00], [2, 2, 80.00], [3, 3, 95.00], [4, 4, 107.20], [5, 10, 140.00]] as [$f, $t, $p]) $tar->execute([$uid, 'TEX', $f, $t, $p]);
$pdo->prepare("INSERT INTO cargo_barem (user_id, carrier, min_price, max_price, max_desi, price_ex_vat) VALUES (?, 'TEX', 0, 200, 10, 40)")->execute([$uid]);

$insO = $pdo->prepare("INSERT INTO orders (user_id, marketplace, order_number, package_number, status, total_price, order_date, lines) VALUES (?,?,?,?,?,?,?,?)");
$now = time();
$line = fn($b, $q, $p, $vat = null) => ['barcode' => $b, 'quantity' => $q, 'price' => $p, 'vatRate' => $vat, 'productName' => $b];
$insO->execute([$uid, $mp, 'O1', 'P1', 'Delivered', 2585.54, $now - 30 * 86400, json_encode([$line('HALI', 1, 2585.54, 10)])]);
$insO->execute([$uid, $mp, 'O2', 'P2', 'Created', 2585.54, $now - 3600, json_encode([$line('HALI', 1, 2585.54, 10)])]);
$insO->execute([$uid, $mp, 'O3', 'P3', 'Created', 150, $now - 7200, json_encode([$line('KUPA', 1, 150, 20)])]);
$insO->execute([$uid, $mp, 'O4', 'P4', 'Cancelled', 150, $now - 7200, json_encode([$line('KUPA', 1, 150, 20)])]);
$insO->execute([$uid, $mp, 'O5', 'P5', 'Created', 100, $now - 7200, json_encode([$line('BOS', 1, 100, 20)])]);
$insO->execute([$uid, $mp, 'O6', 'P6', 'Returned', 2585.54, $now - 25 * 86400, json_encode([$line('HALI', 1, 2585.54, 10)])]);
$insO->execute([$uid, $mp, 'O7', 'P7', 'Delivered', 300, $now - 40 * 86400, json_encode([$line('KUPA', 2, 150, 20)])]);

// Gerçek kayıtlar: O1 için ekstre satış komisyonu ve kargo faturası (107,20 KDV hariç, desi 4)
$insS = $pdo->prepare("INSERT INTO settlements (user_id, marketplace, ext_id, barcode, transaction_type, commission_rate, commission_amount, order_number, shipment_package_id, transaction_date)
                       VALUES (?,?,?,?,?,?,?,?,?,?)");
$insS->execute([$uid, $mp, 'S1', 'HALI', 'Satış', $rate, 293.44, 'O1', 'P1', $now - 20 * 86400]);
$insS->execute([$uid, $mp, 'S6', 'HALI', 'İade', $rate, 293.44, 'O6', 'P6', $now - 20 * 86400]);
$insC = $pdo->prepare("INSERT INTO cargo_invoice_items (user_id, marketplace, invoice_serial, order_number, parcel_id, item_type, is_return, amount, desi, invoice_date)
                       VALUES (?,?,?,?,?,?,?,?,?,?)");
$insC->execute([$uid, $mp, 'F1', 'O1', 'K1', 'Gönderi Kargo Bedeli', 0, 107.20, 4, $now - 20 * 86400]);
$insC->execute([$uid, $mp, 'F1', 'O6', 'K6', 'Gönderi Kargo Bedeli', 0, 107.20, 4, $now - 20 * 86400]);
$insC->execute([$uid, $mp, 'F1', 'O6', 'K6R', 'İade Kargo Bedeli', 1, 107.20, 4, $now - 20 * 86400]);
$insC->execute([$uid, $mp, 'F1', 'O7', 'K7', 'Gönderi Kargo Bedeli', 0, 140.00, 6, $now - 35 * 86400]);   // 2 kupa = 2 desi beklenir, 6 kesilmiş

$r = recompute_order_profits($uid, $mp, 0);
$get = function (string $pkg) use ($pdo, $uid) { $s = $pdo->prepare("SELECT * FROM order_profits WHERE user_id = ? AND package_number = ?"); $s->execute([$uid, $pkg]); return $s->fetch(); };

echo "1) Kesinleşmiş sipariş (ekstre + kargo faturası) = Melontik referansı\n";
$o1 = $get('P1');
ok($o1['commission_src'] === 'kesin' && $o1['cargo_src'] === 'kesin' && (int)$o1['is_final'] === 1, 'komisyon ve kargo kesin');
ok(near((float)$o1['cargo'], 128.64), sprintf('kargo 128,64 (bulunan %.2f)', $o1['cargo']));
ok(near((float)$o1['profit'], 869.72), sprintf('net kâr 869,72 (bulunan %.2f)', $o1['profit']));

echo "2) Henüz faturası gelmemiş aynı sipariş: tarifeden tahmin, aynı kâr\n";
$o2 = $get('P2');
ok($o2['commission_src'] === 'tahmini' && $o2['cargo_src'] === 'tahmini' && (int)$o2['is_final'] === 0, 'kalemler tahmini');
ok(near((float)$o2['cargo'], 128.64) && str_contains($o2['cargo_note'], 'desi 4'), sprintf('kargo desi 4 tarifesinden 128,64 (%s)', $o2['cargo_note']));
ok(near((float)$o2['profit'], 869.72), sprintf('tahmini kâr 869,72 (bulunan %.2f)', $o2['profit']));

echo "3) Düşük sepet: barem kargo\n";
$o3 = $get('P3');
ok(near((float)$o3['cargo'], 48.00) && str_contains($o3['cargo_note'], 'barem'), sprintf('barem 40 + KDV = 48,00 (bulunan %.2f, %s)', $o3['cargo'], $o3['cargo_note']));

echo "4) İptal ve maliyetsiz siparişler\n";
ok((int)$get('P4')['is_cancelled'] === 1, 'iptal işaretli');
$o5 = $get('P5');
ok((int)$o5['missing_cost'] === 1 && $o5['profit'] === null, 'maliyeti eksik → kâr hesaplanmaz');

echo "5) İade: satış iptal, kayıp = gidiş + dönüş kargosu + hizmet bedeli (KDV'si indirilerek)\n";
$o6 = $get('P6');
$expLoss = (128.64 + 128.64 + 13.19) - (128.64 + 128.64 + 13.19) * 20 / 120;
ok(near((float)$o6['profit'], -$expLoss), sprintf('iade zararı %.2f (bulunan %.2f)', -$expLoss, $o6['profit']));
ok($o6['return_src'] === 'kesin', 'iade kargosu faturadan (kesin)');

echo "6) Dashboard toplamları\n";
$t = order_profit_totals($uid, $mp, 0, $now + 86400);
ok((int)$t['orders'] === 6, sprintf('iptal hariç 6 sipariş (bulunan %d)', $t['orders']));
ok((int)$t['costed'] === 5, sprintf('maliyetli 5 sipariş (bulunan %d)', $t['costed']));
$sum = 0.0; foreach (['P1', 'P2', 'P3', 'P6', 'P7'] as $p) $sum += (float)$get($p)['profit'];
ok(near($t['profit'], $sum), sprintf('toplam kâr satırların toplamı %.2f (bulunan %.2f)', $sum, $t['profit']));

echo "7) Ayar değişince yeniden hesap (sipariş başı 10 ₺ sabit gider)\n";
save_user_settings($uid, array_merge(default_user_settings(), ['fixed_cost_per_order' => 10]));
recompute_order_profits($uid, $mp, 0);
$p2 = (float)$get('P2')['profit'];
ok(near($p2, 869.72 - 10 + vat_in(10, 20), 0.05), sprintf('kâr 10 ₺ eksi KDV etkisi kadar azaldı: 861,39 (bulunan %.2f)', $p2));

echo "8) Hakediş kontrolü: fazla desi\n";
$h = hakedis_checks($uid, $mp, 90, 20);
ok(count($h['fazla_desi']) === 1 && $h['fazla_desi'][0]['order_number'] === 'O7', 'O7: 2 desi beklenirken 6 desi kesilmiş');
ok(near($h['fazla_desi'][0]['diff'] ?? null, 140.00 - 80.00), sprintf('fark 60,00 KDV hariç (bulunan %.2f)', $h['fazla_desi'][0]['diff'] ?? -1));
ok(count($h['eksik_hakedis']) === 1 && $h['eksik_hakedis'][0]['order_number'] === 'O7', 'O7 teslim edilmiş ama ekstrede satış kaydı yok');

@unlink($db); @unlink($db . '-wal'); @unlink($db . '-shm');
echo "\n" . ($fail ? "BAŞARISIZ: $fail / $n" : "TÜMÜ GEÇTİ: $n test") . "\n";
exit($fail ? 1 : 0);
