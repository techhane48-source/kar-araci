<?php
declare(strict_types=1);
require_once __DIR__.'/profit_targets.php';
const CATALOG_MAX_AGE=86400;
function catalog_fresh(mixed $ts,int $now): bool
{
    return is_numeric($ts)&&(int)$ts>0&&(int)$ts<=$now&&$now-(int)$ts<=CATALOG_MAX_AGE;
}
function alert_catalog_row(array $p,?array $rule,int $now): array
{
    $issues=[];$calc=null;
    foreach(['cost'=>'missing_cost','price'=>'missing_price'] as $k=>$flag){
        if(!is_numeric($p[$k]??null)||!is_finite((float)$p[$k])||(float)$p[$k]<=0)$issues[]=$flag;
    }
    foreach(['rate','kdv','cost_vat','stopaj_rate','return_rate','cargo','extra_fee','service_fee','stopaj'] as $k){
        if(!isset($p[$k]))continue;
        if(!is_numeric($p[$k])||!is_finite((float)$p[$k])||(float)$p[$k]<0||
           (in_array($k,['rate','kdv','cost_vat','stopaj_rate','return_rate'],true)&&(float)$p[$k]>100)){
            $issues[]='invalid_settings';break;
        }
    }
    if(!$issues){
        try{
            $calc=product_profit($p);
            if(!is_finite((float)$calc['profit']))throw new InvalidArgumentException('Nonfinite');
            if($calc['profit']< -1e-8)$issues[]='loss';
            elseif($rule&&!target_pass($rule,$calc,(float)$p['cost'],(float)$p['price']))$issues[]='below_target';
        }catch(InvalidArgumentException $e){$calc=null;$issues[]='invalid_settings';}
    }
    if(($p['marketplace']??'')==='trendyol'){
        if(!catalog_fresh($p['stock_observed_at']??null,$now))$issues[]='stock_unknown';
        elseif((int)$p['quantity']===0)$issues[]='out_of_stock';
        if(!catalog_fresh($p['sale_price_observed_at']??null,$now))$issues[]='price_unverified';
        if(isset($p['rate'])&&is_numeric($p['rate'])&&(float)$p['rate']===0.0)$issues[]='zero_commission';
    }
    if((float)($p['en']??0)>0&&(float)($p['boy']??0)>0&&(float)($p['yukseklik']??0)>0){
        $expected=(float)$p['en']*(float)$p['boy']*(float)$p['yukseklik']/3000;
        if((float)($p['kesilen_desi']??0)>$expected+0.01)$issues[]='desi_review';
    }
    if((float)($p['return_rate']??0)>15)$issues[]='return_assumption';
    return ['issues'=>$issues,'profit'=>$calc['profit']??null,'margin'=>$calc['margin']??null,'rule'=>$rule];
}
function alert_catalog_labels(): array
{
    return ['loss'=>'Tahmini zarar','below_target'=>'Kâr hedefinin altında','missing_cost'=>'Maliyet eksik',
      'missing_price'=>'Satış fiyatı eksik','invalid_settings'=>'Hesap ayarları geçersiz',
      'stock_unknown'=>'Stok doğrulanmadı / eski','out_of_stock'=>'Son gözlemde stok sıfır',
      'price_unverified'=>'Satış fiyatı doğrulanmadı / eski','zero_commission'=>'%0 komisyonu kontrol edin',
      'desi_review'=>'Girilen desi farkı','return_assumption'=>'Yüksek iade varsayımı'];
}
function alert_catalog_summary(iterable $products,array $rules,int $now): array
{
    $s=['products'=>0,'evaluated'=>0,'flagged'=>0,'counts'=>array_fill_keys(array_keys(alert_catalog_labels()),0)];
    foreach($products as $p){
        $s['products']++;$a=alert_catalog_row($p,target_for_product($rules,$p),$now);
        if($a['profit']!==null)$s['evaluated']++;
        if($a['issues'])$s['flagged']++;
        foreach($a['issues'] as $issue)$s['counts'][$issue]++;
    }
    return $s;
}
function alert_order_row(array $o,array $products): ?array
{
    if(!order_is_domestic_try($o))return ['state'=>'scope_unknown','reason'=>'Ülke / para birimi kapsam dışında','profit'=>null];
    if(!order_is_active_sale($o))return null;
    $r=calc_order_profit($o,$products);
    if(!$r['ok'])return ['state'=>'estimate_missing','reason'=>$r['reason']??'Hesap verisi eksik','profit'=>null];
    if($r['profit']< -1e-8)return ['state'=>'order_loss','reason'=>'Tahmini paket zararı','profit'=>$r['profit']];
    return null;
}
