<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/verify_trendyol.php';
$options=getopt('',['db:','user-id:','days:','environment:','help']);
if(isset($options['help'])){echo "php scripts/verify-trendyol.php [--db=/private/app.sqlite] [--user-id=1] [--days=7]\nGizli ortam değişkenleri ile: --environment=stage veya --environment=production.\nSadece salt okunur sorgular; JSON raporunda kimlik bilgisi yok.\n";exit(0);}
try{
    $days=filter_var($options['days']??7,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>14]]);
    $uid=isset($options['user-id'])?filter_var($options['user-id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
    if(!$days||$uid===false)throw new RuntimeException('Geçersiz gün/kullanıcı seçimi.');
    $db=null;
    if(isset($options['environment'])){
        if(isset($options['db'])||$uid!==null||!in_array($options['environment'],['stage','production'],true))throw new RuntimeException('Ortam seçimi açık ve tek olmalı.');
        $c=['seller'=>getenv('TRENDYOL_SELLER_ID')?:'','key'=>getenv('TRENDYOL_API_KEY')?:'','secret'=>getenv('TRENDYOL_API_SECRET')?:'','stage'=>$options['environment']==='stage','uid'=>null];
        if(!ctype_digit($c['seller'])||trim($c['key'])===''||trim($c['secret'])==='')throw new RuntimeException('Gizli ortam alanları eksik.');
    }else{
        $path=(string)($options['db']??DB_PATH);$db=verify_open_db($path);$c=verify_saved_credentials($db,$path,$uid);
    }
    $client=new TrendyolClient($c['seller'],$c['key'],$c['secret'],$c['stage']);$uid=$c['uid'];$stage=$c['stage'];unset($c);
    $r=verify_trendyol_read_only($client,$db,$uid,$days,time()-1);
    $r['environment']=$stage?'stage':'production';$r['checked_at']=time();$r['days']=$days;
    echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";exit($r['ok']?0:2);
}catch(Throwable $e){
    // Never forward exception messages, paths, response data, or credentials to a report.
    fwrite(STDERR,"Doğrulama başlatılamadı. Veritabanı, mağaza seçimi, ortam ve anahtar dosyasını sunucuda kontrol edin.\n");exit(1);
}
