<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/profit.php';

function cargo_carrier(string $raw): ?string
{
    $v=mb_strtolower(trim($raw),'UTF-8');
    foreach(['TEX'=>['trendyol express','trendyol express marketplace','tex','texmp'],'PTT'=>['ptt kargo','ptt kargo marketplace','ptt','pttmp'],'ARAS'=>['aras kargo','aras kargo marketplace','aras','arasmp'],'DHL'=>['dhl ecommerce','dhl ecommerce marketplace','dhlecommp'],'SURAT'=>['sürat kargo','sürat kargo marketplace','suratmp'],'KG'=>['kolay gelsin','kolay gelsin marketplace','sendeomp','kolaygelsinmp'],'YK'=>['yurtiçi kargo','yurtiçi kargo marketplace','ykmp'],'CEVA'=>['ceva','ceva marketplace','cevamp'],'HOROZ'=>['horoz kargo','horoz kargo marketplace','horozmp']] as $id=>$names)if(in_array($v,$names,true))return $id;
    return null;
}
/** Only exact, dated, unambiguous rate rows; no invented prices. */
function cargo_tariff_quote(array $t,string $carrier,float $desi,float $sale,string $day): ?array
{
    if(!is_finite($desi)||$desi<=0||$desi>500||!is_finite($sale)||$sale<=0||$day<($t['from']??'9999')||$day>($t['through']??'0000'))return null;
    $matches=[];
    foreach($t['row_index'][$carrier.':'.(int)ceil($desi)]??($t['rows']??[]) as $r){
        if(!is_array($r)||($r['carrier']??'')!==$carrier||(int)ceil($desi)!==($r['desi']??null)||!is_numeric($r['min_sale']??null)||!array_key_exists('max_sale',$r))continue;
        if($sale<(float)$r['min_sale']||($r['max_sale']!==null&&(!is_numeric($r['max_sale'])||$sale>(float)$r['max_sale'])))continue;
        $matches[]=$r;
    }
    if(count($matches)!==1)return null;
    try{$units=finance_units($matches[0]['gross_tl']??null);if($units<=0)return null;}catch(InvalidArgumentException $e){return null;}
    $row=$matches[0];$kind=$row['kind']??'standard_desi';$fast=null;
    if($kind==='barem_scenario'){
        try{$v=finance_units($row['fast_gross_tl']??null);if($v<=0||$v>$units)return null;$fast=$v/10000.0;}
        catch(InvalidArgumentException $ex){return null;}
    }
    return ['amount'=>$units/10000.0,'desi'=>$desi,'carrier'=>$carrier,'tariff_from'=>$t['from'],'tariff_through'=>$t['through'],'source'=>$t['source']??'','confidence'=>$t['confidence']??'operator_reference','kind'=>$kind,'fast_scenario'=>$fast];
}
/** Reject malformed or overlapping rate bands before activation. */
function cargo_tariff_valid(array $t): bool
{
    foreach(['from','through'] as $k){
        $s=$t[$k]??null;if(!is_string($s)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$s))return false;
        [$y,$m,$d]=array_map('intval',explode('-',$s));if(!checkdate($m,$d,$y))return false;
    }
    if($t['from']>$t['through']||!is_string($t['source']??null)||trim($t['source'])===''||!is_array($t['rows']??null)||!$t['rows']||count($t['rows'])>10000)return false;
    $bands=[];
    foreach($t['rows'] as $r){
        if(!is_array($r)||!in_array($r['carrier']??null,['TEX','PTT','ARAS','DHL','SURAT','KG','YK','CEVA','HOROZ'],true)||!is_int($r['desi']??null)||$r['desi']<1||$r['desi']>500||!array_key_exists('max_sale',$r))return false;
        try{
            $min=finance_units($r['min_sale']??null);$max=$r['max_sale']===null?PHP_INT_MAX:finance_units($r['max_sale']);$gross=finance_units($r['gross_tl']??null);
            if($min<0||$max<$min||$gross<=0)return false;
            if(!in_array($r['kind']??'standard_desi',['standard_desi','barem_scenario'],true))return false;
            if(($r['kind']??'')==='barem_scenario'){
                $fast=finance_units($r['fast_gross_tl']??null);if($fast<=0||$fast>$gross||$r['desi']>10||$max>=3500000)return false;
            }
        }catch(InvalidArgumentException $ex){return false;}
        $key=$r['carrier'].':'.$r['desi'];
        foreach($bands[$key]??[] as [$lo,$hi])if($min<=$hi&&$max>=$lo)return false;
        $bands[$key][]=[$min,$max];
    }
    return true;
}
function cargo_tariff_index(array $t): array
{
    $t['row_index']=[];
    foreach($t['rows'] as $r)$t['row_index'][$r['carrier'].':'.$r['desi']][]=$r;
    return $t;
}
function cargo_tariffs(): array
{
    $out=[];
    foreach(glob(dirname(DB_PATH).'/cargo-tariffs/*.json')?:[] as $path){
        if(!is_file($path)||filesize($path)>4000000)continue;
        $t=json_decode((string)file_get_contents($path),true);
        if(!is_array($t)||!cargo_tariff_valid($t))continue;
        $out[]=cargo_tariff_index($t);
    }
    // Secondary-source fallback is bounded to the last researched date; never claim official/current indefinitely.
    $fallback=require __DIR__.'/cargo_reference_20261005.php';
    if(cargo_tariff_valid($fallback))$out[]=cargo_tariff_index($fallback);
    return $out;
}
function cargo_has_shipped(array $order): bool
{
    return in_array(strtolower((string)($order['status']??'')),['shipped','delivered','atcollectionpoint','undelivered','returned'],true);
}
function cargo_positive_desi($v): ?float
{
    return is_numeric($v)&&is_finite((float)$v)&&(float)$v>0&&(float)$v<=500?(float)$v:null;
}
function cargo_quote_context(array $order,array $products): ?array
{
    if(($order['currencyCode']??null)!=='TRY'||($order['orderCountryCode']??null)!=='TR'||!empty($order['sellerCargoAgreement']))return null;
    $carrier=cargo_carrier((string)($order['cargoCompany']??''));if($carrier===null)return null;
    $lines=$order['lines']??[];$desi=null;$source=null;
    $apiDesi=cargo_positive_desi($order['cargoDeci']??null);
    if(cargo_has_shipped($order)){
        // After shipment the latest package observation wins; missing is not the old estimate.
        $desi=$apiDesi;$source='shipment_api';
    }elseif(is_array($lines)&&count($lines)===1&&($lines[0]['quantity']??null)==1){
        $p=$products[(string)($lines[0]['barcode']??'')]??[];
        $desi=cargo_positive_desi($p['expected_desi']??null);
        if($desi===null&&($p['en']??0)>0&&($p['boy']??0)>0&&($p['yukseklik']??0)>0)$desi=cargo_positive_desi((float)$p['en']*(float)$p['boy']*(float)$p['yukseklik']/3000);
        if($desi!==null)$source='declared_product';
    }
    if($desi===null&&!cargo_has_shipped($order)&&$apiDesi!==null){$desi=$apiDesi;$source='pre_shipment_api';}
    if($desi===null)return null;
    $ts=(int)($order['orderDate']??0);if($ts>100000000000)$ts=intdiv($ts,1000);if($ts<=0)return null;
    $day=date('Y-m-d',$ts);$sale=$order['totalPrice']??null;
    if(!is_numeric($sale)||!is_finite((float)$sale)||(float)$sale<=0)return null;
    return ['carrier'=>$carrier,'desi'=>$desi,'day'=>$day,'sale'=>(float)$sale,'desi_source'=>$source,
        'barem_candidate'=>($order['createdBy']??null)==='order-creation'&&empty($order['originPackageIds']),
        'observed_at'=>time(),'order_modified_ms'=>(int)($order['lastModifiedDate']??0)];
}
function cargo_quote_from_context(array $context,array $tariffs): ?array
{
    $carrier=$context['carrier']??null;$desi=cargo_positive_desi($context['desi']??null);
    $day=$context['day']??null;$sale=$context['sale']??null;
    if(!in_array($carrier,['TEX','PTT','ARAS','DHL','SURAT','KG','YK','CEVA','HOROZ'],true)||$desi===null||
       !is_string($day)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)||!is_numeric($sale)||!is_finite((float)$sale)||(float)$sale<=0||
       !in_array($context['desi_source']??null,['shipment_api','declared_product','pre_shipment_api'],true))return null;
    // Operator table wins only for its own effective dates; never fill a missing official row from secondary data.
    $primary=array_values(array_filter($tariffs,static fn($t)=>($t['confidence']??'')!=='secondary_partial'&&$day>=($t['from']??'9999')&&$day<=($t['through']??'0000')));
    $candidates=$primary?:$tariffs;$quotes=[];
    foreach($candidates as $t){$q=cargo_tariff_quote($t,$carrier,$desi,(float)$sale,$day);if($q)$quotes[]=$q;}
    if(count($quotes)!==1)return null;
    $q=$quotes[0];
    // Package total is not necessarily order total; split/transfer/cancel or unknown origin cannot establish barem eligibility.
    if($q['kind']==='barem_scenario'&&($context['barem_candidate']??null)!==true)return null;
    return $q+['desi_source'=>$context['desi_source'],'observed_at'=>(int)($context['observed_at']??0),'order_modified_ms'=>(int)($context['order_modified_ms']??0)];
}
function cargo_order_quote(array $order,array $products,array $tariffs): ?array
{
    $context=cargo_quote_context($order,$products);
    return $context===null?null:cargo_quote_from_context($context,$tariffs);
}
/** Recompute against today's installed version of the historical tariff using the saved observation.
 * Never reconstruct past product desi from today's catalog or overwrite first forecast. */
