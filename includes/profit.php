<?php
declare(strict_types=1);

/**
 * Net kâr hesabı (komisyon hesaplama modeli).
 * Kâr = Satış − Alış − Komisyon − Kargo − Ek Bedel (ihracat/aynı gün) − KDV (ödenen)
 * Tüm tutarlar KDV dahil TL. KDV = (Satış − Alış − Komisyon − Kargo − Ek Bedel) × KDV/(1+KDV).
 */
function calc_profit(float $cost, float $price, float $cargo, float $rate, float $kdvRate = 20.0, float $extraFee = 0.0, float $serviceFee = 0.0, float $stopaj = 0.0, float $returnRate = 0.0, array $options = []): array
{
    $commission = $price * ($rate / 100);
    $returnLoss = $price * ($returnRate / 100);   // iade oranı kadar satış tutarı kaybı (tutucu tahmin)
    // Net KDV (Melontik modeli) = Satış KDV − Maliyet KDV − Komisyon KDV − Kargo KDV − Hizmet KDV.
    // Stopaj ayrı bir tevkifat kalemidir, KDV tabanına girmez. Negatif çıkarsa 0 alınır (floor).
    // Legacy callers use the common KDV rate; explicit options override each leg.
    $vat = static function (float $amount, string $key) use ($options, $kdvRate): float {
        $rate = (float)($options[$key] ?? $kdvRate);
        if (!is_finite($rate) || $rate < 0 || $rate > 100) {
            throw new InvalidArgumentException('Geçersiz KDV oranı: ' . $key);
        }
        return $amount * $rate / (100 + $rate);
    };
    $netKdv = $vat($price, 'sale_vat') - $vat($cost, 'cost_vat')
        - $vat($commission, 'commission_vat') - $vat($cargo, 'cargo_vat')
        - $vat($extraFee, 'extra_vat') - $vat($serviceFee, 'service_vat');
    if (!($options['allow_negative_vat'] ?? false) && $netKdv < 0) $netKdv = 0.0;
    $profit = $price - $cost - $commission - $cargo - $extraFee - $serviceFee - $stopaj - $netKdv - $returnLoss;
    $margin = $price > 0 ? ($profit / $price) * 100 : 0.0;   // kâr marjı = kâr / satış
    $ratio = $cost > 0 ? ($profit / $cost) * 100 : 0.0;      // kâr oranı = kâr / maliyet
    return [
        'profit' => $profit,
        'margin' => $margin,
        'ratio' => $ratio,
        'commission' => $commission,
        'net_kdv' => $netKdv,
        'extra_fee' => $extraFee,
        'service_fee' => $serviceFee,
        'stopaj' => $stopaj,
        'return_loss' => $returnLoss,
        'totalCuts' => $commission + $cargo + $extraFee + $serviceFee + $stopaj + $netKdv + $returnLoss,
    ];
}

/**
 * Stopaj (tevkifat) — Melontik modeli: KDV-hariç satış tutarının belirli oranı.
 * Trendyol için %1 (0.01). $stopajRate yüzde olarak verilir (örn. 1.0).
 */
function auto_stopaj(float $price, float $kdvRate = 20.0, float $stopajRate = 1.0): float
{
    if ($price <= 0) return 0.0;
    return $price / (1 + $kdvRate / 100) * ($stopajRate / 100);
}

/** Trendyol sabit hizmet bedeli (₺). */
function trendyol_service_fee(): float
{
    return 13.19;
}

/**
 * Ters hesap motoru — Melontik'in "Fiyat Oluştur" çekirdeği.
 * Hedef net kâr (₺) veya hedef kâr marjı (%) ver; gerekli satış fiyatını kuruşuna çöz.
 * Komisyon, hizmet bedeli, kargo, stopaj (KDV-hariç × oran), Net KDV (floor 0) ve iade kaybı
 * tam olarak modele dahildir. Binary search ile Net KDV floor'unu da doğru ele alır.
 *
 * @param float $targetProfit  hedef net kâr (₺). 0 ise $targetMargin kullanılır.
 * @param float $targetMargin  hedef kâr marjı (%) = kâr / satış. 0 ise $targetProfit kullanılır.
 * @param float $stopajRate    stopaj oranı (%) — Trendyol %1.
 */
