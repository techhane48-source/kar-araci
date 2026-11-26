<?php
declare(strict_types=1);
require_once __DIR__.'/claims.php';
require_once __DIR__.'/finance.php';
require_once __DIR__.'/cost_history.php';

/** Accepted claim units are reconciled to the original order line, never to the return package. */
function return_inventory_evidence(array $claim,?array $order,int $acceptedCount,?array $inspection): array
{
    $out=['cost'=>null,'recovery'=>null,'inventory_loss'=>null,'condition'=>null,'state'=>'İnceleme gerekli','ready'=>false];
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
    if(!$inspection)return array_replace($out,['state'=>'Depo kontrolü girilmedi']);
    foreach(['claim_id','order_number','outbound_package','line_id','barcode'] as $key)
        if((string)($inspection[$key]??'')!==(string)($claim[$key]??''))return array_replace($out,['state'=>'İade kimliği değişti; yeniden kontrol gerekli']);
    if((int)($inspection['claim_modified_ms']??0)!==(int)$claim['modified_ms']||(int)($inspection['cost_units']??0)!==$cost)
        return array_replace($out,['state'=>'İade veya maliyet değişti; yeniden kontrol gerekli']);
    $condition=(string)($inspection['condition']??'');$recovery=(int)($inspection['recovery_units']??-1);
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
        $db->prepare("INSERT INTO return_inventory_checks(user_id,item_id,claim_id,order_number,outbound_package,line_id,barcode,claim_modified_ms,cost_units,condition,recovery_units,checked_at)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,item_id) DO UPDATE SET claim_id=excluded.claim_id,order_number=excluded.order_number,outbound_package=excluded.outbound_package,line_id=excluded.line_id,barcode=excluded.barcode,claim_modified_ms=excluded.claim_modified_ms,cost_units=excluded.cost_units,condition=excluded.condition,recovery_units=excluded.recovery_units,checked_at=excluded.checked_at")
          ->execute([$uid,$itemId,$claim['claim_id'],$claim['order_number'],$claim['outbound_package'],$claim['line_id'],$claim['barcode'],$claim['modified_ms'],$cost,$condition,$value,time()]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
