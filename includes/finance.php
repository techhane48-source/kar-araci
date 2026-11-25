<?php
declare(strict_types=1);

/** Preserve sub-kuruş API precision. Round only for display, never per row. */
function finance_units(mixed $value): int
{
    if(!is_numeric($value)||!is_finite((float)$value)||abs((float)$value)>100000000000.0)
        throw new InvalidArgumentException('Finansal tutar geçersiz.');
    return (int)round((float)$value*10000,0,PHP_ROUND_HALF_UP);
}

/** Explicitly scoped to the domestic marketplace. Unknown or foreign data is not TL. */
function finance_is_domestic(array $raw): bool
{
    return ($raw['affiliate']??null)==='TRENDYOLTR' && in_array($raw['country']??null,['Türkiye','Turkey','TR'],true);
}

/**
 * Documented settlement signs. Commission amounts in Sale/etc already enter sellerRevenue;
 * commission-only adjustments are separate. This is a candidate statement balance, not profit.
 * https://developers.trendyol.com/docs/cari-hesap-ekstresi-entegrasyonu
 */
function settlement_signed_components(array $raw,string $type): array
{
    $signs=[
      'Sale'=>[1,-1],'Return'=>[-1,1],'Discount'=>[-1,1],'DiscountCancel'=>[1,-1],
      'Coupon'=>[-1,1],'CouponCancel'=>[1,-1],'ProvisionPositive'=>[1,-1],'ProvisionNegative'=>[-1,1],
      'ManualRefund'=>[-1,1],'ManualRefundCancel'=>[1,-1],'TyDiscount'=>[-1,1],'TyDiscountCancel'=>[1,-1],
      'TyCoupon'=>[-1,1],'TyCouponCancel'=>[1,-1],'SellerRevenuePositive'=>[1,0],'SellerRevenueNegative'=>[-1,0],
      'CommissionPositive'=>[0,1],'CommissionNegative'=>[0,-1],'SellerRevenuePositiveCancel'=>[-1,0],
      'SellerRevenueNegativeCancel'=>[1,0],'CommissionPositiveCancel'=>[0,-1],'CommissionNegativeCancel'=>[0,1],
      'DeliveryFee'=>[1,-1],'DeliveryFeeCancel'=>[-1,1],'PayByLink'=>[1,-1]
    ];
    if(!isset($signs[$type]))throw new InvalidArgumentException('Bilinmeyen finansal işlem türü.');
    [$rs,$cs]=$signs[$type];
    $r=$rs?finance_units($raw['sellerRevenue']??null):0;
    $c=$cs?finance_units($raw['commissionAmount']??null):0;
    // API sign variations must be checked rather than sign-flipped silently.
    if($r<0||$c<0)throw new InvalidArgumentException('Finansal tutar işareti doğrulanamadı.');
    return ['revenue'=>$r*$rs,'commission'=>$c*$cs,'candidate'=>$rs===0?finance_units($raw['credit']??null)-finance_units($raw['debt']??null):$r*$rs];
}

function finance_payout_candidate(array $settlements,array $documents,string $paymentId,float $officialAmount): array
{
    $revenue=0;$commission=0;$settlement=0;$other=0;$payment=0;$paymentRows=0;$seen=[];$issues=[];
    foreach(['settlements'=>$settlements,'otherfinancials'=>$documents] as $source=>$rows){
        foreach($rows as $row){
            $id=(string)($row['externalId']??'');$key=$source.':'.$id;$raw=$row['raw']??[];$type=(string)($row['queryType']??'');
            if($id===''||(string)($row['paymentOrderId']??'')!==$paymentId){$issues[]='Ödeme emri eşleşmeyen kayıt';continue;}
            if(isset($seen[$key])){
                if($seen[$key]!==[$type,$raw])$issues[]='Çelişkili işlem kimliği';
                continue;
            }
            $seen[$key]=[$type,$raw];
            if(!finance_is_domestic($raw)){$issues[]='Yurtiçi kapsamı doğrulanamayan kayıt';continue;}
            try {
                if($source==='settlements'){
                    $c=settlement_signed_components($raw,$type);
                    $revenue+=$c['revenue'];$commission+=$c['commission'];$settlement+=$c['candidate'];
                }else{
                    $net=finance_units($raw['credit']??null)-finance_units($raw['debt']??null);
                    if($type==='PaymentOrder'){$payment-=$net;$paymentRows++;}
                    else $other+=$net;
                }
            }catch(InvalidArgumentException $e){$issues[]=$e->getMessage();}
        }
    }
    if(!$paymentRows)$issues[]='Cari hesap ödeme kaydı yok';
    $official=finance_units($officialAmount);
    $candidate=$settlement+$other;
    // Explicit candidate only: do not declare bank settlement even if amounts match.
    return ['complete_inputs'=>!$issues,'issues'=>array_values(array_unique($issues)),
       'seller_revenue'=>$revenue/10000,'signed_commission'=>$commission/10000,
       'settlement_candidate'=>$settlement/10000,'other_net'=>$other/10000,
       'candidate'=>$issues?null:$candidate/10000,'official'=>$official/10000,
       'statement_payment'=>$paymentRows?$payment/10000:null,
       'candidate_difference'=>$issues?null:($official-$candidate)/10000,
       'statement_difference'=>$paymentRows?($official-$payment)/10000:null,
       'bank_verified'=>false];
}
