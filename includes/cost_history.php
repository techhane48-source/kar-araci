<?php
declare(strict_types=1);
require_once __DIR__.'/profit.php';
require_once __DIR__.'/analysis_import.php';

/** Effective date means local midnight in Istanbul, not an inferred purchase date. */
function cost_effective_day(string $raw): int
{
    $tz=new DateTimeZone('Europe/Istanbul');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,$tz);
    if(!$d||$d->format('Y-m-d')!==$raw||$raw<'2000-01-01'||$d->getTimestamp()>time())
        throw new InvalidArgumentException('Geçerli bir geçmiş/bugün tarihi girin (YYYY-AA-GG).');
    return $d->getTimestamp();
}
function cost_history_values(array $headers,iterable $rows): array
{
    $map=[];$required=['barcode'=>['barkod','barcode'],'date'=>['gecerliliktarihi','tarih','effectivedate'],
        'cost'=>['urunmaliyetikdvdahil','maliyet','cost'],'vat'=>['maliyetkdvorani','aliskdv','costvat']];
    foreach($headers as $i=>$h)foreach($required as $k=>$aliases){
        if(in_array(analysis_header((string)$h),$aliases,true)){
            if(isset($map[$k]))throw new InvalidArgumentException('Tekrarlanan kolon: '.$k);
            $map[$k]=$i;
        }
    }
    if(count($map)!==4)throw new InvalidArgumentException('Barkod, Geçerlilik Tarihi, Maliyet, Maliyet KDV Oranı gerekli.');
    $out=[];$seen=[];$n=0;
    foreach($rows as $row){
        if(++$n>20000)throw new InvalidArgumentException('En fazla 20.000 satır.');
        if(!array_filter($row,static fn($v)=>trim((string)$v)!==''))continue;
        $barcode=trim((string)($row[$map['barcode']]??''));
        $date=cost_effective_day(trim((string)($row[$map['date']]??'')));
        $cost=parse_money_input((string)($row[$map['cost']]??''));
        $vat=analysis_rate((string)($row[$map['vat']]??''));
        if($barcode===''||strlen($barcode)>128||$cost===null||$cost<=0||$cost>100000000||$vat===null)
            throw new InvalidArgumentException('Satır '.$n.': barkod/maliyet/KDV geçersiz.');
        $key=$barcode.'|'.$date;
        if(isset($seen[$key]))throw new InvalidArgumentException('Dosyada aynı barkod ve tarih tekrar ediyor.');
        $seen[$key]=true;$out[]=['barcode'=>$barcode,'effective_at'=>$date,'cost'=>$cost,'vat'=>$vat];
    }
    if(!$out)throw new InvalidArgumentException('Geçerli maliyet satırı yok.');
    return $out;
}
/** Explicit barcode selection for an entire group; no name-based automatic cost assignment. */
function cost_history_group_values(array $barcodes,string $date,string $cost,string $vat): array
{
    if(!$barcodes||count($barcodes)>200)throw new InvalidArgumentException('1–200 ürün seçin.');
    if(trim($vat)==='')throw new InvalidArgumentException('Alış KDV oranı kaydetmek için gerekli.');
    $rows=[];
    foreach($barcodes as $bc){
        if(!is_string($bc)||$bc===''||trim($bc)!==$bc)throw new InvalidArgumentException('Geçersiz barkod seçimi.');
        $rows[]=[$bc,$date,$cost,$vat];
    }
    return cost_history_values(['Barkod','Geçerlilik Tarihi','Maliyet','Maliyet KDV Oranı'],$rows);
}
/** Append-only revisions. All rows validated first; explicit replacement required for conflicting effective date. */
function cost_history_save(PDO $db,int $uid,array $rows,bool $replace=false,string $source='manual',bool $updateCatalog=false): array
{
    $db->beginTransaction();
    try{
        $q=$db->prepare("SELECT COUNT(*) FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode=?");
        $old=$db->prepare("SELECT cost,cost_vat FROM product_cost_history WHERE user_id=? AND barcode=? AND effective_at=? ORDER BY id DESC LIMIT 1");
        $ins=$db->prepare("INSERT INTO product_cost_history(user_id,barcode,effective_at,cost,cost_vat,source,recorded_at) VALUES(?,?,?,?,?,?,?)");
        $count=0;$same=0;$catalogUpdated=0;
        $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->setTime(0,0)->getTimestamp();
        $update=$db->prepare("UPDATE products SET cost=?,cost_vat=? WHERE user_id=? AND marketplace='trendyol' AND barcode=? AND (cost IS NULL OR cost<>? OR cost_vat IS NULL OR cost_vat<>?)");
        foreach($rows as $r){
            $bc=(string)$r['barcode'];$ts=(int)$r['effective_at'];$c=(float)$r['cost'];$v=(float)$r['vat'];
            if($c<=0||!is_finite($c)||$v<0||$v>100||!is_finite($v)||$ts<=0||$ts>time())throw new InvalidArgumentException('Geçersiz maliyet kaydı.');
            $q->execute([$uid,$bc]);if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Barkod tek bir Trendyol ürünüyle eşleşmeli: '.$bc);
            if($updateCatalog&&$ts!==$today)throw new InvalidArgumentException('Güncel katalog maliyeti yalnızca bugün geçerli olan kayıtla birlikte güncellenebilir.');
            $old->execute([$uid,$bc,$ts]);$existing=$old->fetch();
            if($existing&&abs((float)$existing['cost']-$c)<0.000001&&abs((float)$existing['cost_vat']-$v)<0.000001){
                $same++;
            }else{
                if($existing&&!$replace)throw new InvalidArgumentException('Aynı tarihli farklı maliyet var: '.$bc.'. Düzeltme onayı gerekli.');
                $ins->execute([$uid,$bc,$ts,$c,$v,$source,time()]);$count++;
            }
            if($updateCatalog){
                $update->execute([$c,$v,$uid,$bc,$c,$v]);
                $catalogUpdated+=$update->rowCount();
            }
        }
        $db->commit();return ['inserted'=>$count,'unchanged'=>$same,'catalog_updated'=>$catalogUpdated];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Return latest recorded revision of each effective day, ordered ascending by date/id. */
function cost_history_map(PDO $db,int $uid,array $barcodes,int $maxTs): array
{
    $out=[];$barcodes=array_values(array_unique(array_filter(array_map('strval',$barcodes),static fn($b)=>$b!=='')));
    foreach(array_chunk($barcodes,200) as $chunk){
        $in=implode(',',array_fill(0,count($chunk),'?'));
        $q=$db->prepare("SELECT id,barcode,effective_at,cost,cost_vat FROM product_cost_history WHERE user_id=? AND effective_at<=? AND barcode IN ($in) ORDER BY barcode,effective_at,id");
        $q->execute(array_merge([$uid,$maxTs],$chunk));
        while($row=$q->fetch())$out[$row['barcode']][]=$row;
    }
    return $out;
}
function cost_history_at(array $versions,int $ts): ?array
{
    $lo=0;$hi=count($versions)-1;$match=null;
    while($lo<=$hi){$mid=intdiv($lo+$hi,2);$v=$versions[$mid];
        if((int)$v['effective_at']<=$ts){$match=$v;$lo=$mid+1;}else $hi=$mid-1;
    }
    return $match;
}
/** Read-time overlay: original first-observed snapshots in DB are never changed. */
function cost_history_overlay(PDO $db,int $uid,array $orders): array
{
    $barcodes=[];$maxTs=0;
    foreach($orders as &$o){
        if(($o['marketplace']??'')!=='trendyol')continue;
        if(is_string($o['lines']??null))$o['lines']=json_decode($o['lines'],true);
        if(!is_array($o['lines']??null))$o['lines']=[];
        $maxTs=max($maxTs,(int)$o['order_date']);
        // Preserve malformed rows so downstream reconciliation cannot mistake a partial package for a complete one.
        foreach($o['lines'] as $l)if(is_array($l))$barcodes[]=(string)($l['barcode']??'');
    }
    unset($o);
    $map=cost_history_map($db,$uid,$barcodes,$maxTs);
    foreach($orders as &$o){
        if(($o['marketplace']??'')!=='trendyol')continue;
        foreach($o['lines'] as &$l){
            if(!is_array($l))continue;
            $v=cost_history_at($map[$l['barcode']??'']??[],(int)$o['order_date']);
            if($v){
                $l['cost_snapshot']=(float)$v['cost'];$l['cost_vat_snapshot']=(float)$v['cost_vat'];
                $l['cost_source']='dated_user';$l['cost_revision_id']=(int)$v['id'];$l['cost_effective_at']=(int)$v['effective_at'];
            }elseif(isset($l['cost_snapshot']))$l['cost_source']='first_observed';
            else $l['cost_source']='missing';
        }unset($l);
    }unset($o);
    return $orders;
}
