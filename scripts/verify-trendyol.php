<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$options=getopt('',['user-id:','days:','output:','environment','help']);
if(isset($options['help'])){echo "Kullanım: php scripts/verify-trendyol.php --user-id=1 [--days=7] [--output=/ozel/rapor.json]\nGizli ortam alanlarıyla doğrudan test için --environment kullanın. API ve uygulama veritabanını değiştirmez. Rapor müşteri/ürün kimliği veya anahtar içermez.\n";exit;}
require_once __DIR__.'/../includes/config.php';require_once __DIR__.'/../includes/crypto.php';require_once __DIR__.'/../includes/trendyol.php';
$id=filter_var($options['user-id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$days=filter_var($options['days']??7,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>14]]);
try{
    $environment=isset($options['environment']);
    if((!$id&&!$environment)||!$days)throw new RuntimeException('Geçerli --user-id ve 1–14 günlük --days gerekir.');
    $pdo=null;
    if($environment){
        $seller=getenv('TRENDYOL_SELLER_ID')?:'';$key=getenv('TRENDYOL_API_KEY')?:'';$secret=getenv('TRENDYOL_API_SECRET')?:'';
        if(!preg_match('/^[1-9][0-9]*$/D',$seller)||$key===''||$secret==='')throw new RuntimeException('Güvenli ortam alanlarına satıcı ID, API key ve API secret eklenmeli.');
        $int=['seller_id'=>$seller,'stage'=>false,'id'=>null,'revision'=>null];
    }else{
    $path=realpath(DB_PATH);if(!$path)throw new RuntimeException('Uygulama veritabanı bulunamadı.');
    $pdo=new PDO('sqlite:file:'.str_replace('%2F','/',rawurlencode($path)).'?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $q=$pdo->prepare("SELECT * FROM integrations WHERE user_id=? AND marketplace='trendyol'");$q->execute([$id]);$int=$q->fetch();
    if(!$int)throw new RuntimeException('Bu kullanıcı için Trendyol mağaza bağlantısı yok.');
    if(!is_file(dirname(DB_PATH).'/.enc-key'))throw new RuntimeException('Veritabanıyla eşleşen şifreleme anahtarı bulunamadı.');
    $key=decrypt_string($int['api_key']);$secret=decrypt_string($int['api_secret']);if($key===''||$secret==='')throw new RuntimeException('Mağaza kimlik bilgileri çözülemedi.');
    }
    $client=new TrendyolClient($int['seller_id'],$key,$secret,(bool)$int['stage'],null,true);unset($key,$secret);
    $report=['checked_at'=>time(),'environment'=>$int['stage']?'stage':'production','integration_id'=>$int['id']===null?null:(int)$int['id'],'integration_revision'=>$int['revision']===null?null:(int)$int['revision'],'scope'=>$environment?'api_contract_only':'api_and_local_comparison','read_only'=>true,'days'=>$days,'checks'=>[]];
    $api=$client->getProducts();if(!$api['ok'])throw new RuntimeException($api['error']);
    $local=[];$duplicate=0;
    if($pdo){$q=$pdo->prepare("SELECT barcode,price,quantity FROM products WHERE user_id=? AND marketplace='trendyol'");$q->execute([$id]);$local=[];$duplicate=0;while($r=$q->fetch()){if(isset($local[$r['barcode']]))$duplicate++;$local[$r['barcode']]=$r;}}
    $missing=0;$price=0;$stock=0;
    foreach($api['products'] as $p){$r=$local[$p['barcode']]??null;if(!$r){$missing++;continue;}if(abs((float)$r['price']-(float)$p['salePrice'])>.005)$price++;if((int)$r['quantity']!==(int)$p['quantity'])$stock++;}
    $report['checks']['catalog']=['provider_rows'=>count($api['products']),'local_rows'=>count($local),'missing_local'=>$missing,'price_differences'=>$price,'stock_differences'=>$stock,'duplicate_local'=>$duplicate,'matched'=>$environment?null:$missing+$price+$stock+$duplicate===0];
    $to=time();$from=$to-$days*86400;$orders=$client->getOrders('',date('c',$from),date('c',$to));if(!$orders['ok'])throw new RuntimeException($orders['error']);
    if($pdo)$q=$pdo->prepare("SELECT total_price,status,lines FROM orders WHERE user_id=? AND marketplace='trendyol' AND package_number=?");$missing=0;$amount=0;$status=0;$lines=0;
    foreach($orders['orders'] as $o){if(!$pdo)continue;$q->execute([$id,$o['packageNumber']]);$r=$q->fetch();if(!$r){$missing++;continue;}if(abs((float)$r['total_price']-(float)$o['totalPrice'])>.005)$amount++;if($r['status']!==$o['status'])$status++;$a=json_decode($r['lines'],true);if(!is_array($a)||count($a)!==count($o['lines']))$lines++;else foreach($o['lines'] as $i=>$line)if(($a[$i]['barcode']??null)!==$line['barcode']||(int)($a[$i]['quantity']??0)!==(int)$line['quantity']||abs((float)($a[$i]['price']??-1)-(float)$line['price'])>.005){$lines++;break;}}
    $report['checks']['orders']=['provider_rows'=>count($orders['orders']),'missing_local'=>$missing,'amount_differences'=>$amount,'status_differences'=>$status,'line_differences'=>$lines,'matched'=>$environment?null:$missing+$amount+$status+$lines===0];
    $finance=[];foreach(['Sale','Return','Discount','DiscountCancel','Coupon','CouponCancel','DeliveryFee','DeliveryFeeCancel'] as $type){$f=$client->getSettlements($type,date('c',$from),date('c',$to));if(!$f['ok'])throw new RuntimeException($f['error']);$finance[$type]=count($f['settlements']);}
    $report['checks']['settlements']=['complete_for_requested_types'=>true,'counts'=>$finance,'bank_reconciliation_verified'=>false];
    $barcodes=array_column(array_slice($api['products'],0,10),'barcode');if($barcodes){$b=$client->getBuyboxInformation($barcodes);$report['checks']['buybox']=['ok'=>$b['ok'],'returned'=>count($b['rows']??[]),'missing'=>count($b['missing']??[])];}
    $report['ok']=($environment||($report['checks']['catalog']['matched']&&$report['checks']['orders']['matched']))&&($report['checks']['buybox']['ok']??true);
    $report['limits']=[($environment?'Yalnızca resmi API sözleşmesi kontrol edilir; uygulamaya veri aktarımı karşılaştırması yapılmadı.':'API verileri yerel kayıtlarla karşılaştırıldı.'),'Panel dosyasıyla bağımsız karşılaştırma ve banka mutabakatı ayrı kabul adımlarıdır.','Canlı fiyat/stok yazımı yapılmadı.','Sorgu sırasında değişen stok ve siparişler fark oluşturabilir.'];
    $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    if(isset($options['output'])){
        $dest=(string)$options['output'];$dir=realpath(dirname($dest));$web=realpath(__DIR__.'/../public_html');
        if(!$dir||$dir===$web||str_starts_with($dir,$web.'/'))throw new RuntimeException('Rapor için mevcut, web kökü dışındaki bir dizin seçin.');
        $h=@fopen($dest,'x+b');if(!$h)throw new RuntimeException('Rapor dosyası zaten var veya yazılamıyor.');try{chmod($dest,0600);if(fwrite($h,$json)!==strlen($json))throw new RuntimeException('Rapor yazılamadı.');}finally{fclose($h);}
    }
    echo $json;exit($report['ok']?0:2);
}catch(Throwable $e){fwrite(STDERR,($e instanceof RuntimeException?$e->getMessage():'Doğrulama tamamlanamadı.')."\n");exit(1);}
