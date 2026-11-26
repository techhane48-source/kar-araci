<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-claims-'.bin2hex(random_bytes(8));mkdir($dir,0700);
define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/trendyol.php';
$claim=['id'=>'claim-a','orderNumber'=>'0001','orderOutboundPackageId'=>123,'orderShipmentPackageId'=>456,'claimDate'=>1700000000000,'lastModifiedDate'=>1700000001000,'customerFirstName'=>'PRIVATE','items'=>[
 ['orderLine'=>['barcode'=>'001','productName'=>'Perde'],'claimItems'=>[
  ['id'=>'item-a','orderLineItemId'=>12,'claimItemStatus'=>['name'=>'Accepted'],'customerClaimItemReason'=>['name'=>'Beden'],'autoAccepted'=>true],
  ['id'=>'item-b','orderLineItemId'=>12,'claimItemStatus'=>['name'=>'Rejected'],'customerNote'=>'PRIVATE']
 ]]
]];
$tests=[];$rows=normalize_claim($claim);
$tests['separate returned units']=count($rows)===2&&$rows[0]['status']==='Accepted'&&$rows[1]['status']==='Rejected';
$tests['no customer data retained']=!str_contains(json_encode($rows),'PRIVATE');
$client=new TrendyolClient('123','fake','fake',true,static fn()=>['http'=>200,'body'=>['totalElements'=>1,'totalPages'=>1,'content'=>[$claim]]]);
$res=$client->getClaims(1700000000,1700000100);
$tests['API fixture accepted']=$res['ok']&&count($res['items'])===2;
$client=new TrendyolClient('123','fake','fake',true,static fn()=>['http'=>200,'body'=>['totalElements'=>2,'totalPages'=>1,'content'=>[$claim]]]);
$tests['truncated claims rejected']=!$client->getClaims(1700000000,1700000100)['ok'];
$db=db();persist_claims($db,1,$rows);persist_claims($db,1,$rows);
$tests['idempotent']=(int)$db->query("SELECT COUNT(*) FROM trendyol_claim_items")->fetchColumn()===2;
$new=$rows[0];$new['modified_ms']++;$new['status']='Cancelled';persist_claims($db,1,[$new]);persist_claims($db,1,[$rows[0]]);
$tests['older status cannot overwrite']=$db->query("SELECT status FROM trendyol_claim_items WHERE item_id='item-a'")->fetchColumn()==='Cancelled';
persist_claims($db,2,$rows);$tests['tenant isolation']=(int)$db->query("SELECT COUNT(*) FROM trendyol_claim_items WHERE user_id=2")->fetchColumn()===2;
$order=['package_number'=>'123','order_number'=>'0001','currency_code'=>'TRY','country_code'=>'TR','lines'=>[['line_id'=>'12','barcode'=>'001','cost_snapshot'=>45]]];
$match=claim_order_match($rows[0],$order);$tests['exact outbound line match']=$match['cost']===45.0;
$order['package_number']='456';$tests['return package not treated as outbound']=claim_order_match($rows[0],$order)['cost']===null;
$order['package_number']='123';$order['currency_code']='EUR';$tests['foreign cost not TL']=claim_order_match($rows[0],$order)['cost']===null;
$groupClaim=$claim;$groupClaim['items'][0]['orderLine']['id']=777;
$groupClaim['items'][0]['claimItems'][0]['orderLineItemId']=888;
$groupRow=normalize_claim($groupClaim)[0];
$tests['group and unit identities separate']=$groupRow['group_line_id']==='777'&&$groupRow['line_id']==='888';
$order=['package_number'=>'123','order_number'=>'0001','currency_code'=>'TRY','country_code'=>'TR','lines'=>[['line_id'=>'777','barcode'=>'001','cost_snapshot'=>45,'discount_details'=>[['lineItemId'=>'888']]]]];
$tests['group and unit match']=claim_order_match($groupRow,$order)['cost']===45.0;
$tests['conflicting unit rejected']=claim_order_match(array_replace($groupRow,['line_id'=>'999']),$order)['cost']===null;
$tests['conflicting group rejected']=claim_order_match(array_replace($groupRow,['group_line_id'=>'999']),$order)['cost']===null;
$groupRow['modified_ms']+=2;persist_claims($db,1,[$groupRow]);
$tests['group identity persisted']=$db->query("SELECT group_line_id FROM trendyol_claim_items WHERE user_id=1 AND item_id='item-a'")->fetchColumn()==='777';
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
