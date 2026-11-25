<?php
declare(strict_types=1);
require_once __DIR__.'/crypto.php';
require_once __DIR__.'/trendyol.php';
require_once __DIR__.'/finance.php';

/** Open only an existing database; no application migrations or key generation. */
function verify_open_db(string $path): PDO
{
    $real=realpath($path);
    if($real===false||!is_file($real))throw new RuntimeException('Mevcut veritabanı bulunamadı.');
    $db=new PDO('sqlite:'.$real,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
    $db->exec('PRAGMA query_only=ON');
    return $db;
}
function verify_saved_credentials(PDO $db,string $path,?int $uid): array
{
    $sql="SELECT i.user_id,i.seller_id,i.stage,i.api_key,i.api_secret,b.seller_id AS bound_seller,b.stage AS bound_stage
          FROM integrations i JOIN users u ON u.id=i.user_id
          LEFT JOIN integration_bindings b ON b.user_id=i.user_id AND b.marketplace=i.marketplace
          WHERE i.marketplace='trendyol' AND u.is_demo=0";
    $q=$db->prepare($sql.($uid===null?'':' AND i.user_id=?').' LIMIT 2');$q->execute($uid===null?[]:[$uid]);
    $rows=$q->fetchAll();
    if(count($rows)!==1)throw new RuntimeException('Tek bir gerçek mağaza seçilmeli; gerekirse --user-id belirtin.');
    $r=$rows[0];
    if(!ctype_digit((string)$r['seller_id'])||!in_array((int)$r['stage'],[0,1],true)||
       (string)$r['bound_seller']!==(string)$r['seller_id']||(string)$r['bound_stage']!==(string)$r['stage'])
        throw new RuntimeException('Mağaza/ortam bağlantısı doğrulanamadı.');
    $real=realpath($path);$keyPath=dirname($real).'/.enc-key';
    if(!is_file($keyPath)||!is_readable($keyPath))throw new RuntimeException('Veritabanıyla eşleşen şifreleme anahtarı bulunamadı.');
    $key=@file_get_contents($keyPath);
    if(!is_string($key))throw new RuntimeException('Şifreleme anahtarı okunamadı.');
    $apiKey=decrypt_string_with_key((string)$r['api_key'],$key);
    $secret=decrypt_string_with_key((string)$r['api_secret'],$key);
    if(trim($apiKey)===''||trim($secret)==='')throw new RuntimeException('Mağaza API bilgileri eksik.');
    return ['uid'=>(int)$r['user_id'],'seller'=>(string)$r['seller_id'],'stage'=>(bool)$r['stage'],'key'=>$apiKey,'secret'=>$secret];
}
function verify_order_lines(mixed $lines): ?array
{
    if(is_string($lines))$lines=json_decode($lines,true);
    if(!is_array($lines)||!$lines)return null;
    $out=[];
    foreach($lines as $l){
        if(!is_array($l))return null;
        $id=(string)($l['line_id']??'');$bc=(string)($l['barcode']??'');$qty=$l['quantity']??null;
        if($id===''||$bc===''||isset($out[$id])||!is_numeric($qty)||!is_finite((float)$qty)||(int)$qty<1||(float)$qty!==(float)(int)$qty)return null;
        try{$v=finance_units($l['price']??null);if($v<0)return null;}catch(InvalidArgumentException $e){return null;}
        $out[$id]=[$bc,(int)$qty,$v];
    }
    ksort($out,SORT_STRING);return $out;
}
/** Only counts, booleans and fixed labels leave this function. Never output provider errors or payloads. */
function verify_trendyol_read_only(object $client,?PDO $db,?int $uid,int $days,int $now,?callable $pace=null): array
{
    if($days<1||$days>14||($db!==null&&($uid===null||$uid<1)))throw new InvalidArgumentException('Geçersiz doğrulama kapsamı.');
    $checks=[];$errors=[];$pause=$pace??static fn()=>usleep(650000);
    $record=static function(string $label,callable $check)use(&$checks,&$errors){
        try{$v=$check();if(!is_array($v)||empty($v['ok'])){$errors[]=$label;$checks[$label]=['ok'=>false];}else $checks[$label]=$v;}
        catch(Throwable $e){$errors[]=$label;$checks[$label]=['ok'=>false];}
    };
    $record('connection',static fn()=>['ok'=>!empty($client->testConnection()['ok'])]);
    if($errors)return ['ok'=>false,'checks'=>$checks,'failed_checks'=>$errors,'read_only'=>true,'live_acceptance'=>false,'bank_verified'=>false];
    $barcodes=[];
    $record('catalog',static function()use($client,$db,$uid,&$barcodes){
        $r=$client->getProducts();if(empty($r['ok'])||!is_array($r['products']??null))return ['ok'=>false];
        $local=[];$duplicate=0;$missing=0;$price=0;$stock=0;$unknown=0;$seen=[];
        if($db){$q=$db->prepare("SELECT barcode,price,quantity FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode<>''");$q->execute([$uid]);while($p=$q->fetch()){if(isset($local[$p['barcode']]))$duplicate++;$local[$p['barcode']]=$p;}}
        foreach($r['products'] as $p){
            $bc=(string)($p['barcode']??'');if($bc===''||isset($seen[$bc]))return ['ok'=>false];$seen[$bc]=true;
            if(count($barcodes)<10)$barcodes[]=$bc;
            $valid=is_numeric($p['salePrice']??null)&&is_finite((float)$p['salePrice'])&&(float)$p['salePrice']>0&&is_numeric($p['quantity']??null)&&is_finite((float)$p['quantity'])&&(float)$p['quantity']>=0&&(float)$p['quantity']===(float)(int)$p['quantity'];
            if(!$valid)$unknown++;
            if(!$db)continue;
            if(!isset($local[$bc])){$missing++;continue;}
            if($valid){if(abs((float)$local[$bc]['price']-(float)$p['salePrice'])>0.005)$price++;if((int)$local[$bc]['quantity']!==(int)$p['quantity'])$stock++;}
        }
        $extra=$db?count(array_diff_key($local,$seen)):null;
        return ['ok'=>true,'provider_rows'=>count($seen),'unknown_price_or_stock'=>$unknown,'missing_local'=>$db?$missing:null,'extra_local'=>$extra,'duplicate_local'=>$db?$duplicate:null,'price_differences'=>$db?$price:null,'stock_differences'=>$db?$stock:null,'matched'=>$db?($missing+$extra+$duplicate+$price+$stock+$unknown===0):null];
    });
    $from=$now-$days*86400;$start=date('c',$from);$end=date('c',$now);
    $record('orders',static function()use($client,$db,$uid,$start,$end){
        $r=$client->getOrders('',$start,$end);if(empty($r['ok'])||!is_array($r['orders']??null))return ['ok'=>false];
        $missing=0;$diff=0;$unknown=0;$seen=[];
        if($db)$q=$db->prepare("SELECT total_price,status,lines,currency_code,country_code FROM orders WHERE user_id=? AND marketplace='trendyol' AND package_number=?");
        foreach($r['orders'] as $o){
            $id=(string)($o['packageNumber']??'');if($id===''||isset($seen[$id]))return ['ok'=>false];$seen[$id]=true;
            $lines=verify_order_lines($o['lines']??null);if($lines===null)$unknown++;
            if(!$db)continue;
            $q->execute([$uid,$id]);$p=$q->fetch();if(!$p){$missing++;continue;}
            if(!is_numeric($o['totalPrice']??null)||abs((float)$p['total_price']-(float)$o['totalPrice'])>0.005||$p['status']!==($o['status']??null)||$lines===null||$lines!==verify_order_lines($p['lines'])||$p['currency_code']!==($o['currencyCode']??null)||$p['country_code']!==($o['orderCountryCode']??null))$diff++;
        }
        return ['ok'=>true,'provider_rows'=>count($seen),'unknown_lines'=>$unknown,'missing_local'=>$db?$missing:null,'differences'=>$db?$diff:null,'matched'=>$db?($missing+$diff+$unknown===0):null];
    });
    foreach(['Sale','Return','Discount','DiscountCancel','Coupon','CouponCancel','TyDiscount','TyCoupon','DeliveryFee','DeliveryFeeCancel'] as $type){
        $pause();$record('settlement_'.$type,static function()use($client,$start,$end,$type){
            $r=$client->getSettlements($type,$start,$end);return ['ok'=>!empty($r['ok']),'rows'=>count($r['settlements']??[])];
        });
    }
    foreach(['Stoppage','DeductionInvoices','PaymentOrder','CommissionAgreementInvoice','FinancialItem'] as $type){
        $pause();$record('document_'.$type,static function()use($client,$start,$end,$type){
            $r=$client->getSettlements($type,$start,$end,2000,'otherfinancials');return ['ok'=>!empty($r['ok']),'rows'=>count($r['settlements']??[])];
        });
    }
    $pause();$record('platform_service_fee',static function()use($client,$start,$end){
        $r=$client->getSettlements('DeductionInvoices',$start,$end,2000,'otherfinancials',null,'PlatformServiceFee');return ['ok'=>!empty($r['ok']),'rows'=>count($r['settlements']??[])];
    });
    $record('claims',static function()use($client,$from,$now){$r=$client->getClaims(max($from,$now-14*86400+1),$now);return ['ok'=>!empty($r['ok']),'rows'=>count($r['items']??[])];});
    $record('payout_page',static function()use($client){$r=$client->getPaymentOrders(0);return ['ok'=>!empty($r['ok']),'rows'=>count($r['items']??[]),'pages'=>(int)($r['totalPages']??0)];});
    if($barcodes)$record('buybox',static function()use($client,$barcodes){$r=$client->getBuybox($barcodes);return ['ok'=>!empty($r['ok']),'rows'=>count($r['items']??[]),'missing'=>count($r['missing']??[])];});
    $match=$db?(!empty($checks['catalog']['matched'])&&!empty($checks['orders']['matched'])):null;
    return ['ok'=>!$errors&&($match!==false),'checks'=>$checks,'failed_checks'=>$errors,'local_comparison'=>$match,
        'read_only'=>true,'live_acceptance'=>false,'bank_verified'=>false,'limits'=>['Seçili sorgular ve yerel katalog/sipariş gözlemi; tüm mali hareketlerin panel mutabakatı değildir.','Boş sonuçlar ilgili senaryonun doğrulandığını kanıtlamaz.','Kargo fatura detayı, gerçek tarife, vergi ve banka mutabakatı ayrıca gerekir.']];
}
