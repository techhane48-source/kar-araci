<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/profit.php';

/** A Sale statement must reconcile to every barcode in the package, not just its total. */
function order_commission_evidence(array $order,array $rows,string $seller,bool $hasClaim=false): array
{
    $fail=static fn(string $reason)=>['present'=>true,'amount'=>null,'source'=>$reason];
    if($hasClaim)return $fail('İade talebi var; finansal mutabakat gerekli');
    if(!$rows)return ['present'=>false,'amount'=>null,'source'=>'Sipariş komisyon oranı / tahmin'];
    if($seller===''||!order_is_domestic_try($order)||!order_is_active_sale($order))return $fail('Ekstre kapsamı doğrulanamadı');
    $no=(string)($order['order_number']??'');$pid=(string)($order['package_number']??'');
    if($no===''||$pid==='')return $fail('Sipariş/paket kimliği eksik');
    $lines=$order['lines']??null;if(is_string($lines))$lines=json_decode($lines,true);
    if(!is_array($lines)||!$lines)return $fail('Sipariş satırları eksik');
    $order['lines']=$lines;$seenLines=[];
    foreach($lines as $l){
        if(!is_array($l)||!is_numeric($l['quantity']??null)||(float)$l['quantity']!==(float)(int)$l['quantity']||(int)$l['quantity']<=0)return $fail('Satır adedi geçersiz');
        $id=(string)($l['line_id']??'');if($id===''||isset($seenLines[$id]))return $fail('Satır kimliği geçersiz');$seenLines[$id]=true;
    }
    $expected=report_order_lines($order,[]);if($expected===null)return $fail('Paket tutarı veya indirim eşleşmedi');
    $want=[];$got=[];$seen=[];$commission=0;$credit=0;
    try{
        foreach($expected as $l)$want[$l['barcode']]=($want[$l['barcode']]??0)+finance_units($l['sale']);
        foreach($rows as $r){
            $raw=json_decode((string)($r['raw_json']??''),true);$id=(string)($r['external_id']??'');
            if($id===''||isset($seen[$id])||!is_array($raw)||!finance_is_domestic($raw)||
              (string)($raw['sellerId']??'')!==$seller||(string)($raw['id']??'')!==$id||
              (string)($r['order_number']??'')!==$no||(string)($raw['orderNumber']??'')!==$no||
              (string)($r['shipment_package_id']??'')!==$pid||(string)($raw['shipmentPackageId']??'')!==$pid)return $fail('Ekstre kimliği veya satıcı uyuşmuyor');
            $seen[$id]=true;
            if(($r['query_type']??'')!=='Sale')return $fail('İade/indirim/düzeltme hareketi; ayrı mutabakat gerekli');
            $bc=(string)($raw['barcode']??'');if($bc===''||!isset($want[$bc]))return $fail('Ekstre barkodu uyuşmuyor');
            $c=finance_units($raw['credit']??null);$d=finance_units($raw['debt']??null);$m=finance_units($raw['commissionAmount']??null);$v=finance_units($raw['sellerRevenue']??null);
            if($c<=0||$d!==0||$m<0||$v<0||abs($c-$m-$v)>100)return $fail('Satış komisyonu ve satıcı geliri uyuşmuyor');
            $got[$bc]=($got[$bc]??0)+$c;$commission+=$m;$credit+=$c;
        }
        if(abs($credit-finance_units($order['total_price']??null))>500||$commission>$credit)return $fail('Ekstre paket tutarı uyuşmuyor');
        foreach($want as $bc=>$v)if(!isset($got[$bc])||abs($got[$bc]-$v)>500)return $fail('Ekstre barkod tutarı eksik/uyuşmuyor');
    }catch(InvalidArgumentException $e){return $fail('Ekstre tutarı geçersiz');}
    return ['present'=>true,'amount'=>$commission/10000.0,'source'=>'Eşleşen Sale ekstresi'];
}
/** Batch all movements by order number, including adjustments outside the displayed date. */
function commission_profit_overlay(PDO $db,int $uid,array $orders): array
{
    $q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
    $indices=[];foreach($orders as $i=>$o)if(($o['marketplace']??'')==='trendyol'&&(string)($o['order_number']??'')!=='')$indices[(string)$o['order_number']][]=$i;
    foreach(array_chunk(array_keys($indices),200) as $chunk){
        $in=implode(',',array_fill(0,count($chunk),'?'));$args=array_merge([$uid],$chunk);$byOrder=[];$claims=[];$packages=[];
        $q=$db->prepare("SELECT order_number,package_number FROM orders WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)");$q->execute($args);
        while($r=$q->fetch())$packages[$r['order_number']][(string)$r['package_number']]=true;
        $q=$db->prepare("SELECT external_id,order_number,shipment_package_id,query_type,raw_json FROM settlements WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)");$q->execute($args);
        while($r=$q->fetch())$byOrder[$r['order_number']][]=$r;
        $q=$db->prepare("SELECT DISTINCT order_number FROM trendyol_claim_items WHERE user_id=? AND order_number IN ($in)");$q->execute($args);while($r=$q->fetch())$claims[$r['order_number']]=true;
        foreach($chunk as $no){
            $byPackage=[];$ambiguous=false;
            foreach($byOrder[$no]??[] as $r){
                $pid=(string)$r['shipment_package_id'];
                if(!isset($packages[$no][$pid])||$r['query_type']!=='Sale'){$ambiguous=true;continue;}
                $byPackage[$pid][]=$r;
            }
            foreach($indices[$no] as $i){
                $a=$ambiguous?['present'=>true,'amount'=>null,'source'=>'Siparişin ekstre düzeltmesi/eşleşmeyen paketi var']:order_commission_evidence($orders[$i],$byPackage[(string)($orders[$i]['package_number']??'')]??[],$seller,isset($claims[$no]));
                if($a['present'])$orders[$i]['commission_override']=$a['amount'];
                $orders[$i]['commission_source']=$a['source'];
            }
        }
    }
    return $orders;
}
