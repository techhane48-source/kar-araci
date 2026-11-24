<?php
declare(strict_types=1);

const PROFIT_MAX_AMOUNT = 1000000000.0;

function profit_validate(array $amounts, array $percentages = []): void
{
    foreach ($amounts as $value) {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > PROFIT_MAX_AMOUNT) {
            throw new InvalidArgumentException('Tutarlar 0 ile 1 milyar TL arasında olmalı.');
        }
    }
    foreach ($percentages as $value) {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 100) {
            throw new InvalidArgumentException('Oranlar %0 ile %100 arasında olmalı.');
        }
    }
}

/** Tüm tutarlar KDV dahil; giderler ürün adedi başına tanımlıdır. */
function calc_profit(float $cost, float $price, float $cargo, float $rate, float $kdvRate = 20.0, float $extraFee = 0.0, float $serviceFee = 0.0, float $stopaj = 0.0, float $returnRate = 0.0): array
{
    profit_validate([$cost,$price,$cargo,$extraFee,$serviceFee,$stopaj], [$rate,$kdvRate,$returnRate]);
    $commission = $price * $rate / 100;
    $returnLoss = $price * $returnRate / 100;
    $netKdv = max(0.0, ($price-$cost-$commission-$cargo-$extraFee-$serviceFee) * $kdvRate / (100+$kdvRate));
    $profit = $price-$cost-$commission-$cargo-$extraFee-$serviceFee-$stopaj-$netKdv-$returnLoss;
    return ['ok'=>true, 'price'=>$price, 'sale'=>$price, 'cost'=>$cost, 'cargo'=>$cargo,
        'profit'=>$profit, 'margin'=>$price>0 ? $profit/$price*100 : 0.0,
        'ratio'=>$cost>0 ? $profit/$cost*100 : 0.0, 'commission'=>$commission,
        'net_kdv'=>$netKdv, 'extra_fee'=>$extraFee, 'service_fee'=>$serviceFee,
        'stopaj'=>$stopaj, 'return_loss'=>$returnLoss,
        'totalCuts'=>$commission+$cargo+$extraFee+$serviceFee+$stopaj+$netKdv+$returnLoss];
}

function auto_stopaj(float $price, float $kdvRate = 20.0, float $stopajRate = 1.0): float
{
    profit_validate([$price], [$kdvRate,$stopajRate]);
    return $price / (1+$kdvRate/100) * $stopajRate/100;
}

/**
 * Trendyol platform hizmet bedeli TAHLİNİ (Melontik modelinden alınan sabit).
 * Gerçek bedel `otherfinancials` → `DeductionInvoices` → `transactionSubType=PlatformServiceFee`
 * kaydında bildirilir; resmî belgede sipariş/paket bağı (nullable orderNumber/shipmentPackageId,
 * örnek yok) doğrulanmadığı için kâr hesabında tahmin korunur. Gerçek komisyondan (settlements
 * `Sale`) ayrı bir kalemdir; iki kez düşülmez.
 */
function trendyol_service_fee(): float { return 13.19; }

/** Sıfır değerler varsayılana dönüştürülmez. Eski kayıtların stopajı sabit TL'dir. */
function product_profit_inputs(array $p, ?float $price = null): array
{
    $value = static fn(string $key, float $default=0.0): float => (float)($p[$key] ?? $default);
    $price ??= $value('price');
    $kdv = $value('kdv',20.0);
    $stopaj = ($p['stopaj_mode'] ?? 'manual') === 'auto'
        ? auto_stopaj($price,$kdv) : $value('stopaj');
    return [$value('cost'),$price,$value('cargo'),$value('rate'),$kdv,
        $value('extra_fee'),$value('service_fee'),$stopaj,$value('return_rate')];
}

function calc_product_profit(array $p, ?float $price = null): array
{
    try {
        if ((float)($p['cost'] ?? 0) <= 0) throw new InvalidArgumentException('Ürün maliyeti eksik.');
        return calc_profit(...product_profit_inputs($p,$price));
    } catch (InvalidArgumentException $e) {
        return ['ok'=>false,'missing'=>true,'profit'=>null,'margin'=>null,'ratio'=>null,
            'commission'=>null,'error'=>$e->getMessage()];
    }
}

