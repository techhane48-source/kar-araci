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

/** Local read-only scenario. Existing financial overlays remain authoritative; no cost is persisted.
 * An explicit purchase VAT rate is required before invoking the existing estimate model. */
function private_local_cost_scenario(array $products,array $orders,array $barcodes,mixed $grossCost,?float $purchaseVat): array
{
    if($purchaseVat!==null&&(!is_finite($purchaseVat)||$purchaseVat<0||$purchaseVat>100))throw new InvalidArgumentException('Maliyet KDV oranı geçersiz.');
    $catalog=[];$map=[];$remote=[];
    foreach($products as $p){
        $bc=(string)($p['barcode']??'');if($bc==='')continue;
        $catalog[]=['barcode'=>$bc];$map[$bc]=$p;
    }
    foreach($orders as $o){
        $remote[]=['packageNumber'=>$o['package_number']??null,'currencyCode'=>$o['currency_code']??null,
          'orderCountryCode'=>$o['country_code']??null,'status'=>$o['status']??null,'totalPrice'=>$o['total_price']??null,'lines'=>$o['lines']??null];
    }
    $out=private_cost_scenario($catalog,$remote,$barcodes,$grossCost);
    $out['estimated_packages']=0;$out['estimated_profit']=null;$out['estimated_sale']=null;$out['estimate_gaps']=[];
    if($purchaseVat===null)return $out;
    $selected=array_fill_keys($barcodes,true);$sum=0.0;$sale=0.0;
    foreach($orders as $o){
        if(($o['marketplace']??null)!=='trendyol'||($o['status']??null)!=='Delivered'||!order_is_domestic_try($o)||!is_array($o['lines']??null)||!$o['lines'])continue;
        $valid=true;$ids=[];
        foreach($o['lines'] as &$line){
            if(!is_array($line)||!isset($selected[(string)($line['barcode']??'')])){$valid=false;break;}
            $id=(string)($line['line_id']??'');
            if($id===''||isset($ids[$id])){$valid=false;break;}$ids[$id]=true;
            $line['cost_snapshot']=(float)$grossCost;$line['cost_vat_snapshot']=$purchaseVat;
        }unset($line);
        if(!$valid||report_order_lines($o,[])===null)continue;
        // A delivered order cannot fall back to today's product freight. Missing evidence is a gap.
        if(!array_key_exists('cargo_override',$o)){$o['cargo_override']=null;$o['cargo_source']='Gönderi kargo kanıtı eksik';}
        $p=calc_order_profit($o,$map);
        if(!$p['ok']){$reason=(string)($p['reason']??'Veri eksik');$out['estimate_gaps'][$reason]=($out['estimate_gaps'][$reason]??0)+1;continue;}
        $out['estimated_packages']++;$sum+=$p['profit'];$sale+=$p['sale'];
    }
    if($out['estimated_packages']){$out['estimated_profit']=$sum;$out['estimated_sale']=$sale;}
    return $out;
}
