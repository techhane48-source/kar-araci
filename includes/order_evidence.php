<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/profit.php';
require_once __DIR__.'/cost_history.php';

/**
 * Order-level evidence aggregation. The order number and package ID are separate
 * identifiers. Cargo invoice parcelUniqueId is NOT shipmentPackageId.
 * No account-level fee is allocated to an order; no claim is treated as a refund.
 */
function order_evidence(array $packages,array $settlements,array $cargo,array $claims): array
{
    $issues=[];$byPackage=[];$invoice=0;$cost=0;$missingCost=0;$units=0;$active=0;$datedUnits=0;$observedUnits=0;
    $orderNo=(string)($packages[0]['order_number']??'');$expectedByBarcode=[];$financeByBarcode=[];$invoiceValid=true;$costByBarcode=[];$salePartsByBarcode=[];
    foreach($packages as $p){
        $id=(string)($p['package_number']??'');
        if($id===''||$orderNo===''||(string)($p['order_number']??'')!==$orderNo||isset($byPackage[$id])){
            $issues[]='Paket kimliği çelişkili';continue;
        }
        $byPackage[$id]=$p;
        if(!order_is_domestic_try($p)){$issues[]='Yurtiçi kapsamı doğrulanamadı';continue;}
        if(!order_is_active_sale($p)){$issues[]='İptal/iade veya bölünen paket var';continue;}
        $active++;
        try{$v=finance_units($p['total_price']??null);if($v<=0)throw new InvalidArgumentException('Geçersiz satış');$invoice+=$v;}catch(InvalidArgumentException $e){$invoiceValid=false;$issues[]='Sipariş tutarı eksik/geçersiz';}
        $lines=$p['lines']??[];
        if(is_string($lines))$lines=json_decode($lines,true);
        if(!is_array($lines)||!$lines){$issues[]='Ürün satırları eksik';$missingCost++;continue;}
        $lineView=$p;$lineView['lines']=$lines;
        $reconciled=report_order_lines($lineView,[]);
        if($reconciled===null)$issues[]='Paket barkod/satır tutarları doğrulanamadı';
        else foreach($reconciled as $r){$bc=$r['barcode'];$expectedByBarcode[$id][$bc]=($expectedByBarcode[$id][$bc]??0)+finance_units($r['sale']);}
        $seenLine=[];
        foreach($lines as $l){
            $qty=(int)($l['quantity']??0);$key=(string)($l['line_id']??'');$bc=(string)($l['barcode']??'');
            if($bc===''||$qty<=0||!is_numeric($l['quantity']??null)||(float)$l['quantity']!==(float)$qty||$key===''||isset($seenLine[$key])){$issues[]='Ürün satırı kimliği/adedi çelişkili';$missingCost++;continue;}
            $seenLine[$key]=true;$units+=$qty;
            try{
                $v=finance_units($l['cost_snapshot']??null);
                if($v<=0)throw new InvalidArgumentException('Maliyet yok');
                $cost+=$v*$qty;
                if(!isset($costByBarcode[$bc]))$costByBarcode[$bc]=['units'=>0,'cost'=>0];
                $costByBarcode[$bc]['units']+=$qty;$costByBarcode[$bc]['cost']+=$v*$qty;
                if(($l['cost_source']??'')==='dated_user')$datedUnits+=$qty;else $observedUnits+=$qty;
            }catch(InvalidArgumentException $e){$missingCost+=$qty;}
        }
    }
    $credit=0;$debt=0;$saleCredit=0;$saleCommission=0;$saleRevenue=0;$sales=0;
    $matched=0;$unmatched=0;$unverified=0;$byType=[];$seen=[];$salePackages=[];
    foreach($settlements as $row){
        $id=(string)($row['external_id']??'');$pid=(string)($row['shipment_package_id']??'');
        $raw=$row['raw_json']??null;if(is_string($raw))$raw=json_decode($raw,true);
        if($id===''||isset($seen[$id])){$unverified++;$issues[]='Finansal işlem kimliği eksik/tekrar';continue;}
        $seen[$id]=true;
        if((string)($row['order_number']??'')!==$orderNo||!isset($byPackage[$pid])){$unmatched++;continue;}
        if(!is_array($raw)||!finance_is_domestic($raw)||!order_is_domestic_try($byPackage[$pid])
            ||(string)($raw['orderNumber']??'')!==$orderNo||(string)($raw['shipmentPackageId']??'')!==$pid
            ||(string)($raw['id']??'')!==$id){$unverified++;continue;}
        $type=(string)($row['query_type']??'');
        try{
            $c=finance_units($raw['credit']??null);$d=finance_units($raw['debt']??null);
            if($c<0||$d<0||$type==='')throw new InvalidArgumentException('Geçersiz hareket');
        }catch(InvalidArgumentException $e){$unverified++;continue;}
        $matched++;$credit+=$c;$debt+=$d;
        if(!isset($byType[$type]))$byType[$type]=['credit'=>0,'debt'=>0,'count'=>0];
        $byType[$type]['credit']+=$c;$byType[$type]['debt']+=$d;$byType[$type]['count']++;
        if($type==='Sale'){
            try{
                $comm=finance_units($raw['commissionAmount']??null);$rev=finance_units($raw['sellerRevenue']??null);
                if($comm<0||$rev<0||$d!==0||$c<=0||abs($c-$comm-$rev)>100)throw new InvalidArgumentException('Satış bileşenleri uyuşmuyor');
                $saleCommission+=$comm;$saleRevenue+=$rev;$saleCredit+=$c;$sales++;
                $salePackages[$pid]=($salePackages[$pid]??0)+$c;
                $bc=(string)($raw['barcode']??'');
                if($bc==='')$issues[]='Satış ekstresi barkodu eksik';
                else {
                    $financeByBarcode[$pid][$bc]=($financeByBarcode[$pid][$bc]??0)+$c;
                    if(!isset($salePartsByBarcode[$bc]))$salePartsByBarcode[$bc]=['credit'=>0,'commission'=>0,'revenue'=>0];
                    $salePartsByBarcode[$bc]['credit']+=$c;$salePartsByBarcode[$bc]['commission']+=$comm;$salePartsByBarcode[$bc]['revenue']+=$rev;
                }
            }catch(InvalidArgumentException $e){$issues[]='Satış komisyonu ve geliri uyuşmuyor';}
        }
    }
    if($unmatched)$issues[]='Paketle eşleşmeyen finans hareketi';
    if($unverified)$issues[]='Doğrulanmamış finans hareketi';
    if(!$sales)$issues[]='Satış finans kaydı yok';
    foreach($byPackage as $pid=>$p){
        if(!order_is_active_sale($p))continue;
        try{if(!isset($salePackages[$pid])||abs($salePackages[$pid]-finance_units($p['total_price']??null))>500)$issues[]='Satış ekstresi ile paket tutarı eşleşmiyor';}
        catch(InvalidArgumentException $e){$issues[]='Sipariş tutarı eksik';}
    }
    foreach($expectedByBarcode as $pid=>$expected){
        $actual=$financeByBarcode[$pid]??[];
        foreach(array_unique(array_merge(array_keys($expected),array_keys($actual))) as $bc){
            if(!isset($expected[$bc],$actual[$bc])||abs($expected[$bc]-$actual[$bc])>500){$issues[]='Satış ekstresi ile barkod tutarı uyuşmuyor';break;}
        }
    }
    $outbound=0;$returnCargo=0;$otherCargo=0;$cargoN=0;$cargoSeen=[];$cargoBad=0;$outboundN=0;$returnN=0;
    foreach($cargo as $row){
        $key=(string)($row['invoice_serial']??'').'|'.(string)($row['parcel_id']??'').'|'.(string)($row['shipment_type']??'');
        if((string)($row['order_number']??'')!==$orderNo||empty($row['invoice_serial'])||empty($row['parcel_id'])||isset($cargoSeen[$key])){
            $cargoBad++;continue;
        }
        $cargoSeen[$key]=true;
        // Invoice document must be scoped to domestic trade.
        $raw=$row['invoice_raw']??null;if(is_string($raw))$raw=json_decode($raw,true);
        if(!is_array($raw)||!finance_is_domestic($raw)||(string)($raw['id']??'')!==(string)$row['invoice_serial']){$cargoBad++;continue;}
        try{$amount=finance_units($row['amount']??null);if($amount<0)throw new InvalidArgumentException('Kargo işareti');}
        catch(InvalidArgumentException $e){$cargoBad++;continue;}
        $cargoN++;
        switch($row['shipment_type']){
            case 'Gönderi Kargo Bedeli':$outbound+=$amount;$outboundN++;break;
            case 'İade Kargo Bedeli':$returnCargo+=$amount;$returnN++;$issues[]='İade kargosu mevcut';break;
            default:$otherCargo+=$amount;$issues[]='Diğer kargo türü';break;
        }
    }
    if(!$outboundN)$issues[]='Doğrulanmış gönderi kargosu yok (sıfır kabul edilmez)';
    if($cargoBad)$issues[]='Kargo kalemi doğrulanamadı';
    if($missingCost)$issues[]='Gözlemlenen maliyet eksik';
    if($claims)$issues[]='İade talebi mevcut; stok/geri ödeme durumu ayrı incelenmeli';
    if(count($byType)>1||($byType&&!isset($byType['Sale'])))$issues[]='Satış dışı finans hareketleri var';
    if(!$active)$issues[]='Aktif satış paketi yok';
    // Sale revenue is already net of commission. Subtract only known costs and observed outbound freight once.
    // This subtotal is deliberately NOT realized or accounting profit, and excludes unobserved charges.
    $subtotal=!$issues&&$active&&$invoiceValid&&$units>0&&!$missingCost&&$sales&&$outboundN?($saleRevenue-$cost-$outbound)/10000.0:null;
    $productComponents=[];$unallocatedCargo=null;
    // Only reuse a fully matched order subtotal. A shared shipment is not assigned by guessed weights.
    if($subtotal!==null){
        $single=count($costByBarcode)===1;
        foreach($costByBarcode as $bc=>$v){
            $sp=$salePartsByBarcode[$bc];
            $productComponents[$bc]=['units'=>$v['units'],'sale'=>$sp['credit']/10000.0,'commission'=>$sp['commission']/10000.0,
              'revenue'=>$sp['revenue']/10000.0,'cost'=>$v['cost']/10000.0,'cargo'=>$single?$outbound/10000.0:null,
              'before_shared_cargo'=>($sp['revenue']-$v['cost'])/10000.0,
              'after_cargo'=>$single?($sp['revenue']-$v['cost']-$outbound)/10000.0:null];
        }
        if(!$single)$unallocatedCargo=$outbound/10000.0;
    }
    // Deliberately not a net-profit result: invoices, VAT, withholding, service,
    // merchant shipping, returns and future adjustments are not fully reconciled.
    return ['order_number'=>$orderNo,'packages'=>count($byPackage),'active_packages'=>$active,
        'invoice'=>$active&&$invoiceValid?$invoice/10000.0:null,'units'=>$units,'observed_cost'=>$active&&$units>0&&!$missingCost?$cost/10000.0:null,
        'dated_cost_units'=>$datedUnits,'first_observed_units'=>$observedUnits,'missing_cost_units'=>$missingCost,'matched_finance'=>$matched,'unmatched_finance'=>$unmatched,'unverified_finance'=>$unverified,
        'credit'=>$matched?$credit/10000.0:null,'debt'=>$matched?$debt/10000.0:null,
        'sale_credit'=>$sales?$saleCredit/10000.0:null,'sale_commission'=>$sales?$saleCommission/10000.0:null,'sale_revenue'=>$sales?$saleRevenue/10000.0:null,
        'cargo_rows'=>$cargoN,'outbound_cargo'=>$outboundN?$outbound/10000.0:null,'return_cargo'=>$returnN?$returnCargo/10000.0:null,'other_cargo'=>$cargoN?$otherCargo/10000.0:null,
        'claim_items'=>count($claims),'issues'=>array_values(array_unique($issues)),
        'known_subtotal'=>$subtotal,'product_components'=>$productComponents,'unallocated_product_cargo'=>$unallocatedCargo,'profit'=>null,'state'=>$issues?'Eksik / inceleme gerekli':'Temel kalemler eşleşti; kesin kâr değil'];
}

