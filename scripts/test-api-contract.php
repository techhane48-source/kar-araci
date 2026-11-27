<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/trendyol.php';
require_once __DIR__.'/../includes/profit.php';
$tests=[];$requests=[];
$package=static function(int $modified,string $status):array {
 return ['id'=>123,'orderNumber'=>'1234','orderCountryCode'=>'TR','currencyCode'=>'TRY','packageTotalPrice'=>300,'lastModifiedDate'=>$modified,'orderDate'=>1762253333685,'status'=>$status,'lines'=>[['barcode'=>'001','quantity'=>2,'lineUnitPrice'=>150,'lineGrossAmount'=>160,'lineSellerDiscount'=>10,'lineTyDiscount'=>0,'lineId'=>1,'vatRate'=>20,'commission'=>19,'orderLineItemStatusName'=>$status]]];
};
$client=new TrendyolClient('123','synthetic','synthetic',true,static function($method,$path,$query,$body)use(&$requests,$package){
 $requests[]=[$method,$path,$query,$body];
 if(str_ends_with($path,'/orders/stream')){
  if(!isset($query['nextCursor']))return ['http'=>200,'body'=>['hasMore'=>true,'nextCursor'=>'opaque-01','content'=>[$package(1762253333686,'Created')]]];
  return ['http'=>200,'body'=>['hasMore'=>false,'content'=>[$package(1762253333687,'Delivered')]]];
 }
 return ['http'=>401];
});
$r=$client->getOrders('','2025-11-04T00:00:00+03:00','2025-11-05T00:00:00+03:00');
$tests['cursor carries unchanged filters']=$r['ok']&&count($requests)===2&&$requests[1][2]['nextCursor']==='opaque-01'&&array_diff_key($requests[1][2],['nextCursor'=>true])===$requests[0][2];
$tests['newest package wins']=$r['count']===1&&$r['orders'][0]['status']==='Delivered';
$tests['currency country and barcode parsed']=$r['orders'][0]['currencyCode']==='TRY'&&$r['orders'][0]['orderCountryCode']==='TR'&&$r['orders'][0]['lines'][0]['barcode']==='001';
$requests=[];$res=$client->testConnection();$tests['no cross environment authentication']=!$res['ok']&&count($requests)===1;
$products=['001'=>['cost'=>100,'kdv'=>20,'cargo'=>10,'rate'=>19]];
$o=['marketplace'=>'trendyol','currency_code'=>'TRY','country_code'=>'TR','status'=>'Delivered','total_price'=>299.99,'lines'=>[['barcode'=>'001','quantity'=>2,'price'=>150,'cost_snapshot'=>100,'cost_vat_snapshot'=>20,'discount_details'=>[['lineItemPrice'=>149.99],['lineItemPrice'=>150]]]]];
$tests['multi-unit no package cargo withheld']=!calc_order_profit($o,$products)['ok'];
$o['cargo_override']=10.0;$o['cargo_source']='Paket tahmini';
$tests['item level discount sum']=calc_order_profit($o,$products)['ok'];
$o['currency_code']='EUR';$tests['foreign currency excluded']=!order_is_active_sale($o)&&!calc_order_profit($o,$products)['ok'];
$o['currency_code']=null;$tests['unknown currency excluded']=!order_is_active_sale($o);
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
