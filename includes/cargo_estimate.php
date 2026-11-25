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
    return ['amount'=>$units/10000.0,'desi'=>$desi,'carrier'=>$carrier,'tariff_from'=>$t['from'],'source'=>$t['source']??''];
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
    $est=0;$estOk=true;$actual=0;$n=0;$bad=false;$seen=[];
    $no=(string)($packages[0]['order_number']??'');
    foreach($packages as $p){
        if(!order_is_active_sale($p)||!order_is_domestic_try($p)||!is_numeric($p['cargo_estimate']??null)){$estOk=false;continue;}
        try{$est+=finance_units($p['cargo_estimate']);}catch(InvalidArgumentException $e){$estOk=false;}
    }
    foreach($rows as $r){
        if(($r['shipment_type']??'')!=='Gönderi Kargo Bedeli')continue;
        $raw=json_decode((string)($r['invoice_raw']??''),true);
        $serial=(string)($r['invoice_serial']??'');$parcel=(string)($r['parcel_id']??'');$key=$serial.'|'.$parcel;
        if($seller===''||$no===''||($r['order_number']??null)!==$no||$serial===''||$parcel===''||isset($seen[$key])||!is_array($raw)||!finance_is_domestic($raw)||(string)($raw['sellerId']??'')!==$seller||(string)($raw['id']??'')!==$serial){$bad=true;continue;}
        $seen[$key]=true;
        try{$v=finance_units($r['amount']??null);if($v<0)throw new InvalidArgumentException('Kargo');$actual+=$v;$n++;}catch(InvalidArgumentException $e){$bad=true;}
    }
    $estimate=$packages&&$estOk?$est/10000.0:null;$invoiced=$n&&!$bad?$actual/10000.0:null;
    $complete=count($packages)===1;
    return ['estimate'=>$estimate,'invoiced'=>$invoiced,'selected'=>$bad?null:($invoiced!==null?($complete?$invoiced:null):$estimate),'state'=>$bad?'Fatura çelişkili':($invoiced!==null?($complete?'Faturalanmış gönderi tutarı':'Çoklu paket: fatura kapsamı doğrulanmalı'):($estimate!==null?'Tahmini (KDV dahil tarife)':'Tarife/desi eksik')),'invoice_rows'=>$n];
}
function cargo_effective_report(PDO $db,int $uid,array $numbers): array
{
    $q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
    $out=[];$numbers=array_values(array_unique(array_filter(array_map('strval',$numbers),static fn($s)=>$s!=='')));
    foreach(array_chunk($numbers,200) as $chunk){
        $in=implode(',',array_fill(0,count($chunk),'?'));$args=array_merge([$uid],$chunk);$p=[];$c=[];
        $q=$db->prepare("SELECT * FROM orders WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)");$q->execute($args);while($r=$q->fetch())$p[$r['order_number']][]=$r;
        $q=$db->prepare("SELECT c.*,d.raw_json AS invoice_raw FROM trendyol_cargo_items c LEFT JOIN trendyol_financial_documents d ON d.user_id=c.user_id AND d.external_id=c.invoice_serial AND d.query_type='DeductionInvoices' WHERE c.user_id=? AND c.order_number IN ($in)");$q->execute($args);while($r=$q->fetch())$c[$r['order_number']][]=$r;
        foreach($chunk as $no)if(isset($p[$no]))$out[$no]=cargo_effective($p[$no],$c[$no]??[],$seller);
    }
    return $out;
}
