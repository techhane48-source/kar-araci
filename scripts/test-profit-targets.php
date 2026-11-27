<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-target-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/analysis_import.php';
$db=db();$db->prepare("INSERT INTO products(user_id,marketplace,name,barcode,category,rate,cost,price,cargo,service_fee) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([1,'trendyol','Perde','001','Ev',19,100,200,20,13.19]);
$p=$db->query("SELECT * FROM products WHERE user_id=1")->fetch();$tests=[];
target_rule_save($db,1,'store','','margin',10);target_rule_save($db,1,'category','Ev','roi',25);target_rule_save($db,1,'product','001','profit',50);
$rules=target_rules($db,1);$rule=target_for_product($rules,$p);
$tests['product priority']=$rule['kind']==='profit'&&(float)$rule['value']===50.0;
$tests['category priority']=target_for_product($rules,array_replace($p,['barcode'=>'002']))['kind']==='roi';
$tests['store fallback']=target_for_product($rules,array_replace($p,['barcode'=>'002','category'=>'Other']))['kind']==='margin';
$tests['tenant isolation']=target_rules($db,2)===[];
$tests['market isolation']=target_for_product($rules,array_replace($p,['marketplace'=>'n11']))===null;
$s=target_suggestion($p,$rule);
$tests['minimum cent target']=$s['price']!==null&&product_profit($p,$s['price'])['profit']>=50-1e-8&&product_profit($p,$s['price']-.01)['profit']<50;
$tests['no price writes']=(float)$db->query("SELECT price FROM products WHERE user_id=1")->fetchColumn()===200.0;
$schema=analysis_schema(['Barkod','Fiyat'],'bulk');
$r=analysis_row($schema,['001',(string)$s['price']],['001'=>$p],'bulk',$rules);
$tests['bulk target pass']=$r['meets_target']===true;
$r=analysis_row($schema,['001','1'],['001'=>$p],'bulk',$rules);
$tests['below target']=$r['meets_target']===false;
$schema=analysis_schema(['Barkod','1. Fiyat','1. Komisyon'],'kampanya');
$r=analysis_row($schema,['001','1000','19'],['001'=>$p],'kampanya',$rules);
$tests['unverified campaign not qualified']=$r['meets_target']===null;
try{target_rule_value('margin','100');$tests['invalid margin']=false;}catch(InvalidArgumentException $e){$tests['invalid margin']=true;}
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
