<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/trendyol.php';
$failed=0;
function check(bool $ok,string $label):void {global $failed;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failed++;}
function response(array $items,int $pages,int $total,int $number=0):array {return ['http'=>200,'body'=>['content'=>$items,'totalPages'=>$pages,'totalElements'=>$total,'number'=>$number]];}
function product(int $id):array {return ['barcode'=>'BC'.$id,'title'=>'Product '.$id,'salePrice'=>100,'listPrice'=>150,'quantity'=>1,'categoryName'=>'Giyim'];}
$calls=[];
$transport=static function($method,$path,$query) use(&$calls):array {
    $calls[]=$query;$page=(int)$query['page'];$size=(int)$query['size'];
    if($size===1)return response([product(0)],201,201);
    return response(array_map('product',range($page*200,min(200,$page*200+199))),2,201,$page);
};
$client=new TrendyolClient('seller','unused','unused',false,$transport);
$r=$client->getProducts(500,'',1234567890000,'LAST_MODIFIED_DATE');
check($r['ok'] && count($r['products'])===201,'Ürünlerin bütün sayfaları alınır');
check($calls[0]['startDate']==='1234567890000' && $calls[0]['dateQueryType']==='LAST_MODIFIED_DATE','Ürün artımlı tarih filtresi korunur');
$limited=$client->getProducts(1);
check(!$limited['ok'] && $limited['complete']===false,'Ürün sayfa limiti sessizce kesmez');
$broken=new TrendyolClient('seller','x','x',false,static function($m,$p,$q) use($transport):array {
    if((int)$q['page']===1)return ['http'=>429,'body'=>null];
    return $transport($m,$p,$q);
});
check(!$broken->getProducts()['ok'],'Başarısız paralel ürün sayfası tüm sonucu başarısız yapar');
$badJson=new TrendyolClient('seller','x','x',false,static fn()=>['http'=>200,'body'=>null]);
check(!$badJson->getProducts()['ok'],'HTTP 200 ama geçersiz JSON başarılı sayılmaz');
$missing=new TrendyolClient('seller','x','x',false,static fn()=>['http'=>200,'body'=>['content'=>[]]]);
check(!$missing->getProducts()['ok'],'Eksik sayfalama metadatası boş katalog sayılmaz');
$empty=new TrendyolClient('seller','x','x',false,static fn()=>response([],0,0));
check($empty->getProducts()['ok'] && $empty->getProducts()['count']===0,'Gerçek boş katalog kabul edilir');
$duplicate=new TrendyolClient('seller','x','x',false,static function($m,$p,$q) use($transport):array {
    $res=$transport($m,$p,$q);if((int)$q['page']===1)$res['body']['content']=[product(0)];return $res;
});
check(!$duplicate->getProducts()['ok'],'Sayfa tekrarından oluşan mükerrer barkod eksik sonuç olarak reddedilir');
$orderCalls=0;
$orders=new TrendyolClient('seller','x','x',false,static function($m,$p,$q) use(&$orderCalls):array {
    $orderCalls++;$page=(int)$q['page'];$list=[];
    for($i=$page*200;$i<min(2001,($page+1)*200);$i++)$list[]=['id'=>'PKG'.$i,'orderNumber'=>'O'.$i,'orderDate'=>1700000000000,'totalPrice'=>100,'lines'=>[['barcode'=>'REAL-BC','sku'=>'SKU','quantity'=>1,'amount'=>100]]];
    return response($list,11,2001,$page);
});
$r=$orders->getOrders('','2026-11-01T00:00:00+03:00','2026-11-02T00:00:00+03:00');
check($r['ok'] && count($r['orders'])===2001 && $orderCalls===11,'Siparişler eski 10 sayfa sınırının ötesinde eksiksiz alınır');
check($r['orders'][0]['lines'][0]['barcode']==='REAL-BC','Sipariş satırında resmî barkod SKU’ya tercih edilir');
check(!$orders->getOrders('','2026-11-01','2026-11-02',10)['ok'],'Siparişte açık limit aşımı hata verir');
check(!$orders->getOrders('','bad-date','2026-11-02')['ok'],'Geçersiz sipariş aralığı reddedilir');
$failedOrderPage=new TrendyolClient('seller','x','x',false,static function($m,$p,$q):array {
    return (int)$q['page']===0?response([['id'=>'PKG','lines'=>[]]],2,2):['http'=>500];
});
check(!$failedOrderPage->getOrders('','2026-11-01','2026-11-02')['ok'],'Sonraki sipariş sayfasının hatası gizlenmez');
$finance=new TrendyolClient('seller','x','x',false,static function($m,$p,$q):array {
    $page=(int)$q['page'];return response([['id'=>'FIN'.$page,'transactionType'=>$q['transactionType'],'barcode'=>'A','commissionRate'=>10,'transactionDate'=>1700000000000]],2,2,$page);
});
$r=$finance->getSettlements('Sale','2026-11-01','2026-11-02');
check($r['ok'] && count($r['settlements'])===2 && $r['settlements'][0]['providerId']==='FIN0','Finans sayfaları sağlayıcı işlem kimliğiyle normalize edilir');
check(!$finance->getSettlements('Sale','2026-11-01','2026-11-02',1)['ok'],'Finans limit aşımı eksik başarı sayılmaz');
$noId=new TrendyolClient('seller','x','x',false,static fn()=>response([['transactionType'=>'Sale']],1,1));
check(!$noId->getSettlements('Sale')['ok'],'Kimliksiz finans işlemi istemcide reddedilir');
$badPage=new TrendyolClient('seller','x','x',false,static function($m,$p,$q):array {
    return (int)$q['page']===0?response([['id'=>'F0']],2,2):response([],2,2,1);
});
check(!$badPage->getSettlements('Sale')['ok'],'Eksik finans sayfası hata verir');
$repeated=new TrendyolClient('seller','x','x',false,static function($m,$p,$q):array {return response([['id'=>'SAME']],2,2,(int)$q['page']);});
check(!$repeated->getSettlements('Sale')['ok'],'Tekrarlanan finans sayfası işlem kaybettirmez; hata verir');
$missingRate=new TrendyolClient('seller','x','x',false,static fn()=>response([['id'=>'F','transactionType'=>'Sale']],1,1));
check(!$missingRate->getSettlements('Sale')['ok'],'Eksik satış komisyonu yanlışlıkla yüzde sıfır sayılmaz');
echo $failed?"$failed test başarısız\n":"TÜM TESTLER GEÇTİ\n";exit($failed?1:0);
