<?php
declare(strict_types=1);

/**
 * Amazon SP-API (Selling Partner API) Entegrasyon İstemcisi.
 *
 * Dayandığı resmi API: Amazon Selling Partner API (SP-API).
 *   - Dokümantasyon: https://developer-docs.amazon.com/sp-api/
 *   - Avrupa (Amazon.com.tr dahil) uç noktası: https://sellingpartnerapi-eu.amazon.com
 *   - LWA (Login with Amazon) token uç noktası: https://api.amazon.com/auth/o2/token
 *
 * KİMLİK BİLGİSİ (KARMAŞIK): SP-API, Trendyol'daki Basic Auth'tan çok daha karmaşıktır.
 * Gerekli olanlar:
 *   - LWA client_id      -> apiKey alanına
 *   - LWA client_secret  -> apiSecret alanına
 *   - LWA refresh_token  -> (bu uygulamanın 3 alanlı entegrasyon modelinde ayrıca saklanmıyor)
 *   - sellerId (merchantId) -> sellerId alanına
 *   - IAM role ARN + yetkilendirme (SP-API developer hesabı üzerinden)
 * Ayrıca her istekte `x-amz-access-token` başlığı gerekir; token LWA'dan refresh_token ile
 * alınır. Bu yüzden bu istemci, refresh_token olmadan token alamaz ve bağlantı testi,
 * "refresh_token gerekli" şeklinde dürüst bir hata döndürür.
 *
 * DÜRÜSTLÜK NOTU: Hiçbir istek uydurma veri döndürmez. Kimlik bilgisi eksikse gerçek hata
 * döner. Endpoint'ler ve alan adları SP-API'nin güncel sözleşmesine göre doğrulanmalıdır.
 */

final class AmazonClient
{
    private string $sellerId;
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken; // 3 alanlı modele sığmadığı için varsayılan boş
    private string $base;

    public const PROD = 'https://sellingpartnerapi-eu.amazon.com';
    public const STAGE = 'https://sandbox.sellingpartnerapi-eu.amazon.com';
    public const LWA_TOKEN_URL = 'https://api.amazon.com/auth/o2/token';

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false, string $refreshToken = '')
    {
        $this->sellerId = trim($sellerId);
        $this->clientId = trim($apiKey);
        $this->clientSecret = trim($apiSecret);
        $this->refreshToken = trim($refreshToken);
        $this->base = $stage ? self::STAGE : self::PROD;
    }

    /**
     * LWA'dan erişim token'ı alır. refresh_token şarttır (uygulama 3 alan saklar,
     * bu yüzden istek anında sağlanmadıkça bu adım hata döner).
     */
    public function getAccessToken(): array
    {
        if ($this->refreshToken === '') {
            return ['ok' => false, 'error' => 'Amazon SP-API için LWA refresh_token gerekli. Bu uygulama yalnızca client_id/client_secret saklar; refresh_token eklenmeden token alınamaz.'];
        }
        if ($this->clientId === '' || $this->clientSecret === '') {
            return ['ok' => false, 'error' => 'LWA client_id ve client_secret eksik.'];
        }

        $ch = curl_init(self::LWA_TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => 'LWA bağlantı hatası: ' . $err];
        }
        $decoded = json_decode($body, true);
        if ($http !== 200 || empty($decoded['access_token'])) {
            return ['ok' => false, 'error' => 'LWA token alınamadı (HTTP ' . $http . '): ' . ($decoded['error_description'] ?? $decoded['error'] ?? $body)];
        }
        return ['ok' => true, 'access_token' => $decoded['access_token']];
    }

    /**
     * Token + hafif bir listings isteği ile bağlantıyı sınar.
     */
    public function testConnection(): array
    {
        $token = $this->getAccessToken();
        if (!$token['ok']) return $token;

        $res = $this->request('GET', "/listings/2021-08-01/items/{$this->sellerId}", ['marketplaceIds' => 'A33AVAJ2PDY3EV'], $token['access_token']);
        if (isset($res['http']) && $res['http'] === 200) {
            return ['ok' => true, 'sellerName' => null];
        }
        if (isset($res['http']) && $res['http'] === 401) {
            return ['ok' => false, 'error' => 'Erişim token geçersiz (401). IAM role / yetkilendirme ayarlarını kontrol edin.'];
        }
        return ['ok' => false, 'error' => ($res['error'] ?? 'Bilinmeyen hata') . " (HTTP {$res['http']})"];
    }

    /**
     * Satıcının listeleme (ürün) kayıtlarını çeker.
     */
    public function getProducts(int $maxPage = 50): array
    {
        $token = $this->getAccessToken();
        if (!$token['ok']) return $token;

        $res = $this->request('GET', "/listings/2021-08-01/items/{$this->sellerId}", ['marketplaceIds' => 'A33AVAJ2PDY3EV'], $token['access_token']);
        if (isset($res['http']) && $res['http'] !== 200) {
            return ['ok' => false, 'error' => ($res['error'] ?? 'Ürün çekilemedi') . " (HTTP {$res['http']})"];
        }

        // Listings yanıtında item'lar farklı alanlarda gelir; isim eşlemeleri SP-API sözleşmesine göre ayarlanmalıdır.
        $items = $res['body']['items'] ?? [];
        $all = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $all[] = [
                'barcode' => $it['sku'] ?? '',
                'title' => $it['summaries'][0]['itemName'] ?? '',
                'brand' => '',
                'category' => '',
                'listPrice' => 0.0,
                'salePrice' => 0.0,
                'quantity' => 0,
                'status' => $it['status'] ?? '',
            ];
        }
        return ['ok' => true, 'products' => $all, 'count' => count($all)];
    }

    /**
     * Siparişleri çeker (Orders v0).
     */
    public function getOrders(string $status = '', string $startDate = '', string $endDate = '', int $maxPage = 10): array
    {
        $token = $this->getAccessToken();
        if (!$token['ok']) return $token;

        $params = ['MarketplaceIds' => 'A33AVAJ2PDY3EV'];
        if ($status !== '') $params['OrderStatuses'] = $status;
        if ($startDate !== '') $params['CreatedAfter'] = gmdate('c', strtotime($startDate));
        if ($endDate !== '') $params['CreatedBefore'] = gmdate('c', strtotime($endDate));

        $res = $this->request('GET', '/orders/v0/orders', $params, $token['access_token']);
        if (isset($res['http']) && $res['http'] !== 200) {
            return ['ok' => false, 'error' => ($res['error'] ?? 'Sipariş çekilemedi') . " (HTTP {$res['http']})"];
        }

        $items = $res['body']['Orders'] ?? [];
        $all = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $all[] = [
                'orderNumber' => $it['AmazonOrderId'] ?? '',
                'status' => $it['OrderStatus'] ?? '',
                'customerName' => '',
                'totalPrice' => (float)($it['OrderTotal']['Amount'] ?? 0),
                'orderDate' => $it['PurchaseDate'] ?? null,
                'lines' => [],
            ];
        }
        return ['ok' => true, 'orders' => $all, 'count' => count($all)];
    }

    /**
     * Düşük seviyeli istek. SP-API x-amz-access-token başlığı gerektirir.
     */
    private function request(string $method, string $path, array $query = [], ?string $accessToken = null): array
    {
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'x-amz-access-token: ' . ($accessToken ?? ''),
            'User-Agent: KarFiyat/1.0 (SP-API)',
        ];

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

        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['http' => 0, 'error' => 'Bağlantı hatası: ' . $err];
        }
        $decoded = json_decode($body, true);
        return ['http' => $http, 'body' => $decoded, 'raw' => $body];
    }
}
