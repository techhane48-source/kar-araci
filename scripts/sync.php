<?php
declare(strict_types=1);

/**
 * Cron / CLI senkronizasyonu: tüm kullanıcıların bağlı mağazalarındaki ürünleri çeker.
 * Kullanım (cron'a saatlik ekleyin):
 *   php /yol/scripts/sync.php
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
    $res = sync_products((int)$i['user_id'], $i['marketplace']);
    if ($res['ok']) {
        $ok++;
        echo "[OK] u{$i['user_id']} {$i['marketplace']}: +{$res['inserted']} yeni, {$res['updated']} güncellendi, {$res['skipped']} atlandı\n";
    } else {
        $fail++;
        echo "[FAIL] u{$i['user_id']} {$i['marketplace']}: {$res['error']}\n";
    }
}

echo "Bitti: ok={$ok} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
