<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-cargo-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$raw=json_encode(['id'=>'I1','affiliate'=>'TRENDYOLTR','country'=>'TR']);
$db->prepare("INSERT INTO trendyol_financial_documents(user_id,external_id,query_type,transaction_type,transaction_date,raw_json) VALUES(?,?,?,?,?,?)")->execute([1,'I1','DeductionInvoices','Kargo Faturası',time(),$raw]);
$db->prepare("INSERT INTO trendyol_cargo_items(user_id,invoice_serial,row_number,parcel_id,order_number,shipment_type,amount,desi) VALUES(?,?,?,?,?,?,?,?)")->execute([1,'I1',0,'001','O1','Gönderi Kargo Bedeli',80,5]);
$r=cargo_audit_query($db,1,'001')->fetch();$t=['no expectation'=>cargo_audit_row($r)['state']==='missing'];
cargo_save_expectation($db,1,'I1','001','Gönderi Kargo Bedeli',3,60);
$r=cargo_audit_query($db,1,'001')->fetch();$a=cargo_audit_row($r);
$t['observed difference']=$a['state']==='review'&&$a['desi_diff']===2.0&&$a['amount_diff']===20.0;
$t['tenant isolation']=cargo_audit_query($db,2,'001')->fetch()===false;
try{cargo_save_expectation($db,2,'I1','001','Gönderi Kargo Bedeli',3,60);$t['cross user blocked']=false;}catch(InvalidArgumentException $e){$t['cross user blocked']=true;}
$t['foreign invoice withheld']=cargo_audit_row(array_replace($r,['invoice_raw'=>json_encode(['id'=>'I1','affiliate'=>'OTHER','country'=>'TR'])]))['state']==='unknown';
$t['duplicate withheld']=cargo_audit_row(array_replace($r,['duplicate_count'=>2]))['state']==='unknown';
$t['desi only']=cargo_audit_row(array_replace($r,['expected_desi'=>5,'expected_amount'=>null]))['state']==='desi_only';
foreach($t as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$t,true)?1:0);
