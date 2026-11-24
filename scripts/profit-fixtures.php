<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/profit.php';
$cases=[];
foreach (['','trendyol'] as $marketplace) foreach ([0,10,20] as $vat) foreach (['manual','auto'] as $mode) foreach ([0,20,90] as $margin) {
    $p=['marketplace'=>$marketplace,'cost'=>200.0,'price'=>400.0,'cargo'=>50.0,'rate'=>21.36,'kdv'=>$vat,'extra_fee'=>4.0,
        'service_fee'=>13.19,'stopaj'=>3.0,'return_rate'=>2.0,'stopaj_mode'=>$mode];
    $input=$p+['targetProfit'=>50.0,'targetMargin'=>$margin];
    $direct=$p;
    if ($mode==='auto') $direct['stopaj']=auto_stopaj($p['price'],$vat);
    $cases[]=['input'=>$input,'direct'=>$direct,'profit'=>calc_product_profit($p),'price'=>calc_product_price($p,50,$margin)];
}
echo json_encode($cases,JSON_THROW_ON_ERROR);
