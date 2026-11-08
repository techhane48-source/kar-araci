<?php
declare(strict_types=1);
$path=getenv('KARFIYAT_DB');
if (!$path || (realpath(dirname($path)).'/'.basename($path))===(realpath(__DIR__.'/../data').'/app.sqlite')) throw new RuntimeException('Ayrı test veritabanı seçin.');
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/promotion.php';
require_once __DIR__.'/promotion-fixtures.php';
$base=getenv('KARFIYAT_TEST_BASE')?:'http://127.0.0.1:8090';$fail=0;$temporary=[];$cookies=[];
function expect(bool $ok,string $label):void {global $fail;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fail++;}
function token(string $html,string $name='csrf'):string {preg_match('/name="'.preg_quote($name,'/').'" value="([^"]+)"/',$html,$m);return $m[1]??'';}
function request(CurlHandle $c,string $page,?array $data=null):array {global $base;curl_setopt($c,CURLOPT_URL,$base.'/'.$page);curl_setopt($c,CURLOPT_POST,$data!==null);if($data!==null)curl_setopt($c,CURLOPT_POSTFIELDS,$data);$body=curl_exec($c);if($body===false)throw new RuntimeException(curl_error($c));return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'type'=>curl_getinfo($c,CURLINFO_CONTENT_TYPE),'body'=>$body];}
function client():CurlHandle {global $cookies;$jar=tempnam(sys_get_temp_dir(),'kf-promo-cookie-');$cookies[]=$jar;$c=curl_init();curl_setopt_array($c,[CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false]);return $c;}
function login(CurlHandle $c,string $email):void {$r=request($c,'giris.php');$r=request($c,'giris.php',['csrf'=>token($r['body']),'email'=>$email,'password'=>'Promotion-Test-Only!']);if($r['status']!==302)throw new RuntimeException('Test girişi başarısız.');}
function upload(CurlHandle $c,string $kind,string $file,string $csrf):array {return request($c,'promosyon-analiz.php?mod='.$kind,['action'=>'upload','csrf'=>$csrf,'start'=>'2050-11-05T08:00','end'=>'2050-11-12T07:59','workbook'=>new CURLFile($file,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','synthetic-'.$kind.'.xlsx')]);}
function fixture(string $kind,array $rows):string {global $temporary;$file=promotion_fixture($kind,$rows);$temporary[]=$file;return $file;}
function downloaded(array $r):array {global $temporary;$f=tempnam(sys_get_temp_dir(),'kf-promo-http-out-');$temporary[]=$f;file_put_contents($f,$r['body']);return promo_workbook($f);}
$tag=bin2hex(random_bytes(4));$ids=[];
try {
    foreach(['pro','other','free'] as $name){db()->prepare('INSERT INTO users(email,password_hash,plan) VALUES(?,?,?)')->execute([$name.'-'.$tag.'@example.test',password_hash('Promotion-Test-Only!',PASSWORD_DEFAULT),$name==='free'?'free':'pro']);$ids[$name]=(int)db()->lastInsertId();}
    db()->prepare("INSERT INTO products(user_id,barcode,name,marketplace,category,rate,cost,price,kdv) VALUES(?,'0001','Synthetic Curtain <safe>','trendyol','Ev Tekstili',80,100,888,0)")->execute([$ids['pro']]);
    $c=client();expect(request($c,'promosyon-analiz.php')['status']===302,'Anonymous import page requires login');login($c,'free-'.$tag.'@example.test');expect(request($c,'promosyon-analiz.php')['status']===302,'Free plan cannot use paid imports');
    $c=client();login($c,'pro-'.$tag.'@example.test');$html=request($c,'promosyon-analiz.php')['body'];$csrf=token($html);
    $file=fixture('commission',[promotion_fixture_commission()]);upload($c,'komisyon',$file,'invalid');$r=request($c,'promosyon-analiz.php');expect(str_contains($r['body'],'Oturum süresi doldu'),'Upload rejects invalid CSRF');
    upload($c,'komisyon',$file,$csrf);$r=request($c,'promosyon-analiz.php');$version=token($r['body'],'version');expect($r['status']===200&&$version!==''&&str_contains($r['body'],'453,00'),'Native commission workbook is analyzed in authenticated UI');
    expect(str_contains($r['body'],'Synthetic Curtain &lt;safe&gt;'),'Product names escaped in results');
    $r=request($c,'promosyon-analiz.php',['action'=>'download','csrf'=>$csrf,'version'=>$version,'choice[2]'=>'999']);expect($r['status']===302,'Forged tier cannot generate a workbook');
    $r=request($c,'promosyon-analiz.php',['action'=>'download','csrf'=>$csrf,'version'=>$version,'choice[2]'=>'2']);$out=downloaded($r);$map=promo_schema($out,'komisyon');expect($r['status']===200&&str_contains($r['type'],'spreadsheetml')&&promo_cell($out['records'][0],$map,'YENİ TSF (FİYAT GÜNCELLE)')==='699.99','HTTP download is an XLSX with the chosen numeric price');
    expect((float)db()->query('SELECT price FROM products WHERE user_id='.$ids['pro'])->fetchColumn()===888.0,'Downloading leaves actual product prices unchanged');
    $sf=fixture('star',[promotion_fixture_star()]);upload($c,'etiket',$sf,$csrf);$r=request($c,'promosyon-analiz.php?mod=etiket');$old=token($r['body'],'version');expect(str_contains($r['body'],'komisyon %15,00'),'Star screen uses the separate commission upload');
    $new=promotion_fixture_commission();$new['2.KOMİSYON']=20;upload($c,'komisyon',fixture('commission',[$new]),$csrf);
    $r=request($c,'promosyon-analiz.php?mod=etiket',['action'=>'download','csrf'=>$csrf,'version'=>$old,'choice[2]'=>'1']);expect($r['status']===302,'Stale star screen rejected after dependent tariff replacement');
    $r=request($c,'promosyon-analiz.php?mod=etiket');$v=token($r['body'],'version');db()->prepare('UPDATE products SET cost=101 WHERE user_id=?')->execute([$ids['pro']]);$r=request($c,'promosyon-analiz.php?mod=etiket',['action'=>'download','csrf'=>$csrf,'version'=>$v,'choice[2]'=>'1']);expect($r['status']===302,'Stale selection rejected after cost changes');
    $ff=fixture('flash',array_fill(0,101,promotion_fixture_flash()));upload($c,'flash',$ff,$csrf);$r=request($c,'promosyon-analiz.php?mod=flash&page=2');$v=token($r['body'],'version');expect(str_contains($r['body'],'choice[102]'),'Large file is paginated by source row');
    request($c,'promosyon-analiz.php?mod=flash&page=2',['action'=>'save','csrf'=>$csrf,'version'=>$v,'choice[102]'=>'24']);$r=request($c,'promosyon-analiz.php?mod=flash');$v=token($r['body'],'version');$r=request($c,'promosyon-analiz.php?mod=flash',['action'=>'download','csrf'=>$csrf,'version'=>$v,'choice[2]'=>'24']);$out=downloaded($r);$m=promo_schema($out,'flash');expect(promo_cell($out['records'][0],$m,'Güncellenecek Fiyat')==='24 Saat'&&promo_cell($out['records'][100],$m,'Güncellenecek Fiyat')==='24 Saat'&&promo_cell($out['records'][1],$m,'Güncellenecek Fiyat')==='','Cross-page selections affect separate rows of the same barcode only');
    $other=client();login($other,'other-'.$tag.'@example.test');$r=request($other,'promosyon-analiz.php?mod=flash');expect(token($r['body'],'version')===''&&!str_contains($r['body'],'synthetic-flash.xlsx'),'Other users cannot see the import or its choices');
    $r=request($c,'promosyon-analiz.php?mod=plus');expect(str_contains($r['body'],'henüz hazır değil')&&token($r['body'],'version')==='','Unsupported workbook types do not pretend to analyze');
} finally {foreach($temporary as $f)if(is_file($f))unlink($f);foreach($cookies as $f)if(is_file($f))unlink($f);foreach($ids as $id){$dir=dirname(DB_PATH).'/promotion-imports/'.$id;foreach(glob($dir.'/*.xlsx')?:[] as $f)unlink($f);if(is_dir($dir))rmdir($dir);}}
exit($fail?1:0);