/** Search each linear segment separately; never skip a feasible VAT-floor peak. */
function minimum_price_cents(callable $objective,?float $breakpoint,int $maxCents=10000000000): ?int
{
    $segments=[[1,$maxCents]];
    if($breakpoint!==null&&is_finite($breakpoint)&&$breakpoint>0&&$breakpoint<$maxCents/100){
        $split=(int)floor($breakpoint*100);
        $segments=[[1,$split],[$split+1,$maxCents]];
    }
    $eps=1e-8;
    foreach($segments as [$lo,$hi]){
        if($hi<$lo)continue;
        $left=$objective($lo/100.0);$right=$objective($hi/100.0);
        if(!is_finite($left)||!is_finite($right))continue;
        if($left>=-$eps)return $lo;
        if($right<-$eps)continue;
        while($lo<$hi){
            $mid=$lo+intdiv($hi-$lo,2);
            if($objective($mid/100.0)>=-$eps)$hi=$mid;else $lo=$mid+1;
        }
        if($objective($lo/100.0)>=-$eps)return $lo;
    }
    return null;
}

function calc_price(float $cost, float $cargo, float $rate, float $kdvRate = 20.0,
                    float $extraFee = 0.0, float $serviceFee = 13.19, float $stopajRate = 1.0,
                    float $returnRate = 0.0, float $targetProfit = 0.0, float $targetMargin = 0.0, array $options = []): array
{
    $saleVat = (float)($options['sale_vat'] ?? $kdvRate);
    $profitAt = static function (float $P) use ($cost, $cargo, $rate, $kdvRate, $extraFee, $serviceFee, $stopajRate, $returnRate, $saleVat, $options): float {
        return calc_profit($cost, $P, $cargo, $rate, $kdvRate, $extraFee, $serviceFee,
            (isset($options['fixed_stopaj']) ? (float)$options['fixed_stopaj'] : auto_stopaj($P, $saleVat, $stopajRate)), $returnRate, $options)['profit'];
    };

    $f = $targetMargin > 0
        ? (function (float $P) use ($profitAt, $targetMargin): float {
            return $profitAt($P) - $P * $targetMargin / 100;
        })
        : (function (float $P) use ($profitAt, $targetProfit): float {
            return $profitAt($P) - $targetProfit;
        });

    // With a zero VAT floor, the objective is piecewise linear and may peak
    // at the VAT breakpoint. Searching only a large upper bound can miss a
    // feasible low-price interval. Solve over integer kuruş, not display rounding.
    $failure = static fn(string $msg):array => ['ok'=>false,'error'=>$msg,
        'price'=>0.0,'profit'=>0.0,'margin'=>0.0,'ratio'=>0.0,'commission'=>0.0,
        'net_kdv'=>0.0,'service_fee'=>$serviceFee,'stopaj'=>0.0,'cargo'=>$cargo,'extra_fee'=>$extraFee,'return_loss'=>0.0];
    foreach([$cost,$cargo,$extraFee,$serviceFee,$targetProfit,$targetMargin,$rate,$returnRate,$stopajRate,$saleVat,$kdvRate] as $v)
        if(!is_finite($v)||$v<0)return $failure('Negatif veya geçersiz hesap girdisi.');
    foreach([$rate,$returnRate,$stopajRate,$saleVat,$kdvRate,$targetMargin] as $v)
        if($v>100)return $failure('Oran 0–100 arasında olmalı.');
    if(isset($options['fixed_stopaj'])&&(!is_numeric($options['fixed_stopaj'])||!is_finite((float)$options['fixed_stopaj'])||(float)$options['fixed_stopaj']<0))
        return $failure('Stopaj tutarı geçersiz.');
    $vf=static function(string $key)use($options,$kdvRate):float{
        $v=(float)($options[$key]??$kdvRate);
        if(!is_finite($v)||$v<0||$v>100)throw new InvalidArgumentException('KDV oranı geçersiz.');
        return $v/(100+$v);
    };
    try{
        $a=$vf('sale_vat')-$rate/100*$vf('commission_vat');
        $b=$cost*$vf('cost_vat')+$cargo*$vf('cargo_vat')+$extraFee*$vf('extra_vat')+$serviceFee*$vf('service_vat');
    }catch(InvalidArgumentException $e){return $failure($e->getMessage());}
    $breakpoint=(!($options['allow_negative_vat']??false)&&$a>0)?$b/$a:null;
    $cents=minimum_price_cents($f,$breakpoint);
    if($cents===null)return $failure('Hedef kâr geçerli fiyat aralığında ulaşılamıyor.');
    $P=$cents/100.0;

    $r = calc_profit($cost, $P, $cargo, $rate, $kdvRate, $extraFee, $serviceFee, (isset($options['fixed_stopaj']) ? (float)$options['fixed_stopaj'] : auto_stopaj($P, $saleVat, $stopajRate)), $returnRate, $options);

    return [
        'ok' => true,
        'price' => $P,
        'price_cents'=>$cents,
        'target_basis'=>$targetMargin>0?'margin':'profit',
        'profit' => $r['profit'],
        'margin' => $r['margin'],
        'ratio' => $r['ratio'],
        'commission' => $r['commission'],
        'net_kdv' => $r['net_kdv'],
        'service_fee' => $serviceFee,
        'stopaj' => $r['stopaj'],
        'cargo' => $cargo,
        'extra_fee' => $extraFee,
        'return_loss' => $r['return_loss'],
    ];
}


