<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/profit.php';

/** Sale and seller-funded discount movements must reconcile to every barcode, not just the package total. */
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
    $want=[];$got=[];$seen=[];$commission=0;$credit=0;$sales=[];$adjustments=0;
    try{
        foreach($expected as $l)$want[$l['barcode']]=($want[$l['barcode']]??0)+finance_units($l['sale']);
        foreach($rows as $r){
            $raw=json_decode((string)($r['raw_json']??''),true);$id=(string)($r['external_id']??'');
            if($id===''||isset($seen[$id])||!is_array($raw)||!finance_is_domestic($raw)||
              (string)($raw['sellerId']??'')!==$seller||(string)($raw['id']??'')!==$id||
              (string)($r['order_number']??'')!==$no||(string)($raw['orderNumber']??'')!==$no||
              (string)($r['shipment_package_id']??'')!==$pid||(string)($raw['shipmentPackageId']??'')!==$pid)return $fail('Ekstre kimliği veya satıcı uyuşmuyor');
            $seen[$id]=true;
            $type=(string)($r['query_type']??'');
            if(!in_array($type,['Sale','Discount','Coupon'],true))return $fail('İade/diğer düzeltme hareketi; ayrı mutabakat gerekli');
            $bc=(string)($raw['barcode']??'');if($bc===''||!isset($want[$bc]))return $fail('Ekstre barkodu uyuşmuyor');
            $c=finance_units($raw['credit']??null);$d=finance_units($raw['debt']??null);$m=finance_units($raw['commissionAmount']??null);$v=finance_units($raw['sellerRevenue']??null);
            if($m<0||$v<0||$c<0||$d<0)return $fail('Ekstre tutar işareti geçersiz');
            if($type==='Sale'){
                if($c<=0||$d!==0||abs($c-$m-$v)>100)return $fail('Satış komisyonu ve satıcı geliri uyuşmuyor');
                $sales[$bc]=true;$net=$c;$commission+=$m;
            }else{
                // Only accept an unambiguous seller deduction and its commission reversal.
                // Coupon credit/debt representations vary; unsupported signs stay unresolved.
                if($d<=0||$c!==0||abs($d-$m-$v)>100)return $fail('İndirim/kupon tutarı veya komisyon iadesi uyuşmuyor');
                $net=-$d;$commission-=$m;$adjustments++;
            }
            $got[$bc]=($got[$bc]??0)+$net;$credit+=$net;
        }
        if(abs($credit-finance_units($order['total_price']??null))>500||$commission<0||$commission>$credit)return $fail('Ekstre paket tutarı uyuşmuyor');
        foreach($want as $bc=>$v)if(!isset($sales[$bc],$got[$bc])||abs($got[$bc]-$v)>500)return $fail('Ekstre barkod tutarı eksik/uyuşmuyor');
    }catch(InvalidArgumentException $e){return $fail('Ekstre tutarı geçersiz');}
    return ['present'=>true,'amount'=>$commission/10000.0,'source'=>$adjustments?'Satış + satıcı indirimi/kupon ekstresi eşleşti':'Eşleşen Sale ekstresi'];
}
/** When only seller discounts are unresolved, the already discounted order amount
 * may support a provisional estimate, never a verified statement allocation.
 * Require complete per-item or unit discount evidence and a positive seller deduction.
 */
function order_discount_estimate_allowed(array $order,array $rows,string $seller,array $knownPackages): bool
{
    if(count($knownPackages)!==1||$seller===''||!order_is_active_sale($order))return false;
    $lines=$order['lines']??null;if(is_string($lines))$lines=json_decode($lines,true);
    if(!is_array($lines)||!$lines)return false;
    $order['lines']=$lines;if(report_order_lines($order,[])===null)return false;
    $barcodes=[];$hasDiscount=false;$seen=[];
    foreach($lines as $l){
        $bc=(string)($l['barcode']??'');$qty=(int)$l['quantity'];$barcodes[$bc]=true;
        $gross=$l['gross_unit_price']??null;$discount=$l['seller_discount']??null;
        if(!is_numeric($gross)||!is_numeric($discount)||!is_finite((float)$gross)||!is_finite((float)$discount)||$gross<0||$discount<0)return false;
        $details=$l['discount_details']??[];
        if(!is_array($details))return false;
        if($details){
            if(count($details)!==$qty)return false;
            foreach($details as $d){
                if(!is_array($d)||!is_numeric($d['lineItemSellerDiscount']??null)||!is_finite((float)$d['lineItemSellerDiscount'])||$d['lineItemSellerDiscount']<0||abs((float)$gross-(float)$d['lineItemSellerDiscount']-(float)$d['lineItemPrice'])>0.05)return false;
                if($d['lineItemSellerDiscount']>0)$hasDiscount=true;
            }
        }else{
            if(abs((float)$gross-(float)$discount-(float)$l['price'])>0.05)return false;
            if($discount>0)$hasDiscount=true;
        }
    }
    if(!$hasDiscount)return false;
    $adjust=false;
    foreach($rows as $r){
        $raw=json_decode((string)($r['raw_json']??''),true);$id=(string)($r['external_id']??'');$type=$r['query_type']??'';
        if($id===''||isset($seen[$id])||!is_array($raw)||!finance_is_domestic($raw)||
          (string)($raw['id']??'')!==$id||(string)($raw['sellerId']??'')!==$seller||
          (string)($raw['orderNumber']??'')!==(string)$order['order_number']||
          (string)($r['order_number']??'')!==(string)$order['order_number']||
          (string)($r['shipment_package_id']??'')!==(string)($raw['shipmentPackageId']??'')||
          !isset($barcodes[(string)($raw['barcode']??'')])||!in_array($type,['Sale','Discount','Coupon'],true))return false;
        $seen[$id]=true;
        try{
            $c=finance_units($raw['credit']??null);$d=finance_units($raw['debt']??null);
            if($c<0||$d<0||($c>0&&$d>0))return false;
            if($type==='Sale'){
                if($c<=0||$d!==0)return false;
            }else{
                if($d<=0||$c!==0)return false;
                $adjust=true;
            }
        }catch(InvalidArgumentException $e){return false;}
    }
    return $adjust;
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
                if(!isset($packages[$no][$pid])||!in_array($r['query_type'],['Sale','Discount','Coupon'],true)){$ambiguous=true;continue;}
                $byPackage[$pid][]=$r;
            }
            foreach($indices[$no] as $i){
                $a=$ambiguous?['present'=>true,'amount'=>null,'source'=>'Siparişin ekstre düzeltmesi/eşleşmeyen paketi var']:order_commission_evidence($orders[$i],$byPackage[(string)($orders[$i]['package_number']??'')]??[],$seller,isset($claims[$no]));
                if($a['present']&&$a['amount']===null&&!isset($claims[$no])&&order_discount_estimate_allowed($orders[$i],$byOrder[$no]??[],$seller,$packages[$no]??[])){
                    $a=['present'=>false,'amount'=>null,'source'=>'İndirimli sipariş tutarı / tahmini komisyon; ekstre eşleşmesi bekleniyor'];
                }
                if($a['present'])$orders[$i]['commission_override']=$a['amount'];
                $orders[$i]['commission_source']=$a['source'];
            }
        }
    }
    return $orders;
}
