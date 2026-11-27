<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-coverage-'.bin2hex(random_bytes(8));mkdir($dir,0700);
define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$tests=[];
$tests['adjacent and overlap merge']=sync_merge_ranges([[20,30],[10,20],[15,25],[40,50]])===[[10,30],[40,50]];
$tests['gaps detected']=sync_range_gaps([[10,20],[30,40]],5,45)===[[5,10],[20,30],[40,45]];
$tests['complete window']=sync_range_gaps([[1,10],[10,20]],2,20)===[];
$db->beginTransaction();sync_record_range($db,1,'trendyol','settlements_transaction',100,200);$db->commit();
$db->beginTransaction();sync_record_range($db,1,'trendyol','settlements_transaction',200,300);$db->rollBack();
$tests['rollback keeps coverage']=sync_read_ranges($db,1,'trendyol','settlements_transaction')===[[100,200]];
$tests['tenant isolation']=sync_read_ranges($db,2,'trendyol','settlements_transaction')===[];
$tests['job isolation']=sync_read_ranges($db,1,'trendyol','orders_modified')===[];
$now=1800000000;$last=$now-10*86400;
$tests['recover ten day outage']=sync_order_window([[$last-86400,$last]],$now,7)===[$last-300,$now];
$tests['explicit backfill']=sync_order_window([[$now-600,$now-60]],$now,30)===[$now-30*86400,$now];
try{sync_order_window([[$now-91*86400,$now-90*86400]],$now,7);$tests['old gap flagged']=false;}catch(RuntimeException $e){$tests['old gap flagged']=true;}
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
