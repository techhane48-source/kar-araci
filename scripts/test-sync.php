<?php
declare(strict_types=1);
$path=tempnam(sys_get_temp_dir(),'kf-sync-');putenv('KARFIYAT_DB='.$path);
require_once __DIR__.'/../includes/functions.php';
register_shutdown_function(static function() use($path):void {foreach([$path,$path.'-wal',$path.'-shm'] as $file)if(is_file($file))unlink($file);});
$failed=0;
function check(bool $ok,string $label):void {global $failed;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failed++;}
function scalar(string $sql):mixed {return db()->query($sql)->fetchColumn();}
function integration(int $id=1):array {$q=db()->prepare("SELECT * FROM integrations WHERE user_id=? AND marketplace='trendyol'");$q->execute([$id]);return $q->fetch();}
final class FixtureClient {
    public array $products=[],$orders=[],$settlements=[],$serviceFees=[],$calls=[];
    public ?string $failure=null;
    public $beforeProducts=null,$beforeServiceFees=null;
    public function getProducts(...$args):array {
        $this->calls[]=['products',$args];
        if($this->beforeProducts)($this->beforeProducts)();
        return $this->failure==='products'?['ok'=>false,'error'=>'Fixture HTTP 429']:['ok'=>true,'complete'=>true,'products'=>$this->products];
    }
    public function getOrders(...$args):array {$this->calls[]=['orders',$args];return ['ok'=>true,'complete'=>true,'orders'=>$this->orders];}
    public function getSettlements(string $type,...$args):array {
        $this->calls[]=['settlements',$type,$args];
        return $this->failure===$type?['ok'=>false,'error'=>'Fixture page unavailable']:['ok'=>true,'complete'=>true,'settlements'=>$this->settlements[$type]??[]];
    }
    public function getPlatformServiceFees(...$args):array {
        $this->calls[]=['service_fees',$args];
        if($this->beforeServiceFees)($this->beforeServiceFees)();
        return $this->failure==='service_fees'?['ok'=>false,'error'=>'Fixture page unavailable']:['ok'=>true,'complete'=>true,'rows'=>$this->serviceFees];
    }
}
foreach([1,2] as $id) {
    db()->prepare('INSERT INTO users(id,email,password_hash) VALUES(?,?,?)')->execute([$id,"sync$id@example.test",'unused']);
    db()->prepare("INSERT INTO integrations(user_id,marketplace,seller_id,last_sync) VALUES(?,'trendyol',?,?)")->execute([$id,"seller$id",gmdate('Y-m-d H:i:s',time()-3600)]);
    db()->prepare("INSERT INTO products(user_id,name,barcode,marketplace,category,rate,cost,price,cargo,kdv,extra_fee,service_fee,stopaj,stopaj_mode,return_rate) VALUES(?,'Original','A','trendyol','Local',17,200,300,40,0,4,0,2,'manual',5)")->execute([$id]);
}
$client=new FixtureClient;
$client->products=[['barcode'=>'A','title'=>'API A','category'=>'Giyim','salePrice'=>0,'listPrice'=>999,'quantity'=>0,'brand'=>'Brand'],['barcode'=>'B','title'=>'API B','category'=>'Giyim','salePrice'=>400,'quantity'=>7,'vatRate'=>0,'commissionRate'=>0]];
$before=integration();$r=sync_products(1,'trendyol',$client);
check($r['ok'] && $r['inserted']===1 && $r['updated']===1,'Yeni ürün eklenir ve mevcut ürün güncellenir');
$a=db()->query("SELECT * FROM products WHERE user_id=1 AND barcode='A'")->fetch();
check((float)$a['price']===0.0 && (int)$a['quantity']===0 && $a['name']==='API A','Sıfır fiyat/stok korunur; liste fiyatına geri dönülmez');
check((float)$a['cost']===200.0 && (float)$a['kdv']===0.0 && (float)$a['service_fee']===0.0 && (float)$a['rate']===17.0 && $a['stopaj_mode']==='manual' && (float)$a['stopaj']===2.0 && (float)$a['extra_fee']===4.0 && (float)$a['return_rate']===5.0 && (float)$a['cargo']===40.0,'Satıcının maliyet, KDV, komisyon ve gider ayarları korunur');
check((float)scalar("SELECT price FROM products WHERE user_id=2 AND barcode='A'")===300.0,'Başka mağaza/kullanıcı ürünü etkilenmez');
check($client->calls[0][1][2]===0,'V2 stok doğruluğu için tam görünüm alınır');
check((float)scalar("SELECT kdv FROM products WHERE user_id=1 AND barcode='B'")===0.0 && (float)scalar("SELECT rate FROM products WHERE user_id=1 AND barcode='B'")===0.0,'Yeni ürün V2 varyantının sıfır KDV ve komisyonuyla başlar');
$r=sync_products(1,'trendyol',$client);
check($r['ok'] && (int)scalar('SELECT COUNT(*) FROM products WHERE user_id=1')===2,'Tekrar ürün senkronu mükerrer ürün oluşturmaz');
$cursor=integration()['last_sync'];$client->failure='products';
check(!sync_products(1,'trendyol',$client)['ok'] && integration()['last_sync']===$cursor,'API hatasında imleç ilerlemez');
check(integration()['last_sync_error']!==null,'Başarısızlık durumu kaydedilir');
$client->failure=null;$client->products[0]['salePrice']=123;$client->products[1]['quantity']=-1;
check(!sync_products(1,'trendyol',$client)['ok'] && (float)scalar("SELECT price FROM products WHERE user_id=1 AND barcode='A'")===0.0,'Geçersiz ikinci satır ilk güncellemeyi de geri alır');
$client->products[1]['quantity']=7;
$nested=null;$client->beforeProducts=static function() use(&$nested,$client):void {$nested=sync_products(1,'trendyol',$client);};
check(sync_products(1,'trendyol',$client)['ok'] && !empty($nested['busy']),'Aynı mağazanın eşzamanlı işi engellenir');
$client->beforeProducts=static function():void {db()->exec("UPDATE integrations SET revision=revision+1 WHERE user_id=1");};
$client->products[0]['salePrice']=456;
check(!sync_products(1,'trendyol',$client)['ok'] && (float)scalar("SELECT price FROM products WHERE user_id=1 AND barcode='A'")===123.0,'Bağlantı değişirse eski bağlantının sonucu kaydedilmez');
$client->beforeProducts=static function():void {db()->exec('UPDATE sync_locks SET expires_at=0 WHERE user_id=1');};
check(!sync_products(1,'trendyol',$client)['ok'],'Süresi dolmuş kilitle veri yazılamaz');
$client->beforeProducts=null;
check((int)scalar('SELECT COUNT(*) FROM sync_locks')===0,'Başarı/hata sonrasında kilit bırakılır');
$timestamp=(time()-60)*1000;
$client->orders=[['packageNumber'=>'PKG','orderNumber'=>'ORDER','status'=>'Created','customerName'=>'Fixture','totalPrice'=>500,'orderDate'=>(string)$timestamp,'lines'=>[['barcode'=>'A','quantity'=>1,'price'=>500]]]];
check(sync_orders(1,'trendyol',7,$client)['ok'] && (int)scalar("SELECT order_date FROM orders WHERE user_id=1")===intdiv($timestamp,1000),'Metin milisaniye tarihi doğru kaydedilir');
$client->orders[0]['status']='Cancelled';$client->orders[0]['customerName']='Updated';
check(sync_orders(1,'trendyol',7,$client)['ok'] && (int)scalar('SELECT COUNT(*) FROM orders')===1 && scalar('SELECT status FROM orders')==='Cancelled','Aynı paket tekrar çekilince güncellenir');
$last=integration()['last_orders_sync'];$client->orders[]=['packageNumber'=>'','orderDate'=>$timestamp,'lines'=>[],'totalPrice'=>100];
check(!sync_orders(1,'trendyol',7,$client)['ok'] && integration()['last_orders_sync']===$last,'Geçersiz pakette sipariş işi atomik geri alınır');
$client->orders=[];
$base=['providerId'=>'FIN-1','barcode'=>'A','transactionType'=>'Sale','commissionRate'=>0,'commissionAmount'=>0,'sellerRevenue'=>100,'debt'=>0,'credit'=>100,'orderNumber'=>'ORDER','shipmentPackageId'=>'PKG','paymentPeriod'=>1,'transactionDate'=>$timestamp,'orderDate'=>$timestamp];
$client->settlements=['Sale'=>[$base]];
for($i=0;$i<2;$i++) db()->prepare("INSERT INTO settlements(user_id,barcode,transaction_type,credit,transaction_date) VALUES(1,'A','Sale',100,?)")->execute([intdiv($timestamp,1000)]);
db()->prepare("INSERT INTO settlements(user_id,barcode,transaction_type,credit,transaction_date) VALUES(2,'A','Sale',100,?)")->execute([intdiv($timestamp,1000)]);
$client->failure='Return';
check(!sync_settlements(1,'trendyol',30,$client)['ok'] && (int)scalar('SELECT COUNT(*) FROM settlements WHERE user_id=1')===2 && (int)scalar('SELECT COUNT(*) FROM settlement_legacy_archive')===0,'Bir finans dilimi başarısızsa eski kayıtlar arşivlenmez ve kısmi veri yazılmaz');
$client->failure=null;$r=sync_settlements(1,'trendyol',30,$client);
check($r['ok'] && $r['records']===1 && $r['archived']===2,'Tekrarlanan dönem kayıtları işlem ID ile birleşir; eski kimliksiz kayıtlar arşivlenir');
check((int)scalar('SELECT COUNT(*) FROM settlement_legacy_archive')===2 && (int)scalar('SELECT COUNT(*) FROM settlements WHERE user_id=1')===1,'Eski kayıtlar geri alınabilir arşivde korunur');
check((int)scalar('SELECT COUNT(*) FROM settlements WHERE user_id=2')===1,'Finans yenilemesi diğer kullanıcıya dokunmaz');
check((float)scalar("SELECT rate FROM products WHERE user_id=1 AND barcode='A'")===0.0,'Gerçek %0 komisyon da güncellenebilir');
$r=sync_settlements(1,'trendyol',30,$client);
check($r['ok'] && (int)scalar('SELECT COUNT(*) FROM settlements WHERE user_id=1')===1 && (float)scalar('SELECT SUM(credit) FROM settlements WHERE user_id=1')===100.0,'Tekrar finans senkronu tutarları ikiye katlamaz');
$client->settlements['Sale'][0]['credit']=75;
check(sync_settlements(1,'trendyol',30,$client)['ok'] && (float)scalar('SELECT credit FROM settlements WHERE user_id=1')===75.0,'Aynı işlemde düzeltme gelirse kayıt güncellenir');
$last=integration()['last_settlements_sync'];$client->settlements['Sale'][0]['providerId']='';
check(!sync_settlements(1,'trendyol',30,$client)['ok'] && integration()['last_settlements_sync']===$last,'Kimliksiz finans işlemi reddedilir; başarı zamanı korunur');
$client->settlements['Sale'][0]=$base;
db()->prepare("INSERT INTO settlements(user_id,marketplace,provider_id,barcode,transaction_type,commission_rate,transaction_date) VALUES(1,'trendyol','NEWER','A','Sale',9,?)")->execute([time()-10]);
check(sync_settlements(1,'trendyol',30,$client)['ok'] && (float)scalar("SELECT rate FROM products WHERE user_id=1 AND barcode='A'")===9.0,'Eski dönem çekimi yeni komisyon oranının üzerine yazmaz');
db()->prepare("INSERT INTO settlements(user_id,barcode,transaction_type,credit,transaction_date) VALUES(1,'A','Sale',12,?)")->execute([time()-50]);
$archiveBefore=(int)scalar('SELECT COUNT(*) FROM settlement_legacy_archive');
$activeBefore=(int)scalar('SELECT COUNT(*) FROM settlements WHERE user_id=1');
$client->settlements['Sale'][0]['credit']='invalid';
check(!sync_settlements(1,'trendyol',30,$client)['ok'] && (float)scalar("SELECT credit FROM settlements WHERE provider_id='FIN-1'")===100.0,'Hatalı finans tutarı transaction’ı geri alır');
check((int)scalar('SELECT COUNT(*) FROM settlement_legacy_archive')===$archiveBefore && (int)scalar('SELECT COUNT(*) FROM settlements WHERE user_id=1')===$activeBefore,'Sonradan hata olursa arşiv taşıması da geri alınır');
check(sync_orders(1,'trendyol',7,$client)['ok'] && integration()['last_sync_error_scope']==='settlements','Başka işin başarısı finans hatasını gizlemez');
$badStock=new FixtureClient;
$badStock->products=[['barcode'=>'A','salePrice'=>999,'quantity'=>1e30]];
check(!sync_products(1,'trendyol',$badStock)['ok'] && (float)scalar("SELECT price FROM products WHERE user_id=1 AND barcode='A'")===123.0,'Taşma oluşturabilecek stok reddedilir; fiyat değiştirilmez');
$client->beforeProducts=static function():void { db()->exec("UPDATE sync_locks SET token='replacement',expires_at=".(time()+1800)." WHERE user_id=1"); };
check(!sync_products(1,'trendyol',$client)['ok'] && scalar('SELECT token FROM sync_locks WHERE user_id=1')==='replacement','Eski iş yeni sahibin kilidini silemez');
db()->exec('DELETE FROM sync_locks WHERE user_id=1');
$client->beforeProducts=null;
$psfBase=['id'=>'PSF-1','transactionType'=>'DeductionInvoices','transactionDate'=>$timestamp,'receiptId'=>123,'commissionInvoiceSerialNumber'=>'INV-001','description'=>'Platform hizmet bedeli','debt'=>13.19,'credit'=>0,'orderNumber'=>'ORDER','shipmentPackageId'=>77,'barcode'=>'A','paymentOrderId'=>321];
$client->serviceFees=[$psfBase];
$last=integration()['last_service_fees_sync'];
check(sync_platform_service_fees(1,'trendyol',30,$client)['ok'] && (int)scalar('SELECT COUNT(*) FROM platform_service_fees WHERE user_id=1')===1,'Platform hizmet bedeli kaydedilir');
check((float)scalar("SELECT debt FROM platform_service_fees WHERE provider_id='PSF-1'")===13.19 && (float)scalar("SELECT credit FROM platform_service_fees WHERE provider_id='PSF-1'")===0.0,'Hizmet bedeli borç/alacak doğru saklanır');
check(scalar("SELECT invoice_serial FROM platform_service_fees WHERE provider_id='PSF-1'")==='INV-001' && scalar("SELECT order_number FROM platform_service_fees WHERE provider_id='PSF-1'")==='ORDER','Fatura seri no ve sipariş referansı korunur');
check((int)scalar('SELECT COUNT(*) FROM platform_service_fees WHERE user_id=2')===0,'Başka kullanıcıya hizmet bedeli yazılmaz');
check((float)scalar("SELECT service_fee FROM products WHERE user_id=1 AND barcode='A'")===0.0 && (float)scalar("SELECT rate FROM products WHERE user_id=1 AND barcode='A'")===9.0,'Hizmet bedeli senkronu kâr tahminini/komisyonu değiştirmez');
check(sync_platform_service_fees(1,'trendyol',30,$client)['ok'] && (int)scalar('SELECT COUNT(*) FROM platform_service_fees WHERE user_id=1')===1,'Aynı dönem tekrar çekilince kayıt çoğalmaz');
$client->serviceFees[0]['debt']=20.0;
check(sync_platform_service_fees(1,'trendyol',30,$client)['ok'] && (float)scalar("SELECT debt FROM platform_service_fees WHERE provider_id='PSF-1'")===20.0,'Aynı işlemde düzeltme gelirse kayıt güncellenir');
$last=integration()['last_service_fees_sync'];$client->failure='service_fees';
check(!sync_platform_service_fees(1,'trendyol',30,$client)['ok'] && integration()['last_service_fees_sync']===$last,'Hizmet bedeli sorgusu başarısızsa başarı zamanı ilerlemez');
$client->failure=null;$client->serviceFees[0]['id']='';
check(!sync_platform_service_fees(1,'trendyol',30,$client)['ok'] && integration()['last_service_fees_sync']===$last,'Kimliksiz hizmet bedeli reddedilir; başarı zamanı korunur');
// Mağaza/ortam kapsamı: seller1 → seller2 geçişi, aynı işlem ID ezilmez
$client->serviceFees=[['id'=>'SWITCH-1','transactionType'=>'DeductionInvoices','transactionDate'=>$timestamp,'debt'=>1,'credit'=>0]];
check(sync_platform_service_fees(1,'trendyol',30,$client)['ok'],'Mağaza A hizmet bedeli kaydedilir');
check((int)scalar("SELECT COUNT(*) FROM platform_service_fees WHERE provider_id='SWITCH-1' AND seller_id='seller1' AND stage=0")===1,'Kayıt seller1 kapsamında saklanır');
save_integration(1,'trendyol','seller2','k2','s2');
check(integration()['last_service_fees_sync']===null,'Yeniden bağlanınca son hizmet bedeli zamanı sıfırlanır');
$client->serviceFees=[['id'=>'SWITCH-1','transactionType'=>'DeductionInvoices','transactionDate'=>$timestamp,'debt'=>9,'credit'=>0]];
check(sync_platform_service_fees(1,'trendyol',30,$client)['ok'],'Mağaza B hizmet bedeli kaydedilir');
check((int)scalar("SELECT COUNT(*) FROM platform_service_fees WHERE provider_id='SWITCH-1'")===2,'Aynı işlem ID farklı mağazada ezilmez (2 kayıt)');
check((float)scalar("SELECT debt FROM platform_service_fees WHERE provider_id='SWITCH-1' AND seller_id='seller1'")===1.0 && (float)scalar("SELECT debt FROM platform_service_fees WHERE provider_id='SWITCH-1' AND seller_id='seller2'")===9.0,'Mağaza kapsamı borçları ayrı korur');
save_integration(1,'trendyol','seller1','k1','s1');
// Test/canlı ortam: aynı seller_id + aynı provider_id, farklı ortam → farklı tutar, ezilmez
$client->serviceFees=[['id'=>'ENV-X','transactionType'=>'DeductionInvoices','transactionDate'=>$timestamp,'debt'=>1,'credit'=>0]];
check(sync_platform_service_fees(1,'trendyol',30,$client)['ok'],'Canlı ortam (stage 0) hizmet bedeli kaydedilir');
save_integration(1,'trendyol','seller1','k1','s1',true);
check(integration()['last_service_fees_sync']===null && integration()['last_sync_error']===null,'Ortam değişince son başarı çekim ve hata bilgisi yeni ortama taşınmaz');
$client->serviceFees=[['id'=>'ENV-X','transactionType'=>'DeductionInvoices','transactionDate'=>$timestamp,'debt'=>9,'credit'=>0]];
check(sync_platform_service_fees(1,'trendyol',30,$client)['ok'],'Test ortamı (stage 1) hizmet bedeli kaydedilir');
check((int)scalar("SELECT COUNT(*) FROM platform_service_fees WHERE provider_id='ENV-X'")===2,'Test/canlı aynı provider_id birbirini ezmez');
check((float)scalar("SELECT debt FROM platform_service_fees WHERE provider_id='ENV-X' AND stage=0")===1.0 && (float)scalar("SELECT debt FROM platform_service_fees WHERE provider_id='ENV-X' AND stage=1")===9.0,'Ortam kapsamı borçları ayrı korur');
// Çekim sırasında bağlantı değişimi: eski isteğin sonucu yeni bağlantıya yazılmaz (revision kontrolü)
$last=integration()['last_service_fees_sync'];
$client->beforeServiceFees=static function():void {db()->exec('UPDATE integrations SET revision=revision+1 WHERE user_id=1');};
$client->serviceFees=[['id'=>'MID-X','transactionType'=>'DeductionInvoices','transactionDate'=>$timestamp,'debt'=>5,'credit'=>0]];
check(!sync_platform_service_fees(1,'trendyol',30,$client)['ok'],'Çekim sırasında bağlantı değişirse hizmet bedeli yazılmaz');
check((int)scalar("SELECT COUNT(*) FROM platform_service_fees WHERE provider_id='MID-X'")===0,'Değişen bağlantının hizmet bedeli kaydı yazılmaz');
check(integration()['last_service_fees_sync']===$last,'Bağlantı değişiminde son başarı zamanı ilerlemez');
$client->beforeServiceFees=null;
echo $failed?"$failed test başarısız\n":"TÜM TESTLER GEÇTİ\n";exit($failed?1:0);