/** Batched retrieval by exact order number. All order packages included, even outside selected order dates. */
function load_order_evidence(PDO $db,int $uid,array $orderNumbers): array
{
    $out=[];
    $orderNumbers=array_values(array_unique(array_filter(array_map('strval',$orderNumbers),static fn($n)=>$n!=='')));
    foreach(array_chunk($orderNumbers,200) as $chunk){
        $in=implode(',',array_fill(0,count($chunk),'?'));$args=array_merge([$uid],$chunk);
        $sources=[
           'packages'=>"SELECT * FROM orders WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)",
           'settlements'=>"SELECT external_id,shipment_package_id,order_number,query_type,raw_json FROM settlements WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)",
           'cargo'=>"SELECT c.*,d.raw_json AS invoice_raw FROM trendyol_cargo_items c LEFT JOIN trendyol_financial_documents d ON d.user_id=c.user_id AND d.external_id=c.invoice_serial AND d.query_type='DeductionInvoices' WHERE c.user_id=? AND c.order_number IN ($in)",
           'claims'=>"SELECT item_id,order_number,status FROM trendyol_claim_items WHERE user_id=? AND order_number IN ($in)"
        ];
        $groups=[];
        foreach($sources as $key=>$sql){
            $q=$db->prepare($sql);$q->execute($args);
            while($r=$q->fetch())$groups[(string)$r['order_number']][$key][]=$r;
        }
        $packages=[];
        foreach($groups as $g)foreach($g['packages']??[] as $p)$packages[]=$p;
        $overlaid=[];
        foreach(cost_history_overlay($db,$uid,$packages) as $p)$overlaid[(string)$p['order_number']][]=$p;
        foreach($chunk as $n){
            $g=$groups[$n]??[];
            if(!empty($overlaid[$n]))$out[$n]=order_evidence($overlaid[$n],$g['settlements']??[],$g['cargo']??[],$g['claims']??[]);
        }
    }
    return $out;
}
