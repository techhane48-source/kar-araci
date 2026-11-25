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

require_once __DIR__.'/claims.php';

final class TrendyolClient
{
    private string $sellerId;
    private string $apiKey;
    private string $apiSecret;
    private string $base;
    private ?Closure $testTransport;

    // Üretim ortamı (varsayılan). Test için stageapigw kullanılabilir.
    public const PROD = 'https://apigw.trendyol.com';
    public const STAGE = 'https://stageapigw.trendyol.com';

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false, ?Closure $testTransport = null)
    {
        $this->sellerId = trim($sellerId);
        if (!ctype_digit($this->sellerId)) throw new InvalidArgumentException('Geçersiz satıcı ID.');
        $this->testTransport = $testTransport;
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
        if (($res['http'] ?? 0) === 200 && is_array($res['body']['content'] ?? null)) {
            return ['ok' => true, 'sellerName' => null];
        }

        if (isset($res['http']) && $res['http'] === 401) {
            // Never submit a credential to another environment without the user's choice.
            return ['ok'=>false,'error'=>'Kimlik doğrulanamadı. Satıcı ID, API bilgileri ve seçilen ortamı kontrol edin.'];
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
                    'salePrice' => isset($v['price']['salePrice']) && is_numeric($v['price']['salePrice']) && is_finite((float)$v['price']['salePrice']) ? (float)$v['price']['salePrice'] : null,
                    'quantity' => isset($v['stock']['quantity']) && is_numeric($v['stock']['quantity']) && (float)$v['stock']['quantity']>=0 && floor((float)$v['stock']['quantity'])===(float)$v['stock']['quantity'] ? (int)$v['stock']['quantity'] : null,
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
        $seenContent=[];
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
            if (isset($body['totalElements']) && (int)$body['totalElements']!==$total) return ['ok'=>false,'error'=>'Katalog tarama sırasında değişti; yeniden deneyin.'];
            foreach($items as $item){
                if(!isset($item['contentId']))continue;
                $id=(string)$item['contentId'];
                if(isset($seenContent[$id]))return ['ok'=>false,'error'=>'Katalogda yinelenen içerik sayfası.'];
                $seenContent[$id]=true;
            }
            try {
                foreach (self::normalizeApprovedContent($items) as $p) {
                    if(isset($all[$p['barcode']]))return ['ok'=>false,'error'=>'Katalogda yinelenen barkod.'];
                    $all[$p['barcode']] = $p;
                }
            } catch (UnexpectedValueException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            $contentCount += count($items);
            if ($contentCount === $total) {
                return ['ok' => true, 'products' => array_values($all), 'count' => count($all)];
            }
            if (!$items || $contentCount > $total) return ['ok' => false, 'error' => 'Ürün V2 tutarsız kayıt sayısı döndürdü.'];
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
    public function getOrders(string $status = '', string $startDate = '', string $endDate = '', int $maxPage = 2000): array
    {
        $maxPage = max(1, min(10000, $maxPage));
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
        for ($cs = $startTs; $cs <= $endTs; $cs += $chunk) {
            $ce = min($cs + $chunk - 1, $endTs);
            $page = 0;
            $cursor = null;
            $seenCursors = [];
            while (true) {
                $params = [
                    'size' => '200',
                    'lastModifiedStartDate' => (string)($cs * 1000),
                    'lastModifiedEndDate' => (string)($ce * 1000 + 999),
                ];
                if ($status !== '') $params['packageItemStatuses'] = $status;
                if ($cursor !== null) $params['nextCursor'] = $cursor;
                $res = $this->request('GET', "/integration/order/sellers/{$this->sellerId}/orders/stream", $params);

                if (isset($res['http']) && $res['http'] !== 200) {
                    return ['ok' => false, 'error' => ($res['error'] ?? 'Sipariş çekilemedi') . " (HTTP {$res['http']})"];
                }

                if (!is_array($res['body']['content'] ?? null) || !is_bool($res['body']['hasMore'] ?? null)) {
                    return ['ok' => false, 'error' => 'Sipariş akışı yanıtı eksik.'];
                }
                $items = $res['body']['content'];
                foreach ($items as $it) {
                    $pkg = (string)($it['shipmentPackageId'] ?? ($it['id'] ?? ''));
                    if ($pkg === '') return ['ok' => false, 'error' => 'Sipariş paket kimliği eksik.'];
                    $modified = $it['lastModifiedDate'] ?? null;
                    if (!is_numeric($modified) || (float)$modified <= 0) return ['ok'=>false,'error'=>'Sipariş güncelleme tarihi eksik.'];
                    if (isset($seen[$pkg]) && $seen[$pkg] >= (int)$modified) continue;
                    $seen[$pkg] = (int)$modified;

                    $lines = [];
                    foreach (($it['lines'] ?? []) as $ol) {
                        $lines[] = [
                            'barcode' => (string)($ol['barcode'] ?? $ol['sku'] ?? ''),
                            'productName' => $ol['productName'] ?? '',
                            'quantity' => (int)($ol['quantity'] ?? 0),
                            'price' => isset($ol['lineUnitPrice']) ? (float)$ol['lineUnitPrice'] : null,
                            'gross_unit_price' => isset($ol['lineGrossAmount']) ? (float)$ol['lineGrossAmount'] : null,
                            'seller_discount' => isset($ol['lineSellerDiscount']) ? (float)$ol['lineSellerDiscount'] : null,
                            'platform_discount' => isset($ol['lineTyDiscount']) ? (float)$ol['lineTyDiscount'] : null,
                            'discount_details' => $ol['discountDetails'] ?? [],
                            'line_id' => (string)($ol['lineId'] ?? ''),
                            'vat_rate' => isset($ol['vatRate']) ? (float)$ol['vatRate'] : null,
                            'commission_rate' => isset($ol['commission']) ? (float)$ol['commission'] : null,
                            'status' => (string)($ol['orderLineItemStatusName'] ?? ''),
                            'merchantSku' => $ol['merchantSku'] ?? '',
                        ];
                    }
                    if (!isset($it['packageTotalPrice']) || !is_numeric($it['packageTotalPrice'])) {
                        return ['ok' => false, 'error' => 'Sipariş V2 paket tutarı eksik.'];
                    }
                    $all[$pkg] = [
                        'packageNumber' => $pkg,
                        'orderNumber' => $it['orderNumber'] ?? '',
                        'status' => $it['status'] ?? ($it['shipmentPackageStatus'] ?? ''),
                        'customerName' => trim(($it['customerFirstName'] ?? '') . ' ' . ($it['customerLastName'] ?? '')),
                        'totalPrice' => (float)($it['packageTotalPrice'] ?? $it['totalPrice'] ?? 0),
                        'cargoCompany' => $it['cargoProviderName'] ?? '',
                        'cargoDeci' => $it['cargoDeci'] ?? null,
                        'createdBy' => $it['createdBy'] ?? null,
                        'originPackageIds' => $it['originPackageIds'] ?? null,
                        'sellerCargoAgreement' => ($it['whoPays']??null)==1,
                        'lines' => $lines,
                        'orderDate' => $it['orderDate'] ?? null,
                        'lastModifiedDate' => $it['lastModifiedDate'] ?? null,
                        'currencyCode' => $it['currencyCode'] ?? null,
                        'orderCountryCode' => $it['orderCountryCode'] ?? null,
                    ];
                }

                if (!$res['body']['hasMore']) break;
                $next = (string)($res['body']['nextCursor'] ?? '');
                if ($next === '' || isset($seenCursors[$next]) || ++$page >= $maxPage) {
                    return ['ok' => false, 'error' => 'Sipariş akışı tamamlanamadı: cursor veya sayfa sınırı.'];
                }
                $seenCursors[$next] = true;
                $cursor = $next;
                if ($this->testTransport === null) sleep(5);
            }
        }

        return ['ok' => true, 'orders' => array_values($all), 'count' => count($all)];
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
    public function getSettlements(string $transactionType, string $startDate = '', string $endDate = '', int $maxPage = 2000, string $source = 'settlements', ?string $paymentOrderId = null, ?string $subType = null): array
    {
        $all = [];
        $page = 0;
        $size = 500;
        $seen=[];$expected=null;$pages=null;
        if (!in_array($source, ['settlements', 'otherfinancials'], true)) return ['ok'=>false,'error'=>'Geçersiz finans servisi.'];

        if($subType!==null&&($subType!=='PlatformServiceFee'||$source!=='otherfinancials'||$transactionType!=='DeductionInvoices'))return ['ok'=>false,'error'=>'Geçersiz finans alt türü.'];
        while (true) {
            $params = ['page'=>(string)$page,'size'=>(string)$size,'transactionType'=>$transactionType,'supplierId'=>$this->sellerId];
            if($subType!==null)$params['transactionSubType']=$subType;
            if ($paymentOrderId !== null) {
                if (!ctype_digit($paymentOrderId)) return ['ok'=>false,'error'=>'Geçersiz ödeme emri.'];
                $params['paymentOrderId']=$paymentOrderId;
            } else {
                $start=strtotime($startDate); $end=strtotime($endDate);
                if ($start===false || $end===false || $start>$end || $end-$start>=15*86400)
                    return ['ok'=>false,'error'=>'Geçersiz finans tarih aralığı.'];
                $params['startDate']=$start*1000; $params['endDate']=$end*1000+999;
            }

            $res = $this->request('GET', "/integration/finance/che/sellers/{$this->sellerId}/{$source}", $params);

            if (isset($res['http']) && $res['http'] !== 200) {
                return ['ok' => false, 'error' => ($res['error'] ?? 'Cari hesap ekstresi çekilemedi') . " (HTTP {$res['http']})"];
            }

            if (!is_array($res['body']['content'] ?? null) || !is_numeric($res['body']['totalPages']??null) || !is_numeric($res['body']['totalElements']??null)) {
                return ['ok' => false, 'error' => 'Cari hesap ekstresi yanıtı eksik.'];
            }
            $expected=$expected??(int)$res['body']['totalElements'];$pages=$pages??(int)$res['body']['totalPages'];
            if($expected!==(int)$res['body']['totalElements']||$pages!==(int)$res['body']['totalPages'])return ['ok'=>false,'error'=>'Finansal kayıtlar sorgu sırasında değişti.'];
            if ((int)$res['body']['totalPages'] > $maxPage) {
                return ['ok' => false, 'error' => 'Finansal kayıt sayfa sınırı aşıldı.'];
            }
            $items = $res['body']['content'];
            if (!$items && $page < (int)$res['body']['totalPages']) {
                return ['ok' => false, 'error' => 'Cari hesap ekstresinde eksik sayfa.'];
            }
            foreach ($items as $it) {
                if (trim((string)($it['id'] ?? '')) === '') {
                    return ['ok' => false, 'error' => 'Finansal işlem kimliği eksik.'];
                }
                $id=(string)$it['id'];
                if(isset($seen[$id]))return ['ok'=>false,'error'=>'Yinelenen finansal işlem sayfası.'];
                $seen[$id]=true;
                if((string)($it['sellerId']??'')!==$this->sellerId || !is_numeric($it['transactionDate']??null)
                    || (float)$it['transactionDate']<=0 || !is_numeric($it['credit']??null) || !is_numeric($it['debt']??null))
                    return ['ok'=>false,'error'=>'Finansal işlem satıcısı, tarihi veya borç/alacağı eksik.'];
                if($paymentOrderId!==null && (string)($it['paymentOrderId']??'')!==$paymentOrderId)
                    return ['ok'=>false,'error'=>'Finansal işlem ödeme emri uyuşmuyor.'];
                $all[] = [
                    'externalId' => $id,
                    'queryType' => $transactionType,
                    'source' => $source,
                    'raw' => $it,
                    'paymentOrderId' => isset($it['paymentOrderId']) ? (string)$it['paymentOrderId'] : null,
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

        if(count($all)!==$expected)return ['ok'=>false,'error'=>'Finansal toplam kayıt sayısı uyuşmuyor.'];
        return ['ok' => true, 'settlements' => $all, 'count' => count($all)];
    }

    /** Read-only claim list. Do not invoke approve/reject services. */
    public function getClaims(int $start,int $end,int $maxPages=500): array
    {
        if($start<=0||$end<$start||$end-$start>=14*86400)return ['ok'=>false,'error'=>'İade sorgu aralığı geçersiz.'];
        $all=[];$items=[];$seen=[];$expected=null;$pages=null;
        for($page=0;$page<max(1,min(2000,$maxPages));$page++){
            $res=$this->request('GET',"/integration/order/sellers/{$this->sellerId}/claims",
                ['startDate'=>(string)($start*1000),'endDate'=>(string)($end*1000+999),'page'=>(string)$page,'size'=>'100']);
            $body=$res['body']??null;
            if(($res['http']??0)!==200||!is_array($body['content']??null)||!is_numeric($body['totalPages']??null)||!is_numeric($body['totalElements']??null))
                return ['ok'=>false,'error'=>'İade sayfası alınamadı (HTTP '.($res['http']??0).').'];
            $expected=$expected??(int)$body['totalElements'];$pages=$pages??(int)$body['totalPages'];
            if($expected!==(int)$body['totalElements']||$pages!==(int)$body['totalPages']||$pages>$maxPages)
                return ['ok'=>false,'error'=>'İade sayfalaması değişti veya sınırı aştı.'];
            if(!$body['content']&&$page<$pages)return ['ok'=>false,'error'=>'İade sayfası eksik.'];
            foreach($body['content'] as $claim){
                $id=(string)($claim['id']??$claim['claimId']??'');
                if($id===''||isset($seen[$id]))return ['ok'=>false,'error'=>'Tekrarlanan veya eksik iade paketi.'];
                $seen[$id]=true;
                try{$normalized=normalize_claim($claim);}catch(InvalidArgumentException $e){return ['ok'=>false,'error'=>$e->getMessage()];}
                foreach($normalized as $row){
                    if(isset($items[$row['item_id']]))return ['ok'=>false,'error'=>'Tekrarlanan iade kalemi.'];
                    $items[$row['item_id']]=true;$all[]=$row;
                }
            }
            if($page+1>=$pages)return count($seen)===$expected?['ok'=>true,'items'=>$all,'claims'=>count($seen)]:['ok'=>false,'error'=>'İade paket sayısı uyuşmuyor.'];
        }
        return ['ok'=>false,'error'=>'İade sayfalaması tamamlanamadı.'];
    }

    /** Official PAID payment orders. Caller selects an ID, not an order date. */
    public function getPaymentOrders(int $page = 0): array
    {
        $res=$this->request('GET', "/integration/finance/che/sellers/{$this->sellerId}/payment-order",
            ['page'=>(string)max(0,$page),'size'=>'10']);
        $body=$res['body']??null;
        if (($res['http']??0)!==200 || !is_array($body['content']??null) || !isset($body['totalPages']))
            return ['ok'=>false,'error'=>'Ödeme emirleri alınamadı.'];
        $items=[];
        foreach($body['content'] as $row) {
            if (!ctype_digit((string)($row['id']??'')) || ($row['status']??'')!=='PAID'
                || !is_numeric($row['amount']??null) || !is_numeric($row['payoutDate']??null))
                return ['ok'=>false,'error'=>'Ödeme emri verisi eksik.'];
            if ((string)($row['sellerId']??'')!==$this->sellerId) return ['ok'=>false,'error'=>'Ödeme emri satıcı kimliği uyuşmuyor.'];
            $items[]=$row;
        }
        return ['ok'=>true,'items'=>$items,'totalPages'=>(int)$body['totalPages']];
    }

    /** The official Buybox service accepts at most ten barcodes per request. */
    public function getBuybox(array $barcodes): array
    {
        $barcodes=array_values(array_unique(array_map('strval',$barcodes)));
        if (!$barcodes || count($barcodes)>10 || in_array('', $barcodes,true)) return ['ok'=>false,'error'=>'1–10 barkod girin.'];
        $res=$this->request('POST',"/integration/product/sellers/{$this->sellerId}/products/buybox-information",[],
            json_encode(['barcodes'=>$barcodes],JSON_THROW_ON_ERROR));
        if (($res['http']??0)!==200 || !is_array($res['body']['buyboxInfo']??null))
            return ['ok'=>false,'error'=>'Buybox servisi yanıt vermedi (HTTP '.($res['http']??0).').'];
        $items=[];
        foreach($res['body']['buyboxInfo'] as $r) {
            $bc=(string)($r['barcode']??'');
            if (!in_array($bc,$barcodes,true) || isset($items[$bc])) return ['ok'=>false,'error'=>'Buybox barkod eşleşmesi geçersiz.'];
            if (!is_numeric($r['buyboxOrder']??null) || !is_numeric($r['buyboxPrice']??null) || !is_bool($r['hasMultipleSeller']??null)
                || !is_finite((float)$r['buyboxPrice']) || (float)$r['buyboxPrice']<0 || (float)$r['buyboxOrder']<0 || floor((float)$r['buyboxOrder'])!==(float)$r['buyboxOrder'])
                return ['ok'=>false,'error'=>'Buybox yanıtı eksik.'];
            $items[$bc]=['barcode'=>$bc,'rank'=>(int)$r['buyboxOrder'],'price'=>(float)$r['buyboxPrice'],
                'multiple'=>$r['hasMultipleSeller'],'second'=>isset($r['secondBuyboxPrice'])&&is_numeric($r['secondBuyboxPrice'])?(float)$r['secondBuyboxPrice']:null,
                'third'=>isset($r['thirdBuyboxPrice'])&&is_numeric($r['thirdBuyboxPrice'])?(float)$r['thirdBuyboxPrice']:null];
        }
        return ['ok'=>true,'items'=>$items,'missing'=>array_values(array_diff($barcodes,array_keys($items)))];
    }

    /** Read actual invoice rows; no guessed allocation to shipment packages. */
    public function getCargoInvoiceItems(string $serial, int $maxPage = 2000): array
    {
        $all = [];$expected=null;$pages=null;$seen=[];
        $path = "/integration/finance/che/sellers/{$this->sellerId}/cargo-invoice/" . rawurlencode($serial) . "/items";
        for ($page = 0; $page < $maxPage; $page++) {
            $res = $this->request('GET', $path, ['page'=>(string)$page, 'size'=>'500']);
            $body = $res['body'] ?? [];
            if (($res['http'] ?? 0) !== 200 || !is_array($body['content'] ?? null) || (!is_numeric($body['totalPages']??null)||!is_numeric($body['totalElements']??null))) {
                return ['ok'=>false,'error'=>'Kargo faturası yanıtı eksik veya başarısız.'];
            }
            $expected=$expected??(int)$body['totalElements'];$pages=$pages??(int)$body['totalPages'];
            if($expected!==(int)$body['totalElements']||$pages!==(int)$body['totalPages'])return ['ok'=>false,'error'=>'Kargo faturası sayfa sayısı değişti.'];
            if (!$body['content'] && $page < (int)$body['totalPages']) return ['ok'=>false,'error'=>'Kargo faturası eksik sayfa.'];
            foreach ($body['content'] as $row) {
                if (!isset($row['parcelUniqueId'], $row['orderNumber'], $row['amount'], $row['desi'], $row['shipmentPackageType'])
                    || !is_numeric($row['amount']) || !is_numeric($row['desi']) || !is_finite((float)$row['amount']) || !is_finite((float)$row['desi'])
                    || (float)$row['amount']<0 || (float)$row['desi']<0 || (string)$row['parcelUniqueId']==='' || (string)$row['orderNumber']==='') return ['ok'=>false,'error'=>'Kargo fatura kalemi eksik.'];
                $key=(string)$row['parcelUniqueId'].'|'.(string)$row['shipmentPackageType'];
                if(isset($seen[$key]))return ['ok'=>false,'error'=>'Kargo faturasında yinelenen paket; manuel doğrulama gerekli.'];
                $seen[$key]=true;$all[] = $row;
            }
            if ($page + 1 >= (int)$body['totalPages']) return count($all)===$expected?['ok'=>true,'items'=>$all]:['ok'=>false,'error'=>'Kargo kalemi sayısı uyuşmuyor.'];
        }
        return ['ok'=>false,'error'=>'Kargo faturası sayfa sınırı aşıldı.'];
    }

    /**
     * Düşük seviyeli HTTP isteği. Trendyol Basic auth + User-Agent zorunlu.
     */
    private function request(string $method, string $path, array $query = [], ?string $body = null): array
    {
        // Retrying writes can duplicate side effects after a lost response.
        $tries=$method==='GET'?3:1;
        for($i=0;$i<$tries;$i++){
            $res=$this->requestOnce($method,$path,$query,$body);
            $http=(int)($res['http']??0);
            if(!in_array($http,[0,429,500,502,503,504],true)||$i+1===$tries)return $res;
            $wait=max(1<<$i,(int)($res['retry_after']??0));
            if($wait>30)return $res; // defer long backoff to next job, never ignore Retry-After
            if($this->testTransport===null)usleep($wait*1000000+random_int(0,250000));
        }
        return ['http'=>0,'error'=>'İstek tamamlanamadı.'];
    }

    private function requestOnce(string $method,string $path,array $query,?string $body): array
    {
        if ($this->testTransport !== null) return ($this->testTransport)($method,$path,$query,$body);
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
        $retryAfter=0;
        curl_setopt_array($ch, [
            CURLOPT_HEADERFUNCTION => static function($ch,string $line)use(&$retryAfter):int {
                if(stripos($line,'Retry-After:')===0){$v=trim(substr($line,12));$retryAfter=ctype_digit($v)?(int)$v:max(0,(strtotime($v)?:0)-time());}
                return strlen($line);
            },
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

        $decoded = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        return ['http' => $http, 'body' => $decoded, 'retry_after'=>$retryAfter];
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
        $batch = trim((string)($res['body']['batchRequestId'] ?? ''));
        if ($batch === '') return ['ok'=>false,'error'=>'İşlem kimliği dönmedi; sonucu doğrulamadan tekrar göndermeyin.'];
        return ['ok'=>true,'batch_id'=>$batch];
    }

    public function getBatchRequestResult(string $id): array
    {
        $res=$this->request('GET', "/integration/product/sellers/{$this->sellerId}/products/batch-requests/".rawurlencode($id));
        if (($res['http']??0)!==200 || !is_array($res['body']['items']??null)) return ['ok'=>false,'error'=>'İşlem sonucu henüz alınamadı veya sorgu süresi doldu.'];
        return ['ok'=>true,'items'=>$res['body']['items']];
    }
}
