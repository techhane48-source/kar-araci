<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-bank-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$q=$db->prepare("INSERT INTO trendyol_payouts(user_id,seller_id,payment_order_id,payout_date,amount,raw_json) VALUES(?,?,?,?,?,?)");$q->execute([1,'123','10',time(),100,'{}']);$q->execute([1,'123','11',time(),70,'{}']);
$day='2025-01-01';bank_receipt_save($db,1,'123','10','A',$day,'40');bank_receipt_save($db,1,'123','10','A',$day,'40');bank_receipt_save($db,1,'123','10','B',$day,'60');
$r=bank_receipt_summary(100,$db->query("SELECT * FROM payout_receipts")->fetchAll());
$t=['split receipts sum'=>$r['count']===2&&$r['difference']===0.0&&!$r['bank_verified'],'missing receipts unknown'=>bank_receipt_summary(100,[])['entered']===null];
try{bank_receipt_save($db,1,'123','11','A',$day,'40');$t['duplicate across payments']=false;}catch(InvalidArgumentException $e){$t['duplicate across payments']=true;}
try{bank_receipt_save($db,2,'123','10','C',$day,'40');$t['tenant isolation']=false;}catch(InvalidArgumentException $e){$t['tenant isolation']=true;}
foreach($t as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";exit(in_array(false,$t,true)?1:0);
