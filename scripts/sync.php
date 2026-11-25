<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../includes/functions.php';
$job=$argv[1]??'products';
if(!in_array($job,['products','orders','finance'],true)){fwrite(STDERR,"Kullanım: php scripts/sync.php products|orders|finance\n");exit(2);}
set_time_limit(0);
$rows=db()->query("SELECT user_id FROM integrations WHERE marketplace='trendyol' ORDER BY user_id")->fetchAll();
$fail=0;
foreach($rows as $row){
    $uid=(int)$row['user_id'];
    if($job==='products')$r=sync_products($uid,'trendyol');
    elseif($job==='orders')$r=sync_orders($uid,'trendyol',7);
    else{
        $r=sync_settlements($uid,'trendyol',30);
        if($r['ok'])$r=sync_trendyol_documents($uid,30);
    }
    if(!$r['ok'])$fail++;
    echo ($r['ok']?'OK':'FAIL')." user=$uid job=$job\n";
}
exit($fail?1:0);
