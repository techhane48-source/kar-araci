<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
$options=getopt('',['output-dir:']);$target=$options['output-dir']??dirname(DB_PATH).'/backups';$created=null;
try{
    if(!is_file(DB_PATH))throw new RuntimeException('Yedeklenecek veritabanı bulunamadı.');
    if(!is_dir($target)&&!mkdir($target,0700,true)&&!is_dir($target))throw new RuntimeException('Yedek dizini oluşturulamadı.');
    $real=realpath($target);$web=realpath(__DIR__.'/../public_html');if(!$real||$real===$web||str_starts_with($real,$web.'/'))throw new RuntimeException('Yedekler web kökü dışında saklanmalı.');
    $pdo=new PDO('sqlite:file:'.str_replace('%2F','/',rawurlencode(realpath(DB_PATH))).'?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('PRAGMA busy_timeout=10000');
    $encrypted=false;
    foreach(['integrations'=>['api_key','api_secret'],'checkout_sessions'=>['provider_context']] as $table=>$columns){
        if(!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($table))->fetchColumn())continue;
        $known=array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
        foreach($columns as $c)if(in_array($c,$known,true)&&$pdo->query("SELECT 1 FROM $table WHERE $c IS NOT NULL AND $c<>'' LIMIT 1")->fetchColumn())$encrypted=true;
    }
    if($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='settings'")->fetchColumn()&&$pdo->query("SELECT 1 FROM settings WHERE value LIKE 'secret:%' LIMIT 1")->fetchColumn())$encrypted=true;
    $key=dirname(DB_PATH).'/.enc-key';if($encrypted&&!is_file($key))throw new RuntimeException('Şifreli kayıtların anahtarı eksik; eksik bir yedek oluşturulmadı.');
    if(is_file($key)&&!preg_match('/^[a-f0-9]{64}$/D',(string)file_get_contents($key)))throw new RuntimeException('Şifreleme anahtarı geçersiz; yedek tamamlanmadı.');
    $created=$real.'/'.date('Ymd-His').'-'.bin2hex(random_bytes(4));if(!mkdir($created,0700))throw new RuntimeException('Yedek alanı açılamadı.');
    $old=umask(0077);try{$pdo->exec('VACUUM INTO '.$pdo->quote($created.'/app.sqlite'));}finally{umask($old);}
    chmod($created.'/app.sqlite',0600);$manifest=['created_at'=>date('c'),'database_sha256'=>hash_file('sha256',$created.'/app.sqlite'),'key_included'=>is_file($key)];
    if(is_file($key)){if(!copy($key,$created.'/.enc-key'))throw new RuntimeException('Anahtar yedeklenemedi.');chmod($created.'/.enc-key',0600);$manifest['key_sha256']=hash_file('sha256',$created.'/.enc-key');}
    file_put_contents($created.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($created.'/manifest.json',0600);
    echo $created."\n";
}catch(Throwable $e){if($created){foreach(glob($created.'/*')?:[] as $file)@unlink($file);@unlink($created.'/.enc-key');@rmdir($created);}fwrite(STDERR,($e instanceof RuntimeException?$e->getMessage():'Yedekleme tamamlanamadı.')."\n");exit(1);}
