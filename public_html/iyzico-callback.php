<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

// Çapraz site POST sırasında oturum çerezi gelmeyebilir. Sahiplik, sunucuda
// saklanan checkout kaydıyla belirlenir; callback üyeyi giriş yapmış saymaz.
$token = $_POST['token'] ?? $_GET['token'] ?? '';
if (!is_string($token) || $token === '') {
    http_response_code(400);
    exit('Ödeme doğrulama bilgisi eksik.');
}
$q = db()->prepare('SELECT token FROM checkout_sessions WHERE token = ?');
$q->execute([$token]);
if (!$q->fetch()) {
    http_response_code(400);
    exit('Ödeme kaydı bulunamadı.');
}
$key = setting('iyzico_api_key');
$secret = setting('iyzico_secret_key');
if ($key === '' || $secret === '') {
    http_response_code(503);
    exit('Kartlı ödeme yapılandırılmamış.');
}
require_once __DIR__ . '/../includes/iyzico.php';
$client = new IyzicoClient($key, $secret, setting('iyzico_sandbox') === '1');
$res = $client->retrieveCheckoutFormResult($token);
try {
    if (!$res['ok'] || !is_array($res['result'] ?? null)) {
        throw new RuntimeException('Ödeme sorgulanamadı.');
    }
    complete_checkout($token, $res['result']);
} catch (Throwable $e) {
    http_response_code(400);
    exit('Ödeme doğrulanamadı. Hesabınızdan ödeme durumunu kontrol edin.');
}
header('Location: odeme.php', true, 303);