/** Parse Turkish money or a plain decimal. Invalid/range text is not a price. */
function parse_money_input(string $raw): ?float
{
    $v = trim(str_replace(["₺", "TL", "\xc2\xa0", "\xe2\x80\xaf", " "], '', $raw));
    if (preg_match('/^-?\d{1,3}(?:\.\d{3})+,\d{1,2}$/D', $v)) {
        $v = str_replace(',', '.', str_replace('.', '', $v));
    } elseif (preg_match('/^-?\d+,\d{1,2}$/D', $v)) {
        $v = str_replace(',', '.', $v);
    } elseif (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $v)) {
        return null;
    }
    $n = (float)$v;
    return is_finite($n) ? $n : null;
}


/** Cancelled packages do not count as sales. Return costs remain a separate financial process. */
function order_is_domestic_try(array $order): bool
{
    if (($order['marketplace'] ?? '') !== 'trendyol') return true;
    return ($order['currency_code'] ?? null)==='TRY' && ($order['country_code'] ?? null)==='TR';
}
function order_is_active_sale(array $order): bool
{
    if (!order_is_domestic_try($order)) return false;
    return !in_array(strtolower((string)($order['status'] ?? '')), ['cancelled','canceled','unsupplied','returned','undelivered','unpacked'], true);
}

