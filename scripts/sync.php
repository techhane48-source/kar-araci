<?php
declare(strict_types=1);

/**
 * Cron / CLI senkronizasyonu (saatlik önerilir):
 *   - ürünler (fiyat, stok)        her çalışmada
 *   - siparişler (son 3 gün)        her çalışmada → kâr yeniden hesaplanır
 *   - finans (ekstre + kargo fat.)  gece 01:00–05:59 arası çalışmalarda, son 30 gün
 * Kullanım:  php /yol/scripts/sync.php            (otomatik)
 *            php /yol/scripts/sync.php --finance  (finansı saatten bağımsız zorla)
 */

require_once __DIR__ . '/../includes/functions.php';

$integrations = db()->query("SELECT user_id, marketplace FROM integrations ORDER BY user_id")->fetchAll();

if (!$integrations) {
    echo "Bağlı mağaza yok.\n";
    exit(0);
}

$ok = 0;
$fail = 0;
foreach ($integrations as $i) {
    $uid = (int)$i['user_id']; $mp = $i['marketplace'];
    $res = sync_products($uid, $mp);
    if ($res['ok']) {
        $ok++;
        echo "[OK] u{$uid} {$mp} ürün: +{$res['inserted']} yeni, {$res['updated']} güncellendi" . (!empty($res['warning']) ? " [UYARI] {$res['warning']}" : '') . "\n";
    } else {
        $fail++;
        echo "[FAIL] u{$uid} {$mp} ürün: {$res['error']}\n";
        continue;
    }
    $o = sync_orders($uid, $mp, 3);
    echo ($o['ok'] ? "[OK]" : "[FAIL]") . " u{$uid} {$mp} sipariş: " . ($o['ok'] ? "{$o['count']} paket" : $o['error']) . (!empty($o['warning']) ? " [UYARI] {$o['warning']}" : '') . "\n";
    $hour = (int)date('G');
    if (in_array('--finance', $argv, true) || ($hour >= 1 && $hour <= 5)) {
        $f = sync_finance($uid, $mp, 30);
        echo "[FIN] u{$uid} {$mp}: ekstre +{$f['settlements']['records']}, kargo faturası " . ($f['cargo']['invoices'] ?? 0)
           . ", yeniden hesaplanan {$f['recompute']['count']} (kesin {$f['recompute']['final']})"
           . (($f['settlements']['error'] ?? null) || ($f['cargo']['error'] ?? null) ? " [UYARI] " . implode(' · ', array_filter([$f['settlements']['error'] ?? null, $f['cargo']['error'] ?? null])) : '') . "\n";
    }
}

echo "Bitti: ok={$ok} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
