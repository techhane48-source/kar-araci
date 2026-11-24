<?php
declare(strict_types=1);
$path=getenv('KARFIYAT_DB');
if (!$path || realpath($path)===realpath(__DIR__.'/../data/app.sqlite')) throw new RuntimeException('Ayrı test veritabanı seçin.');
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/promotion.php';
require_once __DIR__.'/promotion-fixtures.php';
$failed=0;
$base=getenv('KARFIYAT_TEST_BASE')?:'http://127.0.0.1:8090';
function check(bool $ok,string $label):void {global $failed;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failed++;}
$jar=tempnam(sys_get_temp_dir(),'kf-profit-cookie-');
$ch=curl_init();
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
function req(string $page,?array $data=null,bool $json=false):array {
    global $ch,$base;
    curl_setopt($ch,CURLOPT_URL,$base.'/'.$page);
    curl_setopt($ch,CURLOPT_POST,$data!==null);
    curl_setopt($ch,CURLOPT_HTTPHEADER,$json?['Content-Type: application/json','X-CSRF-Token: '.($GLOBALS['csrf']??'')]:[]);
    if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$json?json_encode($data):http_build_query($data));
    $body=curl_exec($ch);if($body===false)throw new RuntimeException(curl_error($ch));
    return ['status'=>curl_getinfo($ch,CURLINFO_RESPONSE_CODE),'body'=>$body];
}
function csrf(string $html):string {preg_match('/name="csrf" value="([^"]+)"/',$html,$m);return $m[1]??'';}
function csv_rows(string $body):array {
    $rows=array_map(static fn($line)=>str_getcsv($line,',','"','\\'),explode("\n",trim($body)));
    array_shift($rows);return $rows;
}
function row_for(string $html,string $name):string {
    $at=strpos($html,$name);if($at===false)return '';
    $start=strrpos(substr($html,0,$at),'<tr>');$end=strpos($html,'</tr>',$at);
    return substr($html,$start,$end-$start);
}

db()->prepare("INSERT INTO users(email,password_hash,plan) VALUES(?,?,'pro')")
    ->execute(['profit@example.test',password_hash('Test-Profit-Only!',PASSWORD_DEFAULT)]);
$id=(int)db()->lastInsertId();
$products=[
 ['barcode'=>'A','name'=>'Profit Fixture A','category'=>'Giyim','cost'=>200,'price'=>400,'cargo'=>50,'rate'=>21.36,'kdv'=>20,'service_fee'=>0,'extra_fee'=>0,'stopaj'=>0,'return_rate'=>0,'stopaj_mode'=>'manual'],
 ['barcode'=>'B','name'=>'Profit Fixture B','category'=>'Gıda','cost'=>100,'price'=>300,'cargo'=>10,'rate'=>10,'kdv'=>10,'service_fee'=>2,'extra_fee'=>4,'stopaj'=>0,'return_rate'=>2,'stopaj_mode'=>'auto'],
];
foreach($products as $p) {
    db()->prepare('INSERT INTO products(user_id,marketplace,'.implode(',',array_keys($p)).') VALUES (?,? ,'.implode(',',array_fill(0,count($p),'?')).')')
        ->execute(array_merge([$id,'trendyol'],array_values($p)));
}
$by=array_column($products,null,'barcode');
$lines=[['barcode'=>'A','quantity'=>2,'price'=>400],['barcode'=>'B','quantity'=>1,'price'=>300]];
$insert=db()->prepare('INSERT INTO orders(user_id,marketplace,order_number,package_number,status,total_price,order_date,lines) VALUES (?,\'trendyol\',?,?,?,?,?,?)');
foreach([['ok','Delivered',1100],['discount','Delivered',990],['cancel','Cancelled',10000],['return','Returned',10000]] as [$number,$status,$sale])
    $insert->execute([$id,$number,$number,$status,$sale,strtotime('today')+60,json_encode($lines)]);
