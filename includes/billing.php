<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';

/** Feature gate: deployment owner must explicitly enable after sandbox acceptance. */
function checkout_enabled(): bool
{
    return getenv('KARFIYAT_ENABLE_CARD_PAYMENTS')==='1' && checkout_origin()!==null;
}
function checkout_origin(): ?string
{
    $v=getenv('KARFIYAT_PUBLIC_ORIGIN');
    if(!is_string($v)||!preg_match('#^https://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$#D',$v))return null;
    return $v;
}
function checkout_minor(mixed $amount): ?int
{
    if(!is_numeric($amount)||!is_finite((float)$amount)||$amount<0||$amount>100000000)return null;
    $scaled=(float)$amount*100;
    if(abs($scaled-round($scaled))>0.00001)return null;
    return (int)round($scaled);
}
function checkout_merchant(string $key,string $secret): string{return hash_hmac('sha256','karfiyat-checkout:'.$key,$secret);}
function checkout_token_hash(string $token): string{return hash('sha256',$token);}
function checkout_valid_token(mixed $token): bool{return is_string($token)&&preg_match('/^[A-Za-z0-9_-]{16,200}$/D',$token)===1;}
function checkout_create(PDO $db,int $uid,string $token,string $basket,string $conversation,int $minor,bool $sandbox,string $merchant): void
{
    if(!checkout_valid_token($token)||$minor<=0)throw new InvalidArgumentException('Ödeme oturumu geçersiz.');
    $db->prepare("INSERT INTO checkout_intents(token_hash,user_id,basket_id,conversation_id,amount_minor,sandbox,merchant_fingerprint,created_at)
        VALUES(?,?,?,?,?,?,?,?)")->execute([checkout_token_hash($token),$uid,$basket,$conversation,$minor,(int)$sandbox,$merchant,time()]);
}
function checkout_find(PDO $db,string $token): ?array
{
    if(!checkout_valid_token($token))return null;
    $q=$db->prepare("SELECT * FROM checkout_intents WHERE token_hash=?");$q->execute([checkout_token_hash($token)]);return $q->fetch()?:null;
}
/** Provider response is fetched directly with merchant credentials, not trusted from callback POST. */
function checkout_validate(array $intent,array $r,string $token): bool
{
    return ($r['status']??null)==='success'&&($r['paymentStatus']??null)==='SUCCESS'
       && in_array($r['fraudStatus']??null,[1,'1'],true)
       && ($r['currency']??null)==='TRY'&&($r['basketId']??null)===$intent['basket_id']
       && ($r['conversationId']??null)===$intent['conversation_id']
       && ($r['token']??null)===$token&&checkout_token_hash($token)===$intent['token_hash']
       && checkout_minor($r['price']??null)===(int)$intent['amount_minor']
       && checkout_minor($r['paidPrice']??null)===(int)$intent['amount_minor']
       && preg_match('/^[0-9]+$/D',(string)($r['paymentId']??''))===1;
}
/** Called only after a server-to-server retrieve and validation. Atomic, single application. */
function checkout_apply(PDO $db,array $intent,array $r,string $token): array
{
    if(!checkout_validate($intent,$r,$token))return ['ok'=>false,'error'=>'Ödeme tutarı, kimliği veya sonucu eşleşmedi.'];
    $providerKey=((int)$intent['sandbox']?'sandbox:':'prod:').$intent['merchant_fingerprint'].':'.$r['paymentId'];
    $db->beginTransaction();
    try{
        $q=$db->prepare("UPDATE checkout_intents SET status='approved',provider_payment_key=?,approved_at=? WHERE token_hash=? AND status='pending'");
        $q->execute([$providerKey,time(),$intent['token_hash']]);
        if($q->rowCount()!==1){$db->rollBack();return ['ok'=>true,'duplicate'=>true];}
        $q=$db->prepare("SELECT plan_expires,referred_by FROM users WHERE id=?");$q->execute([$intent['user_id']]);$u=$q->fetch();
        if(!$u)throw new RuntimeException('Ödeme hesabı bulunamadı.');
        if(!(int)$intent['sandbox']){
            $base=max(date('Y-m-d'),(string)($u['plan_expires']??''));
            // Preserve existing days; clamp month-end to final day of next month.
            $d=new DateTimeImmutable($base);$n=$d->modify('first day of next month');
            $expires=$n->setDate((int)$n->format('Y'),(int)$n->format('m'),min((int)$d->format('d'),(int)$n->format('t')))->format('Y-m-d');
            $db->prepare("UPDATE users SET plan='pro',plan_expires=? WHERE id=?")->execute([$expires,$intent['user_id']]);
            $amount=(int)$intent['amount_minor']/100;
            $db->prepare("INSERT INTO payments(user_id,amount,reference,status,reviewed_at) VALUES(?,?,?,'approved',datetime('now'))")
                ->execute([$intent['user_id'],$amount,'IYZ-'.hash('sha256',$providerKey)]);
            if(!empty($u['referred_by'])&&(int)$u['referred_by']!==(int)$intent['user_id'])
                $db->prepare("INSERT INTO partner_commissions(partner_id,referred_user_id,amount,month,status) VALUES(?,?,?,?,'pending')")
                ->execute([(int)$u['referred_by'],(int)$intent['user_id'],round($amount*.15,2),date('Y-m')]);
        }
        $db->commit();return ['ok'=>true,'sandbox'=>(bool)$intent['sandbox']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();return ['ok'=>false,'error'=>'Ödeme işlenemedi; destek kontrolü gerekli.'];}
}
