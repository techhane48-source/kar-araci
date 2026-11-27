<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-returns-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$now=time();$p=['marketplace'=>'trendyol','order_number'=>'O1','package_number'=>'P1','currency_code'=>'TRY','country_code'=>'TR','order_date'=>$now-86400,'lines'=>[['line_id'=>'L1','barcode'=>'001','quantity'=>2,'cost_snapshot'=>50]]];
$c=['claim_id'=>'C1','item_id'=>'I1','order_number'=>'O1','outbound_package'=>'P1','return_package'=>'R1','line_id'=>'L1','barcode'=>'001','product_name'=>'Perde','status'=>'Accepted','reason'=>'','platform_reason'=>'','auto_accepted'=>null,'seller_accepted'=>null,'created_ms'=>$now*1000-1000,'modified_ms'=>$now*1000];
$db->prepare("INSERT INTO orders(user_id,marketplace,order_number,package_number,status,total_price,order_date,lines,currency_code,country_code) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([1,'trendyol','O1','P1','Delivered',200,$p['order_date'],json_encode($p['lines']),'TRY','TR']);
persist_claims($db,1,[$c]);$t=[];
$t['no depot inspection unknown']=return_inventory_evidence($c,$p,1,null)['inventory_loss']===null;
return_inventory_save($db,1,'I1','resellable','');$saved=$db->query("SELECT * FROM return_inventory_checks")->fetch();
$t['resellable zero inventory impairment']=return_inventory_evidence($c,$p,1,$saved)['inventory_loss']===0.0;
return_inventory_save($db,1,'I1','partial','20,00');$saved=$db->query("SELECT * FROM return_inventory_checks")->fetch();
$t['partial recovery loss']=return_inventory_evidence($c,$p,1,$saved)['inventory_loss']===30.0;
$t['legacy group identity unverified']=return_inventory_evidence($c,$p,1,array_replace($saved,['group_line_id'=>null]))['inventory_loss']===null;
$t['wrong item identity withheld']=return_inventory_evidence($c,$p,1,array_replace($saved,['item_id'=>'OTHER']))['inventory_loss']===null;
$t['batch lookup scoped']=count(return_inventory_checks($db,1,[$c,$c]))===1&&return_inventory_checks($db,2,[$c])===[];
$t['group identity captured']=$saved['group_line_id']==='';
$grouped=$c;$grouped['group_line_id']='L1';
$t['group identity change with same timestamp blocked']=return_inventory_evidence($grouped,$p,1,$saved)['inventory_loss']===null;
$db->exec("UPDATE return_inventory_checks SET group_line_id=NULL WHERE user_id=1 AND item_id='I1'");
return_inventory_save($db,1,'I1','partial','20,00');
$t['legacy inspection explicitly refreshed']=return_inventory_evidence($c,$p,1,return_inventory_checks($db,1,[$c])['I1'])['inventory_loss']===30.0;
$t['overclaim blocks loss']=return_inventory_evidence($c,$p,3,$saved)['inventory_loss']===null;
$t['changed claim blocks loss']=return_inventory_evidence(array_replace($c,['modified_ms'=>$c['modified_ms']+1]),$p,1,$saved)['inventory_loss']===null;
$changed=$p;$changed['lines'][0]['cost_snapshot']=51;$t['changed cost blocks loss']=return_inventory_evidence($c,$changed,1,$saved)['inventory_loss']===null;
try{return_inventory_save($db,2,'I1','unusable','');$t['tenant isolation']=false;}catch(InvalidArgumentException $e){$t['tenant isolation']=true;}
try{return_inventory_save($db,1,'I1','partial','55');$t['invalid recovery rejected']=false;}catch(InvalidArgumentException $e){$t['invalid recovery rejected']=true;}
persist_claims($db,1,[array_replace($c,['item_id'=>'I2']),array_replace($c,['item_id'=>'I3'])]);
try{return_inventory_save($db,1,'I1','unusable','');$t['all accepted units checked']=false;}catch(InvalidArgumentException $e){$t['all accepted units checked']=true;}
$group=$c;$group['group_line_id']='L1';$group['line_id']='UNIT1';$group['item_id']='I4';
$p['lines'][0]['discount_details']=[['lineItemId'=>'UNIT1'],['lineItemId'=>'UNIT2']];
$t['group identity inventory match']=return_inventory_evidence($group,$p,1,null)['cost']===50.0;
persist_claims($db,1,[$group,array_replace($group,['item_id'=>'I5','line_id'=>'UNIT2'])]);
$counts=return_accepted_counts($db,1,[$group]);
$t['group count includes ambiguous legacy rows conservatively']=$counts[return_inventory_key($group)]===5;
$other=array_replace($group,['item_id'=>'I6','group_line_id'=>'L2','line_id'=>'UNIT3']);persist_claims($db,1,[$other]);
$counts=return_accepted_counts($db,1,[$group,$other,$c]);
$t['same barcode distinct groups not merged']=$counts[return_inventory_key($group)]===5&&$counts[return_inventory_key($other)]===4;
// Legacy claims intentionally count all groups of the same barcode; their keys must remain separate.
$t['legacy key remains separate and conservative']=count($counts)===3&&$counts[return_inventory_key($c)]===6;
foreach($t as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";exit(in_array(false,$t,true)?1:0);