function cargo_reprice_stored(array $p,array $tariffs): array
{
    $raw=$p['cargo_quote_context']??null;
    $p['cargo_context_known']=is_string($raw)&&$raw!=='';
    if(!$p['cargo_context_known'])return $p;
    $context=json_decode($raw,true);$quote=null;
    if(is_array($context)&&order_is_domestic_try($p)&&order_is_active_sale($p)&&
       ($context['carrier']??null)===cargo_carrier((string)($p['cargo_provider']??''))&&
       ($context['day']??null)===date('Y-m-d',(int)($p['order_date']??0))&&
       is_numeric($context['sale']??null)&&abs((float)$context['sale']-(float)($p['total_price']??0))<0.005&&
       (int)($context['order_modified_ms']??-1)===(int)($p['last_modified_ms']??0)&&
       (!cargo_has_shipped($p)||(($context['desi_source']??null)==='shipment_api'&&cargo_positive_desi($context['desi']??null)===cargo_positive_desi($p['cargo_api_desi']??null)))){
        $quote=cargo_quote_from_context($context,$tariffs);
    }
    $p['cargo_estimate']=$quote['amount']??null;
    $p['cargo_estimate_json']=$quote?json_encode($quote,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):null;
    return $p;
}
/** Order-level amount, not package allocation; return freight remains separate. */
function cargo_effective(array $packages,array $rows,string $seller): array
{
    $est=0;$estOk=true;$packageQuotes=[];$first=0;$firstOk=true;$actual=0;$billedDesi=0.0;$billedDesiOk=true;$gross=0;$grossOk=true;$n=0;$bad=false;$seen=[];$returnSeen=false;
    $no=(string)($packages[0]['order_number']??'');
    foreach($packages as $p){
        $pid=(string)($p['package_number']??'');if($pid!=='')$packageQuotes[$pid]=['amount'=>$p['cargo_estimate']??null,'meta'=>$p['cargo_estimate_json']??null,'context_known'=>!empty($p['cargo_context_known'])];
        try{$v=finance_units($p['cargo_first_estimate']??null);if($v<=0)throw new InvalidArgumentException('İlk tahmin');$first+=$v;}catch(InvalidArgumentException $e){$firstOk=false;}
        if(!order_is_active_sale($p)||!order_is_domestic_try($p)||!is_numeric($p['cargo_estimate']??null)){$estOk=false;continue;}
        try{$est+=finance_units($p['cargo_estimate']);}catch(InvalidArgumentException $e){$estOk=false;}
    }
    foreach($rows as $r){
        if(($r['shipment_type']??'')!=='Gönderi Kargo Bedeli'){$returnSeen=true;continue;}
        $raw=json_decode((string)($r['invoice_raw']??''),true);
        $serial=(string)($r['invoice_serial']??'');$parcel=(string)($r['parcel_id']??'');$key=$serial.'|'.$parcel;
        if($seller===''||$no===''||($r['order_number']??null)!==$no||$serial===''||$parcel===''||isset($seen[$key])||!is_array($raw)||!finance_is_domestic($raw)||(string)($raw['sellerId']??'')!==$seller||(string)($raw['id']??'')!==$serial){$bad=true;continue;}
        $seen[$key]=true;
        try{$v=finance_units($r['amount']??null);if($v<0)throw new InvalidArgumentException('Kargo');$actual+=$v;$n++;$desi=cargo_positive_desi($r['desi']??null);if($desi===null)$billedDesiOk=false;else $billedDesi+=$desi;
            $basis=$r['invoice_basis']??null;
            if($basis==='gross')$gross+=$v;
            elseif($basis==='net')$gross+=(int)round($v*1.2,0,PHP_ROUND_HALF_UP);
            else $grossOk=false;}catch(InvalidArgumentException $e){$bad=true;}
    }
    $estimate=$packages&&$estOk?$est/10000.0:null;$invoiced=$n&&!$bad?$actual/10000.0:null;
    $complete=count($packages)===1&&order_is_domestic_try($packages[0])&&order_is_active_sale($packages[0]);
    $comparison=$complete&&$n===1&&!$bad&&!$returnSeen&&$grossOk&&$estimate!==null;
    $grossDifference=$comparison?($gross-$est)/10000.0:null;
    $apiDesi=$complete?cargo_positive_desi($packages[0]['cargo_api_desi']??null):null;
    $desiDifference=$comparison&&$billedDesiOk&&$apiDesi!==null?$billedDesi-$apiDesi:null;
    return ['gross_difference'=>$grossDifference,'desi_difference'=>$desiDifference,'package_quotes'=>$packageQuotes,'initial_estimate'=>$packages&&$firstOk?$first/10000.0:null,'estimate'=>$estimate,'api_desi'=>count($packages)===1?cargo_positive_desi($packages[0]['cargo_api_desi']??null):null,'carrier'=>count($packages)===1?($packages[0]['cargo_provider']??null):null,'billed_desi'=>$n&&!$bad&&$billedDesiOk?$billedDesi:null,'invoiced'=>$invoiced,'selected'=>$bad?null:($invoiced!==null?($complete?$invoiced:null):$estimate),'state'=>$bad?'Fatura çelişkili':($invoiced!==null?($complete?'Faturalanmış gönderi tutarı':'Çoklu paket: fatura kapsamı doğrulanmalı'):($estimate!==null?'Tahmini (KDV dahil tarife)':'Tarife/desi eksik')),'invoice_rows'=>$n,'gross_for_profit'=>$bad||$returnSeen?null:($n?($complete&&$grossOk?$gross/10000.0:null):($complete?$estimate:null)),'invoice_present'=>$n>0||$bad,'other_cargo_present'=>$returnSeen,'gross_basis'=>$n&&$grossOk&&!$bad?'invoice_total_match':null];
}
/** Infer VAT basis only if ALL unique nonnegative invoice detail rows reconcile to the domestic invoice debt.
 * This is an arithmetic consistency check, not independent proof of tax treatment. */
