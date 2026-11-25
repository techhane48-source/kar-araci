<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/sync_coverage.php';

/** Group actual statement records by exact barcode, at transaction date. No cost or freight allocation. */
function product_finance_row(array $row,string $seller): ?array
{
    $raw=json_decode((string)($row['raw_json']??''),true);
    $bc=(string)($row['barcode']??'');$type=(string)($row['query_type']??'');
    if(!is_array($raw)||$seller===''||!finance_is_domestic($raw)||
       (string)($raw['sellerId']??'')!==$seller||$bc===''||(string)($raw['barcode']??'')!==$bc||
       (string)($row['external_id']??'')===''||(string)($raw['id']??'')!==(string)$row['external_id'])return null;
    try{
        $credit=finance_units($raw['credit']??null);$debt=finance_units($raw['debt']??null);
        if($credit<0||$debt<0)throw new InvalidArgumentException('Borç/alacak işareti');
        $parts=settlement_signed_components($raw,$type);
        if($type==='Sale'&&($credit<=0||$debt!==0||abs($credit-$parts['revenue']+$parts['commission'])>100))throw new InvalidArgumentException('Satış tutarı');
        if($type==='Return'&&($debt<=0||$credit!==0||abs($debt+$parts['revenue']-$parts['commission'])>100))throw new InvalidArgumentException('İade tutarı');
    }catch(InvalidArgumentException $e){return null;}
    return ['barcode'=>$bc,'type'=>$type,'credit'=>$credit,'debt'=>$debt,'revenue'=>$parts['revenue'],'commission'=>$parts['commission'],'candidate'=>$parts['candidate']];
}
function product_finance_period(PDO $db,int $uid,int $start,int $end): array
{
    $q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");
    $q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
    $q=$db->prepare("SELECT external_id,barcode,query_type,raw_json FROM settlements WHERE user_id=? AND marketplace='trendyol' AND transaction_date>=? AND transaction_date<?");
    $q->execute([$uid,$start,$end]);$groups=[];$excluded=0;
    while($row=$q->fetch()){
        $r=product_finance_row($row,$seller);if($r===null){$excluded++;continue;}
        $bc=$r['barcode'];
        if(!isset($groups[$bc]))$groups[$bc]=['sale_n'=>0,'return_n'=>0,'sale_credit'=>0,'return_debt'=>0,'sale_commission'=>0,'return_commission'=>0,'sale_revenue'=>0,'return_revenue'=>0,'campaign_candidate'=>0,'campaign_n'=>0,'adjustments'=>0,'rows'=>0];
        $g=&$groups[$bc];$g['rows']++;
        switch($r['type']){
            case 'Sale':$g['sale_n']++;$g['sale_credit']+=$r['credit'];$g['sale_commission']-=$r['commission'];$g['sale_revenue']+=$r['revenue'];break;
            case 'Return':$g['return_n']++;$g['return_debt']+=$r['debt'];$g['return_commission']+=$r['commission'];$g['return_revenue']-=$r['revenue'];break;
            case 'Discount':case 'DiscountCancel':case 'Coupon':case 'CouponCancel':case 'TyDiscount':case 'TyDiscountCancel':case 'TyCoupon':case 'TyCouponCancel':
                $g['campaign_candidate']+=$r['candidate'];$g['campaign_n']++;break;
            default:$g['adjustments']++;break;
        }
        unset($g);
    }
    ksort($groups,SORT_STRING);
    return ['groups'=>$groups,'excluded'=>$excluded,'gaps'=>sync_range_gaps(sync_read_ranges($db,$uid,'trendyol','settlements_transaction'),$start,$end)];
}
