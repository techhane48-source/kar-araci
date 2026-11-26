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
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
