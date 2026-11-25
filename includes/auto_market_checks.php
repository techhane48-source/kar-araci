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
/** One existing completed payout per pass; repeated checks revisit recent orders after one day. */
function auto_payout_choice(PDO $db,int $uid,string $seller,int $now): ?string
{
    $today=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Istanbul'))->setTime(0,0)->getTimestamp();
    $q=$db->prepare("SELECT payment_order_id FROM trendyol_payouts WHERE user_id=? AND seller_id=? AND payout_date<?
      AND (checked_at IS NULL OR (payout_date>=? AND checked_at<?))
      ORDER BY CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END, COALESCE(checked_at,0),payout_date DESC,payment_order_id LIMIT 1");
    $q->execute([$uid,$seller,$today,$today-30*86400,$now-86400]);$v=$q->fetchColumn();return $v===false?null:(string)$v;
}
function auto_payout_detail_sync(int $uid): array
{
    return run_sync_job($uid,'trendyol','payout_detail',static function()use($uid){
        $int=get_integration($uid,'trendyol');if(!$int)return ['ok'=>false,'error'=>'Mağaza bağlı değil.'];
        $id=auto_payout_choice(db(),$uid,(string)$int['seller_id'],time());
        if($id===null)return ['ok'=>true,'count'=>0];
        return check_trendyol_payout_unlocked($uid,$id);
    });
}
