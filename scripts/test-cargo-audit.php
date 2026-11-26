<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-cargo-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$raw=json_encode(['id'=>'I1','sellerId'=>123,'affiliate'=>'TRENDYOLTR','country'=>'TR','credit'=>0,'debt'=>80]);
$db->prepare("INSERT INTO integration_bindings(user_id,marketplace,seller_id,stage) VALUES(?,?,?,?)")->execute([1,'trendyol','123',1]);
$db->prepare("INSERT INTO trendyol_financial_documents(user_id,external_id,query_type,transaction_type,transaction_date,raw_json) VALUES(?,?,?,?,?,?)")->execute([1,'I1','DeductionInvoices','Kargo Faturası',time(),$raw]);
$db->prepare("INSERT INTO trendyol_cargo_items(user_id,invoice_serial,row_number,parcel_id,order_number,shipment_type,amount,desi) VALUES(?,?,?,?,?,?,?,?)")->execute([1,'I1',0,'001','O1','Gönderi Kargo Bedeli',80,5]);
$r=iterator_to_array(cargo_audit_rows($db,1,cargo_audit_query($db,1,'001')),false)[0];$t=['no expectation'=>cargo_audit_row($r)['state']==='missing'];
cargo_save_expectation($db,1,'I1','001','Gönderi Kargo Bedeli',3,60);
$r=iterator_to_array(cargo_audit_rows($db,1,cargo_audit_query($db,1,'001')),false)[0];$a=cargo_audit_row($r);
$t['observed difference']=$a['state']==='review'&&$a['desi_diff']===2.0&&$a['amount_diff']===20.0;
$t['tenant isolation']=cargo_audit_query($db,2,'001')->fetch()===false;
try{cargo_save_expectation($db,2,'I1','001','Gönderi Kargo Bedeli',3,60);$t['cross user blocked']=false;}catch(InvalidArgumentException $e){$t['cross user blocked']=true;}
$t['foreign invoice withheld']=cargo_audit_row(array_replace($r,['invoice_raw'=>json_encode(['id'=>'I1','affiliate'=>'OTHER','country'=>'TR'])]))['state']==='unknown';
$t['duplicate withheld']=cargo_audit_row(array_replace($r,['duplicate_count'=>2]))['state']==='unknown';
$t['desi only']=cargo_audit_row(array_replace($r,['expected_desi'=>5,'expected_amount'=>null]))['state']==='desi_only';
$net=cargo_audit_row(array_replace($r,['invoice_basis'=>'net']));
$t['net invoice gross comparison']=$net['gross_amount']===96.0&&$net['amount_diff']===36.0;
$unknown=cargo_audit_row(array_replace($r,['invoice_basis'=>null,'expected_desi'=>5]));
$t['unknown VAT no misleading amount difference']=$unknown['state']==='desi_only'&&$unknown['amount_diff']===null;
$t['wrong binding blocked']=cargo_audit_row(array_replace($r,['bound_seller'=>'999']))['state']==='unknown';
foreach($t as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$t,true)?1:0);
