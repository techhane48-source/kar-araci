<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-cost-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$db=db();$db->prepare("INSERT INTO products(user_id,marketplace,name,barcode,category,rate,cost,price) VALUES(?,?,?,?,?,?,?,?)")->execute([1,'trendyol','Perde','001','Ev Tekstili',19,999,200]);
$h=['Barkod','Geçerlilik Tarihi','Maliyet','Maliyet KDV Oranı'];
$rows=cost_history_values($h,[['001','2025-01-01','100','10%'],['001','2025-02-01','150','20']]);
$tests=[];$tests['two cost periods']=cost_history_save($db,1,$rows)['inserted']===2;
$tests['repeat idempotent']=cost_history_save($db,1,$rows)['unchanged']===2;
$changed=cost_history_values($h,[['001','2025-01-01','110','10']]);
try{cost_history_save($db,1,$changed);$tests['explicit correction required']=false;}catch(InvalidArgumentException $e){$tests['explicit correction required']=true;}
cost_history_save($db,1,$changed,true);
$map=cost_history_map($db,1,['001'],time());
$tests['latest revision chosen']=(float)cost_history_at($map['001'],cost_effective_day('2025-01-15'))['cost']===110.0;
$tests['later period chosen']=(float)cost_history_at($map['001'],cost_effective_day('2025-02-15'))['cost']===150.0;
$tests['no backdating before earliest']=cost_history_at($map['001'],cost_effective_day('2024-12-31'))===null;
$tests['tenant isolated']=cost_history_map($db,2,['001'],time())===[];
$o=['marketplace'=>'trendyol','order_date'=>cost_effective_day('2025-01-15'),'lines'=>[['barcode'=>'001','cost_snapshot'=>90,'quantity'=>1]]];
$over=cost_history_overlay($db,1,[$o])[0];
$tests['dated overlay keeps original']=$over['lines'][0]['cost_snapshot']===110.0&&$over['lines'][0]['cost_source']==='dated_user'&&$o['lines'][0]['cost_snapshot']===90;
$bad=cost_history_values($h,[['001','2025-03-01','120','10'],['999','2025-03-01','100','20']]);
try{cost_history_save($db,1,$bad);$tests['atomic bad upload']=false;}catch(InvalidArgumentException $e){$tests['atomic bad upload']=(int)$db->query("SELECT COUNT(*) FROM product_cost_history")->fetchColumn()===3;}
$tests['current catalogue unchanged']=(float)$db->query("SELECT cost FROM products WHERE barcode='001'")->fetchColumn()===999.0;
$db->prepare("INSERT INTO products(user_id,marketplace,name,barcode,category,rate,cost,price) VALUES(?,?,?,?,?,?,?,?)")->execute([1,'trendyol','Perde 2','002','Ev Tekstili',19,900,200]);
$group=cost_history_group_values(['001','002'],'2025-04-01','125','10');
$tests['group saves once per selected barcode']=cost_history_save($db,1,$group,false,'group')['inserted']===2;
$tests['group repeat idempotent']=cost_history_save($db,1,$group,false,'group')['unchanged']===2;
$tests['group new cost effective only from given day']=(float)cost_history_at(cost_history_map($db,1,['001'],time())['001'],cost_effective_day('2025-03-31'))['cost']===150.0;
try{cost_history_group_values(['001','001'],'2025-04-01','125','10');$tests['duplicate group rejected']=false;}catch(InvalidArgumentException $e){$tests['duplicate group rejected']=true;}
try{cost_history_group_values(['001'],'2025-04-01','125','');$tests['unknown purchase VAT not saved']=false;}catch(InvalidArgumentException $e){$tests['unknown purchase VAT not saved']=true;}
$before=(int)$db->query("SELECT COUNT(*) FROM product_cost_history")->fetchColumn();
try{cost_history_save($db,1,cost_history_group_values(['001','999'],'2025-05-01','125','10'),false,'group');$tests['group atomic rollback']=false;}catch(InvalidArgumentException $e){$tests['group atomic rollback']=(int)$db->query("SELECT COUNT(*) FROM product_cost_history")->fetchColumn()===$before;}
$today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
$todayRows=cost_history_group_values(['001','002'],$today,'130','10');
$r=cost_history_save($db,1,$todayRows,false,'group',true);
$tests['today updates catalog and history atomically']=$r['catalog_updated']===2&&$r['inserted']===2&&(int)$db->query("SELECT COUNT(*) FROM products WHERE user_id=1 AND cost=130 AND cost_vat=10 AND price=200")->fetchColumn()===2;
$r=cost_history_save($db,1,$todayRows,false,'group',true);
$tests['repeat catalog update idempotent']=$r['catalog_updated']===0&&$r['unchanged']===2;
try{cost_history_save($db,1,cost_history_group_values(['001'],'2025-06-01','95','10'),false,'group',true);$tests['historic date cannot change current catalog']=false;}catch(InvalidArgumentException $e){$tests['historic date cannot change current catalog']=(float)$db->query("SELECT cost FROM products WHERE user_id=1 AND barcode='001'")->fetchColumn()===130.0;}
try{cost_history_save($db,1,cost_history_group_values(['001','999'],$today,'140','10'),true,'group',true);$tests['catalog and history both rolled back']=false;}catch(InvalidArgumentException $e){$tests['catalog and history both rolled back']=(float)$db->query("SELECT cost FROM products WHERE user_id=1 AND barcode='001'")->fetchColumn()===130.0&&(float)cost_history_at(cost_history_map($db,1,['001'],time())['001'],time())['cost']===130.0;}
$db->prepare("INSERT INTO products(user_id,marketplace,name,barcode,category,rate,cost,price) VALUES(?,?,?,?,?,?,?,?)")->execute([1,'trendyol','Synthetic baseline','003','Test',19,80,200]);
$baseline=cost_history_first_sale_values(['003'],'90','10');
$res=cost_history_save($db,1,$baseline,false,'first_sale',true);
$oldOrder=['marketplace'=>'trendyol','order_date'=>cost_effective_day('2021-01-01'),'lines'=>[['barcode'=>'003','quantity'=>1]]];
$tests['first sale baseline covers older imported orders']=cost_history_overlay($db,1,[$oldOrder])[0]['lines'][0]['cost_snapshot']===90.0&&$res['catalog_updated']===1;
$tests['first sale retry idempotent']=cost_history_save($db,1,$baseline,false,'first_sale',true)['unchanged']===1;
try{cost_history_save($db,1,cost_history_first_sale_values(['001'],'90','10'),true,'first_sale',true);$tests['conflicting later history blocked']=false;}catch(InvalidArgumentException $e){$tests['conflicting later history blocked']=true;}
require_once __DIR__.'/../includes/curtain_cost.php';
$tests['curtain width only']=curtain_dimensions('150 x 200')===[150.0,200.0]&&curtain_dimensions('240×140 cm')===[240.0,140.0]&&curtain_dimensions('150x200x300')===null;
$insCurtain=$db->prepare("INSERT INTO products(user_id,marketplace,name,barcode,category,rate,variant_model_key,variant_model_code,variant_size) VALUES(?,?,?,?,?,?,?,?,?)");
$first=0;for($i=0;$i<550;$i++){$insCurtain->execute([1,'trendyol','Synthetic curtain','CUR-'.$i,'Perde',20,'model-a','A',($i%2===0?'150 x 200':'240 x 140')]);if($i===0)$first=(int)$db->lastInsertId();}
$insCurtain->execute([2,'trendyol','Other tenant','CUR-other','Perde',20,'model-a','A','150 x 200']);
$insCurtain->execute([1,'trendyol','Different model','CUR-other','Perde',20,'model-b','B','150 x 200']);
$cp=curtain_cost_preview($db,1,$first,'150','30','10','today');
$tests['550 exact model variants and width costs']=count($cp['rows'])===550&&$cp['total']===550&&$cp['display'][0]['calculated']===270.0&&count(array_filter($cp['rows'],static fn($r)=>$r['cost']===432.0))===275;
$save=cost_history_save($db,1,$cp['rows'],false,'group',true);
$tests['550 costs written atomically by barcode']=$save['inserted']===550&&$save['catalog_updated']===550;
$tests['preview hash changes when cost changed']=curtain_cost_preview($db,1,$first,'150','30','10','today')['hash']!==$cp['hash'];
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