/** Hedef marj varsa onu, yoksa TL kârını çözer. Manuel stopaj sabit TL olarak korunur. */
function calc_price(float $cost, float $cargo, float $rate, float $kdvRate = 20.0,
    float $extraFee = 0.0, float $serviceFee = 13.19, float $stopajRate = 1.0,
    float $returnRate = 0.0, float $targetProfit = 0.0, float $targetMargin = 0.0,
    ?float $manualStopaj = null): array
{
    try {
        profit_validate([$cost,$cargo,$extraFee,$serviceFee,$targetProfit,$manualStopaj ?? 0],
            [$rate,$kdvRate,$stopajRate,$returnRate,$targetMargin]);
        $vat = $kdvRate/(100+$kdvRate);
        $withholding = $manualStopaj === null ? $stopajRate/(100+$kdvRate) : 0.0;
        $margin = $targetMargin/100;
        $slope = (1-$rate/100)*(1-$vat)-$returnRate/100-$withholding-$margin;
        if ($slope <= 1e-12) throw new InvalidArgumentException('Bu kesintilerle hedef kâra ulaşılamıyor.');
        $fixed = $cost+$cargo+$extraFee+$serviceFee;
        $exact = ($fixed*(1-$vat)+($manualStopaj ?? 0)+($targetMargin>0 ? 0 : $targetProfit))/$slope;
        $price = max(0.01,ceil($exact*100-1e-7)/100);
        if (!is_finite($price) || $price > PROFIT_MAX_AMOUNT) throw new InvalidArgumentException('Hedef için gereken fiyat izin verilen sınırı aşıyor.');
        $stopaj = $manualStopaj ?? auto_stopaj($price,$kdvRate,$stopajRate);
        $result = calc_profit($cost,$price,$cargo,$rate,$kdvRate,$extraFee,$serviceFee,$stopaj,$returnRate);
        $target = $targetMargin>0 ? $price*$margin : $targetProfit;
        if ($result['profit']+1e-7 < $target) throw new InvalidArgumentException('Hedef fiyat hesaplanamadı.');
        return $result;
    } catch (InvalidArgumentException $e) {
        return ['ok'=>false,'price'=>0.0,'profit'=>null,'margin'=>null,'ratio'=>null,'error'=>$e->getMessage()];
    }
}

function calc_product_price(array $p,float $targetProfit=0.0,float $targetMargin=0.0): array
{
    return calc_price((float)$p['cost'],(float)($p['cargo']??0),(float)$p['rate'],(float)($p['kdv']??20),
        (float)($p['extra_fee']??0),(float)($p['service_fee']??0),1.0,(float)($p['return_rate']??0),
        $targetProfit,$targetMargin,($p['stopaj_mode']??'manual')==='auto' ? null : (float)($p['stopaj']??0));
}

function order_is_sale(array $order): bool
{
    $status = strtolower(strtr(trim((string)($order['status']??'')), ['İ'=>'i','ı'=>'i']));
    return !in_array($status,['awaiting','verified','unpacked','cancelled','canceled','returned','undelivered','unsupplied','undeliveredandreturned','iptal','iade'],true);
}

/** Captures assumptions when they first become known, not proof of the purchase cost on order date. */
function capture_order_profit_inputs(array $lines,array $products,int $capturedAt): array
{
    $fields=array_fill_keys(['name','category','cost','cargo','rate','kdv','extra_fee','service_fee','stopaj','return_rate','stopaj_mode'],true);$snapshot=[];
    foreach($lines as $line){$barcode=$line['barcode']??'';if(!is_string($barcode)||$barcode==='')continue;$p=$products[$barcode]??null;$snapshot[$barcode]=$p&&is_numeric($p['cost']??null)&&(float)$p['cost']>0?array_intersect_key($p,$fields)+['_captured_at'=>$capturedAt]:null;}
    return ['version'=>1,'origin'=>'import_estimate','captured_at'=>$capturedAt,'products'=>$snapshot];
}

function merge_order_profit_inputs(?string $old,array $new): array
{
    if($old===null)return $new;
    $saved=json_decode($old,true);
    if(!is_array($saved)||($saved['version']??null)!==1||!is_array($saved['products']??null))throw new RuntimeException('Sipariş maliyet kaydı okunamıyor; önce kayıt düzeltilmeli.');
    foreach($new['products'] as $barcode=>$p)if(!isset($saved['products'][$barcode])&&$p!==null){$saved['products'][$barcode]=$p;$saved['updated_at']=$new['captured_at'];}
    return $saved;
}

