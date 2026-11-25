<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/sync_coverage.php';

/** Confirm filtered documents against the unfiltered invoice response, without double-counting. */
function service_fee_ids(array $all,array $filtered,string $seller): array
{
    $base=[];$result=[];
    foreach($all as $row){
        $id=(string)($row['externalId']??'');if($id===''||isset($base[$id]))throw new InvalidArgumentException('Belge kimliği çelişkili.');
        $base[$id]=$row['raw']??null;
    }
    foreach($filtered as $row){
        $id=(string)($row['externalId']??'');$r=$row['raw']??null;$b=$base[$id]??null;
        if($id===''||isset($result[$id])||!is_array($r)||!is_array($b))throw new InvalidArgumentException('Hizmet faturası genel ekstreyle eşleşmedi.');
        foreach([$r,$b] as $raw)if((string)($raw['id']??'')!==$id||(string)($raw['sellerId']??'')!==$seller)throw new InvalidArgumentException('Fatura satıcısı/kimliği uyuşmuyor.');
        foreach(['transactionDate','affiliate','country'] as $k)if((string)($r[$k]??'')!==(string)($b[$k]??''))throw new InvalidArgumentException('Fatura kapsamı değişti.');
        foreach(['credit','debt'] as $k)if(finance_units($r[$k]??null)!==finance_units($b[$k]??null))throw new InvalidArgumentException('Fatura tutarı değişti.');
        $result[$id]=true;
    }
    return $result;
}
/** Each row belongs to one source group. Amounts are statement movements, not tax expenses or realized profit. */
function period_document_group(array $row,array $raw): string
{
    $type=(string)$row['query_type'];
    if($type==='DeductionInvoices'){
        if((int)($row['service_fee_verified']??0)===1)return 'Platform hizmet bedeli (filtre doğrulamalı)';
        if(in_array($row['transaction_type']??'',['Kargo Faturası','Kargo Fatura'],true))return 'Kargo faturası';
        // Text evidence is only a candidate, not a verified advertising classification.
        if(preg_match('/reklam|influencer|advertis/iu',(string)($row['transaction_type']??'').' '.(string)($raw['description']??'')))return 'Reklam / influencer adayı';
        return 'Diğer / sınıflandırılmamış hizmet faturası';
    }
    return ['Stoppage'=>'Stopaj','CashAdvance'=>'Finansman','WireTransfer'=>'Virman','IncomingTransfer'=>'Gelen havale',
      'ReturnInvoice'=>'İade faturası','CommissionAgreementInvoice'=>'Komisyon mutabakatı','PaymentOrder'=>'Ödeme emri','FinancialItem'=>'Diğer finans hareketi'][$type]??'Bilinmeyen belge türü';
}
function period_finance(PDO $db,int $uid,int $start,int $end): array
{
    $q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
    $groups=[];$excluded=0;
    $queries=['settlements'=>"SELECT external_id,query_type,raw_json FROM settlements WHERE user_id=? AND marketplace='trendyol' AND transaction_date>=? AND transaction_date<?",
       'documents'=>"SELECT external_id,query_type,transaction_type,raw_json,service_fee_verified FROM trendyol_financial_documents WHERE user_id=? AND transaction_date>=? AND transaction_date<?"];
    foreach($queries as $source=>$sql){
        $q=$db->prepare($sql);$q->execute([$uid,$start,$end]);
        while($row=$q->fetch()){
            $raw=json_decode((string)($row['raw_json']??''),true);
            if(!is_array($raw)||!finance_is_domestic($raw)||$seller===''||(string)($raw['sellerId']??'')!==$seller||
              (string)($raw['id']??'')===''||(string)$raw['id']!==(string)$row['external_id']){$excluded++;continue;}
            try{$c=finance_units($raw['credit']??null);$d=finance_units($raw['debt']??null);if($c<0||$d<0)throw new InvalidArgumentException('İşaret');}
            catch(InvalidArgumentException $e){$excluded++;continue;}
            $group=$source==='settlements'?'Ekstre: '.(string)$row['query_type']:period_document_group($row,$raw);
            if(!isset($groups[$group]))$groups[$group]=['count'=>0,'credit'=>0,'debt'=>0];
            $groups[$group]['count']++;$groups[$group]['credit']+=$c;$groups[$group]['debt']+=$d;
        }
    }
    ksort($groups);$coverage=[];
    foreach(['settlements_transaction','documents_transaction','service_fee_transaction'] as $job)$coverage[$job]=sync_range_gaps(sync_read_ranges($db,$uid,'trendyol',$job),$start,$end);
    return ['groups'=>$groups,'excluded'=>$excluded,'coverage'=>$coverage];
}
