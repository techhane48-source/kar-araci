<?php
declare(strict_types=1);

/** Normalize the documented getClaims response. No personal names, addresses or customer notes retained. */
function normalize_claim(array $claim): array
{
    $id=(string)($claim['id']??$claim['claimId']??'');
    $order=(string)($claim['orderNumber']??'');
    $modified=$claim['lastModifiedDate']??null;$created=$claim['claimDate']??null;
    if($id===''||$order===''||!is_numeric($modified)||!is_numeric($created)||$modified<=0||$created<=0
        ||!is_array($claim['items']??null)||!$claim['items'])
        throw new InvalidArgumentException('İade paketi verisi eksik.');
    $out=[];$seen=[];
    foreach($claim['items'] as $group){
        $line=$group['orderLine']??[];$bc=(string)($line['barcode']??'');
        if(!is_array($group['claimItems']??null)||!$group['claimItems']||$bc==='')
            throw new InvalidArgumentException('İade ürün grubu eksik.');
        foreach($group['claimItems'] as $item){
            $itemId=(string)($item['id']??'');$lineId=(string)($item['orderLineItemId']??'');
            $status=(string)($item['claimItemStatus']['name']??'');
            if($itemId===''||$lineId===''||$status===''||isset($seen[$itemId]))
                throw new InvalidArgumentException('İade kalemi kimliği veya statüsü geçersiz.');
            $seen[$itemId]=true;
            $out[]=['claim_id'=>$id,'item_id'=>$itemId,'order_number'=>$order,
                'outbound_package'=>(string)($claim['orderOutboundPackageId']??''),
                'return_package'=>(string)($claim['orderShipmentPackageId']??''),
                'line_id'=>$lineId,'barcode'=>$bc,'product_name'=>(string)($line['productName']??''),
                'status'=>$status,'reason'=>(string)($item['customerClaimItemReason']['name']??''),
                'platform_reason'=>(string)($item['trendyolClaimItemReason']['name']??''),
                'auto_accepted'=>is_bool($item['autoAccepted']??null)?(int)$item['autoAccepted']:null,
                'seller_accepted'=>is_bool($item['acceptedBySeller']??null)?(int)$item['acceptedBySeller']:null,
                'created_ms'=>(int)$created,'modified_ms'=>(int)$modified];
        }
    }
    return $out;
}

/** Exact package/order/barcode match only. Never allocate order-level fees to an individual returned unit. */
function claim_order_match(array $claim,?array $order): array
{
    $unknown=['state'=>'Sipariş eşleşmedi','scope'=>'unknown','cost'=>null];
    if(!$order||$claim['outbound_package']===''||(string)$order['package_number']!==$claim['outbound_package']
        ||(string)$order['order_number']!==$claim['order_number'])return $unknown;
    $scope=($order['currency_code']??null)==='TRY'&&($order['country_code']??null)==='TR'?'domestic':'unverified';
    $lines=is_array($order['lines'])?$order['lines']:json_decode($order['lines'],true);
    $matches=[];
    foreach($lines?:[] as $l){
        if((string)($l['line_id']??'')===$claim['line_id']&&(string)($l['barcode']??'')===$claim['barcode'])$matches[]=$l;
    }
    if(count($matches)!==1)return ['state'=>'Paket eşleşti; ürün satırı doğrulanamadı','scope'=>$scope,'cost'=>null];
    $cost=$matches[0]['cost_snapshot']??null;
    return ['state'=>'Paket ve ürün satırı eşleşti','scope'=>$scope,'cost'=>$scope==='domestic'&&is_numeric($cost)&&(float)$cost>0?(float)$cost:null];
}
function persist_claims(PDO $db,int $uid,array $rows): int
{
    $q=$db->prepare("INSERT INTO trendyol_claim_items(user_id,claim_id,item_id,order_number,outbound_package,return_package,line_id,barcode,product_name,status,reason,platform_reason,auto_accepted,seller_accepted,created_ms,modified_ms)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,item_id) DO UPDATE SET
      claim_id=excluded.claim_id,order_number=excluded.order_number,outbound_package=excluded.outbound_package,return_package=excluded.return_package,
      line_id=excluded.line_id,barcode=excluded.barcode,product_name=excluded.product_name,status=excluded.status,reason=excluded.reason,
      platform_reason=excluded.platform_reason,auto_accepted=excluded.auto_accepted,seller_accepted=excluded.seller_accepted,
      created_ms=excluded.created_ms,modified_ms=excluded.modified_ms WHERE excluded.modified_ms>=trendyol_claim_items.modified_ms");
    $n=0;
    foreach($rows as $r){
        $q->execute([$uid,$r['claim_id'],$r['item_id'],$r['order_number'],$r['outbound_package'],$r['return_package'],$r['line_id'],$r['barcode'],$r['product_name'],$r['status'],$r['reason'],$r['platform_reason'],$r['auto_accepted'],$r['seller_accepted'],$r['created_ms'],$r['modified_ms']]);
        $n+=$q->rowCount();
    }
    return $n;
}
