<?php
declare(strict_types=1);

/**
 * iyzico CheckoutForm istemcisi — kartlı ödeme altyapısı.
 *
 * Kimlik bilgileri (apiKey/secretKey) yönetici panelinden girilir ve DB'de settings
 * tablosunda saklanır. Bu sınıf yalnızca bu bilgileri alıp iyzico API'ye istek atar.
 * API anahtarları log'a YAZILMAZ; yalnızca istek anında Authorization başlığında kullanılır.
 *
 * Auth: iyzico IYZWSv2 (HMAC-SHA256 imza):
 *   randomKey = rastgele dize
 *   signature  = HMAC-SHA256(randomKey + uriPath + requestBody, secretKey) [hex]
 *   authString = "apiKey:KEY&randomKey:rnd&signature:hex"
 *   Authorization: IYZWSv2 <base64(authString)>
 *   x-iyzi-rnd: <randomKey>
 *
 * Endpoint'ler (güncel):
 *   initialize: POST /payment/iyzipos/checkoutform/initialize/auth/ecom
 *   retrieve:   POST /payment/iyzipos/checkoutform/auth/ecom/detail
 *
 * NOT: Gerçek kart çekimi iyzico ticari hesabı + apiKey/secretKey gerektirir.
 * Sandbox key'leriyle (varsayılan) test ödemeleri yapılabilir.
 */

final class IyzicoClient
{
    private string $apiKey;
    private string $secretKey;
    private string $base;

    public const SANDBOX = 'https://sandbox-api.iyzipay.com';
    public const PROD = 'https://api.iyzipay.com';

    public function __construct(string $apiKey, string $secretKey, bool $sandbox = true)
    {
        $this->apiKey = trim($apiKey);
        $this->secretKey = trim($secretKey);
        $this->base = $sandbox ? self::SANDBOX : self::PROD;
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $jsonBody = $body !== [] ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $randomKey = bin2hex(random_bytes(8));
        $signature = hash_hmac('sha256', $randomKey . $path . $jsonBody, $this->secretKey);
        $authString = 'apiKey:' . $this->apiKey . '&randomKey:' . $randomKey . '&signature:' . $signature;
        $authorization = 'IYZWSv2 ' . base64_encode($authString);

        $headers = [
            'Authorization: ' . $authorization,
            'x-iyzi-rnd: ' . $randomKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => getenv('CURL_CA_BUNDLE')?:getenv('SSL_CERT_FILE')?:__DIR__ . '/cacert.pem',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'error' => 'Bağlantı hatası: ' . $err];
        }
        if ($http < 200 || $http >= 300) {
            return ['ok' => false, 'error' => 'Ödeme servisi isteği başarısız.'];
        }
        return ['ok' => true, 'http' => $http, 'body' => json_decode($resp, true)];
    }

    /**
     * CheckoutForm oturumu başlatır. Başarılıysa token + paymentPageUrl döner.
     * $params iyzico'nun initialize alanlarını içerir (locale, conversationId, price,
     * paidPrice, currency, basketId, paymentGroup, callbackUrl, buyer, shippingAddress,
     * billingAddress, basketItems).
     */
    public function initializeCheckoutForm(array $params): array
    {
        $res = $this->request('POST', '/payment/iyzipos/checkoutform/initialize/auth/ecom', $params);
        if (!$res['ok']) {
            return $res;
        }
        $body = $res['body'];
        if (is_array($body) && ($body['status'] ?? '') === 'success') {
            return [
                'ok' => true,
                'token' => (string)($body['token'] ?? ''),
                'paymentPageUrl' => (string)($body['paymentPageUrl'] ?? ''),
                'checkoutFormContent' => (string)($body['checkoutFormContent'] ?? ''),
            ];
        }
        return [
            'ok' => false,
            'error' => is_array($body)
                ? (($body['errorMessage'] ?? '') ?: ($body['errorCode'] ?? 'Ödeme formu başlatılamadı'))
                : 'Ödeme formu başlatılamadı',
        ];
    }

    /**
     * Callback'ten gelen token ile ödeme sonucunu sorgular.
     * Başarılıysa ['ok'=>true,'result'=>[...]] döner; result içinde paymentStatus, fraudStatus vb.
     */
    public function retrieveCheckoutFormResult(string $token): array
    {
        $body = [
            'locale' => 'tr',
            'conversationId' => 'cf-' . uniqid(),
            'token' => $token,
        ];
        $res = $this->request('POST', '/payment/iyzipos/checkoutform/auth/ecom/detail', $body);
        if (!$res['ok']) {
            return $res;
        }
        return ['ok' => true, 'result' => $res['body']];
    }
}
