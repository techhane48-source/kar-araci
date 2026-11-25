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
        // The official filter must not contradict the returned explicit document classification.
        foreach([$r,$b] as $raw){
            $label=(string)($raw['transactionType']??'');
            if(in_array($label,['Kargo Faturası','Kargo Fatura'],true)||
               (isset($raw['transactionSubType'])&&$raw['transactionSubType']!=='PlatformServiceFee'))
                throw new InvalidArgumentException('Platform hizmet bedeli filtresi belge türüyle çelişiyor.');
        }
        $result[$id]=true;
    }
    return $result;
}
/** Each row belongs to one source group. Amounts are statement movements, not tax expenses or realized profit. */
function period_document_group(array $row,array $raw): string
{
    $type=(string)$row['query_type'];
    if($type==='DeductionInvoices'){
        if(in_array($row['transaction_type']??'',['Kargo Faturası','Kargo Fatura'],true))return 'Kargo faturası';
        if((int)($row['service_fee_verified']??0)===1&&(!isset($raw['transactionSubType'])||$raw['transactionSubType']==='PlatformServiceFee'))return 'Platform hizmet bedeli (filtre doğrulamalı)';
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

/** Store-level cash evidence; never net these figures against order-date estimated profit. */
function period_expense_evidence(PDO $db,int $uid,int $start,int $end): array
{
    $q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");
    $q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
    $groups=[];
    foreach(['service','withholding','cargo','other_invoice','other_adjustment'] as $key)
        $groups[$key]=['count'=>0,'credit'=>0,'debt'=>0,'excluded'=>0];
    $q=$db->prepare("SELECT external_id,query_type,transaction_type,raw_json,service_fee_verified FROM trendyol_financial_documents
        WHERE user_id=? AND transaction_date>=? AND transaction_date<? AND query_type IN ('DeductionInvoices','Stoppage','FinancialItem','CommissionAgreementInvoice','ReturnInvoice')");
    $q->execute([$uid,$start,$end]);$excluded=0;
    while($row=$q->fetch()){
        $raw=json_decode((string)$row['raw_json'],true);
        $raw=is_array($raw)?$raw:[];
        $type=$row['query_type'];
        $group=$type==='Stoppage'?'withholding':($type!=='DeductionInvoices'?'other_adjustment':match(period_document_group($row,$raw)){
            'Kargo faturası'=>'cargo','Platform hizmet bedeli (filtre doğrulamalı)'=>'service',default=>'other_invoice'
        });
        if($seller===''||!finance_is_domestic($raw)||(string)($raw['sellerId']??'')!==$seller||
           (string)($raw['id']??'')===''||(string)$raw['id']!==(string)$row['external_id']){
            $groups[$group]['excluded']++;$excluded++;continue;
        }
        try{
            $c=finance_units($raw['credit']??null);$d=finance_units($raw['debt']??null);
            if($c<0||$d<0||($c>0&&$d>0))throw new InvalidArgumentException('Belge işareti');
            if($type==='Stoppage'){
                $label=(string)($raw['transactionType']??$row['transaction_type']);
                // Preserve debits and credits, including cancellations; never treat credits as new deductions.
                if($label==='E-ticaret Stopajı'&&($d<=0||$c!==0))throw new InvalidArgumentException('Stopaj işareti');
                if($label==='E-ticaret Stopaj İptali'&&($c<=0||$d!==0))throw new InvalidArgumentException('Stopaj iptal işareti');
            }
        }catch(InvalidArgumentException $ex){$groups[$group]['excluded']++;$excluded++;continue;}
        $groups[$group]['count']++;$groups[$group]['credit']+=$c;$groups[$group]['debt']+=$d;
    }
    $docs=sync_range_gaps(sync_read_ranges($db,$uid,'trendyol','documents_transaction'),$start,$end);
    $fees=sync_range_gaps(sync_read_ranges($db,$uid,'trendyol','service_fee_transaction'),$start,$end);
    foreach($groups as $key=>&$g){
        $g['observed_net']=$g['count']?($g['debt']-$g['credit'])/10000.0:null;
        $g['coverage_complete']=$seller!==''&&!$docs&&($key!=='service'||!$fees)&&$g['excluded']===0;
    }unset($g);
    return ['groups'=>$groups,'excluded'=>$excluded,'document_gaps'=>$docs,'service_gaps'=>$fees];
}