function calc_order_profit(array $order, array $byBarcode): array
{
    $sale = (float)($order['total_price'] ?? 0);
    $fail = static fn(string $reason): array => ['ok'=>false,'missing'=>true,'sale'=>$sale,'profit'=>null,'reason'=>$reason];
    $lines = $order['lines'] ?? [];
    if(isset($order['partial_error']))return $fail((string)$order['partial_error']);
    if (!order_is_domestic_try($order)) return $fail('Para birimi veya ülke doğrulanmadı; yurtiçi TL hesabına alınmadı.');
    if (!order_is_active_sale($order)) return $fail('İptal/iade: finansal kayıt bekleniyor.');
    if (!is_array($lines) || !$lines || !is_numeric($order['total_price']??null) || !is_finite($sale) || $sale <= 0) return $fail('Sipariş verisi eksik.');
    $cargoOverride=array_key_exists('cargo_override',$order);$cargoSource=$order['cargo_source']??'Kayıtlı kargo tahmini';
    if($cargoOverride&&(!is_numeric($order['cargo_override'])||!is_finite((float)$order['cargo_override'])||(float)$order['cargo_override']<0))return $fail((string)$cargoSource);
    if(!$cargoOverride&&($order['marketplace']??'')==='trendyol'&&(count($lines)!==1||(int)($lines[0]['quantity']??0)!==1))return $fail('Çoklu ürün paketinin kargo tahmini eksik.');
    $commissionOverride=array_key_exists('commission_override',$order);$commissionSource=$order['commission_source']??'Sipariş komisyon oranı / tahmin';
    if($commissionOverride&&(!is_numeric($order['commission_override'])||!is_finite((float)$order['commission_override'])||(float)$order['commission_override']<0||(float)$order['commission_override']>$sale))return $fail((string)$commissionSource);
    $cost=0.0; $cargo=0.0; $commission=0.0; $saleVat=0.0; $costVat=0.0;
    $stopaj=0.0; $sum=0.0; $extra=0.0;
    $seenLines=[];
    foreach ($lines as $line) {
        if(!is_array($line))return $fail('Satır biçimi geçersiz.');
        $lineId=(string)($line['line_id']??'');
        if($lineId!==''&&isset($seenLines[$lineId]))return $fail('Yinelenen sipariş satırı.');
        if($lineId!=='')$seenLines[$lineId]=true;
        $qty=(int)($line['quantity']??0);
        if ($qty<=0 || !is_numeric($line['quantity']??null) || (float)$line['quantity']!==(float)$qty || !isset($line['price']) || !is_numeric($line['price']) || !is_finite((float)$line['price']) || (float)$line['price']<0) return $fail('Satır fiyatı/adedi eksik.');
        if (!order_is_active_sale($line)) return $fail('Kısmi iptal/iade: finansal kayıt bekleniyor.');
        $product=$byBarcode[$line['barcode']??'']??null;
        if (($order['marketplace']??'')==='trendyol' && !isset($line['cost_snapshot'])) return $fail('Sipariş tarihine ait maliyet veya ilk gözlem eksik.');
        $unitCost=$line['cost_snapshot']??$product['cost']??null;
        if (!$product || !is_numeric($unitCost)||!is_finite((float)$unitCost)||(float)$unitCost<=0) return $fail('Ürün maliyeti eksik.');
        // Invoice net is not seller settlement. Platform-funded discounts require reconciliation.
        if (abs((float)($line['platform_discount']??0))>0.005) return $fail('Trendyol katkılı indirim: finansal kayıt bekleniyor.');
        $lineSale=(float)$line['price']*$qty;
        if (isset($line['discount_details']) && is_array($line['discount_details']) && count($line['discount_details'])===$qty) {
            $itemSum=0.0;
            foreach($line['discount_details'] as $detail) {
                if (!is_numeric($detail['lineItemPrice']??null) || (float)$detail['lineItemPrice']<0) return $fail('İndirim detayı eksik.');
                if (abs((float)($detail['lineItemTyDiscount']??0))>0.005) return $fail('Trendyol katkılı indirim: finansal kayıt bekleniyor.');
                $itemSum+=(float)$detail['lineItemPrice'];
            }
            $lineSale=$itemSum;
        }
        foreach([($commissionOverride?0:($line['commission_rate']??$product['rate']??null)),$line['vat_rate']??$product['kdv']??null,$line['cost_vat_snapshot']??$product['cost_vat']??$product['kdv']??null,($cargoOverride?0:($product['cargo']??0))] as $value){
            if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0)return $fail('Geçersiz komisyon, KDV veya kargo girdisi.');
        }
        $rate=$commissionOverride?0.0:(float)($line['commission_rate']??$product['rate']??-1);
        $vat=(float)($line['vat_rate']??$product['kdv']??-1);
        $purchaseVat=(float)($line['cost_vat_snapshot']??$product['cost_vat']??$product['kdv']??-1);
        if ($rate<0||$rate>100||$vat<0||$vat>100||$purchaseVat<0||$purchaseVat>100||$lineSale<0) return $fail('Komisyon/KDV verisi geçersiz.');
        $lineCost=(float)$unitCost*$qty;
        if(!is_finite($lineCost)||!is_finite($lineSale))return $fail('Satır tutarı geçersiz.');
        $sum+=$lineSale; $cost+=$lineCost;
        // Product cargo is a forecast; multi-line package fee allocation is not verified.
        if(!$cargoOverride)$cargo+=(float)($product['cargo']??0);
        $commission+=$lineSale*$rate/100;
        $saleVat+=$lineSale*$vat/(100+$vat);
        $costVat+=$lineCost*$purchaseVat/(100+$purchaseVat);
        $stopaj+=auto_stopaj($lineSale,$vat);
    }
    if($cargoOverride)$cargo=(float)$order['cargo_override'];
    elseif(($order['marketplace']??'')==='trendyol'&&$cargo<=0)return $fail('Kargo tahmini eksik.');
    if (!is_finite($sum)||!is_finite($cost)||!is_finite($cargo)||abs($sum-$sale)>0.05) return $fail('Paket ve satır toplamları uyuşmuyor.');
    if($commissionOverride)$commission=(float)$order['commission_override'];
    $service=trendyol_service_fee();
    $netVat=max(0.0,$saleVat-$costVat-($commission+$cargo+$service)/6);
    $impairment=(float)($order['partial_inventory_loss']??0);
    if(!is_finite($impairment)||$impairment<0)return $fail('İade stok kaybı geçersiz.');
    $cost+=$impairment; // Gross impairment once; no new purchase VAT credit.
    $profit=$sale-$cost-$cargo-$commission-$service-$stopaj-$netVat;
    return ['ok'=>true,'estimated'=>true,'sale'=>$sale,'cost'=>$cost,'cargo'=>$cargo,'cargo_source'=>$cargoSource,'profit'=>$profit,
        'margin'=>$profit/$sale*100,'ratio'=>$profit/$cost*100,'commission'=>$commission,'commission_source'=>$commissionSource,
        'service_fee'=>$service,'stopaj'=>$stopaj,'net_kdv'=>$netVat,'extra_fee'=>0.0,'return_loss'=>0.0];
}

