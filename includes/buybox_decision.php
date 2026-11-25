<?php
declare(strict_types=1);
require_once __DIR__.'/profit_targets.php';

// Conservative application policy, not a Trendyol freshness guarantee.
const BUYBOX_MAX_AGE=3600;

function buybox_is_fresh(?array $bb,int $now): bool
{
    if(!$bb||!is_numeric($bb['checked_at']??null))return false;
    $age=$now-(int)$bb['checked_at'];
    return $age>=0&&$age<=BUYBOX_MAX_AGE;
}

/** Read-only matching-price scenario. Buybox position is not determined by price alone. */
function buybox_decision(array $p,?array $bb,?array $rule,int $now): array
{
    $out=['state'=>'no_data','label'=>'Buybox verisi yok','fresh'=>false,'rule'=>$rule,
        'break_even'=>null,'target_floor'=>null,'match_price'=>null,'match_profit'=>null,
        'match_margin'=>null,'target_met'=>null,'suggested_price'=>null,'difference'=>null];
    if(($p['marketplace']??'')!=='trendyol')return $out;
    $cost=(float)($p['cost']??0);$own=(float)($p['price']??0);
    if($cost<=0||!is_finite($cost)||$own<=0||!is_finite($own)){
        return array_replace($out,['state'=>'missing_input','label'=>'Maliyet veya mevcut satış fiyatı eksik']);
    }
    try{
        foreach(['cost','price','cargo','rate','kdv','cost_vat','extra_fee','service_fee','stopaj','stopaj_rate','return_rate'] as $key){
            if(!isset($p[$key]))continue;
            if(!is_numeric($p[$key])||!is_finite((float)$p[$key])||(float)$p[$key]<0||
               (in_array($key,['rate','kdv','cost_vat','stopaj_rate','return_rate'],true)&&(float)$p[$key]>100))
                throw new InvalidArgumentException('Invalid input');
        }
        $base=product_target_price($p,0,0);
        if($base['ok'])$out['break_even']=$base['price'];
        if($rule){
            $target=target_suggestion($p,$rule);
            $out['target_floor']=$target['price'];
        }
        if(!$bb)return $out;
        if((string)($bb['barcode']??'')!==(string)($p['barcode']??''))return array_replace($out,['state'=>'invalid','label'=>'Barkod eşleşmedi']);
        if(!buybox_is_fresh($bb,$now))return array_replace($out,['state'=>'stale','label'=>'Veri eski; yeniden sorgulayın']);
        $out['fresh']=true;
        $rank=$bb['buybox_rank']??null;$price=$bb['buybox_price']??null;$multiple=$bb['multiple_sellers']??null;
        if(!is_numeric($rank)||(float)$rank!==floor((float)$rank)||(int)$rank<1||!is_numeric($price)||
           !is_finite((float)$price)||(float)$price<=0||!in_array($multiple,[0,1,'0','1'],true))
            return array_replace($out,['state'=>'invalid','label'=>'Buybox verisi eksik veya geçersiz']);
        $price=(float)$price;
        if(abs($price*100-round($price*100))>0.00001)
            return array_replace($out,['state'=>'invalid','label'=>'Buybox fiyatı kuruş hassasiyetinde değil']);
        if((int)$rank===1)return array_replace($out,['state'=>'winning','label'=>'Buybox sizde; düşürme önerisi yok']);
        if(!(int)$multiple)return array_replace($out,['state'=>'invalid','label'=>'Satıcı sayısı ve sıra çelişkili']);
        $r=product_profit($p,$price);
        $out['match_price']=$price;$out['match_profit']=$r['profit'];$out['match_margin']=$r['margin'];$out['difference']=$price-$own;
        // A minimum feasible price is not sufficient for a nonmonotonic objective.
        if($r['profit'] < -1e-8)return array_replace($out,['state'=>'loss','label'=>'Buybox fiyatında tahmini zarar']);
        if(!$rule)return array_replace($out,['state'=>'no_target','label'=>'Kâr hedefi tanımlayın']);
        $out['target_met']=target_pass($rule,$r,$cost,$price);
        if(!$out['target_met'])return array_replace($out,['state'=>'below_target','label'=>'Buybox fiyatı hedefin altında']);
        if($price>=$own-0.00001)return array_replace($out,['state'=>'no_reduction','label'=>'Mevcut fiyat zaten aynı veya düşük; fiyat dışı etkenleri inceleyin']);
        if($out['target_floor']===null)return array_replace($out,['state'=>'invalid','label'=>'Hedef taban fiyatı doğrulanamadı']);
        $out['suggested_price']=$price;
        return array_replace($out,['state'=>'match_possible','label'=>'Hedefi karşılayan eşit fiyat senaryosu']);
    }catch(InvalidArgumentException $e){return array_replace($out,['state'=>'invalid','label'=>'Hesap ayarları geçersiz']);}
}

/** All pages in this user action succeed before any cached rows are replaced. */
function buybox_refresh_page(PDO $db,int $uid,string $seller,array $barcodes,object $client): array
{
    $barcodes=array_values(array_unique(array_filter(array_map('strval',$barcodes),static fn($b)=>$b!=='')));
    if(count($barcodes)>50)return ['ok'=>false,'error'=>'Bir sorguda en fazla 50 barkod.'];
    $items=[];$missing=[];
    foreach(array_chunk($barcodes,10) as $chunk){
        $res=$client->getBuybox($chunk);
        if(!$res['ok'])return $res;
        $observed=time();$returned=[];
        foreach($res['items'] as $r){$bc=(string)($r['barcode']??'');if(!in_array($bc,$chunk,true)||isset($returned[$bc]))return ['ok'=>false,'error'=>'Buybox yanıtı barkodla uyuşmuyor.'];$returned[$bc]=true;$r['observed_at']=$observed;$items[]=$r;}
        foreach($res['missing'] as $bc){$bc=(string)$bc;if(!in_array($bc,$chunk,true)||isset($returned[$bc]))return ['ok'=>false,'error'=>'Buybox eksik barkod listesi uyuşmuyor.'];$returned[$bc]=true;$missing[]=$bc;}
        if(count($returned)!==count($chunk))return ['ok'=>false,'error'=>'Buybox yanıtı tamamlanmadı.'];
    }
    $db->beginTransaction();
    try{
        $up=$db->prepare("INSERT INTO trendyol_buybox(user_id,seller_id,barcode,buybox_rank,buybox_price,multiple_sellers,second_price,third_price,checked_at)
          VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,seller_id,barcode) DO UPDATE SET
          buybox_rank=excluded.buybox_rank,buybox_price=excluded.buybox_price,multiple_sellers=excluded.multiple_sellers,
          second_price=excluded.second_price,third_price=excluded.third_price,checked_at=excluded.checked_at");
        $del=$db->prepare("DELETE FROM trendyol_buybox WHERE user_id=? AND seller_id=? AND barcode=?");
        $now=time();
        foreach($items as $r)$up->execute([$uid,$seller,$r['barcode'],$r['rank'],$r['price'],(int)$r['multiple'],$r['second'],$r['third'],$r['observed_at']]);
        foreach($missing as $bc)$del->execute([$uid,$seller,$bc]);
        $db->commit();return ['ok'=>true,'count'=>count($items),'missing'=>count($missing)];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return ['ok'=>false,'error'=>'Buybox kayıtları güncellenemedi.'];}
}
