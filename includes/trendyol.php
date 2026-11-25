<?php
declare(strict_types=1);

/**
 * Trendyol Entegrasyon Servisi — satıcının kendi API bilgileriyle mağazasına bağlanır.
 *
 * Kimlik bilgileri KULLANICININ kendisi girer (satıcı ID, API key, API secret).
 * Bu sınıf yalnızca bu bilgileri alıp Trendyol Partner API'ye istek atar.
 * Hiçbir API anahtarı bu projede sunucu tarafında kalıcı saklanmaz — yalnızca
 * kullanıcı hesabına şifreli kaydedilir ve istek anında kullanılır.
 */

final class TrendyolClient
{
    private string $sellerId;
    private string $apiKey;
    private string $apiSecret;
    private string $base;

    // Üretim ortamı (varsayılan). Test için stageapigw kullanılabilir.
    public const PROD = 'https://apigw.trendyol.com';
    public const STAGE = 'https://stageapigw.trendyol.com';

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false)
    {
        $this->sellerId = trim($sellerId);
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->base = $stage ? self::STAGE : self::PROD;
    }

    /**
     * Kullanıcının kimlik bilgileriyle Trendyol'a bağlanıp bağlanamadığını test eder.
     * Başarılıysa ['ok'=>true, 'sellerName'=>...] döner; değilse ['ok'=>false, 'error'=>...].
     */
    public function testConnection(): array
    {
        // 1 adet ürün isteyip auth'un doğruluğunu sınamak en ucuz yoldur.
        $res = $this->request('GET', "/integration/product/sellers/{$this->sellerId}/products/approved", ['size' => '1']);
        if (isset($res['http']) && $res['http'] === 200) {
            return ['ok' => true, 'sellerName' => null];
        }

        if (isset($res['http']) && $res['http'] === 401) {
            // Ortam uyuşmazlığı olabilir (test kimliği üretimde / üretim kimliği testte).
            // Diğer ortamı da dene; o tutuyorsa nedeni budur.
            $otherBase = ($this->base === self::PROD) ? self::STAGE : self::PROD;
            $saved = $this->base;
            $this->base = $otherBase;
            $res2 = $this->request('GET', "/integration/product/sellers/{$this->sellerId}/products/approved", ['size' => '1']);
            $this->base = $saved;

            if (isset($res2['http']) && $res2['http'] === 200) {
                if ($otherBase === self::STAGE) {
                    return ['ok' => false, 'error' => "Kimlik bilgileriniz DOĞRU ama bu bir TEST (stage) kimliği. Bağlantı formundaki \"Test ortamı\" kutusunu İŞARETLEYİN."];
                }
                return ['ok' => false, 'error' => "Kimlik bilgileriniz DOĞRU ama bu bir ÜRETİM kimliği. \"Test ortamı\" kutusunu KALDIRIN."];
            }

            $detail = '';
            if (!empty($res['raw'])) {
                $detail = ' Yanıt: ' . mb_substr(trim((string)$res['raw']), 0, 400);
            }
            return ['ok' => false, 'error' => 'Kimlik bilgileri hatalı (401). Satıcı ID / API key / API secret değerlerini kontrol edin (her iki ortam da reddetti).' . $detail];
        }
        return ['ok' => false, 'error' => ($res['error'] ?? 'Bilinmeyen hata') . " (HTTP {$res['http']})"];
    }

    /** Convert V2 content/variants to the existing barcode-based product model. */
    public static function normalizeApprovedContent(array $content): array
    {
        $out = [];
        foreach ($content as $item) {
            if (!is_array($item) || !isset($item['variants']) || !is_array($item['variants'])) {
                throw new UnexpectedValueException('Trendyol V2 ürün varyantları eksik.');
            }
            foreach ($item['variants'] as $v) {
                $barcode = trim((string)($v['barcode'] ?? ''));
                if ($barcode === '') throw new UnexpectedValueException('Trendyol V2 barkodu eksik.');
                $out[] = [
                    'barcode' => $barcode,
                    'title' => (string)($item['title'] ?? $barcode),
                    'brand' => (string)($item['brand']['name'] ?? ''),
                    'category' => (string)($item['category']['name'] ?? ''),
                    'listPrice' => isset($v['price']['listPrice']) ? (float)$v['price']['listPrice'] : null,
                    'salePrice' => isset($v['price']['salePrice']) ? (float)$v['price']['salePrice'] : null,
                    'quantity' => isset($v['stock']['quantity']) ? (int)$v['stock']['quantity'] : null,
                    'commission' => isset($v['commission']) ? (float)$v['commission'] : null,
                    'vatRate' => isset($v['vatRate']) ? (float)$v['vatRate'] : null,
                    'status' => !empty($v['onSale']) ? 'onSale' : 'notOnSale',
                    'approved' => true,
                    'productUrl' => (string)($v['productUrl'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Approved product V2. Offset pagination up to 10,000 content records,
     * followed by nextPageToken. Never report a truncated fetch as success.
     * Unapproved products are intentionally out of scope for this sales catalog.
     */
    public function getProducts(int $maxPage = 500, string $approvalStatus = '', int $startDateMs = 0, string $dateQueryType = ''): array
    {
        if ($approvalStatus !== '' && $approvalStatus !== 'approved') {
            return ['ok' => false, 'error' => 'Bu servis yalnızca onaylı ürünleri destekler.'];
        }
        $size = 100;
        $path = "/integration/product/sellers/{$this->sellerId}/products/approved";
        $base = ['size' => (string)$size];
        if ($startDateMs > 0) {
            $base['startDate'] = (string)$startDateMs;
            $base['dateQueryType'] = $dateQueryType === 'CONTENT_MODIFIED_DATE'
                ? 'CONTENT_MODIFIED_DATE' : 'VARIANT_MODIFIED_DATE';
        }
        $all = [];
        $token = null;
        $seenTokens = [];
        $contentCount = 0;
        $total = null;
        for ($page = 0; $page < $maxPage; $page++) {
            $params = $base;
            if ($token === null) $params['page'] = (string)$page;
            else $params['nextPageToken'] = $token;
            $res = $this->request('GET', $path, $params);
            $body = $res['body'] ?? null;
            if (($res['http'] ?? 0) !== 200 || !is_array($body) || !is_array($body['content'] ?? null)) {
                return ['ok' => false, 'error' => 'Ürün V2 sayfası alınamadı (HTTP ' . ($res['http'] ?? 0) . ').'];
            }
            if ($total === null) {
                if (!isset($body['totalElements']) || !is_numeric($body['totalElements'])) {
                    return ['ok' => false, 'error' => 'Ürün V2 toplam kayıt sayısı eksik.'];
                }
                $total = (int)$body['totalElements'];
            }
            $items = $body['content'];
            try {
                foreach (self::normalizeApprovedContent($items) as $p) $all[$p['barcode']] = $p;
            } catch (UnexpectedValueException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            $contentCount += count($items);
            if ($contentCount >= $total && ($token === null || empty($body['nextPageToken']) || count($items) < $size)) {
                return ['ok' => true, 'products' => array_values($all), 'count' => count($all)];
            }
            if (!$items) return ['ok' => false, 'error' => 'Ürün V2 eksik kayıt döndürdü.'];
            if ($token !== null || ($page + 1) * $size >= 10000) {
                $next = (string)($body['nextPageToken'] ?? '');
                if ($next === '' || isset($seenTokens[$next])) {
                    return ['ok' => false, 'error' => 'Ürün V2 sayfalama tokenı eksik veya tekrar ediyor.'];
                }
                $seenTokens[$next] = true;
                $token = $next;
            }
        }
        return ['ok' => false, 'error' => 'Ürün V2 sayfa sınırına ulaşıldı; senkron tamamlanmadı.'];
    }

    public function getProduct(string $barcode): array
    {
        $res = $this->request('GET', "/integration/product/sellers/{$this->sellerId}/products/approved",
            ['barcode' => $barcode, 'size' => '100']);
        if (($res['http'] ?? 0) !== 200 || !is_array($res['body']['content'] ?? null)) {
            return ['ok' => false, 'error' => 'Ürün V2 sorgusu başarısız.'];
        }
        try {
            foreach (self::normalizeApprovedContent($res['body']['content']) as $p) {
                if ($p['barcode'] === $barcode) return ['ok' => true, 'product' => $p];
            }
        } catch (UnexpectedValueException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => false, 'error' => 'Barkod bulunamadı.'];
    }

    /**
     * Siparişleri çeker — RESMİ /integration/order/sellers/{sellerId}/orders ucu.
     * (Eski /shipment-packages ucu deprecate oldu; 556 "Service Unavailable" dönüyor.)
     * @param string $status Created | Picking | Invoiced | Shipped | Cancelled | Delivered | UnDelivered | ...
     */
    public function getOrders(string $status = '', string $startDate = '', string $endDate = '', int $maxPage = 50): array
    {
        $maxPage = max(1, min(50, $maxPage));
        $all = [];
        $seen = [];
        $size = 200;

        // Zaman aralığı (saniye). Trendyol sipariş ucu ~15 günlük pencereyle sınırlar;
        // daha geniş aralık istendiğinde 14 günlük parçalara bölüp birleştiriyoruz.
        $endTs = ($endDate !== '') ? strtotime($endDate) : time();
        $startTs = ($startDate !== '') ? strtotime($startDate) : ($endTs - 7 * 86400);
        if ($startTs === false || $endTs === false || $startTs >= $endTs) {
            return ['ok' => false, 'error' => 'Geçersiz sipariş tarih aralığı.'];
        }

        $chunk = 14 * 86400;
        for ($cs = $startTs; $cs < $endTs; $cs += $chunk) {
            $ce = min($cs + $chunk, $endTs);
            $page = 0;
            while (true) {
                $params = [
                    'page' => (string)$page,
                    'size' => (string)$size,
                    'startDate' => (string)($cs * 1000),
                    'endDate' => (string)($ce * 1000),
                ];
                if ($status !== '') $params['status'] = $status;

                $res = $this->request('GET', "/integration/order/sellers/{$this->sellerId}/v2/orders", $params);

                if (isset($res['http']) && $res['http'] !== 200) {
                    return ['ok' => false, 'error' => ($res['error'] ?? 'Sipariş çekilemedi') . " (HTTP {$res['http']})"];
                }

                if (!is_array($res['body']['content'] ?? null) || !isset($res['body']['totalPages'], $res['body']['totalElements'])) {
                    return ['ok' => false, 'error' => 'Sipariş V2 yanıtı eksik.'];
                }
                if ((int)$res['body']['totalElements'] > 10000 || (int)$res['body']['totalPages'] > $maxPage) {
                    return ['ok' => false, 'error' => 'Sipariş V2 kayıt sınırı aşıldı; tarih aralığını daraltın.'];
                }
                $items = $res['body']['content'];
                if (!$items && $page < (int)$res['body']['totalPages']) {
                    return ['ok' => false, 'error' => 'Sipariş V2 eksik sayfa döndürdü.'];
                }
                foreach ($items as $it) {
                    $pkg = (string)($it['shipmentPackageId'] ?? ($it['id'] ?? ''));
                    if ($pkg === '') return ['ok' => false, 'error' => 'Sipariş paket kimliği eksik.'];
                    if (isset($seen[$pkg])) continue; // parça sınırında tekrar etmesin
                    $seen[$pkg] = true;

                    $lines = [];
                    foreach (($it['lines'] ?? []) as $ol) {
                        $lines[] = [
                            'barcode' => $ol['sku'] ?? '',
                            'productName' => $ol['productName'] ?? '',
                            'quantity' => (int)($ol['quantity'] ?? 0),
                            'price' => (float)($ol['amount'] ?? ($ol['lineGrossAmount'] ?? 0)) - (float)($ol['lineTotalDiscount'] ?? ($ol['discount'] ?? 0)),
                            'merchantSku' => $ol['merchantSku'] ?? '',
                        ];
                    }
                    $all[] = [
                        'packageNumber' => $pkg,
                        'orderNumber' => $it['orderNumber'] ?? '',
                        'status' => $it['status'] ?? ($it['shipmentPackageStatus'] ?? ''),
                        'customerName' => trim(($it['customerFirstName'] ?? '') . ' ' . ($it['customerLastName'] ?? '')),
                        'totalPrice' => (float)($it['totalPrice'] ?? ($it['grossAmount'] ?? 0)),
                        'cargoCompany' => $it['cargoProviderName'] ?? '',
                        'lines' => $lines,
                        'orderDate' => $it['orderDate'] ?? null,
                    ];
                }

                $totalPages = (int)($res['body']['totalPages'] ?? 0);
                $page++;
                if ($page >= $totalPages || $page >= $maxPage || count($items) === 0) {
                    break;
                }
            }
        }

        return ['ok' => true, 'orders' => $all, 'count' => count($all)];
    }

    /**
     * Trendyol komisyon oranlarını kategori bazında çeker.
     * (Sipariş/komisyon dökümü için; kategori ID -> oran eşleşmesi)
     */
    public function getCategories(): array
    {
        $res = $this->request('GET', '/integration/product/product-categories');
        if (isset($res['http']) && $res['http'] !== 200) {
            return ['ok' => false, 'error' => ($res['error'] ?? 'Kategori çekilemedi')];
        }
        return ['ok' => true, 'categories' => $res['body'] ?? []];
    }

    /**
     * Cari Hesap Ekstresi (settlements) — RESMİ Trendyol finans kayıtları.
     * Sipariş bazında gerçek komisyon oranı + tutarı, hakediş (sellerRevenue),
     * teslimat ücreti (DeliveryFee), iade/indirim/kupon detaylarını verir.
     * Kaynak: https://developers.trendyol.com/docs/cari-hesap-ekstresi-entegrasyonu
     *
     * @param string $transactionType Sale|Return|Discount|Coupon|DeliveryFee|... (tek tip)
     * @param string $startDate      Y-m-d (işlem tarihi; paymentDate yoksa zorunlu, aralık ≤15 gün)
     * @param string $endDate        Y-m-d
     */
    public function getSettlements(string $transactionType, string $startDate = '', string $endDate = '', int $maxPage = 10): array
    {
        $all = [];
        $page = 0;
        $size = 500;

        while (true) {
            $params = ['page' => (string)$page, 'size' => (string)$size, 'transactionType' => $transactionType];
            if ($startDate !== '') $params['startDate'] = strtotime($startDate) * 1000;
            if ($endDate !== '') $params['endDate'] = strtotime($endDate) * 1000;

            $res = $this->request('GET', "/integration/finance/che/sellers/{$this->sellerId}/settlements", $params);

            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Cari hesap ekstresi çekilemedi') . " (HTTP {$res['http']})"];
            }

            $items = $res['body']['content'] ?? [];
            foreach ($items as $it) {
                $all[] = [
                    'barcode' => (string)($it['barcode'] ?? ''),
                    'transactionType' => (string)($it['transactionType'] ?? ''),
                    'commissionRate' => (float)($it['commissionRate'] ?? 0),
                    'commissionAmount' => (float)($it['commissionAmount'] ?? 0),
                    'sellerRevenue' => (float)($it['sellerRevenue'] ?? 0),
                    'debt' => (float)($it['debt'] ?? 0),
                    'credit' => (float)($it['credit'] ?? 0),
                    'orderNumber' => (string)($it['orderNumber'] ?? ''),
                    'shipmentPackageId' => (string)($it['shipmentPackageId'] ?? ''),
                    'paymentPeriod' => (int)($it['paymentPeriod'] ?? 0),
                    'transactionDate' => $it['transactionDate'] ?? null,
                    'orderDate' => $it['orderDate'] ?? null,
                ];
            }

            $totalPages = (int)($res['body']['totalPages'] ?? 0);
            $page++;
            if ($page >= $totalPages || $page >= $maxPage || count($items) === 0) {
                break;
            }
        }

        return ['ok' => true, 'settlements' => $all, 'count' => count($all)];
    }

    /**
     * Düşük seviyeli HTTP isteği. Trendyol Basic auth + User-Agent zorunlu.
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

        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $errMsg = $err;
            if (stripos($err, 'SSL') !== false || stripos($err, 'certificate') !== false) {
                $errMsg = 'SSL sertifika doğrulaması başarısız — sunucuda CA sertifika demeti (cacert.pem) eksik olabilir. cURL için curl.cainfo ayarını yapın.';
            }
            return ['http' => 0, 'error' => 'Bağlantı hatası: ' . $errMsg];
        }

        $decoded = json_decode($body, true);
        return ['http' => $http, 'body' => $decoded, 'raw' => $body];
    }

    /**
     * Birden çok GET isteğini curl_multi ile paralel çeker (girdi sırasında döner).
     * Büyük kataloglarda sayfalama hızını 8-10x artırır.
     * $requests: [ ['method','path',query], ... ]
     */
    private function requestParallel(array $requests): array
    {
        $auth = base64_encode($this->apiKey . ':' . $this->apiSecret);
        $mh = curl_multi_init();
        $handles = [];

        foreach ($requests as $i => $req) {
            [$method, $path, $query] = $req;
            $url = $this->base . $path;
            if ($query) $url .= '?' . http_build_query($query);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . $auth,
                    'User-Agent: ' . $this->sellerId . ' - SelfIntegration',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
            ]);
            if ($method === 'POST' || $method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                usleep(50000); // 50ms
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $results[$i] = [
                'http' => $http,
                'body' => ($body === false) ? null : json_decode($body, true),
                'raw' => $body,
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        ksort($results);
        return $results;
    }

    /**
     * Fiyat ve stok güncelleme (toplu). $items: [['barcode'=>..,'quantity'=>..,'salePrice'=>..,'listPrice'=>..], ...]
     * Trendyol bu uçla ürün fiyatını satıcı panelinden uzaktan yönetmeyi sağlar.
     */
    public function updatePriceAndInventory(array $items): array
    {
        $body = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = $this->request('POST', "/integration/inventory/sellers/{$this->sellerId}/products/price-and-inventory", [], $body);
        if (isset($res['http']) && $res['http'] !== 200) {
            return ['ok' => false, 'error' => ($res['error'] ?? 'Fiyat güncellenemedi') . " (HTTP {$res['http']})"];
        }
        return ['ok' => true];
    }
}