/** Persisted product settings are shared by catalog, pricing, alerts and campaign estimates. */
function product_profit_options(array $product): array
{
    $saleVat = (float)($product['kdv'] ?? 20);
    // Legacy purchase VAT was not stored separately.
    $costVat = (float)($product['cost_vat'] ?? $saleVat);
    return ['sale_vat'=>$saleVat, 'cost_vat'=>$costVat,
        'commission_vat'=>20.0, 'cargo_vat'=>20.0, 'extra_vat'=>20.0, 'service_vat'=>20.0,
        'allow_negative_vat'=>!empty($product['allow_negative_vat'])];
}
function product_stopaj(array $product, float $price): float
{
    $mode = $product['stopaj_mode'] ?? 'legacy';
    if ($mode === 'manual' || ($mode === 'legacy' && ($product['marketplace'] ?? 'trendyol') !== 'trendyol'))
        return (float)($product['stopaj'] ?? 0);
    return auto_stopaj($price,(float)($product['kdv'] ?? 20),(float)($product['stopaj_rate'] ?? 1));
}
/** Validate stored product scenarios before computing. Missing/invalid values are not zero. */
function product_validate_scenario(array $product,float $price,float $rate): void
{
    if(!isset($product['cost'])||!is_numeric($product['cost'])||!is_finite((float)$product['cost'])||(float)$product['cost']<0||
       !is_finite($price)||$price<0||!is_finite($rate)||$rate<0||$rate>100)
        throw new InvalidArgumentException('Geçersiz ürün hesap girdisi.');
    foreach(['cargo','extra_fee','service_fee','stopaj','kdv','cost_vat','stopaj_rate','return_rate'] as $key){
        if(!isset($product[$key]))continue;
        $v=$product[$key];$max=in_array($key,['kdv','cost_vat','stopaj_rate','return_rate'],true)?100:100000000;
        if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>$max)
            throw new InvalidArgumentException('Geçersiz ürün hesap ayarı: '.$key);
    }
}