$expected=calc_order_profit(['total_price'=>1100,'status'=>'Delivered','lines'=>$lines],$by)['profit']
    +calc_order_profit(['total_price'=>990,'status'=>'Delivered','lines'=>$lines],$by)['profit'];
$r=req('giris.php');$csrf=csrf($r['body']);
$r=req('giris.php',['csrf'=>$csrf,'email'=>'profit@example.test','password'=>'Test-Profit-Only!']);
check($r['status']===302,'Kârlılık test kullanıcısı giriş yapar');
$r=req('hesabim.php');$csrf=csrf($r['body']);
foreach(['urunler.php','kar-marji.php'] as $page) {
    $r=req($page);
    check($r['status']===200 && str_contains(row_for($r['body'],'Profit Fixture A'),tl(53.8)),"$page aynı ürün kârını gösterir");
}
$csv=['barkod;fiyat','A;400','B;300'];
$tmp=tempnam(sys_get_temp_dir(),'kf-profit-csv-');file_put_contents($tmp,implode("\n",$csv));
foreach(['toplu-analiz.php'] as $page) {
    curl_setopt($ch,CURLOPT_URL,$base.'/'.$page);
    curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_HTTPHEADER,[]);
    curl_setopt($ch,CURLOPT_POSTFIELDS,['csrf'=>$csrf,'action'=>'analyze','mp'=>'trendyol','csv'=>new CURLFile($tmp,'text/csv','prices.csv')]);
    curl_exec($ch);$r=req($page);
    check(str_contains($r['body'],number_format(53.8,2,',','.')),"$page aynı ürün kârını gösterir");
}
unlink($tmp);
$row=promotion_fixture_commission();
foreach(['BARKOD'=>'A','1.Fiyat Alt Limit'=>400,'2.Fiyat Üst Limiti'=>399.99,'2.Fiyat Alt Limit'=>300,'3.Fiyat Üst Limiti'=>299.99,'3.Fiyat Alt Limit'=>200,'4.Fiyat Üst Limiti'=>199.99,'1.KOMİSYON'=>21.36] as $key=>$value) $row[$key]=$value;
$tmp=promotion_fixture('commission',[$row]);
try {
    curl_setopt($ch,CURLOPT_URL,$base.'/promosyon-analiz.php');
    curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_HTTPHEADER,[]);
    curl_setopt($ch,CURLOPT_POSTFIELDS,['csrf'=>$csrf,'action'=>'upload','start'=>'2050-11-05T08:00','end'=>'2050-11-12T07:59','workbook'=>new CURLFile($tmp,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','commission.xlsx')]);
    curl_exec($ch);$r=req('promosyon-analiz.php');
    check(str_contains($r['body'],number_format(53.8,2,',','.')),'promosyon-analiz.php kaynak kademe komisyonuyla aynı ürün kârını gösterir');
} finally {unlink($tmp);}
foreach(['order','product','category'] as $type) {
    $r=req('raporlar.php?r='.$type.'&export=1');$rows=csv_rows($r['body']);
    $index=['order'=>3,'product'=>5,'category'=>4][$type];
    $sum=array_sum(array_map(static fn($row)=>(float)$row[$index],$rows));
    check(abs($sum-$expected)<0.011,"$type CSV kâr toplamı sipariş hesabıyla eşleşir");
    if($type==='order') check(count($rows)===2,'İptal/iade siparişleri rapora alınmaz');
}
$r=req('dashboard.php?days=7');
check(str_contains($r['body'],tl($expected)) && str_contains($r['body'],tl(2090)),'Dashboard raporlarla aynı ciro/kârı gösterir');
check(str_contains($r['body'],'Hesaba dahil değil'),'Bilinmeyen finans giderleri sıfır gibi gösterilmez');
$r=req('canli-performans.php');
check(str_contains($r['body'],tl($expected)) && str_contains($r['body'],tl(2090)),'Gece ilk saatteki siparişler bugün ve haftalık kârda görünür');
$before=(float)db()->query("SELECT price FROM products WHERE user_id=$id AND barcode='A'")->fetchColumn();
$r=req('kar-marji.php',['csrf'=>$csrf,'repricing'=>1,'tip'=>'marj','deger'=>90,'apply'=>1]);
$after=(float)db()->query("SELECT price FROM products WHERE user_id=$id AND barcode='A'")->fetchColumn();
check($before===$after,'Ulaşılamayan hedef fiyat ürüne yazılmaz');
foreach([['cost'=>-1],['rate'=>101],['rate'=>[1]],['kdv'=>-100]] as $invalid) {
    $data=array_replace(['marketplace'=>'trendyol','category'=>'Giyim','cost'=>100,'price'=>200],$invalid);
    check(req('api-save.php',$data,true)['status']===422,'Geçersiz hesap girdisi API’de reddedilir');
}
$r=req('api-save.php',['marketplace'=>'trendyol','category'=>'Giyim','name'=>'Auto mode saved','cost'=>100,'price'=>200,'stopaj_mode'=>'auto'],true);
check((json_decode($r['body'],true)['ok']??false) && db()->query("SELECT stopaj_mode FROM products WHERE user_id=$id AND name='Auto mode saved'")->fetchColumn()==='auto','Otomatik stopaj tercihi kalıcı saklanır');
// Karışık siparişte bilinmeyen satır hiçbir raporda sahte sıfır kâr üretmesin.
$insert->execute([$id,'missing','missing','Delivered',400,strtotime('today')+60,json_encode([['barcode'=>'UNKNOWN','quantity'=>1,'price'=>400]])]);
$r=req('raporlar.php?r=product&export=1');$unknown=array_values(array_filter(csv_rows($r['body']),static fn($row)=>$row[0]==='UNKNOWN'));
check(isset($unknown[0]) && $unknown[0][5]==='','Maliyeti bilinmeyen ürün CSV kârı boş kalır');
db()->prepare("INSERT INTO settlements(user_id,barcode,transaction_type,debt,transaction_date) VALUES (?,'A','Return',40,?)")->execute([$id,time()]);
db()->prepare("INSERT INTO settlements(user_id,barcode,transaction_type,debt,transaction_date) VALUES (?,'A','Coupon',15,?)")->execute([$id,time()]);
db()->prepare("INSERT INTO settlements(user_id,barcode,transaction_type,debt,transaction_date) VALUES (?,'A','Ads',12,?)")->execute([$id,time()]);
foreach (['return','campaign','ads','buybox'] as $type) check(req('raporlar.php?r='.$type)['status']===200,"$type yardımcı raporu açılır");
$returns=csv_rows(req('raporlar.php?r=return&export=1')['body']);
check(isset($returns[0]) && $returns[0][0]==='A' && (float)$returns[0][3]===40.0,'İngilizce Return kaydı iade raporuna girer');
$campaign=csv_rows(req('raporlar.php?r=campaign&export=1')['body']);
check(isset($campaign[0]) && (float)$campaign[0][2]===15.0,'İngilizce Coupon kaydı kampanya raporuna girer');
db()->prepare("INSERT INTO settlements(user_id,barcode,transaction_type,credit,transaction_date) VALUES (?,'A','CouponCancel',5,?)")->execute([$id,time()]);
$campaign=csv_rows(req('raporlar.php?r=campaign&export=1')['body']);
check((float)$campaign[0][2]===10.0,'Kupon iptali kampanya giderinden düşülür');
$ads=csv_rows(req('raporlar.php?r=ads&export=1')['body']);
check(isset($ads[0]) && $ads[0][6]==='','Reklam atfı olmadan kâr üretilmez');
check(str_contains(req('raporlar.php?r=order')['body'],'<td class="tar">%'),'Rapor yüzdeleri para birimiyle gösterilmez');
// Tekil maliyet formu mevcut ürünü günceller ve ürün sahibine özeldir.
$productId = (int)db()->query("SELECT id FROM products WHERE user_id=$id AND barcode='A'")->fetchColumn();
db()->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute(['other-cost@example.test', 'fixture']);
$otherUserId = (int)db()->lastInsertId();
db()->prepare("INSERT INTO products (user_id, marketplace, category, rate, barcode, name, cost) VALUES (?, 'trendyol', 'Giyim', 20, 'A', 'Other cost fixture', 75)")->execute([$otherUserId]);
$otherProductId = (int)db()->lastInsertId();
$readCost = db()->prepare('SELECT cost FROM products WHERE id = ?');
$costOf = static function (int $pid) use ($readCost): float {
    $readCost->execute([$pid]);
    return (float)$readCost->fetchColumn();
};
$costPage = 'urunler.php?q=Profit+Fixture+A';
$csrf = csrf(req($costPage)['body']);
$form = ['action' => 'update_cost', 'csrf' => $csrf, 'pid' => $productId, 'cost' => '215,50'];
check(req($costPage, $form)['status'] === 302 && $costOf($productId) === 215.5, 'Virgüllü birim maliyet tek ürüne kaydedilir');
check(str_contains(req($costPage)['body'], 'value="215,50"'), 'Aranan ürün yeni maliyeti gösterir');
$expected = calc_product_profit(array_replace($products[0], ['cost' => 215.5]));
check(str_contains(row_for(req('kar-marji.php')['body'], 'Profit Fixture A'), tl($expected['profit'])), 'Kâr marjı güncel maliyetle hesaplanır');
req($costPage, array_replace($form, ['cost' => '-5']));
req($costPage, array_replace($form, ['cost' => '100', 'csrf' => 'invalid']));
req($costPage, array_replace($form, ['pid' => $otherProductId]));
check($costOf($productId) === 215.5 && $costOf($otherProductId) === 75.0, 'Geçersiz maliyet, CSRF ve başka kullanıcı kaydı değiştirmez');
$livePage = 'canli-performans.php?mp=trendyol';
$live = req($livePage);
check(str_contains($live['body'], 'Hesapta kullanılan kargo') && str_contains($live['body'], 'Hizmet bedeli tahmini'),
    'Canlı Performans sipariş kârının kullanılan gider dökümünü gösterir');
req($livePage, array_replace($form, ['action' => 'save_cost', 'cost' => 'yanlis']));
check($costOf($productId) === 215.5, 'Canlı Performans geçersiz girdiyle maliyeti sıfırlamaz');

db()->prepare("INSERT INTO products (user_id, marketplace, category, rate, barcode, name, cost)
    VALUES (?, 'hepsiburada', 'Giyim', 20, 'A', 'Ambiguous cost fixture', 98)")->execute([$id]);
$duplicateId = (int)db()->lastInsertId();
$tmp = tempnam(sys_get_temp_dir(), 'kf-bulk-cost-');
file_put_contents($tmp, "Barkod;Maliyet\nA;777\nB;155,25\n");
try {
    curl_setopt($ch, CURLOPT_URL, $base . '/urunler.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, []);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'csrf' => $csrf, 'action' => 'import_maliyet', 'csv' => new CURLFile($tmp, 'text/csv', 'maliyet.csv'),
    ]);
    curl_exec($ch);
    check($costOf($productId) === 215.5 && $costOf($duplicateId) === 98.0,
        'Toplu maliyet birden çok ürünle eşleşen barkodu değiştirmez');
    check((float)db()->query("SELECT cost FROM products WHERE user_id=$id AND barcode='B'")->fetchColumn() === 155.25,
        'Toplu maliyet tekil barkodun virgüllü maliyetini günceller');
} finally { unlink($tmp); }
curl_close($ch);unlink($jar);
echo $failed?"$failed test başarısız\n":"TÜM TESTLER GEÇTİ\n";
exit($failed?1:0);
