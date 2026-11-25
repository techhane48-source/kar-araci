<?php
declare(strict_types=1);
require_once __DIR__.'/profit.php';

function target_rule_value(string $kind,string $raw): float
{
    $v=parse_money_input($raw);
    if(!in_array($kind,['margin','roi','profit'],true)||$v===null||$v<0||
      ($kind==='margin'&&$v>=100)||($kind==='roi'&&$v>10000)||($kind==='profit'&&$v>100000000))
        throw new InvalidArgumentException('Geçersiz hedef. Marj %100 altında olmalı.');
    return $v;
}
function target_rule_save(PDO $db,int $uid,string $scope,string $key,string $kind,float $value): void
{
    target_rule_value($kind,number_format($value,2,'.',''));
    if($scope==='store')$key='';
    elseif(in_array($scope,['category','product'],true)){
        if($key===''||strlen($key)>250)throw new InvalidArgumentException('Kategori veya barkod gerekli.');
        $col=$scope==='category'?'category':'barcode';
        $q=$db->prepare("SELECT COUNT(*) FROM products WHERE user_id=? AND marketplace='trendyol' AND $col=?");
        $q->execute([$uid,$key]);$n=(int)$q->fetchColumn();
        if(!$n||($scope==='product'&&$n!==1))throw new InvalidArgumentException('Tekil ürün veya mevcut kategori bulunamadı.');
    }else throw new InvalidArgumentException('Geçersiz kural kapsamı.');
    $db->prepare("INSERT INTO profit_targets(user_id,scope,scope_key,kind,value,updated_at) VALUES(?,?,?,?,?,?)
       ON CONFLICT(user_id,scope,scope_key) DO UPDATE SET kind=excluded.kind,value=excluded.value,updated_at=excluded.updated_at")
       ->execute([$uid,$scope,$key,$kind,$value,time()]);
}
function target_rules(PDO $db,int $uid): array
{
    $q=$db->prepare("SELECT * FROM profit_targets WHERE user_id=? ORDER BY scope,scope_key");$q->execute([$uid]);$out=[];
    while($r=$q->fetch())$out[$r['scope']][$r['scope_key']]=$r;
    return $out;
}
function target_for_product(array $rules,array $p): ?array
{
    if(($p['marketplace']??'trendyol')!=='trendyol')return null;
    $bc=(string)($p['barcode']??'');$cat=(string)($p['category']??'');
    return ($bc!==''?($rules['product'][$bc]??null):null)??($rules['category'][$cat]??null)??($rules['store']['']??null);
}
function target_label(array $r): string
{
    return match($r['scope']){'product'=>'Ürün','category'=>'Kategori',default=>'Mağaza'}.' / '.
       match($r['kind']){'margin'=>'Marj %','roi'=>'Maliyete oran %',default=>'Kâr TL'}.' '.number_format((float)$r['value'],2,',','.');
}
function target_pass(array $r,array $calc,float $cost,float $price): bool
{
    if($price<=0||$cost<=0)return false;
    $v=(float)$r['value'];
    $need=match($r['kind']){'margin'=>$price*$v/100,'roi'=>$cost*$v/100,'profit'=>$v,default=>INF};
    return is_finite((float)$calc['profit'])&&$calc['profit']>=$need-1e-8;
}
function target_suggestion(array $p,?array $rule): array
{
    $out=['rule'=>$rule,'current_profit'=>null,'current_margin'=>null,'price'=>null,'target_profit'=>null,'state'=>'Hedef tanımlanmamış'];
    if(!$rule)return $out;
    $cost=(float)($p['cost']??0);$price=(float)($p['price']??0);
    if($cost<=0){$out['state']='Maliyet eksik';return $out;}
    try{
        foreach(['cost','price','cargo','extra_fee','service_fee','rate','kdv','return_rate','stopaj','stopaj_rate','cost_vat'] as $k){
            if(isset($p[$k])&&(!is_numeric($p[$k])||!is_finite((float)$p[$k])||(float)$p[$k]<0))throw new InvalidArgumentException('Girdi');
        }
        if($price>0){
            $c=product_profit($p);$out['current_profit']=$c['profit'];$out['current_margin']=$c['margin'];
            $out['state']=target_pass($rule,$c,$cost,$price)?'Hedef karşılanıyor':'Hedef altında';
        }else $out['state']='Satış fiyatı eksik';
        $v=(float)$rule['value'];$tp=$rule['kind']==='roi'?$cost*$v/100:($rule['kind']==='profit'?$v:0);
        $tm=$rule['kind']==='margin'?$v:0;
        $r=product_target_price($p,$tp,$tm);
        if(!$r['ok']){$out['state']='Hedefe ulaşılamıyor';return $out;}
        if(!target_pass($rule,$r,$cost,$r['price'])){$out['state']='Hedef doğrulanamadı';return $out;}
        $out['price']=$r['price'];$out['target_profit']=$r['profit'];return $out;
    }catch(InvalidArgumentException $e){$out['state']='Hesap ayarları geçersiz';return $out;}
}
