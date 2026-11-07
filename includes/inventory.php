<?php
declare(strict_types=1);

function submit_price_update(int $userId,string $marketplace,string $barcode,float $price,?object $client=null): array
{
    return sync_job($userId,$marketplace,'price',static function($int,$client,$token,$started) use($userId,$marketplace,$barcode,$price):array {
        profit_validate([$price]);
        if ($price<=0 || abs($price-round($price,2))>0.000001) throw new SyncFailure('Fiyat pozitif ve en fazla iki ondalık basamaklı olmalı.');
        $query=db()->prepare('SELECT * FROM products WHERE user_id=? AND marketplace=? AND barcode=?');
        $query->execute([$userId,$marketplace,$barcode]);$products=$query->fetchAll();
        if ($barcode==='' || count($products)!==1) throw new SyncFailure('Güncellenecek ürün bulunamadı veya barkod tekil değil.');
        $product=$products[0];
        $list=max($price,(float)($product['list_price']??$product['price']));
        // Fiyat-only isteğinde eski yerel stok yeniden gönderilmez.
        $payload=['barcode'=>$barcode,'salePrice'=>$price,'listPrice'=>$list];
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
        $check=db()->prepare("SELECT 1 FROM price_updates WHERE user_id=? AND marketplace=? AND barcode=? AND integration_id=? AND integration_revision=? AND (status IN ('pending','submitting','unknown') OR (request_hash=? AND created_at>?)) LIMIT 1");
        $check->execute([$userId,$marketplace,$barcode,$int['id'],$int['revision'],$hash,$started-900]);
        if ($check->fetchColumn()) throw new SyncFailure('Bu ürün için bekleyen veya son 15 dakikada aynı içerikle gönderilmiş bir istek var. Önce işlem sonucunu kontrol edin.');
        $intent=sync_commit($int,$token,static function($pdo) use($int,$userId,$marketplace,$barcode,$price,$list,$hash,$started):array {
            $pdo->prepare("INSERT INTO price_updates(user_id,marketplace,integration_id,integration_revision,barcode,batch_id,request_hash,sale_price,list_price,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,'submitting',?)")
                ->execute([$userId,$marketplace,$int['id'],$int['revision'],$barcode,'local-'.bin2hex(random_bytes(16)),$hash,$price,$list,$started]);
            return ['id'=>(int)$pdo->lastInsertId()];
        });
        try {
            $res=$client->updatePriceAndInventory([$payload]);
            if (empty($res['ok']) || !is_string($res['batchRequestId']??null) || $res['batchRequestId']==='') {
                $state=empty($res['uncertain'])?'failed':'unknown';
                $message=(string)($res['error']??'Fiyatın uygulandığı doğrulanmadı; mağazanızdan kontrol edin.');
                db()->prepare('UPDATE price_updates SET status=?,failure_reason=? WHERE id=?')->execute([$state,$message,$intent['id']]);
                throw new SyncFailure($message);
            }
            return sync_commit($int,$token,static function($pdo) use($res,$intent):array {
                $pdo->prepare("UPDATE price_updates SET batch_id=?,status='pending' WHERE id=? AND status='submitting'")->execute([$res['batchRequestId'],$intent['id']]);
                return ['ok'=>true,'pending'=>true,'batchRequestId'=>$res['batchRequestId']];
            });
        } catch (Throwable $e) {
            db()->prepare("UPDATE price_updates SET status='unknown',failure_reason=? WHERE id=? AND status='submitting'")
                ->execute(['Gönderim sonucu belirsiz. İsteği tekrarlamadan önce mağazadan güncel fiyatı senkronize edin.',$intent['id']]);
            throw new SyncFailure($e instanceof SyncFailure?$e->getMessage():'Gönderim sonucu belirsiz. Fiyatı mağazadan doğrulayın.');
        }
    },$client);
}

function process_price_updates(int $userId,string $marketplace,?object $client=null): array
{
    $query=db()->prepare("SELECT * FROM price_updates WHERE user_id=? AND marketplace=? AND status IN ('pending','submitting') ORDER BY id LIMIT 20");
    $query->execute([$userId,$marketplace]);$updates=$query->fetchAll();
    if (!$updates) return ['ok'=>true,'confirmed'=>0,'failed'=>0,'pending'=>0];
    return sync_job($userId,$marketplace,'price-status',static function($int,$client,$token,$started) use($userId,$marketplace):array {
        $query=db()->prepare("SELECT * FROM price_updates WHERE user_id=? AND marketplace=? AND status IN ('pending','submitting') ORDER BY id LIMIT 20");
        $query->execute([$userId,$marketplace]);$updates=$query->fetchAll();
        $results=[];
        foreach ($updates as $update) {
            if ($update['integration_id']!==$int['id'] || $update['integration_revision']!==$int['revision']) {
                $results[]=[$update,'stale','Mağaza bağlantısı değişti; eski işlemin yerel fiyatı uygulanmadı.'];continue;
            }
            if ($update['status']==='submitting') {
                if ($started-(int)$update['created_at']>=60) $results[]=[$update,'unknown','Gönderim sonucu belirsiz; mağazadan güncel fiyatı çekin.'];
                continue;
            }
            if ($started-(int)$update['created_at']>=4*3600) {
                $results[]=[$update,'unknown','Trendyol sonuç saklama süresi doldu; fiyatı mağazanızdan doğrulayın.'];continue;
            }
            if ($update['checked_at']!==null && $started-(int)$update['checked_at']<5) continue;
            $res=$client->getBatchRequestResult($update['batch_id'],$update['barcode']);
            if (empty($res['ok'])) throw new SyncFailure((string)($res['error']??'İşlem sonucu alınamadı.'));
            $state=empty($res['finished'])?'pending':(!empty($res['success'])?'confirmed':'failed');
            $results[]=[$update,$state,$res['error']??null];
        }
        return sync_commit($int,$token,static function($pdo) use($results,$userId,$marketplace,$started):array {
            $confirmed=0;$failed=0;
            foreach ($results as [$update,$state,$error]) {
                if ($state==='confirmed') {
                    $pdo->prepare('UPDATE products SET price=?,list_price=? WHERE user_id=? AND marketplace=? AND barcode=?')
                        ->execute([$update['sale_price'],$update['list_price'],$userId,$marketplace,$update['barcode']]);
                    $confirmed++;
                } elseif ($state!=='pending') $failed++;
                $pdo->prepare("UPDATE price_updates SET status=?,failure_reason=?,checked_at=? WHERE id=? AND status IN ('pending','submitting')")
                    ->execute([$state,$error,$started,$update['id']]);
            }
            $remaining=$pdo->prepare("SELECT COUNT(*) FROM price_updates WHERE user_id=? AND marketplace=? AND status='pending'");
            $remaining->execute([$userId,$marketplace]);
            return ['ok'=>true,'confirmed'=>$confirmed,'failed'=>$failed,'pending'=>(int)$remaining->fetchColumn()];
        });
    },$client);
}
