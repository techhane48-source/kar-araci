<?php
declare(strict_types=1);

/**
 * ePttAVM Entegrasyon İstemcisi.
 *
 * Dayandığı resmi API: ePttAVM Satıcı (Merchant) API.
 *   - Dokümantasyon: https://dev.pttavm.com/ (Satıcı entegrasyon belgeleri)
 *   - Taban URL: https://apis.pttavm.com (üretim) — tam adres panel belgelerine göre doğrulanmalı.
 *   - Kimlik: API key + API secret (HMAC imzalı istekler).
 *
 * KİMLİK BİLGİSİ: ePttAVM isteklerinde genellikle "x-api-key" üstbilgisi ve zaman damgası +
 * HMAC-SHA256 imzası gerekir. Bu istemci basit bir imza akışı kurar; tam imza algoritması
 * (hangi alanların imzalandığı) ePttAVM'nin güncel dokümantasyonuna göre doğrulanmalıdır.
 *
 * DÜRÜSTLÜK NOTU: Gerçek HTTP isteği atılır; kimlik bilgisi olmadan test edilemez ve uydurma
 * veri döndürülmez.
 */

final class EpttavmClient
{
    private string $sellerId;
    private string $apiKey;
    private string $apiSecret;
    private string $base;

    public const PROD = 'https://apis.pttavm.com';
    public const STAGE = 'https://apis-stage.pttavm.com';

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false)
    {
        $this->sellerId = trim($sellerId);
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->base = $stage ? self::STAGE : self::PROD;
    }

    /**
     * Kimlik bilgileriyle bağlantıyı sınar.
     */
    public function testConnection(): array
    {
        $res = $this->request('GET', "/api/v1/sellers/{$this->sellerId}/products", ['page' => '1', 'pageSize' => '1']);
        if (isset($res['http']) && $res['http'] === 200) {
            return ['ok' => true, 'sellerName' => null];
        }
        if (isset($res['http']) && ($res['http'] === 401 || $res['http'] === 403)) {
            return ['ok' => false, 'error' => 'Kimlik bilgileri hatalı (' . $res['http'] . '). API key / secret ve satıcı numarasını kontrol edin.'];
        }
        return ['ok' => false, 'error' => ($res['error'] ?? 'Bilinmeyen hata') . " (HTTP {$res['http']})"];
    }

    /**
     * Satıcının ürünlerini çeker (sayfalı).
     */
    public function getProducts(int $maxPage = 50): array
    {
        $all = [];
        $page = 1;
        $pageSize = 200;

        for ($i = 0; $i < $maxPage; $i++) {
            $res = $this->request('GET', "/api/v1/sellers/{$this->sellerId}/products", ['page' => (string)$page, 'pageSize' => (string)$pageSize]);
            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Ürün çekilemedi') . " (HTTP {$res['http']})"];
            }

            $items = $res['body']['data'] ?? $res['body']['products'] ?? [];
            if (!is_array($items)) $items = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $all[] = [
                    'barcode' => $it['sku'] ?? $it['barcode'] ?? '',
                    'title' => $it['name'] ?? $it['title'] ?? '',
                    'brand' => $it['brand'] ?? '',
                    'category' => $it['categoryName'] ?? '',
                    'listPrice' => (float)($it['listPrice'] ?? 0),
                    'salePrice' => (float)($it['price'] ?? $it['salePrice'] ?? 0),
                    'quantity' => (int)($it['stock'] ?? $it['quantity'] ?? 0),
                    'status' => $it['status'] ?? '',
                ];
            }

            $page++;
            if (count($items) < $pageSize) break;
        }

        return ['ok' => true, 'products' => $all, 'count' => count($all)];
    }

    /**
     * Siparişleri çeker.
     */
    public function getOrders(string $status = '', string $startDate = '', string $endDate = '', int $maxPage = 10): array
    {
        $all = [];
        $page = 1;
        $pageSize = 200;

        for ($i = 0; $i < $maxPage; $i++) {
            $params = ['page' => (string)$page, 'pageSize' => (string)$pageSize];
            if ($status !== '') $params['status'] = $status;
            if ($startDate !== '') $params['startDate'] = $startDate;
            if ($endDate !== '') $params['endDate'] = $endDate;

            $res = $this->request('GET', "/api/v1/sellers/{$this->sellerId}/orders", $params);
            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Sipariş çekilemedi') . " (HTTP {$res['http']})"];
            }

            $items = $res['body']['data'] ?? $res['body']['orders'] ?? [];
            if (!is_array($items)) $items = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $all[] = [
                    'orderNumber' => $it['orderNumber'] ?? $it['id'] ?? '',
                    'status' => $it['status'] ?? '',
                    'customerName' => $it['customerName'] ?? '',
                    'totalPrice' => (float)($it['totalPrice'] ?? 0),
                    'orderDate' => $it['orderDate'] ?? null,
                    'lines' => [],
                ];
            }

            $page++;
            if (count($items) < $pageSize) break;
        }

        return ['ok' => true, 'orders' => $all, 'count' => count($all)];
    }

    /**
     * Düşük seviyeli istek. ePttAVM imzalı istek üstbilgileri gerektirir.
     * İmza akışı (timestamp + HMAC-SHA256) platformun güncel dokümantasyonuna göre ayarlanmalıdır.
     */
    private function request(string $method, string $path, array $query = [], ?string $body = null): array
    {
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $method . $path . $timestamp, $this->apiSecret);

        $headers = [
            'x-api-key: ' . $this->apiKey,
            'x-timestamp: ' . $timestamp,
            'x-signature: ' . $signature,
            'Accept: application/json',
            'User-Agent: ' . $this->sellerId . ' - SelfIntegration',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $respBody = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($respBody === false) {
            return ['http' => 0, 'error' => 'Bağlantı hatası: ' . $err];
        }
        $decoded = json_decode($respBody, true);
        return ['http' => $http, 'body' => $decoded, 'raw' => $respBody];
    }
}
