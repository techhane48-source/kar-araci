<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-verify-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/{*,.*}',GLOB_BRACE)?:[] as $f)if(is_file($f))unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/verify_trendyol.php';
$db=db();$db->prepare("INSERT INTO users(email,password_hash,is_demo) VALUES(?,?,?)")->execute(['verify@example.invalid','synthetic',0]);$uid=(int)$db->lastInsertId();
$db->prepare("INSERT INTO integrations(user_id,marketplace,seller_id,api_key,api_secret,stage) VALUES(?,?,?,?,?,?)")->execute([$uid,'trendyol','123',encrypt_string('synthetic-private-key'),encrypt_string('synthetic-private-secret'),1]);
$db->prepare("INSERT INTO integration_bindings(user_id,marketplace,seller_id,stage) VALUES(?,?,?,?)")->execute([$uid,'trendyol','123',1]);
$ro=verify_open_db(DB_PATH);$c=verify_saved_credentials($ro,DB_PATH,null);$t=[];
$t['existing encrypted connection']=$c['uid']===$uid&&$c['stage']&&$c['key']==='synthetic-private-key';
try{$ro->exec("DELETE FROM integrations");$t['read only database']=false;}catch(PDOException $e){$t['read only database']=true;}
$client=new class{
    public bool $fail=false;
    public bool $badCatalog=false;
    public array $orders=[];
    function testConnection(){return ['ok'=>true];}
    function getProducts(){return ['ok'=>true,'products'=>[['barcode'=>'PRIVATE-BARCODE','salePrice'=>$this->badCatalog?null:100,'quantity'=>5]]];}
    function getOrders(...$args){return ['ok'=>true,'orders'=>$this->orders];}
    function getSettlements($type,...$args){return $this->fail&&$type==='Sale'?['ok'=>false,'error'=>'PRIVATE-ERROR']:['ok'=>true,'settlements'=>[]];}
    function getClaims(...$args){return ['ok'=>true,'items'=>[]];}
    function getPaymentOrders($page){return ['ok'=>true,'items'=>[],'totalPages'=>0];}
    function getBuybox($barcodes){return ['ok'=>true,'items'=>[],'missing'=>$barcodes];}
};
$r=verify_trendyol_read_only($client,$ro,$uid,7,time(),static fn()=>null);
$t['missing local detected']=!$r['ok']&&$r['checks']['catalog']['missing_local']===1;
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['API only not acceptance']=$r['ok']&&!$r['live_acceptance']&&$r['local_comparison']===null&&!$r['bank_verified'];
$client->badCatalog=true;
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['API only invalid product rejected']=!$r['ok']&&in_array('catalog',$r['failed_checks'],true)&&$r['checks']['catalog']['unknown_price_or_stock']===1;
$client->badCatalog=false;
$client->orders=[['packageNumber'=>'PRIVATE-PACKAGE','totalPrice'=>100,'status'=>'Delivered','lines'=>[]]];
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['API only invalid lines rejected']=!$r['ok']&&in_array('orders',$r['failed_checks'],true)&&$r['checks']['orders']['unknown_lines']===1;
$client->orders=[['packageNumber'=>'PRIVATE-PACKAGE','totalPrice'=>null,'status'=>'Delivered','lines'=>[['line_id'=>'PRIVATE-LINE','barcode'=>'PRIVATE-BARCODE','quantity'=>1,'price'=>100]]]];
$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['API only invalid order fields rejected']=!$r['ok']&&$r['checks']['orders']['unknown_order_fields']===1&&$r['checks']['orders']['unknown_lines']===0&&!str_contains(json_encode($r),'PRIVATE');
$client->orders=[];
$client->fail=true;$r=verify_trendyol_read_only($client,null,null,7,time(),static fn()=>null);
$t['failure reported without private data']=!$r['ok']&&in_array('settlement_Sale',$r['failed_checks'],true)&&!str_contains(json_encode($r),'PRIVATE');
$db->exec("UPDATE integration_bindings SET seller_id='999'");
try{verify_saved_credentials($ro,DB_PATH,$uid);$t['binding mismatch rejected']=false;}catch(RuntimeException $e){$t['binding mismatch rejected']=true;}
foreach($t as $name=>$ok)echo ($ok?'PASS':'FAIL')." $name\n";exit(in_array(false,$t,true)?1:0);
