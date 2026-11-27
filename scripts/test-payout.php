<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/profit.php';
function row($id,$type,$debt,$credit,$payment='123') {return ['externalId'=>$id,'queryType'=>$type,'paymentOrderId'=>$payment,'raw'=>['debt'=>$debt,'credit'=>$credit]];}
$a=row('1','Sale',0,100);$b=row('2','Return',20,0);$c=row('3','PaymentOrder',75,0);$d=row('4','Stoppage',5,0);
$r=payout_components([$a,$a,$b],[$c,$d],'123');
$tests=['duplicate not counted'=>$r['settlement_rows']===2,'sale credit'=>$r['settlement_credit']===100.0,'return debt'=>$r['settlement_debt']===20.0,'payment separate'=>$r['reported_payment']===75.0 && $r['documents_debt']===5.0];
try{payout_components([row('9','Sale',0,5,'999')],[],'123');$tests['wrong payout rejected']=false;}catch(InvalidArgumentException $e){$tests['wrong payout rejected']=true;}
$product=['A'=>['cost'=>150,'kdv'=>20,'cargo'=>10,'rate'=>19]];
$order=['status'=>'Delivered','total_price'=>300,'lines'=>[['barcode'=>'A','quantity'=>1,'price'=>300,'cost_snapshot'=>100,'cost_vat_snapshot'=>10]]];
$before=calc_order_profit($order,$product);$product['A']['cost']=250;$after=calc_order_profit($order,$product);
$tests['snapshot cost stable']=$before['ok']&&$after['ok']&&$before['cost']===100.0&&$before['profit']===$after['profit'];
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
