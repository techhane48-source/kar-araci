<?php
declare(strict_types=1);
/** Link same-day uploaded evidence, never infer that an offered tariff was accepted. */
function campaign_cents($value):?int{
    if(!is_scalar($value))return null;$n=parse_money_input((string)$value);
    return $n!==null&&$n>0&&$n<=100000000?(int)round($n*100,0,PHP_ROUND_HALF_UP):null;
}
function campaign_period_window(string $period,int $uploaded):?array{
    $months=['Ocak'=>1,'Şubat'=>2,'Mart'=>3,'Nisan'=>4,'Mayıs'=>5,'Haziran'=>6,'Temmuz'=>7,'Ağustos'=>8,'Eylül'=>9,'Ekim'=>10,'Kasım'=>11,'Aralık'=>12];
    if(!preg_match('/^(\d{1,2}) ([\p{L}]+) (\d{2})[.:](\d{2})\s*-\s*(\d{1,2}) ([\p{L}]+) (\d{2})[.:](\d{2})$/uD',trim($period),$m))return null;
    $a=$months[$m[2]]??0;$b=$months[$m[6]]??0;
    if(!$a||!$b||$m[3]>23||$m[4]>59||$m[7]>23||$m[8]>59)return null;
    $tz=new DateTimeZone('Europe/Istanbul');$at=(new DateTimeImmutable('@'.$uploaded))->setTimezone($tz);$y=(int)$at->format('Y');$matches=[];
    foreach([$y-1,$y,$y+1] as $yr){
        $ey=$yr+($b<$a?1:0);
        if(!checkdate($a,(int)$m[1],$yr)||!checkdate($b,(int)$m[5],$ey))continue;
        $start=$at->setDate($yr,$a,(int)$m[1])->setTime((int)$m[3],(int)$m[4])->getTimestamp();
        $end=$at->setDate($ey,$b,(int)$m[5])->setTime((int)$m[7],(int)$m[8],59)->getTimestamp();
        if($end>$start&&$end-$start<=8*86400&&$end>=$uploaded&&$start<=$uploaded+8*86400)$matches[]=[$start,$end];
    }
    return count($matches)===1?$matches[0]:null;
}
function campaign_day(int $ts):string{return (new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d');}
function campaign_clean_snapshot(array $m):?int{
    $tsf=campaign_cents($m['tsf']??null);$customer=campaign_cents($m['customer']??null);
    if($tsf===null||$customer!==$tsf||!array_key_exists('promo',$m)||!campaign_empty_promo($m['promo'])||trim((string)($m['plus_customer']??''))!==''||trim((string)($m['plus_basis']??''))!=='')return null;
    if(array_key_exists('basis',$m)&&campaign_cents($m['basis'])!==$tsf)return null;
    return $tsf;
}
function campaign_normal_meta(array $headers):array{
    $h=array_map(static fn($v)=>analysis_header((string)$v),$headers);$out=[];
    foreach(['tsf'=>'gunceltsf','basis'=>'komisyonaesasfiyat','customer'=>'musteriningorduguguncelfiyat','plus_customer'=>'plusmusteriningordugufiyat','promo'=>'gunceluygulananpromosyonlar','current_rate'=>'guncelkomisyon','choice'=>'tarifesecimi','stock'=>'stok'] as $k=>$v){$ix=array_keys($h,$v,true);if(count($ix)!==1)return [];$out[$k]=$ix[0];}
    return $out;
}
/** Exactly four disjoint and contiguous tiers, all from the same period. */
function campaign_normal_tiers(array $r,int $days):?array{
    $out=[];$period=null;
    for($i=1;$i<=4;$i++){
        $t=$r['tiers']['period'.$days.'_tier'.$i]??null;if(!$t||!is_numeric($t['rate']??null)||$t['rate']<0||$t['rate']>100)return null;
        $p=campaign_cents($t['price']??null);if($p===null)return null;
        $lo=$i===1?$p:($i===4?null:campaign_cents($t['min_price']??null));$hi=$i===1?null:$p;
        if(($i!==4&&$lo===null)||($lo!==null&&$hi!==null&&$lo>$hi))return null;
        if($period!==null&&$period!==($t['period']??null))return null;$period=$t['period']??null;
        if($i>1&&$out[$i-2]['min']!==$hi+1)return null;
        $out[]=['min'=>$lo,'max'=>$hi,'rate'=>(float)$t['rate']];
    }
    return is_string($period)&&$period!==''?['tiers'=>$out,'period'=>$period]:null;
}
/** Cross-reference live page rows in one tenant-scoped query. No database writes. */
function campaign_link_context(PDO $db,int $uid,array $barcodes,int $now):array{
    $barcodes=array_values(array_unique(array_filter($barcodes,static fn($x)=>is_string($x)&&$x!=='')));
    if(!$barcodes||count($barcodes)>100)return [];
    $ph=implode(',',array_fill(0,count($barcodes),'?'));
    $q=$db->prepare("SELECT r.module,r.payload_json,j.created_at FROM analysis_rows r JOIN analysis_jobs j ON j.user_id=r.user_id AND j.kind=r.kind AND j.module=r.module WHERE r.user_id=? AND r.kind='promotion' AND r.module IN ('komisyon','plus','etiket','flash') AND json_extract(r.payload_json,'$.barcode') IN ($ph)");
    $q->execute(array_merge([$uid],$barcodes));$out=[];
    while($v=$q->fetch()){
        $r=json_decode($v['payload_json'],true);if(!is_array($r)||!is_string($r['barcode']??null))continue;
        $key='b:'.$r['barcode'];$mod=$v['module'];$at=(int)$v['created_at'];
        if($at>$now||campaign_day($at)!==campaign_day($now))continue;
        if($mod==='flash'){ $out[$key]['flash_count']=($out[$key]['flash_count']??0)+1;continue; }
        if(array_key_exists($mod,$out[$key]??[])){$out[$key][$mod]=null;continue;}
        $out[$key][$mod]=['row'=>$r,'at'=>$at];
    }
    return $out;
}
function campaign_link_quotes(array $r,string $module,array $ctx,array $product,int $uploaded,int $now):array{
    if(!in_array($module,['plus','flash','etiket'],true)||$uploaded>$now||campaign_day($uploaded)!==campaign_day($now))return [];
    $normal=$ctx['komisyon']??null;if(!$normal||($normal['row']['barcode']??null)!==($r['barcode']??null))return [];
    $m=$r['campaign_meta']??[];$nm=$normal['row']['campaign_meta']??[];$tsf=campaign_clean_snapshot($m);
    if($tsf===null||campaign_clean_snapshot($nm)!==$tsf||!is_numeric($product['cost']??null)||$product['cost']<=0||!isset($product['cargo'])||!is_numeric($product['cargo'])||$product['cargo']<=0)return [];
    if($module==='plus'&&(!isset($m['basis'])||!isset($nm['basis'])))return [];
    if(campaign_cents($product['price']??null)!==$tsf)return [];
    $current=analysis_rate((string)($nm['current_rate']??''));if($current===null)return [];
    if(isset($m['current_rate'])&&analysis_rate((string)$m['current_rate'])!==$current)return [];
    if(in_array($module,['flash','etiket'],true)&&($m['tariff']??'')!=='Var')return [];
    if($module==='flash'&&!in_array(trim((string)($m['campaign']??'')),['','-','Yok'],true))return [];
    $out=[];
    foreach($r['tiers'] as $key=>$t){
        $price=campaign_cents($t['price']??null);if($price===null||$price>$tsf)continue;
        $matches=[];
        foreach([3,4] as $days){
            $tier=campaign_normal_tiers($normal['row'],$days);if(!$tier)continue;
            $window=campaign_period_window($tier['period'],(int)$normal['at']);if(!$window)continue;
            if($module==='plus'){
                if($key!=='period'.$days.'_plus'||($t['period']??null)!==$tier['period'])continue;
                $from=max($now,$window[0]);$to=$window[1];
            }elseif($module==='flash'){
                $from=$t['slot']['start']??0;$to=$t['slot']['end']??0;
                if($from<$now||$to<$from)continue;
            }else{$from=$to=$now;}
            if($from<$window[0]||$to>$window[1]||$to<$from)continue;
            $matches[]=$tier;
        }
        if(count($matches)!==1)continue;
        $tier=$matches[0];$nr=campaign_rate($module==='plus'?$tsf:$price,$tier['tiers'],null);
        if($nr===null)continue;
        $pr=$module==='plus'?$t['rate']:null;if($module==='plus'&&(!is_numeric($pr)||$pr<0||$pr>100))continue;
        try{
            $nc=product_profit($product,($module==='plus'?$tsf:$price)/100,$nr);
            $pc=$module==='plus'?product_profit($product,$price/100,(float)$pr):null;
            $out[$key]=['normal_rate'=>$nr,'normal_profit'=>$nc['profit'],'normal_price'=>($module==='plus'?$tsf:$price)/100,'plus_rate'=>$pr,'plus_profit'=>$pc['profit']??null,'plus_price'=>$pc?$price/100:null,'period'=>$tier['period'],'conditional'=>true];
        }catch(InvalidArgumentException $e){}
    }
    return $out;
}
