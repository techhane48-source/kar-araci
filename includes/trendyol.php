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
        $res = $this->request('GET', "/integration/product/sellers/{$this->sellerId}/products", ['size' => '1']);
        if (isset($res['http']) && $res['http'] === 200) {
            return ['ok' => true, 'sellerName' => null];
        }

        if (isset($res['http']) && $res['http'] === 401) {
            // Ortam uyuşmazlığı olabilir (test kimliği üretimde / üretim kimliği testte).
            // Diğer ortamı da dene; o tutuyorsa nedeni budur.
            $otherBase = ($this->base === self::PROD) ? self::STAGE : self::PROD;
            $saved = $this->base;
            $this->base = $otherBase;
            $res2 = $this->request('GET', "/integration/product/sellers/{$this->sellerId}/products", ['size' => '1']);
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

    /**
     * Satıcının onaylı/onay bekleyen tüm ürünlerini çeker (sayfalı).
     * Her ürün: barcode, title, brand, categoryName, price, salePrice, quantity, status, productUrl.
     *
     * @param int $maxPage Maksimum sayfa (varsayılan 500 = 100.000 ürün; büyük kataloglar için)
     */
    public function getProducts(int $maxPage = 500, string $approvalStatus = '', int $startDateMs = 0, string $dateQueryType = ''): array
    {
        $size = 200; // Trendyol max page size

        // Ortak filtre parametreleri (artımlı senkron için startDate + dateQueryType)
        $base = ['size' => (string)$size];
        if ($approvalStatus !== '') $base['approvalStatus'] = $approvalStatus;
        if ($startDateMs > 0) $base['startDate'] = (string)$startDateMs;
        if ($dateQueryType !== '') $base['dateQueryType'] = $dateQueryType;

        // 1) Toplam ürün sayısını tek istekle öğren (kaç sayfa var bilmek için)
        $probeParams = $base;
        $probeParams['page'] = '0';
        $probeParams['size'] = '1';
        $probe = $this->request('GET', "/integration/product/sellers/{$this->sellerId}/products", $probeParams);
        if (isset($probe['http']) && $probe['http'] !== 200) {
            return ['ok' => false, 'error' => ($probe['error'] ?? 'Ürün çekilemedi') . " (HTTP {$probe['http']})"];
        }
        $total = (int)($probe['body']['totalElements'] ?? 0);
        $totalPages = (int)ceil($total / $size);
        if ($totalPages <= 0) $totalPages = 1;
        $totalPages = min($totalPages, $maxPage);

        $all = [];
        $failedPages = 0;
        $batch = 10; // aynı anda çekilen sayfa sayısı (Trendyol rate-limit'e karşı makul)

        for ($start = 0; $start < $totalPages; $start += $batch) {
            $requests = [];
            for ($p = $start; $p < min($start + $batch, $totalPages); $p++) {
                $params = $base;
                $params['page'] = (string)$p;
                $requests[] = ['GET', "/integration/product/sellers/{$this->sellerId}/products", $params];
            }

            $results = $this->requestParallel($requests);
            foreach ($results as $i => $res) {
                if (!isset($res['http']) || $res['http'] !== 200) {
                    // Hız sınırı / geçici hata: bir kez, kısa beklemeyle tekrar dene.
                    usleep(1500000);
                    [$m, $path, $q] = $requests[$i];
                    $res = $this->request($m, $path, $q);
                }
                if (!isset($res['http']) || $res['http'] !== 200) { $failedPages++; continue; }
                $items = $res['body']['content'] ?? [];
                foreach ($items as $it) {
                    $all[] = [
                        'barcode' => $it['barcode'] ?? '',
                        'title' => $it['title'] ?? '',
                        'brand' => $it['brand'] ?? ($it['brandName'] ?? ''),
                        'category' => $it['categoryName'] ?? '',
                        'listPrice' => (float)($it['listPrice'] ?? 0),
                        'salePrice' => (float)($it['salePrice'] ?? 0),
                        'quantity' => (int)($it['quantity'] ?? 0),
                        'status' => $it['status'] ?? '',
                        'approved' => ($it['approved'] ?? false),
                        'productUrl' => $it['productUrl'] ?? '',
                    ];
                }
            }
        }

        return ['ok' => true, 'products' => $all, 'count' => count($all), 'failed_pages' => $failedPages];
    }

    /**
     * Tek bir ürünün detayını barcode ile çeker.
     */
    public function getProduct(string $barcode): array
    {
        $res = $this->request('GET', "/integration/product/sellers/{$this->sellerId}/product/" . rawurlencode($barcode));
        if (isset($res['http']) && $res['http'] !== 200) {
            return ['ok' => false, 'error' => ($res['error'] ?? 'Ürün bulunamadı') . " (HTTP {$res['http']})"];
        }
        return ['ok' => true, 'product' => $res['body']];
    }

    /**
     * Siparişleri çeker — RESMİ /integration/order/sellers/{sellerId}/orders ucu.
     * (Eski /shipment-packages ucu deprecate oldu; 556 "Service Unavailable" dönüyor.)
     * @param string $status Created | Picking | Invoiced | Shipped | Cancelled | Delivered | UnDelivered | ...
     */
    public function getOrders(string $status = '', string $startDate = '', string $endDate = '', int $maxPage = 50): array
    {
        $all = [];
        $seen = [];
        $truncated = false;
        $size = 200;

        // Zaman aralığı (saniye). Trendyol sipariş ucu ~15 günlük pencereyle sınırlar;
        // daha geniş aralık istendiğinde 14 günlük parçalara bölüp birleştiriyoruz.
        $endTs = ($endDate !== '') ? strtotime($endDate) : time();
        $endTs += 3600; // saat kayması buffer'ı (orderDate hafif "gelecekte" görünebilir)
        $startTs = ($startDate !== '') ? strtotime($startDate) : ($endTs - 16 * 86400);

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

                $res = $this->request('GET', "/integration/order/sellers/{$this->sellerId}/orders", $params);

                if (isset($res['http']) && $res['http'] !== 200) {
                    return ['ok' => false, 'error' => ($res['error'] ?? 'Sipariş çekilemedi') . " (HTTP {$res['http']})"];
                }

                $items = $res['body']['content'] ?? [];
                foreach ($items as $it) {
                    $pkg = (string)($it['shipmentPackageId'] ?? ($it['id'] ?? ''));
                    if ($pkg !== '' && isset($seen[$pkg])) continue; // parça sınırında tekrar etmesin
                    $seen[$pkg] = true;

                    $lines = [];
                    foreach (($it['lines'] ?? []) as $ol) {
                        // Birim fiyatlar. Satıcının cebinden çıkan indirim yalnızca satıcı indirimidir;
                        // Trendyol'un karşıladığı indirim (tyDiscount) satıcının hasılatını düşürmez.
                        $gross = (float)($ol['lineGrossAmount'] ?? ($ol['amount'] ?? 0));
                        $sellerDisc = (float)($ol['lineSellerDiscount'] ?? ($ol['discount'] ?? 0));
                        $lines[] = [
                            'barcode' => (string)($ol['barcode'] ?? ($ol['sku'] ?? '')),
                            'productName' => $ol['productName'] ?? '',
                            'quantity' => (int)($ol['quantity'] ?? 0),
                            'price' => $gross - $sellerDisc,
                            'vatRate' => isset($ol['vatRate']) ? (float)$ol['vatRate'] : null,
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
                if ($page >= $totalPages || count($items) === 0) {
                    break;
                }
                if ($page >= $maxPage) {
                    $truncated = true;   // sınıra takıldı: sessizce kesme, çağırana bildir
                    break;
                }
            }
        }

        return ['ok' => true, 'orders' => $all, 'count' => count($all), 'truncated' => $truncated];
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
