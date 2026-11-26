<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-bank-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$q=$db->prepare("INSERT INTO trendyol_payouts(user_id,seller_id,payment_order_id,payout_date,amount,raw_json) VALUES(?,?,?,?,?,?)");$q->execute([1,'123','10',time(),100,'{}']);$q->execute([1,'123','11',time(),70,'{}']);
$db->prepare("INSERT INTO integration_bindings(user_id,marketplace,seller_id,stage) VALUES(?,?,?,?)")->execute([1,'trendyol','123',1]);
$day='2025-01-01';bank_receipt_save($db,1,'123','10','A',$day,'40');bank_receipt_save($db,1,'123','10','A',$day,'40');bank_receipt_save($db,1,'123','10','B',$day,'60');
$r=bank_receipt_summary(100,$db->query("SELECT * FROM payout_receipts")->fetchAll());
$t=['split receipts sum'=>$r['count']===2&&$r['difference']===0.0&&!$r['bank_verified'],'missing receipts unknown'=>bank_receipt_summary(100,[])['entered']===null];
try{bank_receipt_save($db,1,'123','11','A',$day,'40');$t['duplicate across payments']=false;}catch(InvalidArgumentException $e){$t['duplicate across payments']=true;}
try{bank_receipt_save($db,2,'123','10','C',$day,'40');$t['tenant isolation']=false;}catch(InvalidArgumentException $e){$t['tenant isolation']=true;}
$header=['Ödeme emri','Dekont referansı','Tarih','Tutar (TL)'];
$batch=bank_receipt_import_values($header,[['11','C',$day,'30'],['11','D',$day,'40']]);
$t['batch save and retry']=bank_receipt_save_batch($db,1,'123',$batch)['inserted']===2&&bank_receipt_save_batch($db,1,'123',$batch)['unchanged']===2;
try{bank_receipt_save_batch($db,1,'123',bank_receipt_import_values($header,[['11','E',$day,'10'],['UNKNOWN','F',$day,'20']]));$t['atomic rollback']=false;}catch(InvalidArgumentException $e){$t['atomic rollback']=(int)$db->query("SELECT COUNT(*) FROM payout_receipts WHERE reference='E'")->fetchColumn()===0;}
try{bank_receipt_import_values($header,[['11','E',$day,'10'],['11','E',$day,'10']]);$t['duplicate upload rejected']=false;}catch(InvalidArgumentException $e){$t['duplicate upload rejected']=true;}
$po=['amount'=>100,'checked_at'=>time(),'summary_json'=>json_encode(['candidate_check'=>['version'=>2,'complete_inputs'=>true,'three_way_match'=>true,'official'=>100,'candidate'=>100,'statement_payment'=>100]])];
$receipts=$db->query("SELECT * FROM payout_receipts WHERE payment_order_id='10'")->fetchAll();
$ev=bank_payout_evidence($po,$receipts);
$t['four observed amounts match not bank verified']=$ev['comparison_state']==='observed_match'&&!$ev['bank_verified']&&!$ev['final_profit_verified'];
$t['stale official amount invalidates check']=bank_payout_evidence(array_replace($po,['amount'=>101]),$receipts)['comparison_state']==='provider_unverified';
$t['missing receipt distinct']=bank_payout_evidence($po,[])['comparison_state']==='receipt_missing';
foreach($t as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";exit(in_array(false,$t,true)?1:0);
