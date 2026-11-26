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
/** Compare item identity and money without relying on response row order. */
function verify_cargo_item_map(array $rows,bool $local=false): ?array
{
    $map=[];
    foreach($rows as $r){
        if(!is_array($r))return null;
        $parcel=$r[$local?'parcel_id':'parcelUniqueId']??null;
        $order=$r[$local?'order_number':'orderNumber']??null;
        $type=$r[$local?'shipment_type':'shipmentPackageType']??null;
        if((!is_string($parcel)&&!is_int($parcel))||trim((string)$parcel)===''||
           (!is_string($order)&&!is_int($order))||trim((string)$order)===''||
           !in_array($type,['Gönderi Kargo Bedeli','İade Kargo Bedeli'],true))return null;
        $key=json_encode([(string)$parcel,$type],JSON_THROW_ON_ERROR);
        if(isset($map[$key]))return null;
        try{
            $amount=finance_units($r['amount']??null);$desi=finance_units($r['desi']??null);
            if((float)$r['amount']<0||(float)$r['desi']<0)return null;
        }catch(InvalidArgumentException $e){return null;}
        $map[$key]=[(string)$order,$amount,$desi];
    }
    return $map;
}
/** Only domestic invoice documents discovered in the selected period are checked. */
function verify_cargo_invoices(object $client,?PDO $db,?int $uid,array $documents,callable $pause): array
{
    $serials=[];$outside=0;$invalid=0;$failed=0;$empty=0;$rows=0;$missing=0;$extra=0;$different=0;$invalidLocal=0;
    foreach($documents as $d){
        if(!in_array($d['transactionType']??null,['Kargo Faturası','Kargo Fatura'],true))continue;
        $raw=$d['raw']??null;$serial=$d['externalId']??null;
        if(!is_array($raw)||(!is_string($serial)&&!is_int($serial))||trim((string)$serial)===''||
           (string)($raw['id']??'')!==(string)$serial){$invalid++;continue;}
        if(!finance_is_domestic($raw)){$outside++;continue;}
        if(isset($serials[(string)$serial])){$invalid++;continue;}
        $serials[(string)$serial]=true;
    }
    $checked=0;
    if($db)$q=$db->prepare("SELECT parcel_id,order_number,shipment_type,amount,desi FROM trendyol_cargo_items WHERE user_id=? AND invoice_serial=?");
    foreach(array_keys($serials) as $serial){
        $pause();
        try{$r=$client->getCargoInvoiceItems((string)$serial);
            $remote=!empty($r['ok'])&&is_array($r['items']??null)?verify_cargo_item_map($r['items']):null;
        }catch(Throwable $e){$remote=null;}
        if($remote===null){$failed++;continue;}
        $checked++;$rows+=count($remote);if(!$remote)$empty++;
        if(!$db)continue;
        $q->execute([$uid,(string)$serial]);$local=verify_cargo_item_map($q->fetchAll(),true);
        if($local===null){$invalidLocal++;continue;}
        $missing+=count(array_diff_key($remote,$local));$extra+=count(array_diff_key($local,$remote));
        foreach($remote as $key=>$value)if(isset($local[$key])&&$local[$key]!==$value)$different++;
    }
    $ok=$invalid+$failed+$invalidLocal+$missing+$extra+$different===0;
    return ['ok'=>$ok,'domestic_invoices'=>count($serials),'outside_scope'=>$outside,'invalid_documents'=>$invalid,
        'checked_invoices'=>$checked,'failed_invoices'=>$failed,'empty_invoices'=>$empty,'provider_rows'=>$rows,
        'missing_local'=>$db?$missing:null,'extra_local'=>$db?$extra:null,'different_local'=>$db?$different:null,
        'invalid_local_invoices'=>$db?$invalidLocal:null,'nonempty_evidence'=>$rows>0,
        'matched'=>$db?($rows>0&&$ok&&$empty===0):null,'invoice_vat_verified'=>false];
}
/** Translate only known internal errors; arbitrary provider text never leaves the runner. */
function verify_catalog_error_code(mixed $error): string
{
    if(!is_string($error))return 'UNKNOWN';
    if(preg_match('/^Ürün V2 sayfası alınamadı \(HTTP ([0-9]{1,3})\)\.$/Du',$error,$m)){
        return match((int)$m[1]){0=>'TRANSPORT',200=>'RESPONSE_SCHEMA',400=>'HTTP_400',401=>'HTTP_401',403=>'HTTP_403',404=>'HTTP_404',429=>'HTTP_429',default=>'HTTP_OTHER'};
    }
    return match($error){
        'Ürün V2 toplam kayıt sayısı eksik.'=>'TOTAL_MISSING',
        'Katalog tarama sırasında değişti; yeniden deneyin.'=>'TOTAL_CHANGED',
        'Katalogda yinelenen içerik sayfası.'=>'DUPLICATE_CONTENT',
        'Katalogda yinelenen barkod.'=>'DUPLICATE_BARCODE',
        'Trendyol V2 ürün varyantları eksik.'=>'VARIANTS_MISSING',
        'Trendyol V2 barkodu eksik.'=>'BARCODE_MISSING',
        'Ürün V2 tutarsız kayıt sayısı döndürdü.'=>'TOTAL_MISMATCH',
        'Ürün V2 sayfalama tokenı eksik veya tekrar ediyor.'=>'PAGE_TOKEN',
        'Ürün V2 sayfa sınırına ulaşıldı; senkron tamamlanmadı.'=>'PAGE_LIMIT',
        default=>'UNKNOWN'
    };
}
/** Validate domestic Sale/Return statement arithmetic without revealing any values. */
function verify_sale_return_arithmetic(array $rows,string $type): array
{
    if(!in_array($type,['Sale','Return'],true))throw new InvalidArgumentException('Geçersiz işlem türü.');
    $seen=[];$checked=0;$invalid=0;$outside=0;
    foreach($rows as $row){
        $raw=is_array($row)?($row['raw']??null):null;
        $id=is_array($row)?($row['externalId']??null):null;
        if(!is_array($raw)||(!is_string($id)&&!is_int($id))||trim((string)$id)===''||
           (string)($raw['id']??'')!==(string)$id||($row['queryType']??null)!==$type||isset($seen[(string)$id])){
            $invalid++;continue;
        }
        $seen[(string)$id]=true;
        if(!finance_is_domestic($raw)){$outside++;continue;}
        try{
            $credit=finance_units($raw['credit']??null);$debt=finance_units($raw['debt']??null);
            $components=settlement_signed_components($raw,$type);
            $valid=$type==='Sale'
                ?($credit>0&&$debt===0&&abs($credit-$components['revenue']+$components['commission'])<=100)
                :($debt>0&&$credit===0&&abs($debt+$components['revenue']-$components['commission'])<=100);
            if(!$valid){$invalid++;continue;}
            $checked++;
        }catch(InvalidArgumentException $e){$invalid++;}
    }
    return ['ok'=>$invalid===0,'state'=>$invalid?'difference':($checked?'matched':'not_verified'),
        'validated_rows'=>$checked,'invalid_rows'=>$invalid,'outside_scope'=>$outside];
}
/** Check one completed payment from the validated first page, using the production arithmetic.
 * No payment identifier, amount, provider message or candidate details are returned. */
