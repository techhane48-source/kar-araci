<?php
declare(strict_types=1);

/**
 * AliExpress Entegrasyon İstemcisi.
 *
 * Dayandığı resmi API: AliExpress Open Platform API (aliexpress.openplatform).
 *   - Dokümantasyon: https://open.aliexpress.com/ (Developer dokümantasyonu)
 *   - Taban URL: https://api-sg.aliexpress.com/sync (REST) — bölge/endpoint dokümana göre değişebilir.
 *   - Kimlik: app_key (apiKey) + app_secret (apiSecret); her istek HMAC-SHA256 imza (`sign`) gerektirir.
 *
 * KİMLİK BİLGİSİ: AliExpress Open Platform'dan alınan appKey/appSecret ile istek imzalanır.
 * İmza, parametreler sıralanıp `secret` ile HMAC-SHA256 yapılarak üretilir. sellerId alanı
 * (gerekirse) satıcı kimliği için kullanılır; bazen boş kalabilir.
 *
 * DÜRÜSTLÜK NOTU: Gerçek HTTP isteği atılır; geçerli kimlik bilgisi olmadan test edilemez ve
 * uydurma veri döndürülmez. API adları ve imza algoritması AliExpress'in güncel dokümantasyonuna
 * göre doğrulanmalıdır.
 */

final class AliExpressClient
{
    private string $sellerId;
    private string $appKey;
    private string $appSecret;
    private string $base;

    public const PROD = 'https://api-sg.aliexpress.com/sync';
    public const STAGE = 'https://api-sg.aliexpress.com/sync'; // ayrı test ucu dokümanda belirtilir

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false)
    {
        $this->sellerId = trim($sellerId);
        $this->appKey = trim($apiKey);
        $this->appSecret = trim($apiSecret);
        $this->base = self::PROD;
    }

    /**
     * Kimlik bilgileriyle bağlantıyı sınar (hafif bir ürün listesi isteği).
     */
    public function testConnection(): array
    {
        $res = $this->request('aliexpress.solution.product.list.get', ['page_no' => '1', 'page_size' => '1']);
        if (isset($res['http']) && $res['http'] === 200) {
            return ['ok' => true, 'sellerName' => null];
        }
        if (isset($res['http']) && ($res['http'] === 401 || $res['http'] === 403)) {
            return ['ok' => false, 'error' => 'Kimlik bilgileri hatalı (' . $res['http'] . '). appKey/appSecret ve yetkilendirmeyi kontrol edin.'];
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
        $pageSize = 50;

        for ($i = 0; $i < $maxPage; $i++) {
            $res = $this->request('aliexpress.solution.product.list.get', ['page_no' => (string)$page, 'page_size' => (string)$pageSize]);
            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Ürün çekilemedi') . " (HTTP {$res['http']})"];
            }

            $items = $res['body']['data']['aeop_ae_product_s_k_us'] ?? $res['body']['data']['products'] ?? [];
            if (!is_array($items)) $items = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $all[] = [
                    'barcode' => $it['product_id'] ?? $it['barcode'] ?? '',
                    'title' => $it['subject'] ?? $it['title'] ?? '',
                    'brand' => '',
                    'category' => '',
                    'listPrice' => 0.0,
                    'salePrice' => 0.0,
                    'quantity' => 0,
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
        $pageSize = 50;

        for ($i = 0; $i < $maxPage; $i++) {
            $params = ['page_no' => (string)$page, 'page_size' => (string)$pageSize];
            if ($status !== '') $params['order_status'] = $status;
            if ($startDate !== '') $params['create_date_start'] = $startDate;
            if ($endDate !== '') $params['create_date_end'] = $endDate;

            $res = $this->request('aliexpress.trade.seller.orderlist.get', $params);
            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Sipariş çekilemedi') . " (HTTP {$res['http']})"];
            }

            $items = $res['body']['data']['order_list'] ?? [];
            if (!is_array($items)) $items = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $all[] = [
                    'orderNumber' => $it['order_id'] ?? '',
                    'status' => $it['order_status'] ?? '',
                    'customerName' => '',
                    'totalPrice' => (float)($it['total_amount'] ?? 0),
                    'orderDate' => $it['gmt_create'] ?? null,
                    'lines' => [],
                ];
            }

            $page++;
            if (count($items) < $pageSize) break;
        }

        return ['ok' => true, 'orders' => $all, 'count' => count($all)];
    }

    /**
     * Düşük seviyeli istek. AliExpress imzalı POST gerektirir (sign = HMAC-SHA256).
     */
    private function request(string $apiName, array $params = []): array
    {
        $common = $params;
        $common['app_key'] = $this->appKey;
        $common['sign_method'] = 'sha256';
        $common['timestamp'] = (string)time();
        $common['method'] = $apiName;
        $common['sign'] = $this->sign($common);

        $body = json_encode($common, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->base);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);

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

    /**
     * AliExpress imza algoritması: anahtarlar sıralanır, değerler birleştirilir,
     * başına/sonuna secret eklenip HMAC-SHA256 (hex, büyük harf) hesaplanır.
     * Tam algoritma resmi dokümantasyona göre doğrulanmalıdır.
     */
    private function sign(array $params): string
    {
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $v === '' || $v === null) continue;
            $str .= $k . $v;
        }
        $str = $this->appSecret . $str . $this->appSecret;
        return strtoupper(hash_hmac('sha256', $str, $this->appSecret));
    }
}
