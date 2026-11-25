<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-period-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/trendyol.php';
$db=db();$now=time();$raw=['id'=>'F1','sellerId'=>123,'transactionDate'=>($now-1)*1000,'affiliate'=>'TRENDYOLTR','country'=>'Türkiye','credit'=>0,'debt'=>13.19];
$row=['externalId'=>'F1','raw'=>$raw];$t=[];
$t['filtered subset']=service_fee_ids([$row],[$row],'123')===['F1'=>true];
try{service_fee_ids([$row],[array_replace($row,['raw'=>array_replace($raw,['debt'=>14])])],'123');$t['changed amount rejected']=false;}catch(InvalidArgumentException $e){$t['changed amount rejected']=true;}
try{service_fee_ids([],[$row],'123');$t['unmatched filtered invoice rejected']=false;}catch(InvalidArgumentException $e){$t['unmatched filtered invoice rejected']=true;}
$requests=[];$client=new TrendyolClient('123','fake','fake',true,static function($m,$p,$q,$b)use(&$requests,$raw){$requests[]=$q;return ['http'=>200,'body'=>['content'=>[$raw],'totalPages'=>1,'totalElements'=>1]];});
$res=$client->getSettlements('DeductionInvoices',date('c',$now-10),date('c',$now),2000,'otherfinancials',null,'PlatformServiceFee');
$t['documented filter passed']=$res['ok']&&$requests[0]['transactionSubType']==='PlatformServiceFee';
$t['invalid filter rejected']=!$client->getSettlements('Sale','','',2000,'settlements',null,'PlatformServiceFee')['ok']&&count($requests)===1;
$db->prepare("INSERT INTO integration_bindings(user_id,marketplace,seller_id,stage) VALUES(?,?,?,?)")->execute([1,'trendyol','123',1]);
$ins=$db->prepare("INSERT INTO trendyol_financial_documents(user_id,external_id,query_type,transaction_type,transaction_date,raw_json,service_fee_verified) VALUES(?,?,?,?,?,?,?)");
$ins->execute([1,'F1','DeductionInvoices','Hizmet faturası',$now-1,json_encode($raw),1]);
$ins->execute([1,'F2','Stoppage','Stopaj',$now-1,json_encode(array_replace($raw,['id'=>'F2','debt'=>2])),null]);
$ins->execute([1,'F3','DeductionInvoices','Reklam',$now-1,json_encode(array_replace($raw,['id'=>'F3','debt'=>50])),0]);
$ins->execute([1,'F4','DeductionInvoices','Hizmet faturası',$now-1,json_encode(array_replace($raw,['id'=>'F4','sellerId'=>999])),1]);
$ins->execute([2,'F1','DeductionInvoices','Hizmet faturası',$now-1,json_encode($raw),1]);
$r=period_finance($db,1,$now-10,$now);
$t['fee not double counted']=$r['groups']['Platform hizmet bedeli (filtre doğrulamalı)']['count']===1&&$r['groups']['Platform hizmet bedeli (filtre doğrulamalı)']['debt']===131900;
$t['withholding separate']=$r['groups']['Stopaj']['debt']===20000;
$t['ad only candidate']=$r['groups']['Reklam / influencer adayı']['debt']===500000;
$t['wrong seller excluded']=$r['excluded']===1;
$t['coverage missing']=count($r['coverage']['service_fee_transaction'])===1;
$t['schema migration version']=(int)$db->query('PRAGMA user_version')->fetchColumn()>=2;
$cargo=array_replace($row,['raw'=>array_replace($raw,['transactionType'=>'Kargo Faturası'])]);
try{service_fee_ids([$cargo],[$cargo],'123');$t['cargo cannot be service fee']=false;}catch(InvalidArgumentException $ex){$t['cargo cannot be service fee']=true;}
$wrong=array_replace($row,['raw'=>array_replace($raw,['transactionSubType'=>'Other'])]);
try{service_fee_ids([$wrong],[$wrong],'123');$t['explicit conflicting subtype rejected']=false;}catch(InvalidArgumentException $ex){$t['explicit conflicting subtype rejected']=true;}
$ins->execute([1,'F5','Stoppage','E-ticaret Stopaj İptali',$now-1,json_encode(array_replace($raw,['id'=>'F5','transactionType'=>'E-ticaret Stopaj İptali','debt'=>0,'credit'=>0.5])),null]);
$ins->execute([1,'F6','DeductionInvoices','Kargo Faturası',$now-1,json_encode(array_replace($raw,['id'=>'F6','transactionType'=>'Kargo Faturası','debt'=>65])),1]);
$ex=period_expense_evidence($db,1,$now-10,$now);
$t['actual service separated']=$ex['groups']['service']['observed_net']===13.19&&$ex['groups']['service']['excluded']===1;
$t['withholding cancellation offsets']=$ex['groups']['withholding']['observed_net']===1.5;
$t['cargo not double classified']=$ex['groups']['cargo']['observed_net']===65.0;
$t['missing coverage not complete']=!$ex['groups']['service']['coverage_complete'];
$t['empty period unknown']=period_expense_evidence($db,1,$now+1,$now+10)['groups']['service']['observed_net']===null;
sync_record_range($db,1,'trendyol','documents_transaction',$now-10,$now);
sync_record_range($db,1,'trendyol','service_fee_transaction',$now-10,$now);
$ex=period_expense_evidence($db,1,$now-10,$now);
$t['coverage does not erase excluded rows']=!$ex['groups']['service']['coverage_complete']&&$ex['groups']['withholding']['coverage_complete'];
foreach($t as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";exit(in_array(false,$t,true)?1:0);
