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

    $lo = 0.0;
    $hi = ($cost + $cargo + $extraFee + $serviceFee) * 4 + 1000.0;
    $guard = 0;
    while ($f($hi) < 0 && $guard++ < 60) { $hi *= 2; }
    if (!is_finite($hi) || !is_finite($f($hi)) || $f($hi) < 0) {
        return ['ok' => false, 'error' => 'Hedef kâr mevcut giderlerle ulaşılamıyor.',
            'price' => 0.0, 'profit' => 0.0, 'margin' => 0.0, 'ratio' => 0.0,
            'commission' => 0.0, 'net_kdv' => 0.0, 'service_fee' => $serviceFee,
            'stopaj' => 0.0, 'cargo' => $cargo, 'extra_fee' => $extraFee, 'return_loss' => 0.0];
    }

    for ($i = 0; $i < 200; $i++) {
        $mid = ($lo + $hi) / 2;
        if ($f($mid) > 0) $hi = $mid; else $lo = $mid;
    }
    $P = ($lo + $hi) / 2;
    if (!is_finite($P) || $P <= 0) $P = 0.0;

    $r = calc_profit($cost, $P, $cargo, $rate, $kdvRate, $extraFee, $serviceFee, (isset($options['fixed_stopaj']) ? (float)$options['fixed_stopaj'] : auto_stopaj($P, $saleVat, $stopajRate)), $returnRate, $options);

    return [
        'ok' => true,
        'price' => $P,
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
    if (!order_is_domestic_try($order)) return $fail('Para birimi veya ülke doğrulanmadı; yurtiçi TL hesabına alınmadı.');
    if (!order_is_active_sale($order)) return $fail('İptal/iade: finansal kayıt bekleniyor.');
    if (!is_array($lines) || !$lines || $sale <= 0) return $fail('Sipariş verisi eksik.');
    $cost=0.0; $cargo=0.0; $commission=0.0; $saleVat=0.0; $costVat=0.0;
    $stopaj=0.0; $sum=0.0; $extra=0.0;
    foreach ($lines as $line) {
        $qty=(int)($line['quantity']??0);
        if ($qty<=0 || !isset($line['price']) || !is_numeric($line['price'])) return $fail('Satır fiyatı/adedi eksik.');
        if (!order_is_active_sale($line)) return $fail('Kısmi iptal/iade: finansal kayıt bekleniyor.');
        $product=$byBarcode[$line['barcode']??'']??null;
        if (!$product || (float)($line['cost_snapshot'] ?? $product['cost'] ?? 0)<=0) return $fail('Ürün maliyeti eksik.');
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
        $rate=(float)($line['commission_rate']??$product['rate']??-1);
        $vat=(float)($line['vat_rate']??$product['kdv']??-1);
        $purchaseVat=(float)($line['cost_vat_snapshot']??$product['cost_vat']??$product['kdv']??-1);
        if ($rate<0||$rate>100||$vat<0||$vat>100||$purchaseVat<0||$purchaseVat>100||$lineSale<0) return $fail('Komisyon/KDV verisi geçersiz.');
        $lineCost=(float)($line['cost_snapshot']??$product['cost'])*$qty;
        $sum+=$lineSale; $cost+=$lineCost;
        // Product cargo is a forecast; multi-line package fee allocation is not verified.
        $cargo+=(float)($product['cargo']??0);
        $commission+=$lineSale*$rate/100;
        $saleVat+=$lineSale*$vat/(100+$vat);
        $costVat+=$lineCost*$purchaseVat/(100+$purchaseVat);
        $stopaj+=auto_stopaj($lineSale,$vat);
    }
    if (abs($sum-$sale)>0.05) return $fail('Paket ve satır toplamları uyuşmuyor.');
    $service=trendyol_service_fee();
    $netVat=max(0.0,$saleVat-$costVat-($commission+$cargo+$service)/6);
    $profit=$sale-$cost-$cargo-$commission-$service-$stopaj-$netVat;
    return ['ok'=>true,'estimated'=>true,'sale'=>$sale,'cost'=>$cost,'cargo'=>$cargo,'profit'=>$profit,
        'margin'=>$profit/$sale*100,'ratio'=>$profit/$cost*100,'commission'=>$commission,
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
function product_profit(array $product, ?float $price = null, ?float $rate = null): array
{
    $price = $price ?? (float)$product['price'];
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
