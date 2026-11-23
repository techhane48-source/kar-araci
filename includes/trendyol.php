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

require_once __DIR__ . '/trendyol_rate_limit.php';

final class TrendyolClient
{
    private string $sellerId;
    private string $apiKey;
    private string $apiSecret;
    private string $base;
    private $transport;
    private bool $readOnly;

    // Üretim ortamı (varsayılan). Test için stageapigw kullanılabilir.
    public const PROD = 'https://apigw.trendyol.com';
    public const STAGE = 'https://stageapigw.trendyol.com';

    public function __construct(string $sellerId, string $apiKey, string $apiSecret, bool $stage = false, ?callable $transport = null, bool $readOnly = false)
    {
        $this->transport = $transport;
        $this->sellerId = trim($sellerId);
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->base = $stage ? self::STAGE : self::PROD;
        $this->readOnly=$readOnly;
    }

    /**
     * Kullanıcının kimlik bilgileriyle Trendyol'a bağlanıp bağlanamadığını test eder.
     * Başarılıysa ['ok'=>true, 'sellerName'=>...] döner; değilse ['ok'=>false, 'error'=>...].
     */
    public function testConnection(): array
    {
        $res=$this->request('GET',"/integration/product/sellers/{$this->sellerId}/products/approved",['page'=>'0','size'=>'1']);
        $page=$this->page($res,0,PHP_INT_MAX,'Bağlantı');
        if ($page['ok']) return ['ok'=>true,'sellerName'=>null];
        if (($res['http']??0)===401) return ['ok'=>false,'error'=>'Kimlik bilgileri veya seçilen canlı/test ortamı hatalı (401).'];
        if ($this->base===self::STAGE && ($res['http']??0)===503) return ['ok'=>false,'error'=>'Test ortamı IP yetkilendirmesini kontrol edin (503).'];
        return $page;
    }

    /** V2 içerik + varyant kataloğu, ayrı stok/fiyat görünümüyle birleştirilir. */
    public function getProducts(int $maxPage=500,string $approvalStatus='',int $startDateMs=0,string $dateQueryType=''): array
    {
        if ($approvalStatus!=='' && $approvalStatus!=='APPROVED') return $this->incomplete('Bu katalog yalnızca onaylı ürünleri destekler.');
        // V2 katalog değişiklik tarihi stok değişikliğini garanti etmez. Her senkronda tam görünüm alınır.
        $catalog=$this->productContents('/products/approved',$maxPage);
        if (!$catalog['ok']) return $catalog;
        $inventory=$this->productContents('/products/approved/inventory-and-price',$maxPage);
        if (!$inventory['ok']) return $inventory;
        $stocks=[];
        foreach ($inventory['content'] as $content) {
            if (!is_array($content['variants']??null)) return $this->incomplete('V2 stok varyantları eksik.');
            foreach ($content['variants'] as $variant) {
                $barcode=$variant['barcode']??null;
                if (!is_string($barcode) || $barcode==='' || isset($stocks[$barcode])) return $this->incomplete('V2 stok barkodu eksik veya tekrarlı.');
                $stocks[$barcode]=$variant;
            }
        }
        $all=[];
        foreach ($catalog['content'] as $content) {
            if (!is_array($content['variants']??null) || !$content['variants']) return $this->incomplete('V2 katalog varyantları eksik.');
            foreach ($content['variants'] as $variant) {
                $barcode=$variant['barcode']??null;
                if (!is_string($barcode) || $barcode==='' || isset($all[$barcode]) || !isset($stocks[$barcode])) return $this->incomplete('Katalog ile stok/fiyat barkodları uyuşmuyor; tekrar deneyin.');
                $stock=$stocks[$barcode];
                if (!isset($stock['quantity'],$stock['salePrice'],$stock['listPrice'])) return $this->incomplete('V2 stok veya fiyat alanı eksik.');
                $all[$barcode]=['barcode'=>$barcode,'title'=>$content['title']??'',
                    'brand'=>$content['brand']['name']??'', 'category'=>$content['category']['name']??'',
                    'salePrice'=>$stock['salePrice'],'listPrice'=>$stock['listPrice'],'quantity'=>$stock['quantity'],
                    'vatRate'=>$variant['vatRate']??null,'commissionRate'=>$variant['commission']??null,
                    'productUrl'=>$variant['productUrl']??'', 'approved'=>true];
            }
        }
        if (count($all)!==count($stocks)) return $this->incomplete('Katalog ve stok görünümü değişti; tekrar deneyin.');
        return ['ok'=>true,'complete'=>true,'products'=>array_values($all),'count'=>count($all),'contentCount'=>count($catalog['content'])];
    }

