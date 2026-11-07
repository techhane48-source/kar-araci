<?php
declare(strict_types=1);

/**
 * Hepsiburada Entegrasyon İstemcisi.
 *
 * Dayandığı resmi API: Hepsiburada Merchant API (MPOP).
 *   - Dokümantasyon: https://developers.hepsiburada.com/ (Satıcı / Merchant entegrasyonu)
 *   - Üretim taban URL: https://mpop.hepsiburada.com
 *   - Test ortamı için ayrıca panelden test kimlikleri alınır.
 *
 * Kimlik bilgileri: Hepsiburada satıcı panelindeki "Entegrasyon Bilgileri" bölümünden
 * alınan kullanıcı adı (apiKey) ve şifre (apiSecret) ile Basic Auth kullanılır.
 * Satıcı numarası (merchantId) sellerId alanına yazılır.
 *
 * DÜRÜSTLÜK NOTU: Bu sınıf gerçek HTTP istekleri atar. Geçerli kimlik bilgisi olmadan
 * test edilemez; hata durumunda platformun döndürdüğü gerçek yanıt (HTTP kodu + mesaj)
 * iletilir. Alan adları (title/price/quantity vb.) HB'nin güncel API sözleşmesine göre
 * doğrulanmalıdır — HB yanıt şeması zamanla değişebilir.
 */

final class HbClient
{
    private string $sellerId;
    private string $apiKey;
    private string $apiSecret;
    private string $base;

    public const PROD = 'https://mpop.hepsiburada.com';
    public const STAGE = 'https://mpop-test.hepsiburada.com';

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false)
    {
        $this->sellerId = trim($sellerId);
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->base = $stage ? self::STAGE : self::PROD;
    }

    /**
     * Kimlik bilgileriyle Hepsiburada'ya bağlanılıp bağlanılamadığını sınar.
     * 1 ürünlük hafif bir istek atılır.
     */
    public function testConnection(): array
    {
        $res = $this->request('GET', "/product/api/products/merchantid/{$this->sellerId}", ['offset' => '0', 'limit' => '1']);
        if (isset($res['http']) && $res['http'] === 200) {
            return ['ok' => true, 'sellerName' => null];
        }
        if (isset($res['http']) && $res['http'] === 401) {
            return ['ok' => false, 'error' => 'Kimlik bilgileri hatalı (401). Kullanıcı adı / şifre ve satıcı numarasını kontrol edin.'];
        }
        return ['ok' => false, 'error' => ($res['error'] ?? 'Bilinmeyen hata') . " (HTTP {$res['http']})"];
    }

    /**
     * Satıcının ürünlerini çeker (offset/limit sayfalama).
     * Her ürün: barcode, title, brand, category, listPrice, salePrice, quantity, status.
     */
    public function getProducts(int $maxPage = 50): array
    {
        $all = [];
        $offset = 0;
        $limit = 200;

        for ($page = 0; $page < $maxPage; $page++) {
            $res = $this->request('GET', "/product/api/products/merchantid/{$this->sellerId}", ['offset' => (string)$offset, 'limit' => (string)$limit]);
            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Ürün çekilemedi') . " (HTTP {$res['http']})"];
            }

            // HB yanıtı genellikle bir liste döner; sözleşme değişebilir, bu yüzden esnek okuyoruz.
            $items = $res['body']['data'] ?? $res['body'] ?? [];
            if (!is_array($items)) $items = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $all[] = [
                    'barcode' => $it['merchantSku'] ?? $it['barcode'] ?? '',
                    'title' => $it['title'] ?? $it['name'] ?? '',
                    'brand' => $it['brand'] ?? '',
                    'category' => $it['categoryName'] ?? $it['category'] ?? '',
                    'listPrice' => (float)($it['listPrice'] ?? 0),
                    'salePrice' => (float)($it['price'] ?? $it['salePrice'] ?? 0),
                    'quantity' => (int)($it['stock'] ?? $it['quantity'] ?? 0),
                    'status' => $it['status'] ?? '',
                ];
            }

            $offset += $limit;
            if (count($items) < $limit) break;
        }

        return ['ok' => true, 'products' => $all, 'count' => count($all)];
    }

    /**
     * Siparişleri çeker.
     */
    public function getOrders(string $status = '', string $startDate = '', string $endDate = '', int $maxPage = 10): array
    {
        $all = [];
        $offset = 0;
        $limit = 200;

        for ($page = 0; $page < $maxPage; $page++) {
            $params = ['offset' => (string)$offset, 'limit' => (string)$limit];
            if ($status !== '') $params['status'] = $status;
            if ($startDate !== '') $params['beginDate'] = $startDate;
            if ($endDate !== '') $params['endDate'] = $endDate;

            $res = $this->request('GET', "/orders/api/orders/merchantid/{$this->sellerId}", $params);
            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Sipariş çekilemedi') . " (HTTP {$res['http']})"];
            }

            $items = $res['body']['data'] ?? $res['body'] ?? [];
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

            $offset += $limit;
            if (count($items) < $limit) break;
        }

        return ['ok' => true, 'orders' => $all, 'count' => count($all)];
    }

    /**
     * Düşük seviyeli HTTP isteği. Hepsiburada Basic Auth + User-Agent zorunludur.
     */
    private function request(string $method, string $path, array $query = [], ?string $body = null): array
    {
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $auth = base64_encode($this->apiKey . ':' . $this->apiSecret);
        $headers = [
            'Authorization: Basic ' . $auth,
            'User-Agent: ' . $this->sellerId . ' - SelfIntegration',
            'Accept: application/json',
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