function cargo_invoice_basis(array $raw,array $items,string $seller,string $serial): ?string
{
    if($seller===''||!finance_is_domestic($raw)||(string)($raw['sellerId']??'')!==$seller||(string)($raw['id']??'')!==$serial||!$items)return null;
    try{
        $debt=finance_units($raw['debt']??null);$credit=finance_units($raw['credit']??null);
        if($credit!==0||$debt<=0)return null;
        $sum=0;$seen=[];
        foreach($items as $r){
            $parcel=(string)($r['parcel_id']??'');$kind=(string)($r['shipment_type']??'');$key=$parcel.'|'.$kind;
            if($parcel===''||!in_array($kind,['Gönderi Kargo Bedeli','İade Kargo Bedeli'],true)||isset($seen[$key])||(string)($r['invoice_serial']??'')!==$serial)return null;
            $seen[$key]=true;$v=finance_units($r['amount']??null);if($v<0)return null;$sum+=$v;
        }
        if($sum<=0)return null;
        $gross=abs($debt-$sum)<=100;$net=abs($debt-(int)round($sum*1.2,0,PHP_ROUND_HALF_UP))<=100;
        return $gross!==$net?($gross?'gross':'net'):null;
    }catch(InvalidArgumentException $e){return null;}
}
function cargo_invoice_basis_map(PDO $db,int $uid,string $seller,array $serials): array
{
    $out=[];
    foreach(array_chunk(array_values(array_unique($serials)),100) as $chunk){
        if(!$chunk)continue;
        $in=implode(',',array_fill(0,count($chunk),'?'));$args=array_merge([$uid],$chunk);$groups=[];$raws=[];
        $q=$db->prepare("SELECT external_id,raw_json FROM trendyol_financial_documents WHERE user_id=? AND query_type='DeductionInvoices' AND external_id IN ($in)");$q->execute($args);
        while($r=$q->fetch())$raws[$r['external_id']]=json_decode($r['raw_json'],true);
        $q=$db->prepare("SELECT invoice_serial,parcel_id,shipment_type,amount FROM trendyol_cargo_items WHERE user_id=? AND invoice_serial IN ($in)");$q->execute($args);
        while($r=$q->fetch())$groups[$r['invoice_serial']][]=$r;
        foreach($chunk as $serial)$out[$serial]=is_array($raws[$serial]??null)?cargo_invoice_basis($raws[$serial],$groups[$serial]??[],$seller,(string)$serial):null;
    }
    return $out;
}
/** Batched read-time overlay; no network, no mutating original estimate. */
function cargo_profit_overlay(PDO $db,int $uid,array $orders): array
{
    $numbers=[];foreach($orders as $o)if(($o['marketplace']??'')==='trendyol')$numbers[]=(string)$o['order_number'];
    $evidence=cargo_effective_report($db,$uid,$numbers);
    foreach($orders as &$o){
        if(($o['marketplace']??'')!=='trendyol')continue;
        $a=$evidence[(string)$o['order_number']]??null;
        if(!$a)continue;
        if($a['invoice_present']||$a['other_cargo_present']){
            $o['cargo_override']=$a['gross_for_profit'];
            $o['cargo_source']=$a['gross_for_profit']!==null?'Fatura (toplamla uyumlu KDV varsayımı)':'Fatura kapsamı/KDV veya iade doğrulanmalı';
        }else{
            $pq=$a['package_quotes'][(string)($o['package_number']??'')]??null;
            $amount=$pq['amount']??null;
            if(is_numeric($amount)&&(float)$amount>0){
            $o['cargo_override']=(float)$amount;$meta=json_decode((string)($pq['meta']??''),true);$o['cargo_source']=($meta['desi_source']??'')==='shipment_api'?'Güncel API desisi / tarife tahmini':'İlk desi / tarife tahmini';if(($meta['kind']??'')==='barem_scenario')$o['cargo_source'].=' (standart barem varsayımı)';if(($meta['confidence']??'')==='secondary_partial')$o['cargo_source'].=' (ikincil kaynak)';
            if(empty($pq['context_known']))$o['cargo_source'].=' (eski kayıt; yeniden senkron gerekli)';
            }elseif(cargo_has_shipped($o)||!empty($pq['context_known'])){$o['cargo_override']=null;$o['cargo_source']='Gönderi desisi veya geçerli tarife eksik';}
            // Only pre-shipment legacy records without observed inputs may use an explicit catalog estimate.
        }
    }unset($o);
    return $orders;
}
function cargo_effective_report(PDO $db,int $uid,array $numbers,?array $tariffs=null): array
{
    $q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
    $tariffs=$tariffs??cargo_tariffs();$out=[];$basisCache=[];$numbers=array_values(array_unique(array_filter(array_map('strval',$numbers),static fn($s)=>$s!=='')));
    foreach(array_chunk($numbers,200) as $chunk){
        $in=implode(',',array_fill(0,count($chunk),'?'));$args=array_merge([$uid],$chunk);$p=[];$c=[];
        $q=$db->prepare("SELECT * FROM orders WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)");$q->execute($args);while($r=$q->fetch())$p[$r['order_number']][]=cargo_reprice_stored($r,$tariffs);
        $q=$db->prepare("SELECT c.*,d.raw_json AS invoice_raw FROM trendyol_cargo_items c LEFT JOIN trendyol_financial_documents d ON d.user_id=c.user_id AND d.external_id=c.invoice_serial AND d.query_type='DeductionInvoices' WHERE c.user_id=? AND c.order_number IN ($in)");$q->execute($args);while($r=$q->fetch())$c[$r['order_number']][]=$r;
        $serials=[];foreach($c as $group)foreach($group as $row)if(!array_key_exists($row['invoice_serial'],$basisCache))$serials[]=(string)$row['invoice_serial'];
        $basisCache+=cargo_invoice_basis_map($db,$uid,$seller,$serials);
        foreach($c as &$group)foreach($group as &$row)$row['invoice_basis']=$basisCache[$row['invoice_serial']]??null;unset($row,$group);
        foreach($chunk as $no)if(isset($p[$no]))$out[$no]=cargo_effective($p[$no],$c[$no]??[],$seller);
    }
    return $out;
}
