<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/cargo_tariff_install.php';
$dir=sys_get_temp_dir().'/kar-tariff-'.bin2hex(random_bytes(8));
register_shutdown_function(static function()use($dir){if(is_dir($dir)){foreach(glob($dir.'/*') as $p)unlink($p);if(is_file($dir.'/.install.lock'))unlink($dir.'/.install.lock');rmdir($dir);}});
$t=['from'=>'2027-01-01','through'=>'2027-01-31','source'=>'Synthetic only','scope'=>'marketplace_standard','confidence'=>'operator_reference','rows'=>[['carrier'=>'TEX','desi'=>3,'min_sale'=>350,'max_sale'=>null,'gross_tl'=>120]]];
$tests=['preview no directory'=>cargo_tariff_install($dir,$t)['state']==='preview'&&!is_dir($dir)];
$tests['install explicit']=cargo_tariff_install($dir,$t,true)['state']==='installed'&&count(glob($dir.'/*.json'))===1;
$tests['repeat unchanged']=cargo_tariff_install($dir,$t,true)['state']==='unchanged';
$bad=$t;$bad['rows'][0]['gross_tl']=140;
try{cargo_tariff_install($dir,$bad,true);$tests['overlap rejected']=false;}catch(InvalidArgumentException $e){$tests['overlap rejected']=count(glob($dir.'/*.json'))===1;}
try{cargo_tariff_install($dir,array_replace($t,['scope'=>'seller_specific']),true);$tests['private contract rejected']=false;}catch(InvalidArgumentException $e){$tests['private contract rejected']=true;}
$next=array_replace($t,['from'=>'2027-02-01','through'=>'2027-02-28']);
$tests['new nonoverlap date accepted']=cargo_tariff_install($dir,$next,true)['state']==='installed'&&count(glob($dir.'/*.json'))===2;
foreach($tests as $name=>$ok)echo ($ok?'PASS':'FAIL')." $name\n";
exit(in_array(false,$tests,true)?1:0);
