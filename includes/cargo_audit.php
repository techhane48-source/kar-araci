<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/profit.php';
require_once __DIR__.'/cargo_estimate.php';

/** User-entered expected billed desi and gross charge are evidence, not a carrier tariff. */
function cargo_expectation(string $desi,string $amount): array
{
    $d=parse_money_input($desi);$a=trim($amount)===''?null:parse_money_input($amount);
    if($d===null||$d<=0||$d>100000||(trim($amount)!==''&&($a===null||$a<0||$a>100000000)))
        throw new InvalidArgumentException('Beklenen fatura desisi ve tutarı geçerli olmalı.');
    return [$d,$a];
}
function cargo_audit_row(array $r): array
{
    $out=['state'=>'unknown','label'=>'Fatura kapsamı doğrulanamadı','desi_diff'=>null,'amount_diff'=>null,'gross_amount'=>null];
    $raw=$r['invoice_raw']??null;if(is_string($raw))$raw=json_decode($raw,true);
    if(!is_array($raw)||!finance_is_domestic($raw)||(string)($raw['id']??'')!==(string)$r['invoice_serial']
        ||!in_array($r['shipment_type']??'', ['Gönderi Kargo Bedeli','İade Kargo Bedeli'],true)
        ||(int)($r['duplicate_count']??1)!==1||empty($r['bound_seller'])||(string)($raw['sellerId']??'')!==(string)$r['bound_seller'])return $out;
    try{
        $actual=finance_units($r['amount']??null);$desi=finance_units($r['desi']??null);
        if($actual<0||$desi<0)return $out;
        $gross=($r['invoice_basis']??null)==='gross'?$actual:(($r['invoice_basis']??null)==='net'?(int)round($actual*1.2,0,PHP_ROUND_HALF_UP):null);
        $out['gross_amount']=$gross===null?null:$gross/10000.0;
        if(!isset($r['expected_desi']))return array_replace($out,['state'=>'missing','label'=>'Beklenen fatura desisi girilmemiş']);
        $expected=finance_units($r['expected_desi']);if($expected<=0)return $out;
        $dd=$desi-$expected;$out['desi_diff']=$dd/10000.0;
        $ad=null;if(isset($r['expected_amount'])&&$gross!==null){$ad=$gross-finance_units($r['expected_amount']);$out['amount_diff']=$ad/10000.0;}
        if($dd>100||($ad!==null&&$ad>100))return array_replace($out,['state'=>'review','label'=>'Beklenen değerin üzerinde; belge ile inceleyin']);
        if($ad===null)return array_replace($out,['state'=>'desi_only','label'=>isset($r['expected_amount'])?'Fatura KDV temeli doğrulanmadı; tutar farkı hesaplanmadı':'Desi karşılaştırıldı; tutar doğrulanmadı']);
        return array_replace($out,['state'=>'compared','label'=>'Girilen beklentiyi aşmıyor; kesin mutabakat değil']);
    }catch(InvalidArgumentException $e){return $out;}
}
function cargo_save_expectation(PDO $db,int $uid,string $invoice,string $parcel,string $type,float $desi,?float $amount): void
{
    if(!is_finite($desi)||$desi<=0||$desi>100000||($amount!==null&&(!is_finite($amount)||$amount<0||$amount>100000000)))throw new InvalidArgumentException('Geçersiz beklenti.');
    $q=$db->prepare("SELECT COUNT(*) FROM trendyol_cargo_items WHERE user_id=? AND invoice_serial=? AND parcel_id=? AND shipment_type=?");
    $q->execute([$uid,$invoice,$parcel,$type]);
    if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Tekil fatura kalemi bulunamadı.');
    $db->prepare("INSERT INTO cargo_expectations(user_id,invoice_serial,parcel_id,shipment_type,expected_desi,expected_amount,updated_at) VALUES(?,?,?,?,?,?,?)
        ON CONFLICT(user_id,invoice_serial,parcel_id,shipment_type) DO UPDATE SET expected_desi=excluded.expected_desi,expected_amount=excluded.expected_amount,updated_at=excluded.updated_at")
        ->execute([$uid,$invoice,$parcel,$type,$desi,$amount,time()]);
}
function cargo_audit_query(PDO $db,int $uid,string $search,int $limit=50,int $offset=0): PDOStatement
{
    $sql="SELECT c.*,d.raw_json AS invoice_raw,d.transaction_date,e.expected_desi,e.expected_amount,e.updated_at,
       (SELECT COUNT(*) FROM trendyol_cargo_items x WHERE x.user_id=c.user_id AND x.invoice_serial=c.invoice_serial AND x.parcel_id=c.parcel_id AND x.shipment_type=c.shipment_type) AS duplicate_count
       FROM trendyol_cargo_items c LEFT JOIN trendyol_financial_documents d ON d.user_id=c.user_id AND d.external_id=c.invoice_serial AND d.query_type='DeductionInvoices'
       LEFT JOIN cargo_expectations e ON e.user_id=c.user_id AND e.invoice_serial=c.invoice_serial AND e.parcel_id=c.parcel_id AND e.shipment_type=c.shipment_type
       WHERE c.user_id=?";
    $args=[$uid];if($search!==''){$sql.=" AND (c.invoice_serial=? OR c.parcel_id=? OR c.order_number=?)";array_push($args,$search,$search,$search);}
    $sql.=" ORDER BY d.transaction_date DESC,c.invoice_serial,c.row_number";
    if($limit>0)$sql.=" LIMIT ".max(1,min(100,$limit))." OFFSET ".max(0,$offset);
    $q=$db->prepare($sql);$q->execute($args);return $q;
}

/** Enrich a bounded batch with the full invoice's arithmetic basis, even if the search
 * matches only one detail row. Never compare a net API charge to a gross expectation. */
function cargo_audit_rows(PDO $db,int $uid,PDOStatement $q): Generator
{
    $s=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$s->execute([$uid]);$seller=(string)($s->fetchColumn()?:'');
    while(true){
        $batch=[];
        while(count($batch)<100&&($row=$q->fetch()))$batch[]=$row;
        if(!$batch)break;
        $basis=cargo_invoice_basis_map($db,$uid,$seller,array_column($batch,'invoice_serial'));
        foreach($batch as $row){
            $row['bound_seller']=$seller;$row['invoice_basis']=$basis[$row['invoice_serial']]??null;
            yield $row;
        }
    }
}
