<?php
declare(strict_types=1);

/**
 * n11 Entegrasyon İstemcisi.
 *
 * Dayandığı resmi API: n11 Açık Pazaryeri API (SOAP/XML tabanlı).
 *   - Dokümantasyon: https://dev.n11.com/ (Open API)
 *   - Servis adresi: https://api.n11.com/ws
 *   - Kimlik: appKey + appSecret (n11 satıcı paneli → Mağazam → Ayarlar'dan alınır).
 *
 * NOT: n11 API'si REST değil SOAP/XML'dir. İstek gövdesi bir XML zarfıdır ve appKey/appSecret
 * zarf içinde iletilir. Yanıt da XML'dir (GetProductListResponse vb.). Bu istemci, gerçek SOAP
 * çağrısı yapar; yanıtın alan adları n11'in güncel WSDL'ine göre doğrulanmalıdır.
 *
 * DÜRÜSTLÜK NOTU: Kimlik bilgisi olmadan test edilemez; geçersiz kimlikte n11 gerçek hata döner.
 * Uydurma ürün/sipariş listesi DÖNDÜRÜLMEZ.
 */

final class N11Client
{
    private string $sellerId;
    private string $appKey;
    private string $appSecret;
    private string $base;

    public const PROD = 'https://api.n11.com/ws';
    public const STAGE = 'https://api.n11.com/ws'; // n11 için ayrı test ucu yok; aynı adres

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false)
    {
        $this->sellerId = trim($sellerId);
        $this->appKey = trim($apiKey);
        $this->appSecret = trim($apiSecret);
        $this->base = self::PROD;
    }

    /**
     * Kimlik bilgileriyle n11'e bağlanılıp bağlanılamadığını sınar (GetProductList).
     */
    public function testConnection(): array
    {
        $res = $this->request('GetProductListRequest', ['pagingData' => ['pageSize' => 1, 'currentPage' => 0]]);
        if (isset($res['http']) && $res['http'] === 200) {
            // SOAP hataları da HTTP 200 dönebilir; yanıtta result/error kontrolü yapılır.
            $body = $res['parsed'] ?? [];
            $error = $this->extractSoapError($body);
            if ($error !== null) {
                return ['ok' => false, 'error' => 'n11 SOAP hatası: ' . $error];
            }
            return ['ok' => true, 'sellerName' => null];
        }
        return ['ok' => false, 'error' => ($res['error'] ?? 'Bilinmeyen hata') . " (HTTP {$res['http']})"];
    }

    /**
     * Satıcının ürünlerini çeker (GetProductList, sayfalı).
     */
    public function getProducts(int $maxPage = 50): array
    {
        $all = [];
        $page = 0;
        $pageSize = 100;

        for ($i = 0; $i < $maxPage; $i++) {
            $res = $this->request('GetProductListRequest', ['pagingData' => ['pageSize' => $pageSize, 'currentPage' => $page]]);
            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Ürün çekilemedi') . " (HTTP {$res['http']})"];
            }
            $body = $res['parsed'] ?? [];
            if ($this->extractSoapError($body) !== null) {
                return ['ok' => false, 'error' => 'n11 SOAP hatası: ' . $this->extractSoapError($body)];
            }

            $items = $this->findProductsInSoap($body);
            foreach ($items as $it) {
                $all[] = [
                    'barcode' => $it['productSellerCode'] ?? $it['barcode'] ?? '',
                    'title' => $it['title'] ?? '',
                    'brand' => '',
                    'category' => $it['categoryName'] ?? '',
                    'listPrice' => (float)($it['price'] ?? $it['listPrice'] ?? 0),
                    'salePrice' => (float)($it['displayPrice'] ?? $it['salePrice'] ?? 0),
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
     * Siparişleri çeker (OrderListRequest).
     */
    public function getOrders(string $status = '', string $startDate = '', string $endDate = '', int $maxPage = 10): array
    {
        $all = [];
        $page = 0;
        $pageSize = 100;

        for ($i = 0; $i < $maxPage; $i++) {
            $searchData = ['pagingData' => ['pageSize' => $pageSize, 'currentPage' => $page]];
            if ($startDate !== '') $searchData['period'] = ['startDate' => gmdate('d/m/Y H:i', strtotime($startDate))];
            if ($endDate !== '') $searchData['period']['endDate'] = gmdate('d/m/Y H:i', strtotime($endDate));
            if ($status !== '') $searchData['status'] = $status;

            $res = $this->request('OrderListRequest', $searchData);
            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Sipariş çekilemedi') . " (HTTP {$res['http']})"];
            }
            $body = $res['parsed'] ?? [];
            if ($this->extractSoapError($body) !== null) {
                return ['ok' => false, 'error' => 'n11 SOAP hatası: ' . $this->extractSoapError($body)];
            }

            $items = $this->findOrdersInSoap($body);
            foreach ($items as $it) {
                $all[] = [
                    'orderNumber' => $it['id'] ?? '',
                    'status' => $it['status'] ?? '',
                    'customerName' => '',
                    'totalPrice' => (float)($it['totalAmount'] ?? 0),
                    'orderDate' => null,
                    'lines' => [],
                ];
            }

            $page++;
            if (count($items) < $pageSize) break;
        }

        return ['ok' => true, 'orders' => $all, 'count' => count($all)];
    }

    /**
     * SOAP isteği: XML zarfı oluşturur, POST eder, yanıtı ayrıştırır.
     */
    private function request(string $action, array $payload): array
    {
        $auth = '<auth><appKey>' . $this->xml($this->appKey) . '</appKey><appSecret>' . $this->xml($this->appSecret) . '</appSecret></auth>';
        $bodyXml = $this->toXml($payload);
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:sch="http://www.n11.com/ws/schemas">'
            . '<soapenv:Header/><soapenv:Body><sch:' . $action . '>'
            . $auth . $bodyXml
            . '</sch:' . $action . '></soapenv:Body></soapenv:Envelope>';

        $ch = curl_init($this->base);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['http' => 0, 'error' => 'Bağlantı hatası: ' . $err];
        }

        $parsed = null;
        $xml = @simplexml_load_string($body);
        if ($xml !== false) {
            $parsed = json_decode(json_encode($xml), true);
        }
        return ['http' => $http, 'parsed' => $parsed, 'raw' => $body];
    }

    private function extractSoapError(array $body): ?string
    {
        // SOAP Fault veya n11 result.status != success durumları.
        if (isset($body['Body']['Fault']['faultstring'])) {
            return (string)$body['Body']['Fault']['faultstring'];
        }
        // result.status == "success" değilse hata metnini yakalamaya çalış.
        $result = $body['Body']['GetProductListResponse']['result'] ?? $body['Body']['OrderListResponse']['result'] ?? null;
        if (is_array($result) && isset($result['status']) && $result['status'] !== 'success') {
            return (string)($result['errorMessage'] ?? $result['status']);
        }
        return null;
    }

    private function findProductsInSoap(array $body): array
    {
        $products = $body['Body']['GetProductListResponse']['productList']['product'] ?? [];
        if (isset($products['productSellerCode']) || isset($products['title'])) {
            return [$products]; // tek ürün nesnesi diziye sarılır
        }
        return is_array($products) ? array_values($products) : [];
    }

    private function findOrdersInSoap(array $body): array
    {
        $orders = $body['Body']['OrderListResponse']['orderList']['order'] ?? [];
        if (isset($orders['id'])) {
            return [$orders];
        }
        return is_array($orders) ? array_values($orders) : [];
    }

    private function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Basit array -> XML dönüşümü (n11 zarf alanları için yeterli). */
    private function toXml(array $data): string
    {
        $out = '';
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out .= '<' . $k . '>' . $this->toXml($v) . '</' . $k . '>';
            } else {
                $out .= '<' . $k . '>' . $this->xml((string)$v) . '</' . $k . '>';
            }
        }
        return $out;
    }
}
