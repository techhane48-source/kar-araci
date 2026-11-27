<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/profit.php';

/** User-attested TL receipt, not an authenticated bank feed. */
function bank_receipt_values(string $payment,string $reference,string $day,string $raw): array
{
    $reference=trim($reference);$amount=parse_money_input($raw);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('Europe/Istanbul'));
    if($payment===''||strlen($payment)>128||$reference===''||strlen($reference)>128||$amount===null||$amount<=0||$amount>100000000||
       !$date||$date->format('Y-m-d')!==$day||$day<'2000-01-01'||$date->getTimestamp()>time())
        throw new InvalidArgumentException('Geçerli ödeme emri, dekont referansı, tarih ve pozitif TL tutarı gerekli.');
    return ['payment'=>$payment,'reference'=>$reference,'day'=>$day,'units'=>finance_units($amount)];
}
/** Exact four-column template. Do not guess payment IDs from dates or amounts. */
function bank_receipt_import_values(array $headers,iterable $rows): array
{
    $expected=['Ödeme emri','Dekont referansı','Tarih','Tutar (TL)'];
    if(array_map(static fn($x)=>trim((string)$x),$headers)!==$expected)
        throw new InvalidArgumentException('Dört sütunlu dekont şablonunu kullanın.');
    $out=[];$seen=[];$n=0;
    foreach($rows as $row){
        if(++$n>5000)throw new InvalidArgumentException('En fazla 5.000 dekont satırı.');
        if(!array_filter($row,static fn($v)=>trim((string)$v)!==''))continue;
        if(count($row)!==4)throw new InvalidArgumentException('Dekont satırı biçimi geçersiz.');
        $r=bank_receipt_values(trim((string)$row[0]),(string)$row[1],trim((string)$row[2]),trim((string)$row[3]));
        if(isset($seen[$r['reference']]))throw new InvalidArgumentException('Dosyada yinelenen dekont referansı.');
        $seen[$r['reference']]=true;$out[]=$r;
    }
    if(!$out)throw new InvalidArgumentException('Geçerli dekont satırı yok.');
    return $out;
}
/** Atomic, tenant/seller scoped, idempotent batch. */
function bank_receipt_save_batch(PDO $db,int $uid,string $seller,array $rows): array
{
    if($seller===''||!$rows||count($rows)>5000)throw new InvalidArgumentException('Geçersiz dekont grubu.');
    $db->beginTransaction();
    try{
        $binding=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$binding->execute([$uid]);
        if((string)$binding->fetchColumn()!==$seller)throw new InvalidArgumentException('Mağaza bağlantısı doğrulanamadı.');
        $p=$db->prepare("SELECT 1 FROM trendyol_payouts WHERE user_id=? AND seller_id=? AND payment_order_id=?");
        $q=$db->prepare("SELECT payment_order_id,amount_units,received_day FROM payout_receipts WHERE user_id=? AND seller_id=? AND reference=?");
        $ins=$db->prepare("INSERT INTO payout_receipts(user_id,seller_id,payment_order_id,reference,received_day,amount_units,created_at) VALUES(?,?,?,?,?,?,?)");
        $inserted=0;$unchanged=0;$seen=[];
        foreach($rows as $r){
            if(!is_int($r['units']??null)||$r['units']<=0)throw new InvalidArgumentException('Geçersiz dekont tutarı.');
            $v=bank_receipt_values((string)$r['payment'],(string)$r['reference'],(string)$r['day'],'1');
            if($r['units']>1000000000000||$v['payment']!==$r['payment']||$v['reference']!==$r['reference']||$v['day']!==$r['day']||isset($seen[$r['reference']]))throw new InvalidArgumentException('Çelişkili dekont kaydı.');
            $seen[$r['reference']]=true;
            $p->execute([$uid,$seller,$r['payment']]);if(!$p->fetchColumn())throw new InvalidArgumentException('Ödeme emri bulunamadı; hiçbir satır kaydedilmedi.');
            $q->execute([$uid,$seller,$r['reference']]);$old=$q->fetch();
            if($old){
                if((string)$old['payment_order_id']!==$r['payment']||(int)$old['amount_units']!==$r['units']||$old['received_day']!==$r['day'])
                    throw new InvalidArgumentException('Dekont referansı farklı kayıtla kullanılıyor; hiçbir satır kaydedilmedi.');
                $unchanged++;
            }else{$ins->execute([$uid,$seller,$r['payment'],$r['reference'],$r['day'],$r['units'],time()]);$inserted++;}
        }
        $db->commit();return ['inserted'=>$inserted,'unchanged'=>$unchanged];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function bank_receipt_save(PDO $db,int $uid,string $seller,string $payment,string $reference,string $day,string $raw): void
{
    bank_receipt_save_batch($db,$uid,$seller,[bank_receipt_values($payment,$reference,$day,$raw)]);
}
function bank_receipt_summary(float $official,array $receipts): array
{
    $total=0;$seen=[];
    foreach($receipts as $r){
        $ref=(string)($r['reference']??'');$units=$r['amount_units']??null;
        if($ref===''||isset($seen[$ref])||!is_numeric($units)||(int)$units<=0)throw new InvalidArgumentException('Dekont kaydı çelişkili.');
        $seen[$ref]=true;$total+=(int)$units;
    }
    $diff=finance_units($official)-$total;
    return ['count'=>count($seen),'entered'=>$seen?$total/10000.0:null,'difference'=>$seen?$diff/10000.0:null,
        'state'=>!$seen?'Dekont girilmedi':(abs($diff)<=100?'Girilen dekont toplamı eşleşiyor':($diff>0?'Girilen dekont toplamı eksik':'Girilen dekont toplamı fazla')),
        'bank_verified'=>false];
}

/** Four-way observed amount comparison. Never a bank authentication or profit assertion. */
function bank_payout_evidence(array $payout,array $receipts): array
{
    $official=finance_units($payout['amount']??null);
    if($official<0)throw new InvalidArgumentException('Geçersiz ödeme emri tutarı.');
    $summary=json_decode((string)($payout['summary_json']??''),true);
    $check=is_array($summary)?($summary['candidate_check']??null):null;
    $candidate=null;$statement=null;$matched=false;
    if(is_array($check)&&($check['version']??null)===2&&($check['complete_inputs']??false)===true&&!empty($payout['checked_at'])){
        try{
            $c=finance_units($check['candidate']??null);$s=finance_units($check['statement_payment']??null);
            if(finance_units($check['official']??null)===$official&&$c>=0&&$s>=0){
                $candidate=$c/10000.0;$statement=$s/10000.0;
                $matched=($check['three_way_match']??false)===true&&abs($c-$official)<=100&&abs($s-$official)<=100;
            }
        }catch(InvalidArgumentException $e){}
    }
    $b=bank_receipt_summary($official/10000.0,$receipts);
    $state=!$matched?'provider_unverified':($b['difference']===null?'receipt_missing':(abs($b['difference'])<=0.01?'observed_match':'receipt_difference'));
    return $b+['candidate'=>$candidate,'statement'=>$statement,'provider_matched'=>$matched,'comparison_state'=>$state,'final_profit_verified'=>false];
}
