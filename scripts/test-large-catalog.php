<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-large-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$start=microtime(true);$n=20000;$db->beginTransaction();
$ins=$db->prepare("INSERT INTO products(user_id,marketplace,name,barcode,category,rate,cost,price,cargo,quantity) VALUES(?,?,?,?,?,?,?,?,?,?)");
for($i=1;$i<=$n;$i++)$ins->execute([1,'trendyol','Synthetic '.$i,sprintf('%08d',$i),'Ev',19,100,300,25,7]);
$ins->execute([2,'trendyol','Other','00000001','Ev',19,777,999,25,4]);$db->commit();
$rows=(static function()use($n){for($i=1;$i<=$n;$i++)yield [sprintf('%08d',$i),'125,50','20%','9999','0'];})();
$headers=['Barkod','Ürün Maliyeti ( KDV Dahil)','Maliyet KDV Oranı','Trendyol Satış Fiyatı','Stok'];
$importStart=microtime(true);$r=catalog_cost_import($db,1,$headers,$rows);$importTime=microtime(true)-$importStart;
$t=['20k import'=>$r['updated']===$n,'all cost values'=>(int)$db->query("SELECT COUNT(*) FROM products WHERE user_id=1 AND cost=125.5 AND cost_vat=20 AND price=300 AND quantity=7")->fetchColumn()===$n,
 'other tenant untouched'=>(float)$db->query("SELECT cost FROM products WHERE user_id=2")->fetchColumn()===777.0];
try{catalog_cost_import($db,1,$headers,[['00000001','99','20','',''],['00000002','bad','20','','']]);$t['rollback']=false;}catch(InvalidArgumentException $e){$t['rollback']=(float)$db->query("SELECT cost FROM products WHERE user_id=1 AND barcode='00000001'")->fetchColumn()===125.5;}
try{catalog_cost_import($db,1,$headers,[['00000001','99','20','',''],['00000001','98','20','','']]);$t['duplicate rollback']=false;}catch(InvalidArgumentException $e){$t['duplicate rollback']=true;}
$q=$db->query("SELECT * FROM products WHERE user_id=1 AND marketplace='trendyol'");$iter=(static function()use($q){while($p=$q->fetch())yield $p;})();
$a=alert_catalog_summary($iter,[],time());$t['20k alert count']=$a['products']===$n&&$a['evaluated']===$n;
$t['schema version']=(int)$db->query('PRAGMA user_version')->fetchColumn()>=1;migrate();
$t['repeat migration preserves data']=(int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn()===$n+1;
foreach($t as $k=>$ok)echo ($ok?'PASS':'FAIL')." $k\n";
printf("Synthetic catalog: %d; import %.3f s; total %.3f s; PHP peak %.1f MiB\n",$n,$importTime,microtime(true)-$start,memory_get_peak_usage(true)/1048576);
exit(in_array(false,$t,true)?1:0);
