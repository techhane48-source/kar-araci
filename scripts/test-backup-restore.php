<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-restore-'.bin2hex(random_bytes(8));
mkdir($dir,0700);mkdir($dir.'/restored',0700);
define('DB_PATH',$dir.'/source.sqlite');
register_shutdown_function(static function()use($dir){
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($dir);
});
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/verify_trendyol.php';
$db=db();$db->prepare("INSERT INTO users(email,password_hash) VALUES(?,?)")->execute(['backup@example.invalid','synthetic']);$uid=(int)$db->lastInsertId();
save_integration($uid,'trendyol','123','synthetic-key','synthetic-secret',true);
$dest=$dir.'/restored/app.sqlite';
// SQLite online snapshot (WAL-safe); synthetic encryption key copied separately.
$db->exec("VACUUM INTO ".$db->quote($dest));
copy($dir.'/.enc-key',$dir.'/restored/.enc-key');chmod($dir.'/restored/.enc-key',0600);
$db->prepare("DELETE FROM integrations WHERE user_id=?")->execute([$uid]);
$ro=verify_open_db($dest);$saved=verify_saved_credentials($ro,$dest,$uid);
$t=['restored database integrity'=>$ro->query('PRAGMA integrity_check')->fetchColumn()==='ok',
    'snapshot retained original connection'=>(int)$ro->query('SELECT COUNT(*) FROM integrations')->fetchColumn()===1,
    'restored credentials exact'=>$saved['key']==='synthetic-key'&&$saved['secret']==='synthetic-secret',
    'source changed independently'=>(int)$db->query('SELECT COUNT(*) FROM integrations')->fetchColumn()===0];
try{$ro->exec("DELETE FROM users");$t['restored verifier readonly']=false;}catch(PDOException $e){$t['restored verifier readonly']=true;}
foreach($t as $name=>$ok)echo ($ok?'PASS':'FAIL')." $name\n";
exit(in_array(false,$t,true)?1:0);
