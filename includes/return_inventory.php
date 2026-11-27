<?php
declare(strict_types=1);
require_once __DIR__.'/claims.php';
require_once __DIR__.'/finance.php';
require_once __DIR__.'/cost_history.php';

/** Accepted claim units are reconciled to the original order line, never to the return package. */
function return_inventory_evidence(array $claim,?array $order,int $acceptedCount,?array $inspection): array
{
    $out=['cost'=>null,'recovery'=>null,'inventory_loss'=>null,'condition'=>null,'state'=>'İnceleme gerekli','ready'=>false,'assumed'=>false];
    if(($claim['status']??'')!=='Accepted')return array_replace($out,['state'=>'Kabul edilmiş iade değil']);
    $match=claim_order_match($claim,$order);
    if($match['cost']===null||$match['scope']!=='domestic')return array_replace($out,['state'=>'Tarihli / gözlemlenen maliyet veya sipariş eşleşmesi eksik']);
    $lines=is_array($order['lines'])?$order['lines']:json_decode((string)$order['lines'],true);
    $matches=claim_matching_lines($claim,is_array($lines)?$lines:[]);
    $line=count($matches)===1?$matches[0]:null;
    $qty=$line['quantity']??null;
    if(!is_numeric($qty)||!is_finite((float)$qty)||(float)$qty!==(float)(int)$qty||(int)$qty<1||$acceptedCount<1||$acceptedCount>(int)$qty)
        return array_replace($out,['state'=>'Kabul edilmiş iade adedi sipariş adediyle çelişiyor']);
    try{$cost=finance_units($match['cost']);if($cost<=0)throw new InvalidArgumentException('Maliyet');}
    catch(InvalidArgumentException $e){return array_replace($out,['state'=>'Maliyet geçersiz']);}
    $out['cost']=$cost/10000.0;
    // Accepted is a financial/workflow status, not proof of physical receipt or condition.
    // Operational default: no recorded exception => assumed fully recoverable, no manual action.
    if(!$inspection)return array_replace($out,['state'=>'Otomatik: satışa uygun varsayımı','condition'=>'resellable',
        'recovery'=>$cost/10000.0,'inventory_loss'=>0.0,'ready'=>true,'assumed'=>true]);
    if(!array_key_exists('group_line_id',$inspection)||$inspection['group_line_id']===null||(string)$inspection['group_line_id']!==(string)($claim['group_line_id']??''))return array_replace($out,['state'=>'İade grup kimliği doğrulanmadı; yeniden depo kontrolü gerekli']);
    foreach(['claim_id','item_id','order_number','outbound_package','line_id','barcode'] as $key)
        if((string)($inspection[$key]??'')!==(string)($claim[$key]??''))return array_replace($out,['state'=>'İade kimliği değişti; yeniden kontrol gerekli']);
    $condition=(string)($inspection['condition']??'');$recovery=(int)($inspection['recovery_units']??-1);
    // An explicit unsellable exception survives routine refresh and a revised unit cost
    // if the full item identity is unchanged. It must never silently become recoverable.
    if($condition==='unusable'&&$recovery===0&&(int)($inspection['cost_units']??0)>0&&
        (int)($inspection['claim_modified_ms']??0)>0&&(int)$claim['modified_ms']>=(int)$inspection['claim_modified_ms'])
        return array_replace($out,['condition'=>'unusable','recovery'=>0.0,'inventory_loss'=>$cost/10000.0,
            'state'=>'Satılamaz olarak işaretlendi; stok değer kaybı','ready'=>true]);
    if((int)($inspection['claim_modified_ms']??0)!==(int)$claim['modified_ms']||(int)($inspection['cost_units']??0)!==$cost)
        return array_replace($out,['state'=>'İade veya maliyet değişti; yeniden kontrol gerekli']);
    if(!in_array($condition,['resellable','partial','unusable'],true)||$recovery<0||$recovery>$cost||
       ($condition==='resellable'&&$recovery!==$cost)||($condition==='unusable'&&$recovery!==0)||
       ($condition==='partial'&&($recovery<=0||$recovery>=$cost)))return array_replace($out,['state'=>'Depo değeri çelişkili']);
    return array_replace($out,['condition'=>$condition,'recovery'=>$recovery/10000.0,'inventory_loss'=>($cost-$recovery)/10000.0,
        'state'=>'Kullanıcı depo beyanı; toplam iade zararı değil','ready'=>true]);
}
function return_inventory_orders(PDO $db,int $uid,array $claims): array
{
    $packages=array_values(array_unique(array_filter(array_column($claims,'outbound_package'),static fn($p)=>$p!=='')));
    $orders=[];
    foreach(array_chunk($packages,200) as $chunk){
        $q=$db->prepare("SELECT * FROM orders WHERE user_id=? AND marketplace='trendyol' AND package_number IN (".implode(',',array_fill(0,count($chunk),'?')).")");
        $q->execute(array_merge([$uid],$chunk));
        while($o=$q->fetch())$orders[(string)$o['package_number']]=$o;
    }
    return cost_history_overlay($db,$uid,$orders);
}
function return_accepted_counts(PDO $db,int $uid,array $claims): array
{
    $packages=array_values(array_unique(array_filter(array_column($claims,'outbound_package'),static fn($p)=>$p!=='')));
    $out=[];
    foreach(array_chunk($packages,200) as $chunk){
        $q=$db->prepare("SELECT order_number,outbound_package,line_id,group_line_id,barcode FROM trendyol_claim_items WHERE user_id=? AND status='Accepted' AND outbound_package IN (".implode(',',array_fill(0,count($chunk),'?')).")");
        $q->execute(array_merge([$uid],$chunk));$rows=$q->fetchAll();
        // Aggregate once; avoid a quadratic scan on large return histories.
        $baseKey=static fn(array $r)=>json_encode([(string)$r['order_number'],(string)$r['outbound_package'],(string)$r['barcode']],JSON_THROW_ON_ERROR);
        $totals=[];$groups=[];$legacy=[];
        foreach($rows as $r){
            $key=$baseKey($r);$group=(string)$r['group_line_id'];
            $totals[$key]=($totals[$key]??0)+1;
            if($group==='')$legacy[$key]=($legacy[$key]??0)+1;
            else $groups[$key][$group]=($groups[$key][$group]??0)+1;
        }
        $chunkSet=array_fill_keys($chunk,true);
        foreach($claims as $claim){
            if(!isset($chunkSet[(string)$claim['outbound_package']]))continue;
            $key=$baseKey($claim);$group=(string)($claim['group_line_id']??'');
            $out[return_inventory_key($claim)]=$group===''?($totals[$key]??0):(($groups[$key][$group]??0)+($legacy[$key]??0));
        }
    }
    return $out;
}
function return_inventory_key(array $r): string
{
    return json_encode([(string)$r['order_number'],(string)$r['outbound_package'],($r['group_line_id']??'')!==''?'group':'legacy',(string)(($r['group_line_id']??'')!==''?$r['group_line_id']:$r['line_id']),(string)$r['barcode']],JSON_THROW_ON_ERROR);
}
/** Read inspection evidence in bounded tenant-scoped batches. */
function return_inventory_checks(PDO $db,int $uid,array $claims): array
{
    $ids=array_values(array_unique(array_filter(array_map('strval',array_column($claims,'item_id')),static fn($id)=>$id!=='')));
    $out=[];
    foreach(array_chunk($ids,200) as $chunk){
        $q=$db->prepare("SELECT * FROM return_inventory_checks WHERE user_id=? AND item_id IN (".implode(',',array_fill(0,count($chunk),'?')).")");
        $q->execute(array_merge([$uid],$chunk));
        while($r=$q->fetch())$out[(string)$r['item_id']]=$r;
    }
    return $out;
}
/** Undo only an explicit exception for this user's claim. Default remains read-time, not a physical inspection. */
function return_inventory_clear(PDO $db,int $uid,string $itemId): void
{
    $db->prepare("DELETE FROM return_inventory_checks WHERE user_id=? AND item_id=? AND EXISTS (SELECT 1 FROM trendyol_claim_items c WHERE c.user_id=return_inventory_checks.user_id AND c.item_id=return_inventory_checks.item_id AND c.status='Accepted')")
        ->execute([$uid,$itemId]);
}
function return_inventory_save(PDO $db,int $uid,string $itemId,string $condition,string $raw): void
{
    if(!in_array($condition,['resellable','partial','unusable'],true))throw new InvalidArgumentException('Depo durumu geçersiz.');
    $db->beginTransaction();
    try{
        $q=$db->prepare("SELECT * FROM trendyol_claim_items WHERE user_id=? AND item_id=?");$q->execute([$uid,$itemId]);$claim=$q->fetch();
        if(!$claim)throw new InvalidArgumentException('İade kalemi bulunamadı.');
        $orders=return_inventory_orders($db,$uid,[$claim]);$counts=return_accepted_counts($db,$uid,[$claim]);
        $a=return_inventory_evidence($claim,$orders[$claim['outbound_package']]??null,$counts[return_inventory_key($claim)]??0,null);
        if($a['cost']===null)throw new InvalidArgumentException($a['state']);
        $cost=finance_units($a['cost']);
        $value=$condition==='resellable'?$cost:($condition==='unusable'?0:null);
        if($condition==='partial'){
            $v=parse_money_input($raw);if($v===null||$v<=0)throw new InvalidArgumentException('Kısmi geri kazanım tutarı geçersiz.');
            $value=finance_units($v);
            if($value<=0||$value>=$cost)throw new InvalidArgumentException('Kısmi geri kazanım 0 ile kayıtlı maliyet arasında olmalı.');
        }
        $db->prepare("INSERT INTO return_inventory_checks(user_id,item_id,claim_id,order_number,outbound_package,line_id,barcode,claim_modified_ms,cost_units,condition,recovery_units,checked_at,group_line_id)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,item_id) DO UPDATE SET claim_id=excluded.claim_id,order_number=excluded.order_number,outbound_package=excluded.outbound_package,line_id=excluded.line_id,barcode=excluded.barcode,claim_modified_ms=excluded.claim_modified_ms,cost_units=excluded.cost_units,condition=excluded.condition,recovery_units=excluded.recovery_units,checked_at=excluded.checked_at,group_line_id=excluded.group_line_id")
          ->execute([$uid,$itemId,$claim['claim_id'],$claim['order_number'],$claim['outbound_package'],$claim['line_id'],$claim['barcode'],$claim['modified_ms'],$cost,$condition,$value,time(),(string)($claim['group_line_id']??'')]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

/** Current accepted items last modified in the selected period; not a ledger of physical receipts. */
function return_inventory_period(PDO $db,int $uid,int $from,int $through): array
{
    $q=$db->prepare("SELECT * FROM trendyol_claim_items WHERE user_id=? AND status='Accepted' AND modified_ms>=? AND modified_ms<?");
    $q->execute([$uid,$from*1000,$through*1000]);$claims=$q->fetchAll();
    $orders=return_inventory_orders($db,$uid,$claims);$counts=return_accepted_counts($db,$uid,$claims);$checks=return_inventory_checks($db,$uid,$claims);
    $out=['accepted'=>count($claims),'assumed'=>0,'observed'=>0,'unusable'=>0,'unknown'=>0,'loss'=>null];$loss=0;
    foreach($claims as $row){
        $a=return_inventory_evidence($row,$orders[$row['outbound_package']]??null,$counts[return_inventory_key($row)]??0,$checks[$row['item_id']]??null);
        if(!$a['ready']){$out['unknown']++;continue;}
        $out[$a['assumed']?'assumed':'observed']++;
        if($a['condition']==='unusable')$out['unusable']++;
        $loss+=finance_units($a['inventory_loss']);
    }
    if($out['assumed']+$out['observed'])$out['loss']=$loss/10000.0;
    return $out;
}

/** Closed package loss evidence. Never assign an order-level charge across multiple packages.
 * Known expenses are gross cash amounts, not tax-adjusted accounting profit.
 * A missing invoice, service fee or refund is not proof of zero expense.
 */
function closed_package_loss(array $o,array $packages,array $rows,array $cargo,array $claims,array $counts,array $checks,string $seller): array
{
    $out=['loss'=>null,'cargo'=>null,'inventory'=>null,'settlement_loss'=>null,'state'=>'Finans / iade ayrıntıları bekleniyor','assumed'=>false,'pending'=>true];
    $status=strtolower((string)($o['status']??''));
    if(!in_array($status,['cancelled','canceled','unsupplied','returned','undelivered'],true))return $out;
    $cancel=in_array($status,['cancelled','canceled','unsupplied'],true);
    $no=(string)($o['order_number']??'');$pid=(string)($o['package_number']??'');
    if($seller===''||$no===''||$pid===''||count($packages)!==1||(string)($packages[0]['package_number']??'')!==$pid||!order_is_domestic_try($o))
        return array_replace($out,['state'=>'Çoklu paket veya satıcı kapsamı doğrulanmalı']);
    $lines=$o['lines']??[];if(is_string($lines))$lines=json_decode($lines,true);
    if(!is_array($lines)||!$lines)return $out;
    $o['lines']=$lines;$want=[];$lineIds=[];$units=0;
    foreach($lines as $l){
        if(!is_array($l))return $out;
        $id=(string)($l['line_id']??'');$bc=(string)($l['barcode']??'');$q=$l['quantity']??null;
        if($id===''||$bc===''||isset($lineIds[$id])||!is_numeric($q)||!is_finite((float)$q)||(float)$q!==(float)(int)$q||$q<1)return $out;
        $lineIds[$id]=(int)$q;$want[$bc]=true;$units+=(int)$q;
    }
    $cargoSum=0;$cargoOk=true;$seen=[];$outbound=false;$return=false;
    foreach($cargo as $r){
        $serial=(string)($r['invoice_serial']??'');$parcel=(string)($r['parcel_id']??'');$type=(string)($r['shipment_type']??'');$key=json_encode([$serial,$parcel,$type]);
        $raw=json_decode((string)($r['invoice_raw']??''),true);
        if($serial===''||$parcel===''||isset($seen[$key])||(string)($r['order_number']??'')!==$no||!is_array($raw)||!finance_is_domestic($raw)||(string)($raw['sellerId']??'')!==$seller||(string)($raw['id']??'')!==$serial||!in_array($type,['Gönderi Kargo Bedeli','İade Kargo Bedeli'],true)){$cargoOk=false;break;}
        $seen[$key]=true;
        try{$v=finance_units($r['amount']??null);if($v<0)throw new InvalidArgumentException('Kargo');
            if(($r['invoice_basis']??'')==='net')$v=(int)round($v*1.2,0,PHP_ROUND_HALF_UP);
            elseif(($r['invoice_basis']??'')!=='gross')throw new InvalidArgumentException('KDV');
            $cargoSum+=$v;$outbound=$outbound||$type==='Gönderi Kargo Bedeli';$return=$return||$type==='İade Kargo Bedeli';
        }catch(InvalidArgumentException $e){$cargoOk=false;break;}
    }
    if($cargo&&$cargoOk)$out['cargo']=$cargoSum/10000.0;
    // For a fully accepted return, count each unit exactly once and default only
    // uninspected accepted items to recoverable. No assumption about physical receipt.
    $loss=0;$claimOk=true;$used=[];$perLine=[];$assumed=false;
    foreach($claims as $c){
        if(($c['status']??'')!=='Accepted'){$claimOk=false;continue;}
        $item=(string)($c['item_id']??'');$match=claim_matching_lines($c,$lines);
        if($item===''||isset($used[$item])||count($match)!==1){$claimOk=false;continue;}
        $used[$item]=true;$lid=(string)$match[0]['line_id'];$perLine[$lid]=($perLine[$lid]??0)+1;
        if($perLine[$lid]>$lineIds[$lid]){$claimOk=false;continue;}
        $a=return_inventory_evidence($c,$o,$counts[return_inventory_key($c)]??0,$checks[$item]??null);
        if(!$a['ready']){$claimOk=false;continue;}
        $loss+=finance_units($a['inventory_loss']);$assumed=$assumed||$a['assumed'];
    }
    if($claimOk&&count($used)===$units){$out['inventory']=$loss/10000.0;$out['assumed']=$assumed;}
    // Record residual seller debit only when the original sales and refund/campaign
    // movements reconcile to zero gross by barcode. No guessed signs or allocations.
    $gross=[];$revenue=0;$sold=[];$refunded=[];$seen=[];$financeOk=true;
    foreach($rows as $r){
        $raw=json_decode((string)($r['raw_json']??''),true);$id=(string)($r['external_id']??'');$type=(string)($r['query_type']??'');$bc=(string)($raw['barcode']??'');
        if($id===''||isset($seen[$id])||!is_array($raw)||!finance_is_domestic($raw)||(string)($raw['sellerId']??'')!==$seller||(string)($raw['id']??'')!==$id||(string)($r['order_number']??'')!==$no||(string)($raw['orderNumber']??'')!==$no||(string)($r['shipment_package_id']??'')!==$pid||(string)($raw['shipmentPackageId']??'')!==$pid||!isset($want[$bc])||!in_array($type,['Sale','Return','Discount','DiscountCancel','Coupon','CouponCancel'],true)){$financeOk=false;break;}
        $seen[$id]=true;
        try{
            $credit=finance_units($raw['credit']??null);$debt=finance_units($raw['debt']??null);$parts=settlement_signed_components($raw,$type);
            if($credit<0||$debt<0||($credit>0&&$debt>0)||abs(($credit-$debt)-($parts['revenue']-$parts['commission']))>100)throw new InvalidArgumentException('Ekstre');
            // signed commission is negative on sale, positive on refund; gross = revenue - signed commission.
            $positive=in_array($type,['Sale','DiscountCancel','CouponCancel'],true);
            if($positive?($credit<=0||$debt!==0):($debt<=0||$credit!==0))throw new InvalidArgumentException('İşaret');
            $gross[$bc]=($gross[$bc]??0)+$credit-$debt;$revenue+=$parts['revenue'];
            if($type==='Sale')$sold[$bc]=true;if($type==='Return')$refunded[$bc]=true;
        }catch(InvalidArgumentException $e){$financeOk=false;break;}
    }
    if($financeOk&&$rows&&$revenue<=0&&count($sold)===count($want)&&count($refunded)===count($want)&&!array_filter($gross,static fn($v)=>abs($v)>100))
        $out['settlement_loss']=-$revenue/10000.0;
    $known=array_filter([$out['cargo'],$out['inventory'],$out['settlement_loss']],static fn($v)=>$v!==null);
    if($known)$out['loss']=array_sum($known);
    if($cancel&&!$rows&&!$cargo&&!$claims){
        $out['state']='İptal: kayıtlı kesinti yok; gönderim ve finans kesinleşmedi';
        return $out;
    }
    $out['state']=$cancel?'İptal: bilinen giderler; finans tamamlanmadı':'İade/teslim edilemedi: bilinen giderler; finans tamamlanmadı';
    if($out['loss']===null)$out['state']=$cancel?'İptal: gider kaydı bekleniyor':'İade: kargo, stok veya ekstre kaydı bekleniyor';
    return $out;
}
/** Batch closed orders, including all packages, claim units and invoice rows. */
function closed_package_loss_overlay(PDO $db,int $uid,array $orders): array
{
    $by=[];foreach($orders as $i=>$o)if(($o['marketplace']??'')==='trendyol'&&in_array(strtolower((string)($o['status']??'')),['cancelled','canceled','unsupplied','returned','undelivered'],true)&&!empty($o['order_number']))$by[(string)$o['order_number']][]=$i;
    if(!$by)return $orders;
    $q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
    foreach(array_chunk(array_keys($by),200) as $chunk){
        $in=implode(',',array_fill(0,count($chunk),'?'));$args=array_merge([$uid],$chunk);$groups=[];
        $sqls=[
          'packages'=>"SELECT order_number,package_number FROM orders WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)",
          'rows'=>"SELECT external_id,order_number,shipment_package_id,query_type,raw_json FROM settlements WHERE user_id=? AND marketplace='trendyol' AND order_number IN ($in)",
          'cargo'=>"SELECT c.*,d.raw_json AS invoice_raw FROM trendyol_cargo_items c LEFT JOIN trendyol_financial_documents d ON d.user_id=c.user_id AND d.external_id=c.invoice_serial AND d.query_type='DeductionInvoices' WHERE c.user_id=? AND c.order_number IN ($in)",
          'claims'=>"SELECT * FROM trendyol_claim_items WHERE user_id=? AND order_number IN ($in)"
        ];
        foreach($sqls as $k=>$sql){$q=$db->prepare($sql);$q->execute($args);while($r=$q->fetch())$groups[(string)$r['order_number']][$k][]=$r;}
        $serials=[];$claims=[];foreach($groups as $g){foreach($g['cargo']??[] as $r)$serials[]=(string)$r['invoice_serial'];foreach($g['claims']??[] as $c)$claims[]=$c;}
        $basis=cargo_invoice_basis_map($db,$uid,$seller,$serials);$counts=return_accepted_counts($db,$uid,$claims);$checks=return_inventory_checks($db,$uid,$claims);
        foreach($chunk as $no){
            $g=$groups[$no]??[];$cargo=$g['cargo']??[];foreach($cargo as &$r)$r['invoice_basis']=$basis[$r['invoice_serial']]??null;unset($r);
            foreach($by[$no] as $i)$orders[$i]['closed_loss']=closed_package_loss($orders[$i],$g['packages']??[],$g['rows']??[],$cargo,$g['claims']??[],$counts,$checks,$seller);
        }
    }
    return $orders;
}
