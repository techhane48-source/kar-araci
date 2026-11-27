<?php
declare(strict_types=1);
require_once __DIR__.'/buybox_decision.php';

/** Stable keyset pagination; a successful chunk advances the cursor, not a failed request. */
function auto_buybox_batch(PDO $db,int $uid,string $seller,object $client,int $now,int $limit=50): array
{
    $limit=max(1,min(50,$limit));
    $q=$db->prepare("SELECT cursor FROM auto_buybox_progress WHERE user_id=? AND seller_id=?");$q->execute([$uid,$seller]);$cursor=(string)($q->fetchColumn()?:'');
    $select=$db->prepare("SELECT barcode FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode>? GROUP BY barcode HAVING COUNT(*)=1 ORDER BY barcode LIMIT ".$limit);
    $select->execute([$uid,$cursor]);$barcodes=$select->fetchAll(PDO::FETCH_COLUMN);
    $wrapped=false;
    if(!$barcodes&&$cursor!==''){$wrapped=true;$select->execute([$uid,'']);$barcodes=$select->fetchAll(PDO::FETCH_COLUMN);}
    if($barcodes){
        $r=buybox_refresh_page($db,$uid,$seller,$barcodes,$client);if(!$r['ok'])return $r;
    }else $r=['ok'=>true,'count'=>0,'missing'=>0];
    $next=$barcodes?(string)end($barcodes):'';
    $db->prepare("INSERT INTO auto_buybox_progress(user_id,seller_id,cursor,last_batch_at,cycles) VALUES(?,?,?,?,?)
      ON CONFLICT(user_id,seller_id) DO UPDATE SET cursor=excluded.cursor,last_batch_at=excluded.last_batch_at,cycles=auto_buybox_progress.cycles+excluded.cycles")
      ->execute([$uid,$seller,$next,$now,$wrapped?1:0]);
    return $r+['attempted'=>count($barcodes),'wrapped'=>$wrapped];
}
function auto_buybox_sync(int $uid): array
{
    return run_sync_job($uid,'trendyol','buybox',static function()use($uid){
        $int=get_integration($uid,'trendyol');if(!$int)return ['ok'=>false,'error'=>'Mağaza bağlı değil.'];
        return auto_buybox_batch(db(),$uid,(string)$int['seller_id'],integration_client($int),time());
    });
}
/** Persist a complete validated page atomically; invalidate stale detail when payment evidence changes. */
function payout_store_page(PDO $db,int $uid,string $seller,array $items): void
{
    $seen=[];$prepared=[];
    foreach($items as $row){
        $id=(string)($row['id']??'');$date=$row['payoutDate']??null;
        if(!ctype_digit($id)||isset($seen[$id])||($row['status']??null)!=='PAID'||(string)($row['sellerId']??'')!==$seller||
           !is_numeric($date)||!is_finite((float)$date)||(float)$date!==(float)(int)$date||(float)$date<946684800000||(float)$date>4102444800000)
            throw new InvalidArgumentException('Ödeme emri kimliği/tarihi geçersiz.');
        $units=finance_units($row['amount']??null);if($units<0)throw new InvalidArgumentException('Ödeme tutarı geçersiz.');
        $seen[$id]=true;$prepared[]=[$uid,$seller,$id,intdiv((int)$date,1000),$units/10000.0,json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)];
    }
    $db->beginTransaction();
    try{
        $st=$db->prepare("INSERT INTO trendyol_payouts(user_id,seller_id,payment_order_id,payout_date,amount,raw_json)
            VALUES(?,?,?,?,?,?) ON CONFLICT(user_id,seller_id,payment_order_id) DO UPDATE SET
            summary_json=CASE WHEN trendyol_payouts.payout_date<>excluded.payout_date OR trendyol_payouts.amount<>excluded.amount THEN NULL ELSE trendyol_payouts.summary_json END,
            checked_at=CASE WHEN trendyol_payouts.payout_date<>excluded.payout_date OR trendyol_payouts.amount<>excluded.amount THEN NULL ELSE trendyol_payouts.checked_at END,
            detail_failures=CASE WHEN trendyol_payouts.payout_date<>excluded.payout_date OR trendyol_payouts.amount<>excluded.amount THEN 0 ELSE trendyol_payouts.detail_failures END,
            detail_retry_after=CASE WHEN trendyol_payouts.payout_date<>excluded.payout_date OR trendyol_payouts.amount<>excluded.amount THEN 0 ELSE trendyol_payouts.detail_retry_after END,
            payout_date=excluded.payout_date,amount=excluded.amount,raw_json=excluded.raw_json");
        foreach($prepared as $row)$st->execute($row);
        $db->commit();
    }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function payout_fetch_store_page(PDO $db,int $uid,string $seller,object $client,int $page): array
{
    $r=$client->getPaymentOrders($page);if(empty($r['ok']))return $r;
    if(!is_int($r['totalPages']??null)||$r['totalPages']<0||$r['totalPages']>100000||
       !is_array($r['items']??null)||count($r['items'])>10||
       ($page<$r['totalPages']&&!$r['items'])||($page>=$r['totalPages']&&$r['items']))
        return ['ok'=>false,'error'=>'Ödeme sayfası kapsamı tutarsız.'];
    try{payout_store_page($db,$uid,$seller,$r['items']);}
    catch(Throwable $ex){return ['ok'=>false,'error'=>'Ödeme sayfası kaydedilemedi.'];}
    return ['ok'=>true,'count'=>count($r['items']),'totalPages'=>$r['totalPages']];
}
/** Refresh the head and one historical page; repeated cycles tolerate shifting offset pagination.
 * A completed pass is not proof that every historical payment is available. */
function auto_payout_list_batch(PDO $db,int $uid,string $seller,object $client,int $now): array
{
    $head=payout_fetch_store_page($db,$uid,$seller,$client,0);if(!$head['ok'])return $head;
    $q=$db->prepare("SELECT next_page FROM auto_payout_progress WHERE user_id=? AND seller_id=?");$q->execute([$uid,$seller]);$page=max(1,(int)$q->fetchColumn());
    $pages=$head['totalPages'];$wrapped=false;$count=$head['count'];$next=1;
    if($page<$pages){
        $r=payout_fetch_store_page($db,$uid,$seller,$client,$page);if(!$r['ok'])return $r;
        $count+=$r['count'];$next=$page+1;
        if($next>=min($pages,$r['totalPages'])){$next=1;$wrapped=true;}
    }else{$wrapped=true;}
    $db->prepare("INSERT INTO auto_payout_progress(user_id,seller_id,next_page,last_batch_at,cycles) VALUES(?,?,?,?,?)
        ON CONFLICT(user_id,seller_id) DO UPDATE SET next_page=excluded.next_page,last_batch_at=excluded.last_batch_at,cycles=auto_payout_progress.cycles+excluded.cycles")
       ->execute([$uid,$seller,$next,$now,$wrapped?1:0]);
    return ['ok'=>true,'count'=>$count,'totalPages'=>$pages,'next_page'=>$next,'wrapped'=>$wrapped];
}
function auto_payout_list_sync(int $uid): array
{
    return run_sync_job($uid,'trendyol','payout_list',static function()use($uid){
        $int=get_integration($uid,'trendyol');if(!$int)return ['ok'=>false,'error'=>'Mağaza bağlı değil.'];
        return auto_payout_list_batch(db(),$uid,(string)$int['seller_id'],integration_client($int),time());
    });
}
/** Failure backoff belongs to the payment order, not the whole seller queue. */
function payout_detail_attempt(PDO $db,int $uid,string $seller,string $id,int $now,callable $check): array
{
    $q=$db->prepare("UPDATE trendyol_payouts SET last_detail_attempt=?,detail_retry_after=? WHERE user_id=? AND seller_id=? AND payment_order_id=?");
    $q->execute([$now,$now+1800,$uid,$seller,$id]);
    if(!$q->rowCount())return ['ok'=>false,'error'=>'Ödeme emri bulunamadı.'];
    try{$r=$check();}catch(Throwable $ex){$r=['ok'=>false,'error'=>'Ödeme detayı sorgulanamadı.'];}
    if(!empty($r['ok'])){
        $db->prepare("UPDATE trendyol_payouts SET detail_failures=0,detail_retry_after=0 WHERE user_id=? AND seller_id=? AND payment_order_id=?")->execute([$uid,$seller,$id]);
    }else{
        $q=$db->prepare("SELECT detail_failures FROM trendyol_payouts WHERE user_id=? AND seller_id=? AND payment_order_id=?");$q->execute([$uid,$seller,$id]);
        $fail=min(8,(int)$q->fetchColumn()+1);$delay=min(86400,1800*(2**($fail-1)));
        $db->prepare("UPDATE trendyol_payouts SET detail_failures=?,detail_retry_after=?,summary_json=NULL,checked_at=NULL WHERE user_id=? AND seller_id=? AND payment_order_id=?")
            ->execute([$fail,$now+$delay,$uid,$seller,$id]);
    }
    return $r;
}
/** One existing completed payout per pass; repeated checks revisit recent orders after one day. */
function auto_payout_choice(PDO $db,int $uid,string $seller,int $now): ?string
{
    $today=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Istanbul'))->setTime(0,0)->getTimestamp();
    $q=$db->prepare("SELECT payment_order_id FROM trendyol_payouts WHERE user_id=? AND seller_id=? AND payout_date<?
      AND detail_retry_after<=? AND (checked_at IS NULL OR (payout_date>=? AND checked_at<?))
      ORDER BY CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END, COALESCE(checked_at,0),payout_date DESC,payment_order_id LIMIT 1");
    $q->execute([$uid,$seller,$today,$now,$today-30*86400,$now-86400]);$v=$q->fetchColumn();return $v===false?null:(string)$v;
}
function auto_payout_detail_sync(int $uid): array
{
    return run_sync_job($uid,'trendyol','payout_detail',static function()use($uid){
        $int=get_integration($uid,'trendyol');if(!$int)return ['ok'=>false,'error'=>'Mağaza bağlı değil.'];
        $id=auto_payout_choice(db(),$uid,(string)$int['seller_id'],time());
        if($id===null)return ['ok'=>true,'count'=>0];
        return payout_detail_attempt(db(),$uid,(string)$int['seller_id'],$id,time(),static fn()=>check_trendyol_payout_unlocked($uid,$id));
    });
}
