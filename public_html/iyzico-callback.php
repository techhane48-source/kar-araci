<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/billing.php';
require_once __DIR__.'/../includes/iyzico.php';
// Cross-site provider POST does not require browser session; never activate the browser's user.
$token=$_POST['token']??null;
if($_SERVER['REQUEST_METHOD']!=='POST'||!checkout_valid_token($token)){http_response_code(400);exit('Geçersiz ödeme bildirimi.');}
$intent=checkout_find(db(),$token);
if(!$intent){http_response_code(404);exit('Ödeme oturumu bulunamadı.');}
if($intent['status']==='approved'){header('Location: odeme.php',true,303);exit;}
$key=setting('iyzico_api_key');$secret=setting('iyzico_secret_key');$sandbox=setting('iyzico_sandbox')==='1';
if(!$key||!$secret||(int)$sandbox!==(int)$intent['sandbox']||!hash_equals($intent['merchant_fingerprint'],checkout_merchant($key,$secret))){
    http_response_code(503);exit('Ödeme doğrulaması destek kontrolü gerektiriyor.');
}
$res=(new IyzicoClient($key,$secret,$sandbox))->retrieveCheckoutFormResult($token,$intent['conversation_id']);
if(!$res['ok']||!is_array($res['result']??null)){http_response_code(503);exit('Ödeme sonucu henüz doğrulanamadı.');}
$r=checkout_apply(db(),$intent,$res['result'],$token);
if(!$r['ok']){http_response_code(409);exit('Ödeme henüz onaylanmadı. Destek ile görüşün.');}
header('Location: odeme.php',true,303);
