<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-regression-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($dir);
});
require_once __DIR__.'/../includes/functions.php';
$tests=[];$db=db();
$db->prepare("INSERT INTO users(email,name,password_hash) VALUES(?,?,?)")->execute(['ci@example.invalid','CI',password_hash('synthetic-only',PASSWORD_DEFAULT)]);
$uid=(int)$db->lastInsertId();
$encrypted=encrypt_string('synthetic-key');
$tests['GCM roundtrip']=str_starts_with($encrypted,'v2:')&&decrypt_string($encrypted)==='synthetic-key';
$raw=base64_decode(substr($encrypted,3));$raw[15]=chr(ord($raw[15])^1);
try{decrypt_string('v2:'.base64_encode($raw));$tests['tamper rejected']=false;}catch(RuntimeException $e){$tests['tamper rejected']=true;}
$iv=random_bytes(16);$old=base64_encode($iv.openssl_encrypt('legacy','aes-256-cbc',hex2bin(enc_key()),OPENSSL_RAW_DATA,$iv));
$tests['legacy CBC readable']=decrypt_string($old)==='legacy';
save_integration($uid,'trendyol','123','synthetic-key','synthetic-secret',true);
$tests['connection persisted']=get_integration($uid,'trendyol')['api_key']==='synthetic-key';
delete_integration($uid,'trendyol');
try{save_integration($uid,'trendyol','456','a','b',false);$tests['cross seller rejected']=false;}catch(RuntimeException $e){$tests['cross seller rejected']=true;}
save_integration($uid,'trendyol','123','a','b',true);
$tests['same seller reconnect']=get_integration($uid,'trendyol')['api_key']==='a';
$r=run_sync_job($uid,'trendyol','test',static fn()=>['ok'=>true]);
$tests['success timestamp']=$r['ok']&&(int)$db->query("SELECT last_success FROM sync_status WHERE job='test'")->fetchColumn()>0;
$last=$db->query("SELECT last_success FROM sync_status WHERE job='test'")->fetchColumn();
run_sync_job($uid,'trendyol','test',static fn()=>['ok'=>false]);
$st=$db->query("SELECT state,last_success FROM sync_status WHERE job='test'")->fetch();
$tests['failure retains success']=$st['state']==='failed'&&$st['last_success']===$last;
$outer=with_marketplace_lock($uid,'trendyol',static fn()=>with_marketplace_lock($uid,'trendyol',static fn()=>['ok'=>true]));
$tests['concurrent sync blocked']=!$outer['ok'];
migrate();
$tests['repeat migration']=true;
foreach($tests as $name=>$ok)echo ($ok?'PASS':'FAIL')." $name\n";
exit(in_array(false,$tests,true)?1:0);
