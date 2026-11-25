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
    foreach($t['rows']??[] as $r){
        if(!is_array($r)||($r['carrier']??'')!==$carrier||(int)ceil($desi)!==($r['desi']??null)||!is_numeric($r['min_sale']??null)||!array_key_exists('max_sale',$r))continue;
        if($sale<(float)$r['min_sale']||($r['max_sale']!==null&&(!is_numeric($r['max_sale'])||$sale>(float)$r['max_sale'])))continue;
        $matches[]=$r;
    }
    if(count($matches)!==1)return null;
    try{$units=finance_units($matches[0]['gross_tl']??null);if($units<=0)return null;}catch(InvalidArgumentException $e){return null;}
    return ['amount'=>$units/10000.0,'desi'=>$desi,'carrier'=>$carrier,'tariff_from'=>$t['from'],'source'=>$t['source']??'','confidence'=>$t['confidence']??'operator_reference'];
}
function cargo_tariffs(): array
{
    $out=[];
    foreach(glob(dirname(DB_PATH).'/cargo-tariffs/*.json')?:[] as $path){
        if(!is_file($path)||filesize($path)>4000000)continue;
        $t=json_decode((string)file_get_contents($path),true);
        if(!is_array($t)||!is_string($t['source']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$t['from']??'')||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$t['through']??'')||$t['from']>$t['through']||!is_array($t['rows']??null))continue;
        $out[]=$t;
    }
    // Secondary-source fallback is bounded to the last researched date; never claim official/current indefinitely.
    $fallback=require __DIR__.'/cargo_reference_20261005.php';
    $fallback['rows']=array_values(array_filter($fallback['rows'],static function($r)use($out,$fallback){
        foreach($out as $t)if($t['from']<=$fallback['through']&&$t['through']>=$fallback['from'])return false;
        return true;
    }));
    if($fallback['rows'])$out[]=$fallback;
    return $out;
}
function cargo_order_quote(array $order,array $products,array $tariffs): ?array
{
    if(($order['currencyCode']??null)!=='TRY'||($order['orderCountryCode']??null)!=='TR'||!empty($order['sellerCargoAgreement']))return null;
    $carrier=cargo_carrier((string)($order['cargoCompany']??''));if($carrier===null)return null;
    $lines=$order['lines']??[];$desi=null;
    if(count($lines)===1&&($lines[0]['quantity']??null)==1){
        $p=$products[(string)($lines[0]['barcode']??'')]??[];
        if(is_numeric($p['expected_desi']??null)&&(float)$p['expected_desi']>0)$desi=(float)$p['expected_desi'];
        elseif(($p['en']??0)>0&&($p['boy']??0)>0&&($p['yukseklik']??0)>0)$desi=(float)$p['en']*(float)$p['boy']*(float)$p['yukseklik']/3000;
    }
    if($desi===null&&is_numeric($order['cargoDeci']??null)&&(float)$order['cargoDeci']>0)$desi=(float)$order['cargoDeci'];
    if($desi===null)return null;
    $ts=(int)($order['orderDate']??0);if($ts>100000000000)$ts=intdiv($ts,1000);if($ts<=0)return null;
    $quotes=[];foreach($tariffs as $t){$q=cargo_tariff_quote($t,$carrier,$desi,(float)($order['totalPrice']??0),date('Y-m-d',$ts));if($q)$quotes[]=$q;}
    return count($quotes)===1?$quotes[0]:null;
}
/** Order-level amount, not package allocation; return freight remains separate. */
function cargo_effective(array $packages,array $rows,string $seller): array
{
    $est=0;$estOk=true;$actual=0;$gross=0;$grossOk=true;$n=0;$bad=false;$seen=[];$returnSeen=false;
    $no=(string)($packages[0]['order_number']??'');
    foreach($packages as $p){
        if(!order_is_active_sale($p)||!order_is_domestic_try($p)||!is_numeric($p['cargo_estimate']??null)){$estOk=false;continue;}
        try{$est+=finance_units($p['cargo_estimate']);}catch(InvalidArgumentException $e){$estOk=false;}
    }
    foreach($rows as $r){
        if(($r['shipment_type']??'')!=='Gönderi Kargo Bedeli'){$returnSeen=true;continue;}
        $raw=json_decode((string)($r['invoice_raw']??''),true);
        $serial=(string)($r['invoice_serial']??'');$parcel=(string)($r['parcel_id']??'');$key=$serial.'|'.$parcel;
        if($seller===''||$no===''||($r['order_number']??null)!==$no||$serial===''||$parcel===''||isset($seen[$key])||!is_array($raw)||!finance_is_domestic($raw)||(string)($raw['sellerId']??'')!==$seller||(string)($raw['id']??'')!==$serial){$bad=true;continue;}
        $seen[$key]=true;
        try{$v=finance_units($r['amount']??null);if($v<0)throw new InvalidArgumentException('Kargo');$actual+=$v;$n++;
            $basis=$r['invoice_basis']??null;
            if($basis==='gross')$gross+=$v;
            elseif($basis==='net')$gross+=(int)round($v*1.2,0,PHP_ROUND_HALF_UP);
            else $grossOk=false;}catch(InvalidArgumentException $e){$bad=true;}
    }
    $estimate=$packages&&$estOk?$est/10000.0:null;$invoiced=$n&&!$bad?$actual/10000.0:null;
    $complete=count($packages)===1&&order_is_domestic_try($packages[0])&&order_is_active_sale($packages[0]);
    return ['estimate'=>$estimate,'invoiced'=>$invoiced,'selected'=>$bad?null:($invoiced!==null?($complete?$invoiced:null):$estimate),'state'=>$bad?'Fatura çelişkili':($invoiced!==null?($complete?'Faturalanmış gönderi tutarı':'Çoklu paket: fatura kapsamı doğrulanmalı'):($estimate!==null?'Tahmini (KDV dahil tarife)':'Tarife/desi eksik')),'invoice_rows'=>$n,'gross_for_profit'=>$bad||$returnSeen?null:($n?($complete&&$grossOk?$gross/10000.0:null):($complete?$estimate:null)),'invoice_present'=>$n>0||$bad,'other_cargo_present'=>$returnSeen,'gross_basis'=>$n&&$grossOk&&!$bad?'invoice_total_match':null];
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
        }elseif(is_numeric($o['cargo_estimate']??null)&&(float)$o['cargo_estimate']>0){
            $o['cargo_override']=(float)$o['cargo_estimate'];$o['cargo_source']='Tarife tahmini';
        }
        // An explicit legacy product cargo estimate may remain available for a single line.
    }unset($o);
    return $orders;
}
function cargo_effective_report(PDO $db,int $uid,array $numbers): array
{
    $q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
    $out=[];$basisCache=[];$numbers=array_values(array_unique(array_filter(array_map('strval',$numbers),static fn($s)=>$s!=='')));
    foreach(array_chunk($numbers,200) as $chunk){
        $in=implode(',',array_fill(0,count($chunk),'?'));$args=array_merge([$uid],$chunk);$p=[];$c=[];
        $q=$db->prepare("SELECT * FROM orders WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)");$q->execute($args);while($r=$q->fetch())$p[$r['order_number']][]=$r;
        $q=$db->prepare("SELECT c.*,d.raw_json AS invoice_raw FROM trendyol_cargo_items c LEFT JOIN trendyol_financial_documents d ON d.user_id=c.user_id AND d.external_id=c.invoice_serial AND d.query_type='DeductionInvoices' WHERE c.user_id=? AND c.order_number IN ($in)");$q->execute($args);while($r=$q->fetch())$c[$r['order_number']][]=$r;
        $serials=[];foreach($c as $group)foreach($group as $row)if(!array_key_exists($row['invoice_serial'],$basisCache))$serials[]=(string)$row['invoice_serial'];
        $basisCache+=cargo_invoice_basis_map($db,$uid,$seller,$serials);
        foreach($c as &$group)foreach($group as &$row)$row['invoice_basis']=$basisCache[$row['invoice_serial']]??null;unset($row,$group);
        foreach($chunk as $no)if(isset($p[$no]))$out[$no]=cargo_effective($p[$no],$c[$no]??[],$seller);
    }
    return $out;
}
