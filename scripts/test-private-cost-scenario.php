<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/private_cost_scenario.php';
$c=[['barcode'=>'001','title'=>'Synthetic product'],['barcode'=>'002','title'=>'Other product']];
$o=['packageNumber'=>'P','currencyCode'=>'TRY','orderCountryCode'=>'TR','status'=>'Delivered','totalPrice'=>500,'lines'=>[['barcode'=>'001','line_id'=>'1','quantity'=>2,'price'=>250]]];
$r=private_cost_scenario($c,[$o],['001'],100);
$t=['cost quantity and gross difference'=>$r['units']===2&&$r['hypothetical_gross_cost']===200.0&&$r['gross_difference_before_deductions']===300.0&&$r['net_profit']===null&&!$r['historical_cost_verified']];
$m=$o;$m['lines'][]=['barcode'=>'002','line_id'=>'2','quantity'=>1,'price'=>50];$m['totalPrice']=550;
$r=private_cost_scenario($c,[$m],['001'],100);
$t['mixed package excluded']=$r['mixed_packages_excluded']===1&&$r['gross_sales']===null;
$r=private_cost_scenario($c,[array_replace($o,['status'=>'Returned'])],['001'],100);
$t['returned package excluded']=$r['other_packages_excluded']===1&&$r['net_profit']===null;
try{private_cost_scenario($c,[$o],['unknown'],100);$t['unknown barcode rejected']=false;}catch(InvalidArgumentException $e){$t['unknown barcode rejected']=true;}
$t['preview does not assign cost']=count(private_cost_candidates($c,'synthetic'))===1;
foreach($t as $name=>$ok)echo ($ok?'PASS':'FAIL')." $name\n";
exit(in_array(false,$t,true)?1:0);