function verify_payout_sample(object $client,array $items,int $now,callable $pause): array
{
    $tz=new DateTimeZone('Europe/Istanbul');
    $today=(new DateTimeImmutable('@'.$now))->setTimezone($tz)->setTime(0,0)->getTimestamp();
    $eligible=[];
    foreach($items as $row){
        if(!is_array($row)||($row['status']??null)!=='PAID'||!ctype_digit((string)($row['id']??''))||
           !ctype_digit((string)($row['sellerId']??''))||!is_numeric($row['amount']??null)||!is_finite((float)$row['amount'])||(float)$row['amount']<0||
           !is_numeric($row['payoutDate']??null)||!is_finite((float)$row['payoutDate'])||(float)$row['payoutDate']<=0||
           (float)$row['payoutDate']!==(float)(int)$row['payoutDate'])
            return ['ok'=>false,'state'=>'invalid_payment','bank_verified'=>false];
        $ts=(int)$row['payoutDate'];if($ts>100000000000)$ts=intdiv($ts,1000);
        if($ts<$today)$eligible[]=[$ts,$row];
    }
    if(!$eligible)return ['ok'=>true,'state'=>'not_verified','bank_verified'=>false];
    usort($eligible,static fn($a,$b)=>$b[0]<=>$a[0]);
    $payment=$eligible[0][1];$id=(string)$payment['id'];$seller=(string)$payment['sellerId'];
    $types=['settlements'=>['Sale','Return','Discount','DiscountCancel','Coupon','CouponCancel','ProvisionPositive','ProvisionNegative',
        'ManualRefund','ManualRefundCancel','TyDiscount','TyDiscountCancel','TyCoupon','TyCouponCancel','SellerRevenuePositive','SellerRevenueNegative',
        'CommissionPositive','CommissionNegative','SellerRevenuePositiveCancel','SellerRevenueNegativeCancel','CommissionPositiveCancel',
        'CommissionNegativeCancel','DeliveryFee','DeliveryFeeCancel','PayByLink'],
        'otherfinancials'=>['Stoppage','CashAdvance','WireTransfer','IncomingTransfer','ReturnInvoice','CommissionAgreementInvoice','PaymentOrder','DeductionInvoices','FinancialItem']];
    $rows=['settlements'=>[],'otherfinancials'=>[]];
    foreach($types as $source=>$list)foreach($list as $type){
        $pause();
        try{$r=$client->getSettlements($type,'','',2000,$source,$id);}
        catch(Throwable $e){return ['ok'=>false,'state'=>'request_failed','bank_verified'=>false];}
        if(empty($r['ok'])||!is_array($r['settlements']??null))return ['ok'=>false,'state'=>'request_failed','bank_verified'=>false];
        foreach($r['settlements'] as $row){if(!is_array($row))return ['ok'=>false,'state'=>'invalid_payment','bank_verified'=>false];$rows[$source][]=$row;}
    }
    try{$result=finance_payout_candidate($rows['settlements'],$rows['otherfinancials'],$id,(float)$payment['amount'],$seller);}
    catch(Throwable $e){return ['ok'=>false,'state'=>'incomplete','bank_verified'=>false];}
    $state=$result['state'];
    return ['ok'=>$state==='matched','state'=>$state,'bank_verified'=>false];
}
/** Only counts, booleans and fixed labels leave this function. Never output provider errors or payloads. */
function verify_trendyol_read_only(object $client,?PDO $db,?int $uid,int $days,int $now,?callable $pace=null): array
{
    if($days<1||$days>14||($db!==null&&($uid===null||$uid<1)))throw new InvalidArgumentException('Geçersiz doğrulama kapsamı.');
    $checks=[];$errors=[];$pause=$pace??static fn()=>usleep(650000);
    $record=static function(string $label,callable $check)use(&$checks,&$errors){
        try{$v=$check();if(!is_array($v)||empty($v['ok'])){$errors[]=$label;$checks[$label]=is_array($v)?$v:['ok'=>false];}else $checks[$label]=$v;}
        catch(Throwable $e){$errors[]=$label;$checks[$label]=['ok'=>false];}
    };
    $record('connection',static fn()=>['ok'=>!empty($client->testConnection()['ok'])]);
    if($errors)return ['ok'=>false,'checks'=>$checks,'failed_checks'=>$errors,'read_only'=>true,'live_acceptance'=>false,'bank_verified'=>false];
    $barcodes=[];
    $record('catalog',static function()use($client,$db,$uid,&$barcodes){
        $r=$client->getProducts();if(empty($r['ok'])||!is_array($r['products']??null))return ['ok'=>false,'reason'=>verify_catalog_error_code($r['error']??null)];
        $local=[];$duplicate=0;$missing=0;$price=0;$stock=0;$unknown=0;$badPrice=false;$badStock=false;$inactiveUnpriced=0;$activeBadPrice=false;$unknownBadPrice=false;$seen=[];
        if($db){$q=$db->prepare("SELECT barcode,price,quantity,sale_price_observed_at FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode<>''");$q->execute([$uid]);while($p=$q->fetch()){if(isset($local[$p['barcode']]))$duplicate++;$local[$p['barcode']]=$p;}}
        foreach($r['products'] as $p){
            $bc=(string)($p['barcode']??'');if($bc===''||isset($seen[$bc]))return ['ok'=>false,'reason'=>$bc===''?'BARCODE_MISSING':'DUPLICATE_BARCODE'];$seen[$bc]=true;
            if(count($barcodes)<10)$barcodes[]=$bc;
            $priceValid=is_numeric($p['salePrice']??null)&&is_finite((float)$p['salePrice'])&&(float)$p['salePrice']>0;
            $stockValid=is_numeric($p['quantity']??null)&&is_finite((float)$p['quantity'])&&(float)$p['quantity']>=0&&(float)$p['quantity']===(float)(int)$p['quantity'];
            // Explicitly inactive variants may have no sale price; never treat it as a usable price.
            $status=$p['status']??'unknown';
            $inactiveNoPrice=!$priceValid&&$status==='notOnSale'&&in_array($p['sale_price_state']??null,['missing','zero'],true);
            if($inactiveNoPrice)$inactiveUnpriced++;
            if(!$priceValid&&!$inactiveNoPrice){
                $badPrice=true;$activeBadPrice=$activeBadPrice||$status==='onSale';
                $unknownBadPrice=$unknownBadPrice||$status!=='onSale'&&$status!=='notOnSale';
            }
            $badStock=$badStock||!$stockValid;
            if((!$priceValid&&!$inactiveNoPrice)||!$stockValid)$unknown++;
            if(!$db)continue;
            if(!isset($local[$bc])){$missing++;continue;}
            if($priceValid&&abs((float)$local[$bc]['price']-(float)$p['salePrice'])>0.005)$price++;
            if($inactiveNoPrice&&$local[$bc]['sale_price_observed_at']!==null)$price++;
            if($stockValid&&(int)$local[$bc]['quantity']!==(int)$p['quantity'])$stock++;
        }
        $extra=$db?count(array_diff_key($local,$seen)):null;
        return ['ok'=>true,'provider_rows'=>count($seen),'unknown_price_or_stock'=>$unknown,'inactive_unpriced'=>$inactiveUnpriced,'reason'=>$badPrice?($badStock?'PRICE_AND_STOCK_INVALID':($activeBadPrice?'ACTIVE_PRICE_INVALID':($unknownBadPrice?'UNKNOWN_STATUS_PRICE_INVALID':'PRICE_INVALID'))):($badStock?'STOCK_INVALID':null),'missing_local'=>$db?$missing:null,'extra_local'=>$extra,'duplicate_local'=>$db?$duplicate:null,'price_differences'=>$db?$price:null,'stock_differences'=>$db?$stock:null,'matched'=>$db?($missing+$extra+$duplicate+$price+$stock+$unknown===0):null];
    });
    $from=$now-$days*86400;$start=date('c',$from);$end=date('c',$now);
    $record('orders',static function()use($client,$db,$uid,$start,$end){
        $r=$client->getOrders('',$start,$end);if(empty($r['ok'])||!is_array($r['orders']??null))return ['ok'=>false];
        $missing=0;$diff=0;$unknown=0;$unknownOrderFields=0;$seen=[];
        if($db)$q=$db->prepare("SELECT total_price,status,lines,currency_code,country_code FROM orders WHERE user_id=? AND marketplace='trendyol' AND package_number=?");
        foreach($r['orders'] as $o){
            $id=(string)($o['packageNumber']??'');if($id===''||isset($seen[$id]))return ['ok'=>false];$seen[$id]=true;
            $lines=verify_order_lines($o['lines']??null);if($lines===null)$unknown++;
            $total=$o['totalPrice']??null;
            if(!is_numeric($total)||!is_finite((float)$total)||(float)$total<0||!is_string($o['status']??null)||trim($o['status'])==='')$unknownOrderFields++;
            if(!$db)continue;
            $q->execute([$uid,$id]);$p=$q->fetch();if(!$p){$missing++;continue;}
            if(!is_numeric($o['totalPrice']??null)||abs((float)$p['total_price']-(float)$o['totalPrice'])>0.005||$p['status']!==($o['status']??null)||$lines===null||$lines!==verify_order_lines($p['lines'])||$p['currency_code']!==($o['currencyCode']??null)||$p['country_code']!==($o['orderCountryCode']??null))$diff++;
        }
        return ['ok'=>true,'provider_rows'=>count($seen),'unknown_lines'=>$unknown,'unknown_order_fields'=>$unknownOrderFields,'missing_local'=>$db?$missing:null,'differences'=>$db?$diff:null,'matched'=>$db?($missing+$diff+$unknown+$unknownOrderFields===0):null];
    });
    $saleReturnChecks=[];
    foreach(['Sale','Return','Discount','DiscountCancel','Coupon','CouponCancel','TyDiscount','TyCoupon','DeliveryFee','DeliveryFeeCancel'] as $type){
        $pause();$record('settlement_'.$type,static function()use($client,$start,$end,$type,&$saleReturnChecks){
            $r=$client->getSettlements($type,$start,$end);
            if(empty($r['ok'])||!is_array($r['settlements']??null))return ['ok'=>false];
            if(in_array($type,['Sale','Return'],true))$saleReturnChecks[$type]=verify_sale_return_arithmetic($r['settlements'],$type);
            return ['ok'=>true,'rows'=>count($r['settlements'])];
        });
    }
    foreach(['Sale','Return'] as $type)$record('statement_arithmetic_'.$type,static fn()=>$saleReturnChecks[$type]??['ok'=>false,'state'=>'request_failed']);
    $deductionDocuments=null;
    foreach(['Stoppage','DeductionInvoices','PaymentOrder','CommissionAgreementInvoice','FinancialItem'] as $type){
        $pause();$record('document_'.$type,static function()use($client,$start,$end,$type,&$deductionDocuments){
            $r=$client->getSettlements($type,$start,$end,2000,'otherfinancials');
            if(empty($r['ok'])||!is_array($r['settlements']??null))return ['ok'=>false];
            if($type==='DeductionInvoices')$deductionDocuments=$r['settlements'];
            return ['ok'=>true,'rows'=>count($r['settlements'])];
        });
    }
    $record('cargo_invoice_items',static fn()=>$deductionDocuments===null?['ok'=>false]:verify_cargo_invoices($client,$db,$uid,$deductionDocuments,$pause));
    $pause();$record('platform_service_fee',static function()use($client,$start,$end){
        $r=$client->getSettlements('DeductionInvoices',$start,$end,2000,'otherfinancials',null,'PlatformServiceFee');return ['ok'=>!empty($r['ok']),'rows'=>count($r['settlements']??[])];
    });
    $record('claims',static function()use($client,$from,$now){$r=$client->getClaims(max($from,$now-14*86400+1),$now);return ['ok'=>!empty($r['ok']),'rows'=>count($r['items']??[])];});
    $payoutItems=null;
    $record('payout_page',static function()use($client,&$payoutItems){
        $r=$client->getPaymentOrders(0);if(empty($r['ok'])||!is_array($r['items']??null))return ['ok'=>false];
        $payoutItems=$r['items'];return ['ok'=>true,'rows'=>count($payoutItems),'pages'=>(int)($r['totalPages']??0)];
    });
    $record('payout_sample_arithmetic',static fn()=>$payoutItems===null?['ok'=>false,'state'=>'request_failed']:verify_payout_sample($client,$payoutItems,$now,$pause));
    if($barcodes)$record('buybox',static function()use($client,$barcodes){$r=$client->getBuybox($barcodes);return ['ok'=>!empty($r['ok']),'rows'=>count($r['items']??[]),'missing'=>count($r['missing']??[])];});
    // Successful HTTP calls are not enough: malformed normalized data must fail in both modes.
    foreach(['catalog'=>['unknown_price_or_stock'],'orders'=>['unknown_lines','unknown_order_fields']] as $label=>$fields){
        foreach($fields as $field){
            if(($checks[$label][$field]??0)>0){
                $checks[$label]['ok']=false;
                if(!in_array($label,$errors,true))$errors[]=$label;
            }
        }
    }
    $match=$db?(!empty($checks['catalog']['matched'])&&!empty($checks['orders']['matched'])):null;
    return ['ok'=>!$errors&&($match!==false),'checks'=>$checks,'failed_checks'=>$errors,'local_comparison'=>$match,
        'read_only'=>true,'live_acceptance'=>false,'bank_verified'=>false,'limits'=>['Seçili sorgular ve yerel katalog/sipariş gözlemi; tüm mali hareketlerin panel mutabakatı değildir.','Boş sonuçlar ilgili senaryonun doğrulandığını kanıtlamaz.','Kargo karşılaştırması seçilen yerli fatura kalemleriyle sınırlıdır; tarife, KDV, belge toplamı ve banka mutabakatı ayrıca gerekir.']];
}
