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
$result=verify_checkout($token);
if (!$result['ok']) {
    http_response_code($result['code']==='not_found'?400:503);
    exit('Ödeme henüz doğrulanamadı. Hesabınızdan durumunu kontrol edin; tekrar ödeme yapmadan önce mevcut işlemin sonucunu bekleyin.');
}
header('Location: odeme.php',true,303);