function product_profit(array $product, ?float $price = null, ?float $rate = null): array
{
    if($price===null&&!is_numeric($product['price']??null))throw new InvalidArgumentException('Satış fiyatı eksik.');
    if($rate===null&&!is_numeric($product['rate']??null))throw new InvalidArgumentException('Komisyon oranı eksik.');
    $price = $price ?? (float)$product['price'];
    product_validate_scenario($product,$price,$rate??(float)$product['rate']);
    $options = product_profit_options($product);
    return calc_profit((float)$product['cost'],$price,(float)($product['cargo']??0),
        $rate ?? (float)$product['rate'],(float)($product['kdv']??20),
        (float)($product['extra_fee']??0),(float)($product['service_fee']??0),
        product_stopaj($product,$price),(float)($product['return_rate']??0),$options);
}
function product_target_price(array $product, float $targetProfit, float $targetMargin): array
{
    $options=product_profit_options($product);
    $mode=$product['stopaj_mode']??'legacy';
    if ($mode==='manual' || ($mode==='legacy' && ($product['marketplace']??'trendyol')!=='trendyol'))
        $options['fixed_stopaj']=(float)($product['stopaj']??0);
    return calc_price((float)$product['cost'],(float)($product['cargo']??0),(float)$product['rate'],
        (float)($product['kdv']??20),(float)($product['extra_fee']??0),(float)($product['service_fee']??0),
        (float)($product['stopaj_rate']??1),(float)($product['return_rate']??0),$targetProfit,$targetMargin,$options);
}

/** Raw payout components; do not infer a bank balance or net profit from incomplete accounting semantics. */
function payout_components(array $settlements, array $financials, string $paymentId): array
{
    $result=['settlement_rows'=>0,'document_rows'=>0,'settlement_credit'=>0.0,'settlement_debt'=>0.0,
        'documents_credit'=>0.0,'documents_debt'=>0.0,'reported_payment'=>0.0,'by_type'=>[]];
    foreach (['settlements'=>$settlements,'otherfinancials'=>$financials] as $source=>$rows) {
        $seen=[];
        foreach($rows as $r) {
            $id=(string)($r['externalId']??'');
            if ($id==='' || (string)($r['paymentOrderId']??'')!==$paymentId)
                throw new InvalidArgumentException('Ödeme emriyle eşleşmeyen finansal kayıt.');
            if(isset($seen[$id])) continue;
            $seen[$id]=true;
            $raw=$r['raw']??[];
            if (!is_numeric($raw['credit']??null)||!is_numeric($raw['debt']??null))
                throw new InvalidArgumentException('Finansal borç/alacak eksik.');
            $credit=(float)$raw['credit'];$debt=(float)$raw['debt'];
            $type=(string)($r['queryType']??'');
            $key=$source.':'.$type;
            if (!isset($result['by_type'][$key])) $result['by_type'][$key]=['count'=>0,'credit'=>0.0,'debt'=>0.0];
            $result['by_type'][$key]['count']++;
            $result['by_type'][$key]['credit']+=$credit;
            $result['by_type'][$key]['debt']+=$debt;
            if($source==='settlements') {
                $result['settlement_rows']++; $result['settlement_credit']+=$credit; $result['settlement_debt']+=$debt;
            } else {
                $result['document_rows']++;
                if($type==='PaymentOrder') $result['reported_payment']+=$debt-$credit;
                else {$result['documents_credit']+=$credit;$result['documents_debt']+=$debt;}
            }
        }
    }
    return $result;
}

