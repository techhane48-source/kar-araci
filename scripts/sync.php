<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/functions.php';
$options=getopt('',['products-only','days:']);
$days=isset($options['days']) ? filter_var($options['days'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>365]]) : 90;
if ($days===false) { fwrite(STDERR,"--days 1 ile 365 arasında olmalı.\n");exit(1); }
$integrations=db()->query('SELECT user_id,marketplace FROM integrations ORDER BY user_id')->fetchAll();
if (!$integrations) { echo "Bağlı mağaza yok.\n";exit; }
$ok=0;$fail=0;
foreach ($integrations as $int) {
    $tasks=['products'=>static fn()=>sync_products((int)$int['user_id'],$int['marketplace'])];
    if (!array_key_exists('products-only',$options)) {
        $tasks['price-status']=static fn()=>process_price_updates((int)$int['user_id'],$int['marketplace']);
        $tasks['orders']=static fn()=>sync_orders((int)$int['user_id'],$int['marketplace'],$days);
        $tasks['settlements']=static fn()=>sync_settlements((int)$int['user_id'],$int['marketplace'],$days);
        $tasks['service-fees']=static fn()=>sync_platform_service_fees((int)$int['user_id'],$int['marketplace'],$days);
    }
    foreach ($tasks as $task=>$run) {
        $result=$run();
        if ($result['ok']) { $ok++;echo "[OK] u{$int['user_id']} {$int['marketplace']} $task\n"; }
        else { $fail++;echo "[FAIL] u{$int['user_id']} {$int['marketplace']} $task: {$result['error']}\n"; }
    }
}
echo "Bitti: ok=$ok fail=$fail\n";
exit($fail?1:0);
