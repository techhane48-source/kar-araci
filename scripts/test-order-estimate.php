<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/profit.php';
$products=['A'=>['cost'=>100,'kdv'=>20,'rate'=>30,'cargo'=>50],'B'=>['cost'=>60,'kdv'=>10,'rate'=>20,'cargo'=>20]];
$o=['total_price'=>300,'status'=>'Delivered','lines'=>[
 ['barcode'=>'A','quantity'=>1,'price'=>200,'commission_rate'=>10,'vat_rate'=>20],
 ['barcode'=>'B','quantity'=>1,'price'=>100,'commission_rate'=>15,'vat_rate'=>10]
]];
$r=calc_order_profit($o,$products);
$tests=['estimate returned'=>$r['ok']&&$r['estimated'],'order commission used'=>abs($r['commission']-35)<0.00001,
    'mixed stopaj'=>abs($r['stopaj']-(200/1.2+100/1.1)*.01)<0.00001];
$c=$o;$c['status']='Cancelled';$tests['cancelled excluded']=!calc_order_profit($c,$products)['ok'];
$c=$o;$c['lines'][0]['platform_discount']=10;$tests['unreconciled platform subsidy']=!calc_order_profit($c,$products)['ok'];
$c=$o;$c['total_price']=999;$tests['mismatched totals']=!calc_order_profit($c,$products)['ok'];
$c=$o;unset($c['lines'][0]['price']);$tests['missing price']=!calc_order_profit($c,$products)['ok'];
$c=$o;$c['lines'][0]['quantity']=1.5;$tests['fractional quantity rejected']=!calc_order_profit($c,$products)['ok'];
$c=$o;$c['lines'][0]['commission_rate']='invalid';$tests['malformed commission rejected']=!calc_order_profit($c,$products)['ok'];
$c=$o;$c['lines'][0]['line_id']='L1';$c['lines'][1]['line_id']='L1';$tests['duplicate line rejected']=!calc_order_profit($c,$products)['ok'];
$bad=$products;$bad['A']['cargo']=-50;$tests['negative cargo rejected']=!calc_order_profit($o,$bad)['ok'];
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
