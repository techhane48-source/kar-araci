<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../includes/functions.php';
$job=$argv[1]??'products';
if($job==='auto'){
    set_time_limit(0);
    $result=auto_sync_worker(db(),6);
    if($result['busy']){echo "SKIP worker already running\n";exit(0);}
    $fail=0;foreach($result['jobs'] as $r){if(!$r['ok'])$fail++;echo ($r['ok']?'OK':'FAIL').' user='.$r['uid'].' job='.$r['job']."\n";}
    exit($fail?1:0);
}
if(!in_array($job,['products','orders','finance','claims'],true)){fwrite(STDERR,"Kullanım: php scripts/sync.php auto|products|orders|finance|claims\n");exit(2);}
set_time_limit(0);
$rows=db()->query("SELECT user_id FROM integrations WHERE marketplace='trendyol' ORDER BY user_id")->fetchAll();
$fail=0;
foreach($rows as $row){
    $uid=(int)$row['user_id'];
    if($job==='products')$r=sync_products($uid,'trendyol');
    elseif($job==='orders')$r=sync_orders($uid,'trendyol',7);
    elseif($job==='claims')$r=sync_trendyol_claims($uid,7);
    else{
        $r=sync_settlements($uid,'trendyol',30);
        if($r['ok'])$r=sync_trendyol_documents($uid,30);
    }
    if(!$r['ok'])$fail++;
    echo ($r['ok']?'OK':'FAIL')." user=$uid job=$job\n";
}
exit($fail?1:0);
