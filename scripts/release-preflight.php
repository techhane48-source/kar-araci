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
    foreach(['pdo_sqlite','mbstring','openssl','curl','zip','simplexml'] as $e)if(!extension_loaded($e))$checks['extensions']=false;
    $real=realpath($path);$root=realpath(__DIR__.'/../public_html');
    $checks['outside_web_root']=$real!==false&&$root!==false&&$real!==$root&&!str_starts_with($real,$root.DIRECTORY_SEPARATOR);
    if($checks['outside_web_root']){
        $db=verify_open_db($real);$checks['database_integrity']=$db->query('PRAGMA quick_check')->fetchColumn()==='ok';
        $checks['schema_v10']=(int)$db->query('PRAGMA user_version')->fetchColumn()>=10;
    }
    // Configuration and external financial evidence are distinct from executable checks.
    echo json_encode(['checks'=>$checks,'application_preflight_passed'=>!in_array(false,$checks,true),
        'production_approved'=>false,'independent_finance_verified'=>false,'official_tariff_verified'=>false,
        'cron_and_visual_review_verified'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit(in_array(false,$checks,true)?2:0);
}catch(Throwable $e){fwrite(STDERR,"Sunucu ön kontrolü tamamlanamadı. Özel ortamda kontrol edin.\n");exit(1);}
