<?php
declare(strict_types=1);
$path=tempnam(sys_get_temp_dir(),'kf-price-');putenv('KARFIYAT_DB='.$path);
require_once __DIR__.'/../includes/functions.php';
register_shutdown_function(static function() use($path):void {foreach([$path,$path.'-wal',$path.'-shm'] as $f)if(is_file($f))unlink($f);});
$failed=0;
function check(bool $ok,string $label):void {global $failed;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failed++;}
function value(string $sql):mixed {return db()->query($sql)->fetchColumn();}
final class PriceFixture {
    public array $payload=[];public int $writes=0,$reads=0;public bool $throw=false;public string $state='pending';
    public function updatePriceAndInventory(array $items):array {$this->writes++;$this->payload=$items;if($this->throw)throw new RuntimeException('Fixture timeout');return ['ok'=>true,'batchRequestId'=>'B'.$this->writes];}
    public function getBatchRequestResult(string $batch,string $barcode):array {$this->reads++;return ['ok'=>true,'finished'=>$this->state!=='pending','success'=>$this->state==='confirmed','error'=>$this->state==='failed'?'Fixture rejection':null];}
    public function getProducts(...$args):array {return ['ok'=>true,'complete'=>true,'products'=>[['barcode'=>'A','salePrice'=>170,'listPrice'=>200,'quantity'=>6,'commissionRate'=>0,'vatRate'=>0]]];}
}
foreach([1,2] as $id){
 db()->prepare('INSERT INTO users(id,email,password_hash) VALUES(?,?,?)')->execute([$id,"price$id@example.test",'unused']);
 db()->prepare("INSERT INTO integrations(user_id,marketplace,seller_id) VALUES(?,'trendyol',?)")->execute([$id,'S'.$id]);
 db()->prepare("INSERT INTO products(user_id,marketplace,barcode,name,category,rate,price,list_price,quantity) VALUES(?,'trendyol','A','Fixture','Giyim',10,100,200,7)")->execute([$id]);
}
$c=new PriceFixture;$r=submit_price_update(1,'trendyol','A',120,$c);
check($r['ok'] && $r['pending'] && value('SELECT status FROM price_updates')==='pending' && (float)value('SELECT price FROM products WHERE user_id=1')===100.0,'Kabul edilen batch kalıcı bekler; yerel fiyat değişmez');
check(!isset($c->payload[0]['quantity']) && $c->payload[0]['listPrice']===200.0,'Eski stok gönderilmez; liste fiyatı korunur');
check(!submit_price_update(1,'trendyol','A',130,$c)['ok'] && $c->writes===1,'Bekleyen barkoda ikinci yazma gönderilmez');
$r=process_price_updates(1,'trendyol',$c);
check($r['pending']===1 && (float)value('SELECT price FROM products WHERE user_id=1')===100.0,'Bekleyen uzak sonuç fiyatı değiştirmez');
$c->state='confirmed';db()->exec('UPDATE price_updates SET checked_at=NULL');$r=process_price_updates(1,'trendyol',$c);
check($r['confirmed']===1 && (float)value('SELECT price FROM products WHERE user_id=1')===120.0 && (int)value('SELECT quantity FROM products WHERE user_id=1')===7,'Açık SUCCESS yalnızca fiyatı uygular; stok korunur');
check((float)value('SELECT price FROM products WHERE user_id=2')===100.0,'Başka kullanıcının aynı barkodu etkilenmez');
$reads=$c->reads;process_price_updates(1,'trendyol',$c);check($c->reads===$reads,'Tamamlanan batch yeniden işlenmez');
check(!submit_price_update(1,'trendyol','A',120,$c)['ok'] && $c->writes===1,'Aynı içerik 15 dakika içinde yeniden gönderilmez');
check(submit_price_update(1,'trendyol','A',130,$c)['ok'],'Tamamlanan işlemden sonra farklı fiyat gönderilebilir');
$c->state='failed';$r=process_price_updates(1,'trendyol',$c);
check($r['failed']===1 && (float)value('SELECT price FROM products WHERE user_id=1')===120.0,'Reddedilen işlem yerel fiyatı değiştirmez');
$c->throw=true;$r=submit_price_update(1,'trendyol','A',140,$c);
check(!$r['ok'] && value('SELECT status FROM price_updates ORDER BY id DESC LIMIT 1')==='unknown','Ağ hatasında önceden kaydedilmiş gönderim belirsiz tutulur');
$writes=$c->writes;check(!submit_price_update(1,'trendyol','A',150,$c)['ok'] && $c->writes===$writes,'Belirsiz yazma otomatik tekrarlanmaz');
$c->throw=false;check(sync_products(1,'trendyol',$c)['ok'] && value('SELECT status FROM price_updates ORDER BY id DESC LIMIT 1')==='reconciled' && (float)value('SELECT price FROM products WHERE user_id=1')===170.0,'Tam ürün senkronu mağazanın gözlenen fiyatıyla belirsizliği giderir');
submit_price_update(1,'trendyol','A',180,$c);db()->exec("UPDATE price_updates SET created_at=".(time()-14401)." WHERE status='pending'");$reads=$c->reads;
$r=process_price_updates(1,'trendyol',$c);
check($r['failed']===1 && $c->reads===$reads && value('SELECT status FROM price_updates ORDER BY id DESC LIMIT 1')==='unknown','Dört saatten eski sonuç sorgulanmaz; belirsiz olarak gösterilir');
sync_products(1,'trendyol',$c);submit_price_update(1,'trendyol','A',190,$c);db()->exec('UPDATE integrations SET revision=revision+1 WHERE user_id=1');$c->state='confirmed';$reads=$c->reads;
$r=process_price_updates(1,'trendyol',$c);
check($r['failed']===1 && $c->reads===$reads && (float)value('SELECT price FROM products WHERE user_id=1')===170.0 && value('SELECT status FROM price_updates ORDER BY id DESC LIMIT 1')==='stale','Bağlantı değişmişse eski batch fiyatı uygulanmaz');
check(!submit_price_update(1,'trendyol','MISSING',100,$c)['ok'],'Kullanıcıya ait olmayan ürün gönderilemez');
echo $failed?"$failed test başarısız\n":"TÜM TESTLER GEÇTİ\n";exit($failed?1:0);
