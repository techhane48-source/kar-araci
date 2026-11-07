<?php
declare(strict_types=1);
$path=getenv('KARFIYAT_DB');
if(!$path || realpath($path)===realpath(__DIR__.'/../data/app.sqlite'))throw new RuntimeException('Ayrı test veritabanı gerekli.');
require_once __DIR__.'/../includes/functions.php';
$user=db()->query("SELECT id FROM users WHERE email='profit@example.test'")->fetchColumn();
if(!$user)throw new RuntimeException('Önce test-profit-http.php çalıştırın.');
db()->prepare("INSERT INTO integrations(user_id,marketplace,seller_id,last_sync,last_orders_sync,last_settlements_sync,last_sync_error,last_sync_error_scope) VALUES(?,'trendyol','fixture-seller',?,?,?,?, 'products')")
    ->execute([$user,gmdate('Y-m-d H:i:s'),time(),time(),'Fixture: <script>unsafe</script>']);
$jar=tempnam(sys_get_temp_dir(),'kf-sync-ui-');$ch=curl_init();
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10]);
curl_setopt($ch,CURLOPT_URL,'http://127.0.0.1:8090/giris.php');$body=curl_exec($ch);
preg_match('/name="csrf" value="([^"]+)"/',$body,$match);
curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query(['csrf'=>$match[1],'email'=>'profit@example.test','password'=>'Test-Profit-Only!']));curl_exec($ch);
curl_setopt($ch,CURLOPT_POST,false);curl_setopt($ch,CURLOPT_URL,'http://127.0.0.1:8090/magaza.php');$body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
$ok=$code===200 && str_contains($body,'Son eksiksiz sipariş senkronu') && str_contains($body,'Son eksiksiz finans senkronu') && str_contains($body,'&lt;script&gt;unsafe&lt;/script&gt;') && !str_contains($body,'<script>unsafe</script>');
curl_close($ch);unlink($jar);
echo ($ok?'PASS ':'FAIL ')."Mağaza ekranı başarı zamanlarını ve güvenli hata metnini gösterir.\n";
exit($ok?0:1);
