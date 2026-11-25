<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/profit.php';
$p=['marketplace'=>'trendyol','cost'=>400,'price'=>717.74,'cargo'=>65,'rate'=>19,'kdv'=>20,'cost_vat'=>20,'service_fee'=>13.19,'stopaj_mode'=>'auto','stopaj_rate'=>1];
$r=product_profit($p);
$tests=['reference net profit'=>abs($r['profit']-80)<0.005,'stopaj recalculated'=>abs(product_profit($p,1000)['stopaj']-1000/120)<0.00001];
$p['cost_vat']=10;
$tests['separate purchase VAT']=product_profit($p)['profit']<$r['profit'];
$p['stopaj_mode']='manual';$p['stopaj']=0;
$tests['manual zero preserved']=product_profit($p)['stopaj']===0.0;
$target=product_target_price($p,80,0);
$tests['inverse fixed stopaj']= $target['ok'] && abs(product_profit($p,$target['price'])['profit']-80)<0.00001;
$p['kdv']=0;$p['cost_vat']=0;
$tests['zero VAT stays zero']=product_profit($p)['net_kdv']===0.0;
foreach($tests as $k=>$ok) echo ($ok?'PASS':'FAIL')." $k\n";
exit(in_array(false,$tests,true)?1:0);
