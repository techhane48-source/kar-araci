<?php
declare(strict_types=1);
/**
 * Kâr hesap motoru testleri. Çalıştır: php scripts/test-hesap.php
 * Referans: Melontik'in panelde gösterdiği gerçek bir sipariş (27 Kas 2026 incelemesi):
 *   satış 2.585,54 · maliyet 1.204,00 · komisyon 293,44 · kargo 128,64 · hizmet 13,19 · stopaj 23,51 · net KDV 53,04 → net kâr 869,72
 *   ürün KDV'si %10.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$fail = 0; $n = 0;
function ok(bool $c, string $msg): void { global $fail, $n; $n++; echo ($c ? "  ✓ " : "  ✗ ") . $msg . "\n"; if (!$c) $fail++; }
function near(float $a, float $b, float $eps = 0.015): bool { return abs($a - $b) <= $eps; }

echo "1) Melontik referans siparişi (KDV %10)\n";
$sale = 2585.54; $rate = 293.44 / $sale * 100;
$stopaj = auto_stopaj($sale, 10.0, 1.0);
ok(near($stopaj, 23.51), sprintf('stopaj 23,51 (bulunan %.2f)', $stopaj));
$r = calc_profit(1204.00, $sale, 128.64, $rate, 10.0, 0.0, 13.19, $stopaj, 0.0);
ok(near($r['commission'], 293.44), sprintf('komisyon 293,44 (bulunan %.2f)', $r['commission']));
ok(near($r['net_kdv'], 53.04, 0.02), sprintf('net KDV 53,04 (bulunan %.2f)', $r['net_kdv']));
ok(near($r['profit'], 869.72, 0.02), sprintf('net kâr 869,72 (bulunan %.2f)', $r['profit']));

echo "2) Aynı sipariş, sipariş motoru üzerinden\n";
$byBarcode = ['B1' => ['cost' => 1204.00, 'cargo' => 128.64, 'rate' => $rate, 'kdv' => 10.0]];
$o = ['total_price' => $sale, 'lines' => [['barcode' => 'B1', 'quantity' => 1, 'price' => $sale]]];
$or = calc_order_profit($o, $byBarcode);
ok($or['ok'] && near($or['profit'], 869.72, 0.02), sprintf('sipariş kârı 869,72 (bulunan %.2f)', $or['profit'] ?? -1));

echo "3) KDV %20 üründe eski ve yeni formül aynı sonucu verir\n";
$r20 = calc_profit(500, 1000, 50, 20, 20.0, 0, 13.19, auto_stopaj(1000, 20.0), 0);
$eski = (1000 - 500 - 200 - 50 - 13.19) * 20 / 120; if ($eski < 0) $eski = 0;
ok(near($r20['net_kdv'], $eski), sprintf('net KDV %.2f = %.2f', $r20['net_kdv'], $eski));

echo "4) Çok satırlı pakette kargo ve hizmet bedeli bir kez düşülür\n";
$bb = ['A' => ['cost' => 100, 'cargo' => 40, 'rate' => 20, 'kdv' => 20], 'B' => ['cost' => 100, 'cargo' => 30, 'rate' => 20, 'kdv' => 20]];
$o2 = ['lines' => [['barcode' => 'A', 'quantity' => 1, 'price' => 300], ['barcode' => 'B', 'quantity' => 2, 'price' => 300]]];
$r2 = calc_order_profit($o2, $bb);
ok($r2['ok'] && near($r2['cargo'], 40) && near($r2['sale'], 900) && near($r2['cost'], 300), sprintf('kargo 40, satış 900, maliyet 300 (bulunan %.2f / %.2f / %.2f)', $r2['cargo'], $r2['sale'], $r2['cost']));

echo "5) Maliyeti eksik satır → kâr hesaplanmaz\n";
$r3 = calc_order_profit(['lines' => [['barcode' => 'X', 'quantity' => 1, 'price' => 100]]], $bb);
ok(!$r3['ok'] && $r3['missing'], 'missing=true döner');

echo "6) Ters hesap: hedef kâr ve hedef marj geri doğrulanır\n";
$p1 = calc_price(1204.00, 128.64, $rate, 10.0, 0.0, 13.19, 1.0, 0.0, 869.72, 0.0, null);
ok(near($p1['price'], 2585.54, 0.03), sprintf('869,72 ₺ kâr için fiyat 2.585,54 (bulunan %.2f)', $p1['price']));
$p2 = calc_price(300, 40, 20, 20.0, 0, 13.19, 1.0, 0, 0, 25.0);
ok($p2['price'] > 0 && $p2['margin'] >= 24.99, sprintf('%%25 marj hedefi → fiyat %.2f, marj %.2f', $p2['price'], $p2['margin']));
$p3 = calc_price(300, 40, 60, 20.0, 0, 13.19, 1.0, 0, 0, 50.0);
ok($p3['price'] == 0.0, 'ulaşılamayan hedef (komisyon %60 + marj %50) → fiyat 0');

echo "7) İade kaybı = iade oranı × (2 × kargo + hizmet bedeli)\n";
$ri = calc_profit(500, 1000, 50, 20, 20.0, 0, 13.19, 0, 10.0);
ok(near($ri['return_loss'], 0.10 * (100 + 13.19)), sprintf('iade kaybı %.2f', $ri['return_loss']));

echo "\n" . ($fail ? "BAŞARISIZ: $fail / $n" : "TÜMÜ GEÇTİ: $n test") . "\n";
exit($fail ? 1 : 0);