    private function productContents(string $suffix,int $maxPage): array
    {
        $path="/integration/product/sellers/{$this->sellerId}".$suffix;
        $all=[];$total=null;$token=null;$seenTokens=[];$seenIds=[];
        for ($page=0;$page<$maxPage;$page++) {
            $params=['size'=>'100','orderByDirection'=>'ASC'];
            if ($page<100) $params['page']=(string)$page;
            elseif ($token!==null) $params['nextPageToken']=$token;
            else return $this->incomplete('10.000 içerik sonrası devam tokenı eksik.');
            $info=$this->page($this->request('GET',$path,$params),$page<100?$page:null,$maxPage,'V2 ürün');
            if (!$info['ok']) return $info;
            if ($info['total']===null) return $this->incomplete('V2 içerik toplamı eksik.');
            if ($total!==null && $total!==$info['total']) return $this->incomplete('V2 katalog toplamı işlem sırasında değişti.');
            $total=$info['total'];
            foreach ($info['items'] as $item) {
                if (!is_array($item) || !isset($item['contentId']) || isset($seenIds[(string)$item['contentId']])) return $this->incomplete('Eksik veya tekrarlanan V2 içerik.');
                $seenIds[(string)$item['contentId']]=true;$all[]=$item;
            }
            if (count($all)===$total) return ['ok'=>true,'content'=>$all];
            if (!$info['items'] || count($all)>$total) return $this->incomplete('V2 içerik sayısı uyuşmuyor.');
            $next=$info['nextPageToken'];
            if ($page>=99) {
                if (!is_string($next) || $next==='' || isset($seenTokens[$next])) return $this->incomplete('V2 devam tokenı eksik veya tekrarlı.');
                $seenTokens[$next]=true;
            }
            $token=$next;
        }
        return $this->incomplete('V2 ürün sayfa sınırı aşıldı; eksik katalog kaydedilmedi.');
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

    /** Resmî polling servisi: en fazla 14 günlük last-modified pencereleri ve opaque cursor. */
    public function getOrders(string $status='',string $startDate='',string $endDate='',int $maxPage=1000): array
    {
        $end=$endDate!==''?strtotime($endDate):time();
        $start=$startDate!==''?strtotime($startDate):($end-14*86400);
        if ($start===false || $end===false || $start>=$end || $maxPage<1) return $this->incomplete('Geçersiz sipariş tarih aralığı veya sayfa sınırı.');
        $all=[];
        for ($from=$start*1000;$from<=$end*1000;$from=$to+1) {
            $to=min($from+14*86400000-1,$end*1000);
            $base=['size'=>'200','lastModifiedStartDate'=>(string)$from,'lastModifiedEndDate'=>(string)$to];
            if ($status!=='') $base['packageItemStatuses']=$status;
            $cursor=null;$seenCursors=[];$windowIds=[];$finished=false;
            for ($page=0;$page<$maxPage;$page++) {
                $query=$base;
                if ($cursor!==null) $query['nextCursor']=$cursor;
                $res=$this->request('GET',"/integration/order/sellers/{$this->sellerId}/orders/stream",$query);
                $body=$res['body']??null;
                if (($res['http']??0)!==200 || !is_array($body) || !is_array($body['content']??null) || !is_bool($body['hasMore']??null)) return $this->incomplete('Sipariş akış yanıtı eksik veya alınamadı (HTTP '.($res['http']??0).').');
                foreach ($body['content'] as $item) {
                    $order=$this->normalizeOrder($item);
                    if (!$order['ok']) return $order;
                    $id=$order['order']['packageNumber'];
                    if (isset($windowIds[$id])) return $this->incomplete('Sipariş akışında tekrarlanan paket var.');
                    $windowIds[$id]=true;
                    $all[$id]=$order['order'];
                }
                if (!$body['hasMore']) { $finished=true;break; }
                $next=$body['nextCursor']??null;
                if (!$body['content'] || !is_string($next) || $next==='' || isset($seenCursors[$next])) return $this->incomplete('Sipariş devam cursorı eksik veya tekrarlı.');
                $seenCursors[$next]=true;$cursor=$next;
            }
            if (!$finished) return $this->incomplete('Sipariş akış limiti aşıldı; kısmi veri kaydedilmedi.');
        }
        return ['ok'=>true,'complete'=>true,'orders'=>array_values($all),'count'=>count($all)];
    }

    private function normalizeOrder(mixed $item): array
    {
        if (!is_array($item) || !isset($item['shipmentPackageId'],$item['packageTotalPrice'],$item['orderDate'])
            || !is_array($item['lines']??null) || !is_numeric($item['orderDate']) || !is_numeric($item['packageTotalPrice'])) return $this->incomplete('V2 sipariş alanları eksik.');
        $lines=[];
        foreach ($item['lines'] as $line) {
            if (!is_array($line) || !is_string($line['barcode']??null) || !isset($line['quantity']) || !is_numeric($line['quantity']) || !is_finite((float)$line['quantity']) || (float)$line['quantity']<1 || (float)$line['quantity']>2147483647 || (float)$line['quantity']!==floor((float)$line['quantity'])) return $this->incomplete('V2 sipariş satırı eksik.');
            $price=$line['lineUnitPrice']??null;
            if ($price===null && isset($line['lineGrossAmount'],$line['lineTotalDiscount']) && is_numeric($line['lineGrossAmount']) && is_numeric($line['lineTotalDiscount'])) $price=(float)$line['lineGrossAmount']-(float)$line['lineTotalDiscount'];
            if (!is_numeric($price) || (float)$price<0) return $this->incomplete('V2 net birim fiyatı eksik veya geçersiz.');
            $lines[]=['barcode'=>$line['barcode'],'productName'=>$line['productName']??'',
                'quantity'=>(int)$line['quantity'],'price'=>(float)$price,'merchantSku'=>$line['stockCode']??'',
                'vatRate'=>$line['vatRate']??null,'commissionRate'=>$line['commission']??null,
                'orderLineId'=>isset($line['lineId']) ? (string)$line['lineId'] : (isset($line['id']) ? (string)$line['id'] : null),
                'discounts'=>array_intersect_key($line, array_flip(['discount', 'tyDiscount', 'lineTotalDiscount',
                    'lineSellerDiscount', 'lineTyDiscount', 'lineGrossAmount', 'currencyCode']))];
        }
        // Resmî sipariş belgesi: orderDate GMT+3, createdDate GMT. Standart UTC epoch'a çevir.
        $date=(int)$item['orderDate']-10800000;
        if ($date<=0) return $this->incomplete('V2 sipariş tarihi geçersiz.');
        return ['ok'=>true,'order'=>['packageNumber'=>(string)$item['shipmentPackageId'],
            'orderNumber'=>(string)($item['orderNumber']??''),'status'=>(string)($item['shipmentPackageStatus']??$item['status']??''),
            'customerName'=>trim(($item['customerFirstName']??'').' '.($item['customerLastName']??'')),
            'totalPrice'=>(float)$item['packageTotalPrice'],'lines'=>$lines,'orderDate'=>$date,
            'cargoCompany'=>(string)($item['cargoProviderName']??'')]];
    }

    /**
     * Trendyol kategori ağacını çeker; komisyon oranı sağlamaz.
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
                if ($transactionType==='Sale' && (!isset($it['commissionRate']) || !is_numeric($it['commissionRate']) || (float)$it['commissionRate']<0 || (float)$it['commissionRate']>100)) return $this->incomplete('Satış komisyon oranı eksik veya geçersiz.');
                $all[] = [
                    'providerId' => (string)$it['id'],
                    'barcode' => (string)($it['barcode'] ?? ''),
                    'transactionType' => $transactionType,
                    'providerTransactionType' => (string)($it['transactionType'] ?? ''),
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

    /** Read-only product query, despite the POST verb mandated by the provider. */
    public function getBuyboxInformation(array $barcodes): array
    {
        if (!$barcodes || count($barcodes)>10 || count(array_unique($barcodes))!==count($barcodes)) return $this->incomplete('Bir sorguda 1–10 farklı barkod gerekir.');
        foreach($barcodes as $b)if(!is_string($b)||$b==='')return $this->incomplete('Barkod geçersiz.');
        $r=$this->request('POST',"/integration/product/sellers/{$this->sellerId}/products/buybox-information",[],json_encode(['barcodes'=>array_values($barcodes)],JSON_THROW_ON_ERROR));
        if(($r['http']??0)!==200||!is_array($r['body']['buyboxInfo']??null))return $this->incomplete('Buybox yanıtı alınamadı.');
        $rows=[];
        foreach($r['body']['buyboxInfo'] as $row){
            if(!is_array($row)||!is_string($row['barcode']??null)||!in_array($row['barcode'],$barcodes,true)||isset($rows[$row['barcode']]))return $this->incomplete('Buybox barkodları tutarsız.');
            foreach(['buyboxOrder','buyboxPrice'] as $key)if(isset($row[$key])&&(!is_numeric($row[$key])||!is_finite((float)$row[$key])||(float)$row[$key]<0))return $this->incomplete('Buybox sayısal alanı geçersiz.');
            if(isset($row['buyboxOrder'])&&(float)$row['buyboxOrder']!==(float)(int)$row['buyboxOrder'])return $this->incomplete('Buybox sırası geçersiz.');
            if(isset($row['hasMultipleSeller'])&&!is_bool($row['hasMultipleSeller']))return $this->incomplete('Buybox satıcı bilgisi geçersiz.');
            $rows[$row['barcode']]=$row;
        }
        return ['ok'=>true,'rows'=>$rows,'missing'=>array_values(array_diff($barcodes,array_keys($rows))),'checked_at'=>time()];
    }

    private function financeRows(string $endpoint,array $params,int $maxPage,int $size): array
    {
        $all=[];$seen=[];$pages=null;$total=null;
        for($p=0;;$p++){
            $r=$this->request('GET',"/integration/finance/che/sellers/{$this->sellerId}/$endpoint",$params+['page'=>(string)$p,'size'=>(string)$size]);
            $info=$this->page($r,$p,$maxPage,'Finans');if(!$info['ok'])return $info;
            if(($pages!==null&&$pages!==$info['pages'])||($total!==null&&$info['total']!==$total))return $this->incomplete('Finans sayfaları sorgu sırasında değişti.');
            $pages=$info['pages'];$total=$info['total'];
            foreach($info['items'] as $row){
                if(!is_array($row)||!isset($row['id'])||!is_scalar($row['id'])||(string)$row['id']===''||isset($seen[(string)$row['id']]))return $this->incomplete('Finans işlem kimlikleri eksik veya tekrarlı.');
                $seen[(string)$row['id']]=true;$all[]=$row;
            }
            if($p+1>=$pages)break;
        }
        if($total!==null&&count($all)!==$total)return $this->incomplete('Finans kayıt sayısı uyuşmuyor.');
        return ['ok'=>true,'complete'=>true,'rows'=>$all];
    }

    public function getPaymentOrders(int $maxPage=20,?int $onlyPage=null): array
    {
        if($onlyPage!==null) {
            if($onlyPage<0)return $this->incomplete('Sayfa geçersiz.');
            $info=$this->page($this->request('GET',"/integration/finance/che/sellers/{$this->sellerId}/payment-order",['page'=>(string)$onlyPage,'size'=>'10']),$onlyPage,PHP_INT_MAX,'Ödeme emri');
            if(!$info['ok'])return $info;
            $r=['ok'=>true,'rows'=>$info['items'],'page'=>$onlyPage,'pages'=>$info['pages'],'total'=>$info['total']];
        } else $r=$this->financeRows('payment-order',[],$maxPage,10);
        if(!$r['ok'])return $r;
        foreach($r['rows'] as $row)if(($row['status']??'')!=='PAID'||!is_numeric($row['amount']??null)||!is_finite((float)$row['amount'])||(float)$row['amount']<0||!preg_match('/^[1-9][0-9]*$/D',(string)$row['id']))return $this->incomplete('Ödeme emri bilgileri geçersiz.');
        return $r;
    }

    public function getPaymentOrderLedger(string $id,int $maxPage=100): array
    {
        if(!preg_match('/^[1-9][0-9]*$/D',$id))return $this->incomplete('Ödeme emri kimliği geçersiz.');
        $types=[
            'settlements'=>'Sale,Return,Discount,DiscountCancel,Coupon,CouponCancel,ProvisionPositive,ProvisionNegative,ManualRefund,ManualRefundCancel,TyDiscount,TyDiscountCancel,TyCoupon,TyCouponCancel,SellerRevenuePositive,SellerRevenueNegative,CommissionPositive,CommissionNegative,SellerRevenuePositiveCancel,SellerRevenueNegativeCancel,CommissionPositiveCancel,CommissionNegativeCancel,DeliveryFee,DeliveryFeeCancel,PayByLink',
            'otherfinancials'=>'Stoppage,CashAdvance,WireTransfer,IncomingTransfer,ReturnInvoice,CommissionAgreementInvoice,PaymentOrder,DeductionInvoices,FinancialItem',
        ];$rows=[];
        foreach($types as $endpoint=>$list){
            $r=$this->financeRows($endpoint,['paymentOrderId'=>$id,'transactionTypes'=>$list],$maxPage,500);if(!$r['ok'])return $r;
            foreach($r['rows'] as $row){
                if((string)($row['paymentOrderId']??'')!==$id)return $this->incomplete('Finans kaydı seçilen ödeme emrine ait değil.');
                foreach(['debt','credit'] as $k)if(!is_numeric($row[$k]??null)||!is_finite((float)$row[$k])||abs((float)$row[$k])>1e12)return $this->incomplete('Finans tutarı eksik veya geçersiz.');
                $row['source']=$endpoint;$rows[]=$row;
            }
        }
        return ['ok'=>true,'complete'=>true,'rows'=>$rows,'payment_order_id'=>$id,'checked_at'=>time()];
    }

    /**
     * Gerçek platform hizmet bedeli kayıtları (Other Financials → DeductionInvoices +
     * transactionSubType=PlatformServiceFee). Resmî belgede sipariş/paket bağı
     * doğrulanmadığı için yalnızca dönem düzeyinde referanstır; kâr hesabındaki
     * tahminle (trendyol_service_fee) değiştirilmez. Para birimi alanı yoktur (TL).
     * Kaynak: https://developers.trendyol.com/reference/getotherfinancials.md
     *
     * @param string $startDate Y-m-d (işlem tarihi; aralık ≤15 gün)
     * @param string $endDate   Y-m-d
     */
    public function getPlatformServiceFees(string $startDate = '', string $endDate = '', int $maxPage = 100): array
    {
        $params = ['transactionType' => 'DeductionInvoices', 'transactionSubType' => 'PlatformServiceFee'];
        if ($startDate !== '') $params['startDate'] = strtotime($startDate) * 1000;
        if ($endDate !== '') $params['endDate'] = strtotime($endDate) * 1000;
        $r = $this->financeRows('otherfinancials', $params, $maxPage, 500);
        if (!$r['ok']) return $r;
        foreach ($r['rows'] as $row) {
            foreach (['debt', 'credit'] as $k) {
                if (!is_numeric($row[$k] ?? null) || !is_finite((float)$row[$k]) || abs((float)$row[$k]) > 1e12) {
                    return $this->incomplete('Platform hizmet bedeli tutarı geçersiz.');
                }
            }
        }
        return $r;
    }

    public function getOrderClaims(string $orderNumber, int $maxPages = 20): array
    {
        if ($orderNumber === '') return $this->incomplete('Sipariş numarası eksik.');
        return $this->accountingPages("/integration/order/sellers/{$this->sellerId}/claims",
            ['orderNumber' => $orderNumber, 'size' => '200'], $maxPages, 'İadeler');
    }

    public function getCargoInvoiceItems(string $invoiceId, int $maxPages = 50): array
    {
        if ($invoiceId === '') return $this->incomplete('Kargo fatura kimliği eksik.');
        $id = rawurlencode($invoiceId);
        return $this->accountingPages("/integration/finance/che/sellers/{$this->sellerId}/cargo-invoice/$id/items",
            ['size' => '500'], $maxPages, 'Kargo faturası');
    }

    private function accountingPages(string $path, array $query, int $limit, string $label): array
    {
        $rows = [];
        $pages = $total = null;
        for ($number = 0; ; $number++) {
            $result = $this->page($this->request('GET', $path, $query + ['page' => (string)$number]), $number, $limit, $label);
            if (!$result['ok']) return $result;
            if ($number > 0 && ($pages !== $result['pages'] || $total !== $result['total'])) {
                return $this->incomplete($label . ' sayfaları sorgu sırasında değişti.');
            }
            $pages = $result['pages'];
            $total = $result['total'];
            foreach ($result['items'] as $row) {
                if (!is_array($row)) return $this->incomplete($label . ' satırı geçersiz.');
                $rows[] = $row;
            }
            if ($number + 1 >= $pages) break;
        }
        if ($total !== null && count($rows) !== $total) return $this->incomplete($label . ' toplamı uyuşmuyor.');
        return ['ok' => true, 'complete' => true, 'rows' => $rows];
    }

    private function incomplete(string $error): array
    {
        return ['ok'=>false,'complete'=>false,'error'=>$error];
    }

    private function page(array $response,?int $number,int $limit,string $label): array
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
        if ($number!==null && isset($body['page']) && (int)$body['page']!==$number) return $this->incomplete($label.' sayfa numarası uyuşmuyor.');
        if ($number!==null && isset($body['number']) && (int)$body['number']!==$number) return $this->incomplete($label.' sayfa numarası uyuşmuyor.');
        $total=null;
        if (isset($body['totalElements'])) {
            $total=filter_var($body['totalElements'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($total===false) return $this->incomplete($label.' toplam kayıt sayısı geçersiz.');
        }
        if ((!$body['content'] && ($pages>1 || ($total??0)>0)) || ($body['content'] && $pages===0)) return $this->incomplete($label.' sayfası eksik.');
        return ['ok'=>true,'items'=>$body['content'],'pages'=>$pages,'total'=>$total,'nextPageToken'=>$body['nextPageToken']??null];
    }

    /**
     * Düşük seviyeli HTTP isteği. Trendyol Basic auth + User-Agent zorunlu.
     */
    private function request(string $method, string $path, array $query = [], ?string $body = null): array
    {
        if($this->readOnly && $method!=='GET' && !($method==='POST'&&str_ends_with($path,'/products/buybox-information'))) return ['http'=>0,'error'=>'Salt okunur doğrulama fiyat/stok yazımını engelledi.'];
        if ($this->transport!==null) return ($this->transport)($method,$path,$query,$body);
        $group = str_contains($path, '/orders/stream') ? 'stream' :
            (str_contains($path, '/finance/') ? 'finance' : (str_contains($path, '/claims') ? 'claims' : 'products'));
        $interval = ['stream' => 5.0, 'finance' => 0.65, 'claims' => 0.65, 'products' => 0.21][$group];
        $scope = $this->base . '|' . $this->sellerId . '|' . $group;
        try {
            trendyol_rate_slot($scope, $interval);
        } catch (RuntimeException $e) {
            return ['http' => 0, 'error' => $e->getMessage()];
        }
        $retryAfter = null;
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $auth = base64_encode($this->apiKey . ':' . $this->apiSecret);
        $headers = [
            'Authorization: Basic ' . $auth,
            'User-Agent: ' . $this->sellerId . ' - KarFiyat',
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) $retryAfter = trim(substr($header, 12));
                return strlen($header);
            },
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => getenv('CURL_CA_BUNDLE')?:getenv('SSL_CERT_FILE')?:__DIR__ . '/cacert.pem',
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

        if ($http === 429) {
            $now = time();
            $retryAt = $retryAfter === null ? false : (ctype_digit($retryAfter) ? $now + (int)$retryAfter : strtotime($retryAfter));
            try {
                trendyol_rate_slot($scope, $interval, $retryAt !== false && $retryAt > $now ? $retryAt : $now + 30);
            } catch (RuntimeException $e) {
                return ['http' => 429, 'error' => $e->getMessage()];
            }
        }
        $decoded = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        return ['http' => $http, 'body' => $decoded, 'raw' => $body];
    }

    /** Kabul, uygulama anlamına gelmez; resmî batch kimliği döndürülür. */
    public function updatePriceAndInventory(array $items): array
    {
        if (!$items || count($items)>1000) return $this->incomplete('1–1000 ürün gönderilebilir.');
        foreach ($items as $item) {
            if (!is_string($item['barcode']??null) || $item['barcode']==='') return $this->incomplete('Barkod gerekli.');
            if (isset($item['quantity']) && (!is_int($item['quantity']) || $item['quantity']<0 || $item['quantity']>20000)) return $this->incomplete('Stok 0–20.000 arasında olmalı.');
            $hasSale=isset($item['salePrice']);$hasList=isset($item['listPrice']);
            if ($hasSale!==$hasList) return $this->incomplete('Satış ve liste fiyatı birlikte gönderilmeli.');
            if ($hasSale && (!is_numeric($item['salePrice']) || !is_numeric($item['listPrice']) || !is_finite((float)$item['salePrice']) || !is_finite((float)$item['listPrice']) || (float)$item['salePrice']<=0 || (float)$item['listPrice']<(float)$item['salePrice'])) return $this->incomplete('Geçersiz satış/liste fiyatı.');
            if (!$hasSale && !isset($item['quantity'])) return $this->incomplete('Fiyat veya stok değişikliği gerekli.');
        }
        $body=json_encode(['items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $res=$this->request('POST',"/integration/inventory/sellers/{$this->sellerId}/products/price-and-inventory",[],$body);
        $batch=$res['body']['batchRequestId']??null;
        if (!in_array($res['http']??0,[200,201,202],true) || !is_string($batch) || $batch==='') return ['ok'=>false,'uncertain'=>!in_array($res['http']??0,[400,401,403,409,422,429],true),'error'=>'Fiyat isteği için batch kimliği alınamadı (HTTP '.($res['http']??0).'). İsteği otomatik tekrarlamayın.'];
        return ['ok'=>true,'pending'=>true,'batchRequestId'=>$batch];
    }

    public function getBatchRequestResult(string $batchId,string $barcode): array
    {
        if ($batchId==='' || $barcode==='') return $this->incomplete('İşlem kimliği ve barkod gerekli.');
        $res=$this->request('GET',"/integration/product/sellers/{$this->sellerId}/products/batch-requests/".rawurlencode($batchId));
        $body=$res['body']??null;
        if (($res['http']??0)!==200 || !is_array($body) || !is_array($body['items']??null) || (isset($body['batchRequestId']) && $body['batchRequestId']!==$batchId)) return $this->incomplete('Trendyol işlem sonucu alınamadı. Batch sonuçları 4 saat saklanır.');
        foreach ($body['items'] as $item) {
            if (($item['requestItem']['barcode']??'')!==$barcode) continue;
            $state=$item['status']??'';
            if ($state==='SUCCESS') return ['ok'=>true,'finished'=>true,'success'=>true];
            if ($state==='FAILURE' || ($item['failureReasons']??[])) {
                $reasons=[];
                foreach (($item['failureReasons']??[]) as $reason) if (is_string($reason)) $reasons[]=$reason;
                return ['ok'=>true,'finished'=>true,'success'=>false,'error'=>implode('; ',$reasons)?:'Trendyol ürün güncellemesini reddetti.'];
            }
            return ['ok'=>true,'finished'=>false];
        }
        return ['ok'=>true,'finished'=>false];
    }
}
