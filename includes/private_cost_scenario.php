<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/profit.php';

/** Explicitly hypothetical current unit cost, not an historical cost snapshot.
 * Only exact selected catalog barcodes and fully reconciled eligible packages are included. */
function private_cost_scenario(array $catalog,array $orders,array $barcodes,mixed $grossCost): array
{
    $cost=finance_units($grossCost);
    if($cost<=0||$cost>1000000000000||!$barcodes||count($barcodes)>20000)throw new InvalidArgumentException('Geçersiz senaryo.');
    $available=[];$selected=[];
    foreach($catalog as $p){
        $bc=(string)($p['barcode']??'');
        if($bc===''||isset($available[$bc]))throw new InvalidArgumentException('Katalog kimliği çelişkili.');
        $available[$bc]=true;
    }
    foreach($barcodes as $bc){
        if(!is_string($bc)||$bc===''||!isset($available[$bc])||isset($selected[$bc]))throw new InvalidArgumentException('Barkod seçimi geçersiz.');
        $selected[$bc]=true;
    }
    $packages=0;$units=0;$sales=0;$excluded=0;$mixed=0;$seen=[];
    foreach($orders as $o){
        $pid=(string)($o['packageNumber']??'');if($pid===''||isset($seen[$pid]))throw new InvalidArgumentException('Paket kimliği çelişkili.');
        $seen[$pid]=true;
        $lines=$o['lines']??null;if(!is_array($lines)||!$lines)continue;
        $hits=0;
        foreach($lines as $l)if(is_array($l)&&isset($selected[(string)($l['barcode']??'')]))$hits++;
        if(!$hits)continue;
        if($hits!==count($lines)){$mixed++;continue;}
        if(($o['currencyCode']??null)!=='TRY'||($o['orderCountryCode']??null)!=='TR'||($o['status']??null)!=='Delivered'){$excluded++;continue;}
        $order=['marketplace'=>'trendyol','currency_code'=>'TRY','country_code'=>'TR','status'=>'Delivered','total_price'=>$o['totalPrice']??null,'lines'=>$lines];
        $ids=[];$valid=true;
        foreach($lines as $l){
            $id=(string)($l['line_id']??'');
            if($id===''||isset($ids[$id])){$valid=false;break;}$ids[$id]=true;
        }
        $reconciled=$valid?report_order_lines($order,[]):null;
        if($reconciled===null){$excluded++;continue;}
        $n=0;foreach($reconciled as $l)$n+=$l['qty'];
        $sale=finance_units($order['total_price']);
        if($n<1||$n>1000000||$units+$n>1000000||$cost>intdiv(PHP_INT_MAX, max(1,$units+$n))||$sales>PHP_INT_MAX-$sale)throw new InvalidArgumentException('Senaryo toplam sınırı.');
        $units+=$n;$sales+=$sale;$packages++;
    }
    return ['selected_barcodes'=>count($selected),'eligible_packages'=>$packages,'units'=>$units,'mixed_packages_excluded'=>$mixed,'other_packages_excluded'=>$excluded,
        'gross_sales'=>$packages?$sales/10000.0:null,'hypothetical_gross_cost'=>$packages?$cost*$units/10000.0:null,
        'gross_difference_before_deductions'=>$packages?($sales-$cost*$units)/10000.0:null,
        'historical_cost_verified'=>false,'final_profit_verified'=>false,'net_profit'=>null];
}
/** Candidate selection is a preview, not authorization to assume every matching title has this cost. */
function private_cost_candidates(array $catalog,string $phrase): array
{
    $phrase=trim($phrase);if($phrase===''||mb_strlen($phrase)<3||mb_strlen($phrase)>150)throw new InvalidArgumentException('Geçersiz ürün araması.');
    $out=[];
    foreach($catalog as $p)if(mb_stripos((string)($p['title']??''),$phrase,0,'UTF-8')!==false)
        $out[]=['barcode'=>(string)$p['barcode'],'title'=>(string)$p['title']];
    return $out;
}
