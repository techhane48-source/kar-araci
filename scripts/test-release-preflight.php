<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/kar-preflight-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/app.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$db->exec("PRAGMA user_version=10");
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/release-preflight.php').' '.escapeshellarg(DB_PATH);
exec($cmd,$lines,$code);$r=json_decode(implode("\n",$lines),true);
$ok=$code===0&&($r['application_preflight_passed']??false)&&!($r['production_approved']??true)&&!($r['independent_finance_verified']??true);
$db->exec("PRAGMA user_version=9");$lines=[];exec($cmd,$lines,$code);$r=json_decode(implode("\n",$lines),true);
$old=$code===2&&!($r['checks']['schema_v10']??true);
echo ($ok?'PASS':'FAIL')." preflight read-only current schema\n".($old?'PASS':'FAIL')." old schema blocks preflight\n";
exit($ok&&$old?0:1);
