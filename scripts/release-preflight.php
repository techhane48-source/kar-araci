<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/verify_trendyol.php';
require_once __DIR__.'/../includes/cargo_estimate.php';
// Read-only environment inspection: no migration, sync, network or key output.
try{
    $path=$argv[1]??DB_PATH;
    $checks=['php_runtime'=>PHP_VERSION_ID>=80300,'extensions'=>true,'database_integrity'=>false,'schema_v10'=>false,'outside_web_root'=>false];
    $operational=['seller_binding_consistent'=>null,'key_recoverable'=>null,'worker_recent'=>false,'worker_completed_recently'=>false,'connected_store_present'=>false];
    foreach(['pdo_sqlite','mbstring','openssl','curl','zip','simplexml'] as $e)if(!extension_loaded($e))$checks['extensions']=false;
    $real=realpath($path);$root=realpath(__DIR__.'/../public_html');
    $checks['outside_web_root']=$real!==false&&$root!==false&&$real!==$root&&!str_starts_with($real,$root.DIRECTORY_SEPARATOR);
    if($checks['outside_web_root']){
        $db=verify_open_db($real);$checks['database_integrity']=$db->query('PRAGMA quick_check')->fetchColumn()==='ok';
        $cols=array_column($db->query('PRAGMA table_info(return_inventory_checks)')->fetchAll(),'name');
        $checks['schema_v10']=(int)$db->query('PRAGMA user_version')->fetchColumn()===10&&in_array('group_line_id',$cols,true);
        if($checks['schema_v10']){
            $q=$db->query("SELECT i.user_id FROM integrations i JOIN users u ON u.id=i.user_id WHERE i.marketplace='trendyol' AND u.is_demo=0");
            $ids=$q->fetchAll(PDO::FETCH_COLUMN);$operational['connected_store_present']=count($ids)>0;
            if($ids){
                $operational['seller_binding_consistent']=true;$operational['key_recoverable']=true;
                $bad=$db->query("SELECT COUNT(*) FROM integrations i JOIN users u ON u.id=i.user_id LEFT JOIN integration_bindings b ON b.user_id=i.user_id AND b.marketplace=i.marketplace WHERE i.marketplace='trendyol' AND u.is_demo=0 AND (b.seller_id IS NULL OR b.seller_id<>i.seller_id OR b.stage<>i.stage OR i.stage NOT IN (0,1))")->fetchColumn();
                if((int)$bad>0)$operational['seller_binding_consistent']=false;
                foreach($ids as $uid){
                    try{$credentials=verify_saved_credentials($db,$real,(int)$uid);unset($credentials);}
                    catch(Throwable $e){$operational['key_recoverable']=false;}
                }
            }
            $q=$db->prepare("SELECT value FROM settings WHERE key=?");$now=time();
            foreach(['auto_sync_heartbeat'=>'worker_recent','auto_sync_last_finished'=>'worker_completed_recently'] as $key=>$label){
                $q->execute([$key]);$ts=(int)$q->fetchColumn();$operational[$label]=$ts>0&&$ts<=$now&&$now-$ts<=1800;
            }
        }
    }
    // Configuration and external financial evidence are distinct from executable checks.
    echo json_encode(['checks'=>$checks,'application_preflight_passed'=>!in_array(false,$checks,true),'operational'=>$operational,
        'production_approved'=>false,'independent_finance_verified'=>false,'official_tariff_verified'=>false,
        'cron_and_visual_review_verified'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit(in_array(false,$checks,true)?2:0);
}catch(Throwable $e){fwrite(STDERR,"Sunucu ön kontrolü tamamlanamadı. Özel ortamda kontrol edin.\n");exit(1);}
