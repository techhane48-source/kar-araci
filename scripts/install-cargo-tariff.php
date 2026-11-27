<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/cargo_tariff_install.php';
// Local operator only. Defaults to validation/preview; no provider write or network request.
try{
    if(count($argv)>2||($argv[1]??'')!==''&&$argv[1]!=='--apply')throw new InvalidArgumentException('Yalnız --apply seçeneği desteklenir.');
    $raw=stream_get_contents(STDIN,4000001);
    if(strlen($raw)>4000000)throw new InvalidArgumentException('Dosya çok büyük.');
    $input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($input))throw new InvalidArgumentException('Geçersiz tarife.');
    $out=cargo_tariff_install(dirname(DB_PATH).'/cargo-tariffs',$input,($argv[1]??'')==='--apply');
    echo json_encode($out,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"Tarife doğrulanamadı veya yüklenemedi; mevcut tarife korunur.\n");exit(1);}
