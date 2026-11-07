<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/profit.php';
$failed=0;
function check(bool $ok,string $label):void { global $failed; echo ($ok?'PASS ':'FAIL ').$label."\n"; if(!$ok)$failed++; }
function near(float $a,float $b):bool { return abs($a-$b)<0.000001; }
$p=['barcode'=>'A','name'=>'Ürün A','category'=>'Giyim','cost'=>200.0,'price'=>400.0,'cargo'=>50.0,'rate'=>21.36,'kdv'=>20.0,
    'service_fee'=>0.0,'extra_fee'=>0.0,'stopaj'=>0.0,'return_rate'=>0.0,'stopaj_mode'=>'manual'];
check(near(calc_product_profit($p)['profit'],53.8),'Kayıtlı sıfır gider korunur: 53,80 TL');
check(near(calc_product_profit(array_replace($p,['kdv'=>0]))['profit'],64.56),'%0 KDV korunur');
$auto=array_replace($p,['service_fee'=>13.19,'stopaj_mode'=>'auto']);
check(near(calc_product_profit($auto)['profit'],39.475),'Otomatik stopaj ve hizmet: 39,475 TL');
check(near(calc_product_profit($auto,600)['stopaj'],5),'Yeni fiyat otomatik stopajı yeniden hesaplar');
check(near(calc_product_profit(array_replace($p,['stopaj'=>3]),600)['stopaj'],3),'Manuel stopaj fiyatla değişmez');
check(!calc_product_profit(array_replace($p,['cost'=>0]))['ok'],'Maliyetsiz ürüne kâr uydurulmaz');
check(!calc_product_profit(array_replace($p,['rate'=>101]))['ok'],'Geçersiz oran reddedilir');
check(!calc_product_price($auto,0,90)['ok'],'Ulaşılamayan %90 marj reddedilir');
check(!calc_product_price($auto,0,-1)['ok'],'Negatif hedef reddedilir');
foreach (['auto','manual'] as $mode) {
    $product=array_replace($auto,['stopaj_mode'=>$mode,'stopaj'=>3.0]);
    foreach ([[50,0],[0,20],[0,0]] as [$target,$margin]) {
        $r=calc_product_price($product,$target,$margin);
        $back=calc_product_profit($product,$r['price']);
        check($r['ok'] && near($r['profit'],$back['profit']) && $back['profit']+1e-7>=($margin>0 ? $r['price']*$margin/100 : $target),"$mode hedef fiyat ileri hesapla doğrulanır");
        $lower=calc_product_profit($product,$r['price']-0.01);
        check($lower['profit']<($margin>0 ? ($r['price']-0.01)*$margin/100 : $target),"$mode hedefi sağlayan en düşük kuruş seçilir");
    }
}
foreach (['Awaiting','Verified','UnPacked'] as $status) check(!order_is_sale(['status'=>$status]),"$status satış sayılmaz");
$q=array_replace($p,['barcode'=>'B','name'=>'Ürün B','category'=>'Gıda','cost'=>100,'price'=>300,'cargo'=>10,'rate'=>10,'kdv'=>10,'extra_fee'=>4,'service_fee'=>2,'stopaj'=>1,'return_rate'=>2]);
$order=['status'=>'Delivered','total_price'=>1100.0,'lines'=>[['barcode'=>'A','quantity'=>2,'price'=>400],['barcode'=>'B','quantity'=>1,'price'=>300]]];
$by=['A'=>$p,'B'=>$q];
$r=calc_order_profit($order,$by);
check($r['ok'] && near($r['profit'],calc_product_profit($p)['profit']*2+calc_product_profit($q)['profit']),'Çok adetli karma KDV siparişi satır hesaplarının toplamıdır');
check(near($r['cost'],500) && near($r['cargo'],110) && near($r['extra_fee'],4),'Adet başı maliyet/kargo/ek giderler toplanır');
$discounted=array_replace($order,['total_price'=>990]);
$d=calc_order_profit($discounted,$by);
check(near(array_sum(array_column($d['lines'],'sale')),990),'Paket indirimi dağıtılır; satır ciroları paketle eşleşir');
check(near($d['profit'],calc_product_profit($p,360)['profit']*2+calc_product_profit($q,270)['profit']),'Paket indirimi sonrası her satır kendi KDV’siyle hesaplanır');
foreach (['Cancelled','Canceled','Returned','UnDelivered'] as $status) check(!calc_order_profit(array_replace($order,['status'=>$status]),$by)['ok'],"$status satış kârına alınmaz");
check(!calc_order_profit($order,['A'=>$p])['ok'],'Eksik satır maliyeti siparişin kârını belirsiz yapar');
$rows=aggregate_profit_rows([$order,$discounted],$by,'product');
$cats=aggregate_profit_rows([$order,$discounted],$by,'category');
check(near(array_sum(array_column($rows,'profit')),$r['profit']+$d['profit']),'Ürün raporu toplamı sipariş kârıyla eşleşir');
check(near(array_sum(array_column($cats,'profit')),$r['profit']+$d['profit']),'Kategori raporu toplamı sipariş kârıyla eşleşir');
$unknown=aggregate_profit_rows([$order],['A'=>$p],'product');
check($unknown['A']['profit']===null && $unknown['B']['profit']===null,'Eksik siparişin satırlarında yanlış kâr gösterilmez');
$known=aggregate_profit_rows([$order,array_replace($order,['status'=>'Cancelled'])],$by,'product');
check(near($known['A']['sale'],800),'İptal paket rapor toplamlarına girmez');
// Başka modellerle karıştırılmadan, sıfır KDV ve kayıplı satış kontrolü.
check(near(calc_profit(100,50,10,10,0)['profit'],-65),'Zararlı satışta vergi sıfırlanır, zarar korunur');
check(!calc_price(1000000000,0,99,20,0,0,0,0,1000000000)['ok'],'Aşırı hedef izin verilen fiyat sınırını aşamaz');
echo $failed?"$failed test başarısız\n":"TÜM TESTLER GEÇTİ\n";
exit($failed?1:0);
