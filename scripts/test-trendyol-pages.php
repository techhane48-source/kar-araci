<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/trendyol.php';
$failed=0;
function check(bool $ok,string $label):void {global $failed;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failed++;}
function response(array $items,int $pages,int $total,int $number=0):array {return ['http'=>200,'body'=>['content'=>$items,'totalPages'=>$pages,'totalElements'=>$total,'page'=>$number]];}
function content(int $id,bool $stock):array {return ['contentId'=>$id,'title'=>'Product '.$id,'category'=>['name'=>'Giyim'],'brand'=>['name'=>'Brand'],'variants'=>[$stock?['barcode'=>'BC'.$id,'salePrice'=>0,'listPrice'=>150,'quantity'=>0]:['barcode'=>'BC'.$id,'vatRate'=>0,'commission'=>0]]];}
$calls=[];
$transport=static function($method,$path,$query) use(&$calls):array {
    $calls[]=[$path,$query];$page=(int)($query['page']??100);$size=(int)$query['size'];
    $stock=str_ends_with($path,'inventory-and-price');
    return response(array_map(fn($id)=>content($id,$stock),range($page*$size,min(200,($page+1)*$size-1))),3,201,$page);
};
$client=new TrendyolClient('seller','unused','unused',false,$transport);
$r=$client->getProducts(500,'',1234567890000,'LAST_MODIFIED_DATE');
check($r['ok'] && count($r['products'])===201 && count($calls)===6,'V2 katalog ve ayrı stok görünümünün tüm sayfaları alınır');
check(!isset($calls[0][1]['startDate']) && $calls[0][1]['size']==='100','Stok için tam görünüm ve resmî 100 içerik sınırı kullanılır');
check($r['products'][0]['quantity']===0 && $r['products'][0]['salePrice']===0 && $r['products'][0]['vatRate']===0 && $r['products'][0]['commissionRate']===0,'Varyantın sıfır stok, fiyat, KDV ve komisyonu korunur');
check(!$client->getProducts(1)['ok'],'Ürün sayfa sınırı kısmi başarı sayılmaz');
$broken=new TrendyolClient('seller','x','x',false,static fn($m,$p,$q)=>($q['page']??'')==='1'?['http'=>429]:$transport($m,$p,$q));
check(!$broken->getProducts()['ok'],'Sonraki ürün sayfasının hatası gizlenmez');
$badJson=new TrendyolClient('seller','x','x',false,static fn()=>['http'=>200,'body'=>null]);
check(!$badJson->getProducts()['ok'],'HTTP 200 ama geçersiz JSON başarılı sayılmaz');
$missing=new TrendyolClient('seller','x','x',false,static fn()=>['http'=>200,'body'=>['content'=>[]]]);
check(!$missing->getProducts()['ok'],'Eksik metadata boş katalog sayılmaz');
$empty=new TrendyolClient('seller','x','x',false,static fn()=>response([],0,0));
check($empty->getProducts()['ok'] && $empty->getProducts()['count']===0,'Gerçek boş katalog kabul edilir');
$duplicate=new TrendyolClient('seller','x','x',false,static function($m,$p,$q) use($transport):array {$res=$transport($m,$p,$q);if($q['page']==='1')$res['body']['content'][0]=content(0,false);return $res;});
check(!$duplicate->getProducts()['ok'],'Tekrarlanan içerik reddedilir');
$missingStock=new TrendyolClient('seller','x','x',false,static fn($m,$p)=>response([content(0,false)],1,1));
check(!$missingStock->getProducts()['ok'],'Eksik stok alanı sıfıra çevrilmez');
$tokenCalls=0;
$tokenTransport=static function($m,$p,$q) use(&$tokenCalls):array {
    $page=isset($q['page'])?(int)$q['page']:100;
    if($page===100){check(($q['nextPageToken']??'')==='opaque-100' && !isset($q['page']),'10.000 içerik sonrası opaque token ile devam edilir');$tokenCalls++;}
    $list=[];for($i=$page*100;$i<min(10001,($page+1)*100);$i++)$list[]=content($i,str_ends_with($p,'inventory-and-price'));
    $res=response($list,101,10001,$page);$res['body']['nextPageToken']='opaque-'.($page+1);return $res;
};
$tokens=new TrendyolClient('seller','x','x',false,$tokenTransport);
check($tokens->getProducts()['count']===10001 && $tokenCalls===2,'Katalog ve stok için token sayfaları eksiksiz birleşir');
$noToken=new TrendyolClient('seller','x','x',false,static function($m,$p,$q) use($tokenTransport):array {$r=$tokenTransport($m,$p,$q);unset($r['body']['nextPageToken']);return $r;});
check(!$noToken->getProducts()['ok'],'10.000 sonrası eksik token hatası görünür');
$orderCalls=[];
$orderTransport=static function($m,$p,$q) use(&$orderCalls):array {
    $orderCalls[]=[$p,$q];$page=(int)($q['nextCursor']??0);$list=[];
    for($i=$page*200;$i<min(2001,($page+1)*200);$i++)$list[]=['shipmentPackageId'=>'PKG'.$i,'orderNumber'=>'O'.$i,'orderDate'=>1762253333685,'packageTotalPrice'=>180,'lines'=>[['barcode'=>'REAL-BC','stockCode'=>'SKU','quantity'=>2,'lineUnitPrice'=>90]]];
    return ['http'=>200,'body'=>['content'=>$list,'hasMore'=>$page<10,'nextCursor'=>(string)($page+1)]];
};
$orders=new TrendyolClient('seller','x','x',false,$orderTransport);
$r=$orders->getOrders('Delivered','2026-11-01','2026-11-02');
check($r['ok'] && count($r['orders'])===2001 && count($orderCalls)===11,'Stream cursor ile eski 10 sayfa sınırı aşılır');
check($r['orders'][0]['lines'][0]['barcode']==='REAL-BC' && $r['orders'][0]['lines'][0]['price']===90.0 && $r['orders'][0]['totalPrice']===180.0,'Resmî barkod ve net paket/birim fiyatları kullanılır');
check($r['orders'][0]['orderDate']===1762242533685,'Belgede GMT+3 olarak tanımlı sipariş tarihi UTC epoch değerine çevrilir');
check($orderCalls[0][1]['lastModifiedStartDate']===$orderCalls[1][1]['lastModifiedStartDate'] && $orderCalls[1][1]['packageItemStatuses']==='Delivered' && !isset($orderCalls[1][1]['page']) && str_ends_with($orderCalls[0][0],'/orders/stream'),'Cursor boyunca filtreler sabit ve resmî stream yolu kullanılır');
check(!$orders->getOrders('','2026-11-01','2026-11-02',10)['ok'],'Siparişte açık limit aşımı hata verir');
check(!$orders->getOrders('','bad-date','2026-11-02')['ok'],'Geçersiz tarih reddedilir');
$noCursor=new TrendyolClient('seller','x','x',false,static function($m,$p,$q) use($orderTransport):array {$r=$orderTransport($m,$p,$q);unset($r['body']['nextCursor']);return $r;});
check(!$noCursor->getOrders('','2026-11-01','2026-11-02')['ok'],'Eksik sipariş cursorı reddedilir');
$windowCalls=[];$windows=new TrendyolClient('seller','x','x',false,static function($m,$p,$q) use(&$windowCalls):array {$windowCalls[]=$q;return ['http'=>200,'body'=>['content'=>[],'hasMore'=>false]];});
check($windows->getOrders('','2026-09-01','2026-11-01')['ok'] && count($windowCalls)===5 && max(array_map(fn($q)=>(int)$q['lastModifiedEndDate']-(int)$q['lastModifiedStartDate'],$windowCalls))<14*86400000,'Uzun sipariş aralığı en çok 14 günlük pencerelere ayrılır');
$finance=new TrendyolClient('seller','x','x',false,static function($m,$p,$q):array {$page=(int)$q['page'];return response([['id'=>'FIN'.$page,'transactionType'=>'Satış','barcode'=>'A','commissionRate'=>10,'transactionDate'=>1700000000000]],2,2,$page);});
$r=$finance->getSettlements('Sale','2026-11-01','2026-11-02');
check($r['ok'] && count($r['settlements'])===2 && $r['settlements'][0]['providerId']==='FIN0' && $r['settlements'][0]['transactionType']==='Sale' && $r['settlements'][0]['providerTransactionType']==='Satış','Türkçe finans türü korunur; istek türü ve sağlayıcı ID ile normalize edilir');
check(!$finance->getSettlements('Sale','2026-11-01','2026-11-02',1)['ok'],'Finans limit aşımı eksik başarı sayılmaz');
$noId=new TrendyolClient('seller','x','x',false,static fn()=>response([['transactionType'=>'Sale']],1,1));
check(!$noId->getSettlements('Sale')['ok'],'Kimliksiz finans işlemi reddedilir');
$badPage=new TrendyolClient('seller','x','x',false,static fn($m,$p,$q)=>$q['page']==='0'?response([['id'=>'F0']],2,2):response([],2,2,1));
check(!$badPage->getSettlements('DeliveryFee')['ok'],'Eksik finans sayfası hata verir');
$repeated=new TrendyolClient('seller','x','x',false,static fn($m,$p,$q)=>response([['id'=>'SAME']],2,2,(int)$q['page']));
check(!$repeated->getSettlements('Return')['ok'],'Tekrarlanan finans işlemi reddedilir');
$missingRate=new TrendyolClient('seller','x','x',false,static fn()=>response([['id'=>'F','transactionType'=>'Satış']],1,1));
check(!$missingRate->getSettlements('Sale')['ok'],'Eksik satış komisyonu yüzde sıfır sayılmaz');
$psfCalls=[];
$psf=new TrendyolClient('seller','x','x',false,static function($m,$p,$q) use(&$psfCalls):array {$psfCalls[]=$q;$page=(int)$q['page'];return response([['id'=>'PSF'.$page,'transactionType'=>'DeductionInvoices','transactionDate'=>1700000000000,'debt'=>13.19,'credit'=>0]],2,2,$page);});
$r=$psf->getPlatformServiceFees('2026-11-01','2026-11-02');
check($r['ok'] && count($r['rows'])===2,'Platform hizmet bedeli tüm sayfaları eksiksiz birleşir');
check($psfCalls[0]['transactionType']==='DeductionInvoices' && $psfCalls[0]['transactionSubType']==='PlatformServiceFee' && isset($psfCalls[0]['startDate']) && isset($psfCalls[0]['endDate']),'Hizmet bedeli resmî DeductionInvoices+PlatformServiceFee ve tarih filtresiyle çekilir');
$psfNoId=new TrendyolClient('seller','x','x',false,static fn()=>response([['debt'=>1,'credit'=>0]],1,1));
check(!$psfNoId->getPlatformServiceFees()['ok'],'Kimliksiz hizmet bedeli kaydı reddedilir');
$psfRepeat=new TrendyolClient('seller','x','x',false,static fn($m,$p,$q)=>response([['id'=>'SAME','debt'=>1,'credit'=>0]],2,2,(int)$q['page']));
check(!$psfRepeat->getPlatformServiceFees()['ok'],'Tekrarlanan hizmet bedeli işlemi reddedilir');
$psfBad=new TrendyolClient('seller','x','x',false,static fn()=>response([['id'=>'F','debt'=>'x','credit'=>0]],1,1));
check(!$psfBad->getPlatformServiceFees()['ok'],'Geçersiz hizmet bedeli tutarı reddedilir');
$psfEmpty=new TrendyolClient('seller','x','x',false,static fn()=>response([],0,0));
check($psfEmpty->getPlatformServiceFees()['ok'] && count($psfEmpty->getPlatformServiceFees()['rows'])===0,'Gerçek boş hizmet bedeli sonucu kabul edilir');
$psfBadPage2=new TrendyolClient('seller','x','x',false,static fn($m,$p,$q)=>($q['page']??'0')==='0'?response([['id'=>'P0','debt'=>1,'credit'=>0]],2,2):['http'=>500]);
check(!$psfBadPage2->getPlatformServiceFees()['ok'],'Hizmet bedeli ikinci sayfası başarısızsa eksik başarı sayılmaz');
$batch=new TrendyolClient('seller','x','x',false,static function($m,$p,$q,$body):array {
    if($m==='POST'){check(!isset(json_decode($body,true)['items'][0]['quantity']),'Fiyat isteği stok içermek zorunda değildir');return ['http'=>200,'body'=>['batchRequestId'=>'BATCH']];}
    return ['http'=>200,'body'=>['batchRequestId'=>'BATCH','items'=>[['requestItem'=>['barcode'=>'A'],'status'=>'SUCCESS','failureReasons'=>[]]]]];
});
check($batch->updatePriceAndInventory([['barcode'=>'A','salePrice'=>100,'listPrice'=>150]])['pending'],'Kabul edilen fiyat isteği beklemede olarak döner');
check($batch->getBatchRequestResult('BATCH','A')['success'],'Üst düzey status olmadan ürün SUCCESS sonucu doğrulanır');
check(!$batch->getBatchRequestResult('BATCH','OTHER')['finished'],'Başka barkodun başarısı uygulanmaz');
check(!$batch->updatePriceAndInventory([['barcode'=>'A','quantity'=>20001]])['ok'],'Resmî 20.000 stok üst sınırı uygulanır');
check(!$batch->updatePriceAndInventory(array_fill(0,1001,['barcode'=>'A','quantity'=>1]))['ok'],'1000 ürün üst sınırı uygulanır');
$uncertain=new TrendyolClient('seller','x','x',false,static fn()=>['http'=>200,'body'=>[]]);
check($uncertain->updatePriceAndInventory([['barcode'=>'A','quantity'=>0]])['uncertain'],'Batch ID eksikse uzak yazma sonucu belirsiz kalır');
echo $failed?"$failed test başarısız\n":"TÜM TESTLER GEÇTİ\n";exit($failed?1:0);
