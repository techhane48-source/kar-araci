<?php
declare(strict_types=1);
/** Akademi Plus examples: integer kuruş, explicit eligible discount splits only.
 * Each candidate receives its own already evaluated eligible discounts.
 * No assumptions about stacking, basket conditions or equal-price tie breaking. */
function campaign_price(int $price,array $discounts):?array{
    if($price<=0||$price>10000000000)return null;
    $seller=0;$platform=0;
    foreach($discounts as $d){
        if(!is_array($d)||!is_int($d['seller']??null)||!is_int($d['platform']??null)||$d['seller']<0||$d['platform']<0||$d['seller']>$price||$d['platform']>$price)return null;
        $seller+=$d['seller'];$platform+=$d['platform'];
        if($seller+$platform>=$price)return null;
    }
    return ['customer'=>$price-$seller-$platform,'basis'=>$price-$seller,'seller_discount'=>$seller,'platform_discount'=>$platform];
}
/** Bounds in kuruş, inclusive. Unknown/overlapping evidence is not a standard rate. */
function campaign_rate(int $basis,array $tiers,?float $standard):?float{
    if($basis<=0)return null;$matches=[];
    foreach($tiers as $t){
        $lo=$t['min']??null;$hi=$t['max']??null;$r=$t['rate']??null;
        if(($lo!==null&&(!is_int($lo)||$lo<0))||($hi!==null&&(!is_int($hi)||$hi<=0))||($lo===null&&$hi===null)||($lo!==null&&$hi!==null&&$lo>$hi)||!is_numeric($r)||!is_finite((float)$r)||$r<0||$r>100)return null;
        if(($lo===null||$basis>=$lo)&&($hi===null||$basis<=$hi))$matches[]=(float)$r;
    }
    if(count($matches)>1)return null;
    return $matches[0]??($standard!==null&&is_finite($standard)&&$standard>=0&&$standard<=100?$standard:null);
}
function campaign_plus_quote(int $normal,?int $special,?int $plusLimit,array $generalNormal,array $generalSpecial,array $plusOnly,array $normalTiers,array $plusTiers,?float $standard):?array{
    if($special!==null&&($plusLimit===null||$special<=0||$special>min($normal,$plusLimit)))return null;
    $n=campaign_price($normal,$generalNormal);$b=campaign_price($normal,array_merge($generalNormal,$plusOnly));
    $a=$special===null?null:campaign_price($special,$generalSpecial);
    if(!$n||!$b||($special!==null&&!$a))return null;
    if($a&&$a['customer']===$b['customer']&&$a['basis']!==$b['basis'])return null;
    $p=$a&&$a['customer']<=$b['customer']?$a:$b;
    $n['rate']=campaign_rate($n['basis'],$normalTiers,$standard);
    // Invalid or ambiguous tariff evidence must not fall through.
    $normalFallback=campaign_rate($p['basis'],$normalTiers,$standard);
    $plusMatch=campaign_rate($p['basis'],$plusTiers,null);
    $eligible=0;
    foreach($plusTiers as $t)if((!isset($t['min'])||$p['basis']>=$t['min'])&&(!isset($t['max'])||$p['basis']<=$t['max']))$eligible++;
    if($eligible>0&&$plusMatch===null)return null;
    $p['rate']=$plusMatch??$normalFallback;
    $p['route']=$a&&$a['customer']<=$b['customer']?'plus_price':'plus_promotion';
    foreach([&$n,&$p] as &$v){$v['commission']=$v['rate']===null?null:(int)round($v['basis']*$v['rate']/100,0,PHP_ROUND_HALF_UP);}unset($v);
    return ['normal'=>$n,'plus'=>$p];
}
function campaign_empty_promo(string $s):bool{return in_array(trim($s),['','-','—'],true);}
/** Recognize only the real workbook layouts, including duplicated Plus rate columns. */
function campaign_schema(array $headers,string $module):?array{
    $h=array_map(static fn($v)=>analysis_header((string)$v),$headers);
    $one=static function(string $key)use($h):int{$a=array_keys($h,$key,true);if(count($a)!==1)throw new RuntimeException('Kolon eksik veya tekrar ediyor: '.$key);return $a[0];};
    $prices=[];$rates=[];$meta=[];
    if(in_array('plusfiyatustlimiti',$h,true)){
        if($module!=='plus')throw new RuntimeException('Bu dosya Plus Komisyon Tarifeleri ekranına ait.');
        $a=$one('tariharaligi3gun');$b=$one('tariharaligi4gun');$ix=array_keys($h,'pluskomisyonteklifi',true);
        if(count($ix)!==2||!($a<$ix[0]&&$ix[0]<$b&&$b<$ix[1]))throw new RuntimeException('Plus dönem komisyonları eksik.');
        $limit=$one('plusfiyatustlimiti');
        foreach([3=>$a,4=>$b] as $days=>$date){$k='period'.$days.'_plus';$prices[$k]=['index'=>$limit,'upper_index'=>$limit,'lower_index'=>null,'period_index'=>$date,'label'=>$days.' Gün / Plus'];$rates[$k]=$ix[$days===3?0:1];}
        foreach(['tsf'=>'gunceltsf','basis'=>'komisyonaesasfiyat','plus_basis'=>'pluskomisyonaesasfiyati','customer'=>'musteriningorduguguncelfiyat','plus_customer'=>'plusmusteriningordugufiyat','promo'=>'gunceluygulananpromosyonlar','current_rate'=>'guncelkomisyon','stock'=>'stok','limit'=>'plusfiyatustlimiti','id'=>'urunid'] as $k=>$v)$meta[$k]=$one($v);
    }elseif(in_array('1yildizustfiyat',$h,true)){
        if($module!=='etiket')throw new RuntimeException('Bu dosya Avantajlı Ürün Etiketleri ekranına ait.');
        for($i=1;$i<=3;$i++){$k='star'.$i;$hi=$one($i.'yildizustfiyat');$prices[$k]=['index'=>$hi,'upper_index'=>$hi,'lower_index'=>$i===3?null:$one($i.'yildizaltfiyat'),'label'=>$i.' Yıldız'];}
        foreach(['tsf'=>'trendyolsatisfiyati','customer'=>'musteriningorduguguncelfiyat','plus_customer'=>'plusmusteriningordugufiyat','promo'=>'gunceluygulananpromosyonlar','stock'=>'stok','tariff'=>'komisyontarifesi','id'=>'externalid'] as $k=>$v)$meta[$k]=$one($v);
    }elseif(in_array('24saatfiyat',$h,true)&&in_array('24saatflasbaslangictarihi',$h,true)){
        if($module!=='flash')throw new RuntimeException('Bu dosya Flaş Ürünler ekranına ait.');
        foreach([24,3] as $hours){$k='flash'.$hours;$prices[$k]=['index'=>$one($hours.'saatfiyat'),'start_index'=>$one($hours.'saatflasbaslangictarihi'),'end_index'=>$one($hours.'saatflasbitistarihi'),'label'=>$hours.' Saat'];}
        foreach(['tsf'=>'mevcutfiyat','customer'=>'musteriningordugufiyat','plus_customer'=>'plusmusteriningordugufiyat','promo'=>'gunceluygulananpromosyonlar','stock'=>'stok','tariff'=>'urunkomisyontarifesecenegi','campaign'=>'kampanyaliurun','current_rate'=>'mevcutkomisyon','id'=>'urunid'] as $k=>$v)$meta[$k]=$one($v);
    }else return null;
    return ['barcode'=>$one('barkod'),'name'=>$one($module==='flash'?'urunadi':'urunismi'),'prices'=>$prices,'rates'=>$rates,'campaign_meta'=>$meta,'campaign_kind'=>$module];
}
function campaign_slot(string $start,string $end,int $hours):?array{
    $tz=new DateTimeZone('Europe/Istanbul');$parse=static function($v)use($tz){$d=DateTimeImmutable::createFromFormat('!d/m/Y H:i',$v,$tz);return $d&&$d->format('d/m/Y H:i')===$v?$d:null;};
    $a=$parse($start);$b=$parse($end);if(!$a||!$b)return null;
    $delta=$b->getTimestamp()-$a->getTimestamp();
    if(!in_array($delta,[$hours*3600,$hours*3600-60],true))return null;
    $hh=(int)$a->format('H');if($a->format('i')!=='00'||($hours===24&&$hh!==0)||($hours===3&&!in_array($hh,[9,12,15,18,21],true)))return null;
    return ['start'=>$a->getTimestamp(),'end'=>$b->getTimestamp(),'label'=>$start.' – '.$end];
}
