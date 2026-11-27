<?php
declare(strict_types=1);
require_once __DIR__.'/cargo_estimate.php';

/** Global marketplace reference only, never a seller-specific negotiated price.
 * Preserve historical tables, refuse intersecting dates, install atomically. */
function cargo_tariff_install(string $directory,array $input,bool $apply=false): array
{
    if(($input['scope']??null)!=='marketplace_standard'||($input['confidence']??null)!=='operator_reference'||!cargo_tariff_valid($input))
        throw new InvalidArgumentException('Geçerli standart pazaryeri referansı gerekli.');
    if(strlen($input['source'])>500)throw new InvalidArgumentException('Kaynak açıklaması çok uzun.');
    $clean=['from'=>$input['from'],'through'=>$input['through'],'source'=>$input['source'],'confidence'=>'operator_reference','scope'=>'marketplace_standard','rows'=>[]];
    foreach($input['rows'] as $row){
        $item=array_intersect_key($row,array_flip(['carrier','desi','min_sale','max_sale','gross_tl','kind','fast_gross_tl']));
        $clean['rows'][]=$item;
    }
    $json=json_encode($clean,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if(strlen($json)>4000000)throw new InvalidArgumentException('Tarife dosyası çok büyük.');
    if(!is_dir($directory)){
        if(!$apply)return ['state'=>'preview','rows'=>count($clean['rows']),'official_verified'=>false];
        if(!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Tarife dizini oluşturulamadı.');
    }
    if(is_link($directory))throw new RuntimeException('Tarife dizini sembolik bağlantı olamaz.');
    $lockPath=$directory.'/.install.lock';
    if(is_link($lockPath))throw new RuntimeException('Tarife kilidi geçersiz.');
    $lock=fopen($lockPath,'c');
    if(!$lock)throw new RuntimeException('Tarife kilidi açılamadı.');
    try{
        if(!flock($lock,LOCK_EX))throw new RuntimeException('Tarife kilitlenemedi.');
        foreach(glob($directory.'/*.json')?:[] as $path){
            if(is_link($path)||!is_file($path)||filesize($path)>4000000)throw new RuntimeException('Mevcut tarife dosyası kontrol edilmeli.');
            $raw=file_get_contents($path);$old=json_decode((string)$raw,true);
            if(!is_array($old)||!cargo_tariff_valid($old))throw new RuntimeException('Mevcut tarife dosyası geçersiz.');
            if($raw===$json)return ['state'=>'unchanged','rows'=>count($clean['rows']),'official_verified'=>false];
            if(($old['confidence']??'')!=='secondary_partial'&&$clean['from']<=$old['through']&&$clean['through']>=$old['from'])
                throw new InvalidArgumentException('Tarih aralıkları çakışıyor; mevcut tarife korunur.');
        }
        if(!$apply)return ['state'=>'preview','rows'=>count($clean['rows']),'official_verified'=>false];
        $target=$directory.'/'.$clean['from'].'_'.$clean['through'].'_'.substr(hash('sha256',$json),0,16).'.json';
        $tmp=tempnam($directory,'.pending-');
        if($tmp===false)throw new RuntimeException('Geçici dosya oluşturulamadı.');
        try{
            if(!chmod($tmp,0600)||file_put_contents($tmp,$json)!==strlen($json)||!rename($tmp,$target))
                throw new RuntimeException('Tarife atomik olarak kaydedilemedi.');
        }finally{if(is_file($tmp))unlink($tmp);}
        return ['state'=>'installed','rows'=>count($clean['rows']),'official_verified'=>false];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