/** Paket tutarı satırların satış ağırlığıyla dağıtılır; her satır kendi KDV/giderleriyle hesaplanır. */
function calc_order_profit(array $order,array $byBarcode): array
{
    $sale=(float)($order['total_price']??0);
    $lines=$order['lines']??[];
    $unknown=['ok'=>false,'missing'=>true,'sale'=>$sale,'profit'=>null,'lines'=>[]];
    if(isset($order['profit_inputs'])){
        $snapshot=is_string($order['profit_inputs'])?json_decode($order['profit_inputs'],true):$order['profit_inputs'];
        if(!is_array($snapshot)||($snapshot['version']??null)!==1||!is_array($snapshot['products']??null))return $unknown;
        $byBarcode=$snapshot['products'];
    }
    if (!order_is_sale($order)) return $unknown+['excluded'=>true];
    if (!is_array($lines) || !$lines || !is_finite($sale) || $sale<=0) return $unknown;
    $weights=[];
    foreach ($lines as $line) {
        $qty=(int)($line['quantity']??1);
        $price=(float)($line['price']??0);
        if ($qty<1 || !is_finite($price) || $price<0) return $unknown;
        $weights[]=$price*$qty;
    }
    $weight=array_sum($weights);
    if ($weight<=0) return $unknown;
    // The default Trendyol estimate is charged once per calculated package.
    // Stored custom service fees remain unit inputs.
    $defaultFee = trendyol_service_fee();
    $feeWeights = [];
    if (($order['marketplace'] ?? '') === 'trendyol') {
        foreach ($lines as $i => $line) {
            $product = $byBarcode[$line['barcode'] ?? ''] ?? [];
            if (is_numeric($product['service_fee'] ?? null) && (float)$product['service_fee'] === $defaultFee) {
                $feeWeights[$i] = $weights[$i];
            }
        }
    }
    $feeWeight = array_sum($feeWeights);
    $feeCount = count($feeWeights);
    $totals=array_fill_keys(['sale','cost','cargo','profit','commission','service_fee','stopaj','net_kdv','extra_fee','return_loss','totalCuts'],0.0);
    $parts=[]; $remaining=$sale; $complete=true;
    foreach ($lines as $i=>$line) {
        $qty=(int)($line['quantity']??1);
        $lineSale=$i===count($lines)-1 ? $remaining : $sale*$weights[$i]/$weight;
        $remaining-=$lineSale;
        $p=$byBarcode[$line['barcode']??'']??null;
        if ($p && ($order['marketplace'] ?? '') === 'trendyol') {
            foreach (['commissionRate' => 'rate', 'vatRate' => 'kdv'] as $field => $input) {
                $rate = $line[$field] ?? null;
                if (is_numeric($rate) && (float)$rate >= 0 && (float)$rate <= 100) {
                    $p[$input] = (float)$rate;
                }
            }
        }
        if (isset($feeWeights[$i])) {
            $share = $feeWeight > 0 ? $feeWeights[$i] / $feeWeight : 1 / $feeCount;
            $p['service_fee'] = $defaultFee * $share / $qty;
        }
        $r=$p ? calc_product_profit($p,max(0.0,$lineSale/$qty)) : ['ok'=>false];
        $part=['barcode'=>(string)($line['barcode']??''),'name'=>(string)($p['name']??$line['productName']??''),
            'category'=>(string)($p['category']??'Bilinmeyen'),'quantity'=>$qty,'sale'=>$lineSale];
        if (!$r['ok']) { $complete=false; $parts[]=$part+['ok'=>false,'profit'=>null]; continue; }
        foreach ($totals as $key=>$_) {
            $amount=$key==='sale' ? $lineSale : $r[$key]*$qty;
            $totals[$key]+=$amount;
            if ($key!=='sale') $part[$key]=$amount;
        }
        $parts[]=$part+['ok'=>true];
    }
    // Bir satır eksikse siparişin ve o siparişe ait ürün/kategori dağılımının kârı bilinmez.
    if (!$complete) return array_replace($unknown,['lines'=>$parts]);
    return $totals+['ok'=>true,'lines'=>$parts,'margin'=>$totals['profit']/$sale*100,
        'ratio'=>$totals['cost']>0 ? $totals['profit']/$totals['cost']*100 : 0.0];
}

/** Ürün/kategori raporları aynı sipariş hesabının satırlarını toplar. */
function aggregate_profit_rows(array $orders,array $byBarcode,string $group): array
{
    $rows=[];
    foreach ($orders as $order) {
        if (!order_is_sale($order)) continue;
        $r=calc_order_profit($order,$byBarcode);
        $parts = $r['lines'] ?: [['barcode'=>'(eşleştirilemedi)','name'=>'Sipariş satırı eksik',
            'category'=>'Bilinmeyen','quantity'=>0,'sale'=>(float)$order['total_price']]];
        foreach ($parts as $line) {
            $key=$group==='category' ? $line['category'] : $line['barcode'];
            if (!isset($rows[$key])) $rows[$key]=['key'=>$key,'name'=>$line['name'],'quantity'=>0,'sale'=>0.0,
                'cost'=>0.0,'profit'=>0.0,'complete'=>true];
            $rows[$key]['quantity']+=$line['quantity'];
            $rows[$key]['sale']+=$line['sale'];
            if ($r['ok']) {
                $rows[$key]['cost']+=$line['cost'];
                $rows[$key]['profit']+=$line['profit'];
            } else $rows[$key]['complete']=false;
        }
    }
    foreach ($rows as &$row) {
        $row['margin']=$row['complete'] && $row['sale']>0 ? $row['profit']/$row['sale']*100 : null;
        if (!$row['complete']) { $row['cost']=null; $row['profit']=null; }
    }
    unset($row);
    return $rows;
}
