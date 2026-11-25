<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/profit.php';
$products=['001'=>['name'=>'Perde','category'=>'Ev','cost'=>999]];
$order=['marketplace'=>'trendyol','currency_code'=>'TRY','country_code'=>'TR','status'=>'Delivered','total_price'=>300,'lines'=>[
 ['barcode'=>'001','quantity'=>2,'price'=>100,'cost_snapshot'=>50],
 ['barcode'=>'001','quantity'=>1,'price'=>100],
]];
$r=aggregate_report_lines([$order],$products);$g=$r['groups']['001'];
$tests=['partial coverage'=>$g['sale']===300.0&&$g['known_sale']===200.0&&$g['missing_qty']===1,'uses observed cost'=>$g['cost']===100.0,'does not use unknown cost'=>$g['known_sale']-$g['cost']===100.0];
$bad=$order;$bad['total_price']=299;$r=aggregate_report_lines([$bad],$products);$tests['mismatched package excluded']=$r['excluded']===1&&!$r['groups'];
$bad=$order;$bad['lines'][0]['platform_discount']=10;$tests['unreconciled platform discount excluded']=report_order_lines($bad,$products)===null;
$bad=$order;$bad['status']='Returned';$tests['return excluded']=report_order_lines($bad,$products)===null;
foreach($tests as $k=>$ok)echo ($ok?'PASS':'FAIL')." $k\n";
exit(in_array(false,$tests,true)?1:0);
