<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';require_once __DIR__.'/../includes/billing.php';$options=getopt('',['json','live','trendyol-report:']);$checks=[];
foreach(['pdo_sqlite','mbstring','openssl','curl','zip','dom','xmlreader','xmlwriter'] as $extension)$checks['extension_'.$extension]=extension_loaded($extension);
$path=realpath(DB_PATH);$web=realpath(__DIR__.'/../public_html');$checks['database_exists']=$path!==false;$checks['database_outside_web']=$path!==false&&!str_starts_with($path,$web.'/');$checks['database_private']=$path!==false&&(fileperms($path)&0077)===0;
$settings=[];$integrations=[];
if($path)try{$pdo=new PDO('sqlite:file:'.str_replace('%2F','/',rawurlencode($path)).'?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);foreach($pdo->query('SELECT key,value FROM settings') as $r)$settings[$r['key']]=$r['value'];$integrations=$pdo->query("SELECT id,revision,stage FROM integrations WHERE marketplace='trendyol'")->fetchAll();}catch(Throwable $e){$checks['database_readable']=false;}
$base=getenv('KARFIYAT_APP_URL')?:($settings['app_url']??'');try{$checks['fixed_https_url']=str_starts_with(application_base_url($base),'https://');}catch(RuntimeException $e){$checks['fixed_https_url']=false;}
$checks['merchant_connection_present']=(bool)$integrations;$key=dirname(DB_PATH).'/.enc-key';$checks['encryption_key_present']=is_file($key)&&preg_match('/^[a-f0-9]{64}$/D',(string)file_get_contents($key))===1;$checks['encryption_key_private']=is_file($key)&&(fileperms($key)&0077)===0;
$checks['provider_credentials_present']=($settings['iyzico_api_key']??'')!==''&&($settings['iyzico_secret_key']??'')!=='';
if(isset($options['live'])){
 $checks['payment_provider_live']=($settings['iyzico_sandbox']??'1')==='0';$verified=false;
 if(isset($options['trendyol-report'])&&is_file($options['trendyol-report'])){$r=json_decode(file_get_contents($options['trendyol-report']),true);foreach($integrations as $i)if(empty($i['stage'])&&($r['ok']??false)&&($r['environment']??'')==='production'&&($r['checked_at']??0)>time()-86400&&($r['integration_id']??null)===(int)$i['id']&&($r['integration_revision']??null)===(int)$i['revision'])$verified=true;}
 $checks['recent_readonly_trendyol_report']=$verified;
}
$ok=!in_array(false,$checks,true);$result=['configuration_ok'=>$ok,'checks'=>$checks,'limits'=>['Yapılandırma kontrolü gerçek tahsilat, banka mutabakatı veya bütün özelliklerin kabul testi yerine geçmez.','Canlı fiyat yazımı, iadeler/chargeback ve işletme kabulü ayrıca doğrulanmalıdır.']];
if(isset($options['json']))echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";else foreach($checks as $name=>$passed)echo($passed?'OK ':'EKSİK ').$name."\n";exit($ok?0:1);