/** Per-product report inputs: only complete, reconciled package prices; observed cost is not invoice cost. */
function report_order_lines(array $order,array $products): ?array
{
    if (!order_is_active_sale($order) || !is_array($order['lines']??null) || !$order['lines']) return null;
    if(!is_numeric($order['total_price']??null)||!is_finite((float)$order['total_price'])||(float)$order['total_price']<=0)return null;
    $sum=0.0;$out=[];$seen=[];
    foreach($order['lines'] as $l) {
        if(!is_array($l))return null;
        $id=(string)($l['line_id']??'');if($id!==''&&isset($seen[$id]))return null;if($id!=='')$seen[$id]=true;
        $bc=(string)($l['barcode']??'');$qty=(int)($l['quantity']??0);
        if ($bc===''||$qty<=0||!is_numeric($l['quantity']??null)||!is_finite((float)$l['quantity'])||(float)$l['quantity']!==(float)$qty||!order_is_active_sale($l)||!is_numeric($l['price']??null)||!is_finite((float)$l['price'])||(float)$l['price']<0
            ||abs((float)($l['platform_discount']??0))>0.005)return null;
        $sale=(float)$l['price']*$qty;
        $details=$l['discount_details']??[];
        if(is_array($details)&&count($details)===$qty){
            $sale=0.0;
            foreach($details as $item){
                if(!is_array($item)||!is_numeric($item['lineItemPrice']??null)||!is_finite((float)$item['lineItemPrice'])||(float)$item['lineItemPrice']<0||abs((float)($item['lineItemTyDiscount']??0))>0.005)return null;
                $sale+=(float)$item['lineItemPrice'];
            }
        }
        if(!is_finite($sale)||$sale<0)return null;
        $product=$products[$bc]??[];
        // No present-day cost backfill masquerading as historical cost.
        $cost=is_numeric($l['cost_snapshot']??null)&&is_finite((float)$l['cost_snapshot'])&&is_finite((float)$l['cost_snapshot']*$qty)&&(float)$l['cost_snapshot']>0?(float)$l['cost_snapshot']*$qty:null;
        $out[]=['barcode'=>$bc,'name'=>(string)($l['productName']??$product['name']??$bc),'category'=>(string)($product['category']??'Bilinmeyen'),'qty'=>$qty,'sale'=>$sale,'cost'=>$cost];
        $sum+=$sale;
    }
    return abs($sum-(float)($order['total_price']??0))<=0.05?$out:null;
}
function aggregate_report_lines(array $orders,array $products,bool $byCategory=false): array
{
    $groups=[];$excluded=0;
    foreach($orders as $order){
        if(!order_is_active_sale($order))continue;
        $lines=report_order_lines($order,$products);
        if($lines===null){$excluded++;continue;}
        foreach($lines as $l){
            $key=$byCategory?$l['category']:$l['barcode'];
            if(!isset($groups[$key]))$groups[$key]=['name'=>$l['name'],'qty'=>0,'sale'=>0.0,'known_sale'=>0.0,'cost'=>0.0,'missing_qty'=>0];
            $g=&$groups[$key];$g['qty']+=$l['qty'];$g['sale']+=$l['sale'];
            if($l['cost']===null)$g['missing_qty']+=$l['qty'];
            else {$g['cost']+=$l['cost'];$g['known_sale']+=$l['sale'];}
            unset($g);
        }
    }
    return ['groups'=>$groups,'excluded'=>$excluded];
}

/** Excel must not execute user/API supplied text as formulas. Numeric fields remain numeric. */
function csv_safe_text(string $v): string
{
    return preg_match('/^[\x00-\x20]*[=+@-]/',$v) || preg_match('/^[\t\r\n]/',$v) ? "'".$v : $v;
}
