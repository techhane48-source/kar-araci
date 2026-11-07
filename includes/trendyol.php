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
    private $transport;

    // Üretim ortamı (varsayılan). Test için stageapigw kullanılabilir.
    public const PROD = 'https://apigw.trendyol.com';
    public const STAGE = 'https://stageapigw.trendyol.com';

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false, ?callable $transport = null)
    {
        $this->transport = $transport;
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
        $size=200;$base=['size'=>(string)$size];
        if ($approvalStatus!=='') $base['approvalStatus']=$approvalStatus;
        if ($startDateMs>0) $base['startDate']=(string)$startDateMs;
        if ($dateQueryType!=='') $base['dateQueryType']=$dateQueryType;
        $path="/integration/product/sellers/{$this->sellerId}/products";
        $probe=$this->page($this->request('GET',$path,array_replace($base,['page'=>'0','size'=>'1'])),0,PHP_INT_MAX,'Ürün');
        if (!$probe['ok']) return $probe;
        if ($probe['total']===null) return $this->incomplete('Ürün toplam sayısı API yanıtında bulunamadı.');
        $total=$probe['total'];$pages=max(1,(int)ceil($total/$size));
        if ($pages>$maxPage) return $this->incomplete('Ürün sayfa sınırı aşıldı; eksik katalog kaydedilmedi.');
        $all=[];
        for ($start=0;$start<$pages;$start+=10) {
            $requests=[];
            for ($page=$start;$page<min($start+10,$pages);$page++) $requests[]=['GET',$path,$base+['page'=>(string)$page]];
            $results=$this->requestParallel($requests);
            if (count($results)!==count($requests)) return $this->incomplete('Ürün sayfalarının bir kısmı alınamadı.');
            foreach ($results as $offset=>$result) {
                $info=$this->page($result,$start+$offset,$maxPage,'Ürün');
                if (!$info['ok']) return $info;
                if ($total>0 && $info['pages']!==$pages) return $this->incomplete('Ürün sayfa sayısı değişti; tekrar deneyin.');
                if ($info['total']!==null && $info['total']!==$total) return $this->incomplete('Katalog çekilirken toplam ürün sayısı değişti; tekrar deneyin.');
                foreach ($info['items'] as $it) {
                    if (!is_array($it)) return $this->incomplete('Ürün yanıtı geçersiz.');
                    $barcode=(string)($it['barcode']??'');
                    if ($barcode==='' || isset($all[$barcode])) return $this->incomplete('Ürün sayfalarında eksik veya tekrarlanan barkod var.');
                    $all[$barcode]=[
                        'barcode'=>$barcode,'title'=>$it['title']??'', 'brand'=>$it['brand']??$it['brandName']??'',
                        'category'=>$it['categoryName']??'', 'listPrice'=>$it['listPrice']??null,
                        'salePrice'=>$it['salePrice']??null, 'quantity'=>$it['quantity']??null,
                        'status'=>$it['status']??'', 'approved'=>$it['approved']??false, 'productUrl'=>$it['productUrl']??'',
                    ];
                }
            }
        }
        if (count($all)!==$total) return $this->incomplete('API ürün sayısı ile alınan kayıt sayısı uyuşmuyor.');
        return ['ok'=>true,'complete'=>true,'products'=>array_values($all),'count'=>count($all)];
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
    public function getOrders(string $status = '', string $startDate = '', string $endDate = '', int $maxPage = 1000): array
    {
        $all = [];
        $size = 200;

        // Zaman aralığı (saniye). Trendyol sipariş ucu ~15 günlük pencereyle sınırlar;
        // daha geniş aralık istendiğinde 14 günlük parçalara bölüp birleştiriyoruz.
        $endTs = ($endDate !== '') ? strtotime($endDate) : time();
        $startTs = ($startDate !== '') ? strtotime($startDate) : ($endTs - 16 * 86400);

        if ($startTs===false || $endTs===false || $startTs >= $endTs) return $this->incomplete('Geçersiz sipariş tarih aralığı.');
        $chunk = 14 * 86400;
        for ($cs = $startTs; $cs < $endTs; $cs += $chunk) {
            $ce = min($cs + $chunk, $endTs);
            $page = 0; $expectedPages=null; $expectedTotal=null; $received=0; $chunkSeen=[];
            while (true) {
                $params = [
                    'page' => (string)$page,
                    'size' => (string)$size,
                    'startDate' => (string)($cs * 1000),
                    'endDate' => (string)($ce * 1000),
                ];
                if ($status !== '') $params['status'] = $status;

                $res = $this->request('GET', "/integration/order/sellers/{$this->sellerId}/orders", $params);

                $info=$this->page($res,$page,$maxPage,'Sipariş');
                if (!$info['ok']) return $info;
                $items=$info['items'];
                if ($expectedPages!==null && $expectedPages!==$info['pages']) return $this->incomplete('Sipariş sayfa sayısı işlem sırasında değişti.');
                $expectedPages=$info['pages'];
                if ($expectedTotal!==null && $info['total']!==null && $expectedTotal!==$info['total']) return $this->incomplete('Sipariş toplamı işlem sırasında değişti.');
                $expectedTotal=$info['total'];
                $received+=count($items);
                foreach ($items as $it) {
                    $pkg = (string)($it['shipmentPackageId'] ?? ($it['id'] ?? ''));
                    if (isset($chunkSeen[$pkg])) return $this->incomplete('Sipariş sayfalarında tekrarlanan paket var.');
                    $chunkSeen[$pkg]=true;
                    if ($pkg==='') return $this->incomplete('Sipariş paket kimliği eksik.');

                    $lines = [];
                    foreach (($it['lines'] ?? []) as $ol) {
                        $lines[] = [
                            'barcode' => $ol['barcode'] ?? ($ol['sku'] ?? ''),
                            'productName' => $ol['productName'] ?? '',
                            'quantity' => (int)($ol['quantity'] ?? 0),
                            'price' => (float)($ol['amount'] ?? ($ol['lineGrossAmount'] ?? 0)) - (float)($ol['lineTotalDiscount'] ?? ($ol['discount'] ?? 0)),
                            'merchantSku' => $ol['merchantSku'] ?? '',
                        ];
                    }
                    $all[$pkg] = [
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

                $page++;
                if ($page >= $info['pages']) break;
            }
            if ($expectedTotal!==null && $received!==$expectedTotal) return $this->incomplete('Sipariş sayısı alınan kayıtlarla uyuşmuyor.');
        }

        return ['ok'=>true,'complete'=>true,'orders'=>array_values($all),'count'=>count($all)];
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
    public function getSettlements(string $transactionType, string $startDate = '', string $endDate = '', int $maxPage = 1000): array
    {
        $all = [];
        $page = 0;
        $size = 500; $expectedPages=null; $expectedTotal=null; $seenIds=[];

        while (true) {
            $params = ['page' => (string)$page, 'size' => (string)$size, 'transactionType' => $transactionType];
            if ($startDate !== '') $params['startDate'] = strtotime($startDate) * 1000;
            if ($endDate !== '') $params['endDate'] = strtotime($endDate) * 1000;

            $res = $this->request('GET', "/integration/finance/che/sellers/{$this->sellerId}/settlements", $params);

            $info=$this->page($res,$page,$maxPage,'Finans');
            if (!$info['ok']) return $info;
            $items=$info['items'];
            if ($expectedPages!==null && $expectedPages!==$info['pages']) return $this->incomplete('Finans sayfa sayısı işlem sırasında değişti.');
            $expectedPages=$info['pages'];
            if ($expectedTotal!==null && $info['total']!==null && $expectedTotal!==$info['total']) return $this->incomplete('Finans toplamı işlem sırasında değişti.');
            $expectedTotal=$info['total'];
            foreach ($items as $it) {
                if (!is_array($it) || !isset($it['id']) || !is_scalar($it['id']) || trim((string)$it['id'])==='') return $this->incomplete('Finans işlem kimliği eksik.');
                if (isset($seenIds[(string)$it['id']])) return $this->incomplete('Finans sayfalarında tekrarlanan işlem var.');
                $seenIds[(string)$it['id']]=true;
                if (($it['transactionType']??'')==='Sale' && (!isset($it['commissionRate']) || !is_numeric($it['commissionRate']) || (float)$it['commissionRate']<0 || (float)$it['commissionRate']>100)) return $this->incomplete('Satış komisyon oranı eksik veya geçersiz.');
                $all[] = [
                    'providerId' => (string)$it['id'],
                    'barcode' => (string)($it['barcode'] ?? ''),
                    'transactionType' => (string)($it['transactionType'] ?? ''),
                    'commissionRate' => $it['commissionRate'] ?? 0,
                    'commissionAmount' => $it['commissionAmount'] ?? 0,
                    'sellerRevenue' => $it['sellerRevenue'] ?? 0,
                    'debt' => $it['debt'] ?? 0,
                    'credit' => $it['credit'] ?? 0,
                    'orderNumber' => (string)($it['orderNumber'] ?? ''),
                    'shipmentPackageId' => (string)($it['shipmentPackageId'] ?? ''),
                    'paymentPeriod' => (int)($it['paymentPeriod'] ?? 0),
                    'transactionDate' => $it['transactionDate'] ?? null,
                    'orderDate' => $it['orderDate'] ?? null,
                ];
            }

            $page++;
            if ($page >= $info['pages']) break;
        }

        if ($expectedTotal!==null && count($all)!==$expectedTotal) return $this->incomplete('Finans kayıt sayısı uyuşmuyor.');
        return ['ok'=>true,'complete'=>true,'settlements'=>$all,'count'=>count($all)];
    }

    private function incomplete(string $error): array
    {
        return ['ok'=>false,'complete'=>false,'error'=>$error];
    }

    private function page(array $response,int $number,int $limit,string $label): array
    {
        if ($limit<1) return $this->incomplete('Sayfa sınırı en az 1 olmalı.');
        if (($response['http']??0)!==200) return $this->incomplete($label.' sayfası alınamadı (HTTP '.($response['http']??0).').');
        $body=$response['body']??null;
        if (!is_array($body) || !isset($body['content']) || !is_array($body['content'])
            || !isset($body['totalPages']) || filter_var($body['totalPages'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false) {
            return $this->incomplete($label.' sayfalama yanıtı geçersiz.');
        }
        if (array_values($body['content'])!==$body['content']) return $this->incomplete($label.' kayıt listesi geçersiz.');
        $pages=(int)$body['totalPages'];
        if ($number>0 && $number>=$pages) return $this->incomplete($label.' sayfa aralığı değişti.');
        if ($pages>$limit) return $this->incomplete($label.' sayfa sınırı aşıldı; eksik sonuç kaydedilmedi.');
        if (isset($body['number']) && (int)$body['number']!==$number) return $this->incomplete($label.' sayfa numarası uyuşmuyor.');
        $total=null;
        if (isset($body['totalElements'])) {
            $total=filter_var($body['totalElements'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($total===false) return $this->incomplete($label.' toplam kayıt sayısı geçersiz.');
        }
        if ((!$body['content'] && ($pages>1 || ($total??0)>0)) || ($body['content'] && $pages===0)) return $this->incomplete($label.' sayfası eksik.');
        return ['ok'=>true,'items'=>$body['content'],'pages'=>$pages,'total'=>$total];
    }

    /**
     * Düşük seviyeli HTTP isteği. Trendyol Basic auth + User-Agent zorunlu.
     */
    private function request(string $method, string $path, array $query = [], ?string $body = null): array
    {
        if ($this->transport!==null) return ($this->transport)($method,$path,$query,$body);
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
        if ($this->transport!==null) return array_map(fn($request)=>$this->request(...$request),$requests);
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
