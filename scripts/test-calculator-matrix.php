<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/profit.php';
$fail=0;$count=0;
function assert_case(bool $ok,string $name):void{global $fail,$count;$count++;if(!$ok){$fail++;echo "FAIL $name\n";}}
function check_near(float $actual,float $expected,float $tol,string $name):void{assert_case(abs($actual-$expected)<=$tol,$name);}
$ref=calc_profit(400,717.74,65,19,20,0,13.19,auto_stopaj(717.74));
foreach(['profit'=>80,'commission'=>136.37,'net_kdv'=>17.20,'stopaj'=>5.98,'margin'=>11.15,'ratio'=>20] as $k=>$v)check_near($ref[$k],$v,0.0051,'public example '.$k);
$discount=round(717.74*.9,2);$disc=calc_profit(400,$discount,65,19,20,0,13.19,auto_stopaj($discount));
check_near($disc['profit'],32.15,.011,'discount example (one kuruş display tolerance)');
foreach([[400,65,19,0,597.74],[1000,100,20,250,1788.85],[1000,100,23.5,250,1871.78]] as [$c,$g,$r,$target,$expected]){
  $v=calc_price($c,$g,$r,20,0,13.19,1,0,$target);
  assert_case($v['ok'],'public target feasible');check_near($v['price'],$expected,.00001,'safe minimum price');
}
$rows=[]; // Deterministic parity fixtures for JS. All are hypothetical, not competitor data.
foreach([0,1,10,20] as $vat)foreach([0,10,20] as $purchase)foreach([0,19,45,90] as $rate)
foreach([false,true] as $negative)foreach([0,8,65] as $margin){
  $cost=123.45;$cargo=27.50;$service=13.19;$extra=4.25;$ret=3;$stopRate=1;
  $opts=['sale_vat'=>$vat,'cost_vat'=>$purchase,'commission_vat'=>20,'cargo_vat'=>20,'extra_vat'=>20,'service_vat'=>20,'allow_negative_vat'=>$negative];
  $tp=$margin>0?0:25;
  $r=calc_price($cost,$cargo,$rate,$vat,$extra,$service,$stopRate,$ret,$tp,$margin,$opts);
  $at=static fn($price)=>calc_profit($cost,$price,$cargo,$rate,$vat,$extra,$service,auto_stopaj($price,$vat,$stopRate),$ret,$opts)['profit'];
  if($r['ok']){
    assert_case(abs($r['price']*100-round($r['price']*100))<.00001,'cent grid');
    $target=$margin>0?$r['price']*$margin/100:$tp;
    assert_case($at($r['price'])>=$target-1e-8,'target satisfied');
    $prev=$r['price']-.01;
    assert_case($prev<=0||$at($prev)<($margin>0?$prev*$margin/100:$tp),'previous cent fails');
  }
  $rows[]=['cost'=>$cost,'cargo'=>$cargo,'rate'=>$rate,'vat'=>$vat,'purchase'=>$purchase,'extra'=>$extra,'service'=>$service,'ret'=>$ret,'stopRate'=>$stopRate,'negative'=>$negative,'tp'=>$tp,'margin'=>$margin,'price'=>$r['ok']?$r['price']:null,'profit'=>$r['ok']?$r['profit']:null];
}
// This is feasible below the VAT-floor peak, even though the large-price objective is negative.
$peak=minimum_price_cents(static fn(float $x)=>min($x-1,3-$x),2);
assert_case($peak===100,'bounded feasible region before VAT breakpoint');
assert_case(minimum_price_cents(static fn(float $x)=>-1.0,null)===null,'impossible rejected');
if(isset($argv[1]))file_put_contents($argv[1],json_encode($rows,JSON_THROW_ON_ERROR));
echo "$count assertions; $fail failures; ".count($rows)." hypothetical cases.\n";
exit($fail?1:0);
