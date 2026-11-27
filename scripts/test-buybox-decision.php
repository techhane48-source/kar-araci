<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-buybox-'.bin2hex(random_bytes(8));mkdir($dir,0700);define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
$now=time();$p=['marketplace'=>'trendyol','barcode'=>'001','cost'=>100,'price'=>300,'cargo'=>20,'rate'=>19,'kdv'=>20,'service_fee'=>13.19];
$rule=['scope'=>'store','kind'=>'profit','value'=>20];
$b=['barcode'=>'001','buybox_rank'=>2,'buybox_price'=>250,'multiple_sellers'=>1,'checked_at'=>$now];
$tests=[];$x=buybox_decision($p,$b,$rule,$now);
$tests['target matching scenario']=$x['state']==='match_possible'&&$x['suggested_price']===250.0&&$x['match_profit']>=20;
$tests['no blind floor comparison']=$x['target_met']===true;
$tests['stale withheld']=buybox_decision($p,array_replace($b,['checked_at'=>$now-3601]),$rule,$now)['suggested_price']===null;
$tests['future withheld']=!buybox_is_fresh(array_replace($b,['checked_at'=>$now+1]),$now);
$tests['winning no cut']=buybox_decision($p,array_replace($b,['buybox_rank'=>1]),$rule,$now)['state']==='winning';
$tests['loss withheld']=buybox_decision($p,array_replace($b,['buybox_price'=>50]),$rule,$now)['state']==='loss';
$tests['no target no recommendation']=buybox_decision($p,$b,null,$now)['suggested_price']===null;
$tests['higher price no cut']=buybox_decision($p,array_replace($b,['buybox_price'=>350]),$rule,$now)['state']==='no_reduction';
$tests['target below']=buybox_decision($p,$b,array_replace($rule,['value'=>1000]),$now)['state']==='below_target';
$tests['barcode mismatch']=buybox_decision($p,array_replace($b,['barcode'=>'002']),$rule,$now)['state']==='invalid';
$tests['unknown cost']=buybox_decision(array_replace($p,['cost'=>0]),$b,$rule,$now)['state']==='missing_input';
$db=db();$db->prepare("INSERT INTO trendyol_buybox(user_id,seller_id,barcode,buybox_rank,buybox_price,multiple_sellers,checked_at) VALUES(?,?,?,?,?,?,?)")->execute([1,'123','001',2,250,1,$now-10]);
$client=new class {
    public int $calls=0;
    public function getBuybox(array $b):array{
        if(++$this->calls===2)return ['ok'=>false,'error'=>'synthetic failure'];
        return ['ok'=>true,'items'=>[['barcode'=>$b[0],'rank'=>1,'price'=>999,'multiple'=>true,'second'=>null,'third'=>null]],'missing'=>array_slice($b,1)];
    }
};
$res=buybox_refresh_page($db,1,'123',array_merge(['001'],array_map('strval',range(2,11))),$client);
$tests['partial refresh preserves old']=!$res['ok']&&(float)$db->query("SELECT buybox_price FROM trendyol_buybox")->fetchColumn()===250.0;
$res=buybox_refresh_page($db,1,'123',['001'],$client);
$tests['complete refresh stored']=$res['ok']&&(float)$db->query("SELECT buybox_price FROM trendyol_buybox")->fetchColumn()===999.0;
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
