<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/verify_trendyol.php';
require_once __DIR__.'/../includes/private_cost_scenario.php';
// Never run in public CI. Inputs and output may contain commercial data; keep them private.
if(getenv('CI')||getenv('GITHUB_ACTIONS')){fwrite(STDERR,"Özel sunucu dışında çalıştırmayın.\n");exit(1);}
try{
    $input=json_decode(stream_get_contents(STDIN,2000001),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($input))throw new InvalidArgumentException('Geçersiz girdi.');
    $days=filter_var($input['days']??14,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>14]]);
    if(!$days)throw new InvalidArgumentException('Geçersiz tarih.');
    $env=$input['environment']??'production';
    if(!in_array($env,['stage','production'],true))throw new InvalidArgumentException('Geçersiz ortam.');
    $seller=getenv('TRENDYOL_SELLER_ID')?:'';$key=getenv('TRENDYOL_API_KEY')?:'';$secret=getenv('TRENDYOL_API_SECRET')?:'';
    if(!ctype_digit($seller)||trim($key)===''||trim($secret)==='')throw new RuntimeException('Bağlantı eksik.');
    $client=new TrendyolClient($seller,$key,$secret,$env==='stage');unset($seller,$key,$secret);
    $r=$client->getProducts();
    if(empty($r['ok'])||!is_array($r['products']??null))throw new RuntimeException('Katalog alınamadı.');
    if(($input['mode']??null)==='preview'){
        $out=['mode'=>'preview','candidates'=>private_cost_candidates($r['products'],(string)($input['phrase']??'')),'final_profit_verified'=>false];
    }elseif(($input['mode']??null)==='scenario'&&is_array($input['barcodes']??null)){
        $now=time()-1;$orders=$client->getOrders('',date('c',$now-$days*86400),date('c',$now));
        if(empty($orders['ok'])||!is_array($orders['orders']??null))throw new RuntimeException('Siparişler alınamadı.');
        $out=private_cost_scenario($r['products'],$orders['orders'],$input['barcodes'],$input['gross_unit_cost']??null);
    }else throw new InvalidArgumentException('Geçersiz mod.');
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"Özel maliyet senaryosu tamamlanamadı. Girdi ve bağlantıyı özel ortamda kontrol edin.\n");exit(1);}
