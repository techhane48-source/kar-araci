<?php
declare(strict_types=1);
require_once __DIR__.'/finance.php';
require_once __DIR__.'/profit.php';
/** A user-entered bank receipt is not a verified bank feed. Reference is unique per user/seller. */
function bank_receipt_save(PDO $db,int $uid,string $seller,string $payment,string $reference,string $day,string $raw): void
{
    $reference=trim($reference);$amount=parse_money_input($raw);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('Europe/Istanbul'));
    if($reference===''||strlen($reference)>128||$amount===null||$amount<=0||$amount>100000000||
       !$date||$date->format('Y-m-d')!==$day||$day<'2000-01-01'||$date->getTimestamp()>time())
       throw new InvalidArgumentException('Geçerli dekont referansı, tarih ve pozitif TL tutarı gerekli.');
    $q=$db->prepare("SELECT 1 FROM trendyol_payouts WHERE user_id=? AND seller_id=? AND payment_order_id=?");
    $q->execute([$uid,$seller,$payment]);if(!$q->fetchColumn())throw new InvalidArgumentException('Ödeme emri bulunamadı.');
    $db->beginTransaction();
    try{
        $q=$db->prepare("SELECT payment_order_id,amount_units,received_day FROM payout_receipts WHERE user_id=? AND seller_id=? AND reference=?");
        $q->execute([$uid,$seller,$reference]);$old=$q->fetch();$units=finance_units($amount);
        if($old){
            if((string)$old['payment_order_id']!==$payment||(int)$old['amount_units']!==$units||$old['received_day']!==$day)
                throw new InvalidArgumentException('Bu dekont referansı farklı kayıtla kullanılıyor. Önce eski kaydı kaldırın.');
        }else{
            $db->prepare("INSERT INTO payout_receipts(user_id,seller_id,payment_order_id,reference,received_day,amount_units,created_at) VALUES(?,?,?,?,?,?,?)")
               ->execute([$uid,$seller,$payment,$reference,$day,$units,time()]);
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
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
