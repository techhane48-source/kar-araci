<?php
declare(strict_types=1);

final class SyncFailure extends RuntimeException {}

function sync_timestamp(mixed $value, bool $required = false): int
{
    if ($value === null || $value === '') $ts = 0;
    elseif (is_numeric($value)) $ts = (int)((float)$value > 100000000000 ? (float)$value/1000 : $value);
    elseif (is_string($value)) $ts = strtotime($value) ?: 0;
    else $ts = 0;
    if ($ts < 0 || ($required && $ts === 0)) throw new SyncFailure('API kaydında geçerli işlem tarihi bulunamadı.');
    return $ts;
}

function sync_job(int $userId, string $marketplace, string $scope, callable $work, ?object $client = null): array
{
    $int = get_integration($userId,$marketplace);
    if (!$int) return ['ok'=>false,'error'=>'Önce mağaza bağlantısı kurun.'];
    $pdo = db(); $token = bin2hex(random_bytes(16)); $now=time();
    $lock=$pdo->prepare('INSERT INTO sync_locks(user_id,marketplace,token,expires_at) VALUES (?,?,?,?)
        ON CONFLICT(user_id,marketplace) DO UPDATE SET token=excluded.token,expires_at=excluded.expires_at WHERE sync_locks.expires_at < ?');
    $lock->execute([$userId,$marketplace,$token,$now+1800,$now]);
    if ($lock->rowCount()!==1) return ['ok'=>false,'busy'=>true,'error'=>'Bu mağaza için senkronizasyon zaten çalışıyor.'];
    try {
        $result=$work($int,$client ?? integration_client($int),$token,$now);
        if (empty($result['ok'])) throw new SyncFailure((string)($result['error']??'API yanıtı alınamadı.'));
        $pdo->prepare('UPDATE integrations SET last_sync_error=NULL,last_sync_error_scope=NULL WHERE id=? AND revision=? AND (last_sync_error_scope=? OR last_sync_error_scope IS NULL)')->execute([$int['id'],$int['revision'],$scope]);
        return $result;
    } catch (Throwable $e) {
        $error=$e instanceof SyncFailure ? $e->getMessage() : 'Senkronizasyon tamamlanamadı; veriler kaydedilmedi. Tekrar deneyin.';
        $pdo->prepare('UPDATE integrations SET last_sync_error=?,last_sync_error_scope=? WHERE id=? AND revision=?')
            ->execute([mb_substr($error,0,500),$scope,$int['id'],$int['revision']]);
        return ['ok'=>false,'error'=>$error];
    } finally {
        $pdo->prepare('DELETE FROM sync_locks WHERE user_id=? AND marketplace=? AND token=?')->execute([$userId,$marketplace,$token]);
    }
}

/** Ağ çağrısı transaction dışında; tüm kayıtlar ve başarı zamanı tek transaction içindedir. */
function sync_commit(array $int,string $token,callable $write): array
{
    $pdo=db();$pdo->exec('BEGIN IMMEDIATE');
    try {
        $lock=$pdo->prepare('SELECT token,expires_at FROM sync_locks WHERE user_id=? AND marketplace=?');
        $lock->execute([$int['user_id'],$int['marketplace']]);$row=$lock->fetch();
        if (!$row || !hash_equals($token,$row['token']) || (int)$row['expires_at']<time()) throw new SyncFailure('Senkronizasyon kilidinin süresi doldu; tekrar deneyin.');
        $current=$pdo->prepare('SELECT id,revision FROM integrations WHERE user_id=? AND marketplace=?');
        $current->execute([$int['user_id'],$int['marketplace']]);$connection=$current->fetch();
        if (!$connection || $connection['id']!==$int['id'] || $connection['revision']!==$int['revision']) throw new SyncFailure('Mağaza bağlantısı işlem sırasında değişti; tekrar senkronize edin.');
        $result=$write($pdo);
        $pdo->exec('COMMIT');return $result;
    } catch (Throwable $e) { $pdo->exec('ROLLBACK');throw $e; }
}

function sync_response(array $result,string $key): array
{
    if (empty($result['ok']) || !isset($result[$key]) || !is_array($result[$key]) || ($result['complete']??true)!==true) {
        throw new SyncFailure((string)($result['error']??'API eksiksiz veri döndürmedi; tekrar deneyin.'));
    }
    return $result[$key];
}

function sync_products(int $userId,string $marketplace,?object $client=null): array
{
    return sync_job($userId,$marketplace,'products',static function($int,$client,$token,$started) use($userId,$marketplace):array {
        $from=0;
        if ($int['last_sync']) {
            $ts=(new DateTimeImmutable($int['last_sync'],new DateTimeZone('UTC')))->getTimestamp();
            if ($ts<=$started) $from=max(0,$ts-120)*1000;
        }
        $rows=sync_response($client->getProducts(500,'',$from,$from?'LAST_MODIFIED_DATE':''),'products');
        return sync_commit($int,$token,static function($pdo) use($userId,$marketplace,$rows,$started):array {
            $find=$pdo->prepare('SELECT id,name,category,marka FROM products WHERE user_id=? AND marketplace=? AND barcode=?');
            $update=$pdo->prepare("UPDATE products SET name=?,category=?,price=?,quantity=?,marka=? WHERE id=?");
            $insert=$pdo->prepare("INSERT INTO products(user_id,marketplace,barcode,name,category,price,quantity,marka,rate,service_fee,stopaj_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $inserted=0;$updated=0;
            foreach ($rows as $row) {
                $barcode=trim((string)($row['barcode']??''));
                if ($barcode==='') throw new SyncFailure('Ürün barkodu eksik; kayıtlar değiştirilmedi.');
                $price=$row['salePrice']??$row['listPrice']??null;
                if ($price===null) throw new SyncFailure('Ürün fiyatı eksik.');
                profit_validate([$price]);
                $qty=$row['quantity']??null;
                if (!is_numeric($qty) || !is_finite((float)$qty) || (float)$qty<0 || (float)$qty>2147483647 || (float)$qty!==floor((float)$qty)) throw new SyncFailure('Ürün stok miktarı geçersiz.');
                $name=trim((string)($row['title']??''))?:$barcode;
                $category=trim((string)($row['category']??''))?:'Diğer';
                $brand=(string)($row['brand']??'');
                $find->execute([$userId,$marketplace,$barcode]);$ids=$find->fetchAll();
                if (count($ids)>1) throw new SyncFailure('Aynı barkoda ait birden fazla ürün var; önce mükerrer ürünleri düzenleyin.');
                if ($ids) {
                    $old=$ids[0];
                    $name=trim((string)($row['title']??''))?:$old['name'];
                    $category=trim((string)($row['category']??''))?:$old['category'];
                    $brand=trim($brand)!==''?$brand:$old['marka'];
                    $update->execute([$name,$category,(float)$price,(int)$qty,$brand,$old['id']]);$updated++;
                }
                else {
                    $insert->execute([$userId,$marketplace,$barcode,$name,$category,(float)$price,(int)$qty,$brand,category_rate($category,$marketplace),$marketplace==='trendyol'?trendyol_service_fee():0,$marketplace==='trendyol'?'auto':'manual']);
                    $inserted++;
                }
            }
            $pdo->prepare('UPDATE integrations SET last_sync=? WHERE user_id=? AND marketplace=?')->execute([gmdate('Y-m-d H:i:s',$started),$userId,$marketplace]);
            return ['ok'=>true,'inserted'=>$inserted,'updated'=>$updated,'skipped'=>0,'total'=>count($rows)];
        });
    },$client);
}

function sync_orders(int $userId,string $marketplace,int $days=7,?object $client=null): array
{
    return sync_job($userId,$marketplace,'orders',static function($int,$client,$token,$started) use($userId,$marketplace,$days):array {
        if (!method_exists($client,'getOrders')) throw new SyncFailure('Bu pazaryerinde sipariş senkronu desteklenmiyor.');
        if ($days<1 || $days>365) throw new SyncFailure('Sipariş aralığı 1–365 gün olmalı.');
        $rows=sync_response($client->getOrders('',date('c',$started-$days*86400),date('c',$started)),'orders');
        return sync_commit($int,$token,static function($pdo) use($rows,$userId,$marketplace,$started):array {
            $insert=$pdo->prepare('INSERT INTO orders(user_id,marketplace,order_number,package_number,status,customer_name,total_price,order_date,lines) VALUES (?,?,?,?,?,?,?,?,?)
                ON CONFLICT(user_id,marketplace,package_number) DO UPDATE SET order_number=excluded.order_number,status=excluded.status,customer_name=excluded.customer_name,total_price=excluded.total_price,order_date=excluded.order_date,lines=excluded.lines');
            foreach ($rows as $row) {
                $package=trim((string)($row['packageNumber']??''));
                if ($package==='') throw new SyncFailure('Sipariş paket kimliği eksik.');
                $date=sync_timestamp($row['orderDate']??null,true);
                if (!isset($row['totalPrice']) || !is_array($row['lines']??null)) throw new SyncFailure('Sipariş tutarı veya satırları eksik.');
                profit_validate([$row['totalPrice']]);
                $insert->execute([$userId,$marketplace,(string)($row['orderNumber']??''),$package,(string)($row['status']??''),(string)($row['customerName']??''),(float)$row['totalPrice'],$date,json_encode($row['lines'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            }
            $pdo->prepare('UPDATE integrations SET last_orders_sync=? WHERE user_id=? AND marketplace=?')->execute([$started,$userId,$marketplace]);
            return ['ok'=>true,'count'=>count($rows)];
        });
    },$client);
}

function sync_settlements(int $userId,string $marketplace,int $days=30,?object $client=null): array
{
    return sync_job($userId,$marketplace,'settlements',static function($int,$client,$token,$started) use($userId,$marketplace,$days):array {
        if (!method_exists($client,'getSettlements')) throw new SyncFailure('Bu pazaryerinde finans senkronu desteklenmiyor.');
        if ($days<1 || $days>365) throw new SyncFailure('Finans aralığı 1–365 gün olmalı.');
        $from=$started-$days*86400;$rows=[];
        foreach (['Sale','DeliveryFee','Return','Discount','Coupon'] as $type) {
            for ($s=$from;$s<$started;$s+=14*86400) {
                $e=min($s+14*86400,$started);
                $batch=sync_response($client->getSettlements($type,date('c',$s),date('c',$e)),'settlements');
                foreach ($batch as $row) {
                    $id=trim((string)($row['providerId']??''));
                    if ($id==='') throw new SyncFailure('Finans işlem kimliği eksik; tekrar güvenli eşleştirme yapılamadı.');
                    if (($row['transactionType']??'')!==$type) throw new SyncFailure('Finans işlem türü API isteğiyle uyuşmuyor.');
                    $row['transactionDate']=sync_timestamp($row['transactionDate']??null,true);
                    $row['orderDate']=sync_timestamp($row['orderDate']??null);
                    if ($row['transactionDate']<$from || $row['transactionDate']>$started) throw new SyncFailure('Finans kaydı istenen dönemin dışında.');
                    $rows[$id]=$row;
                }
            }
        }
        return sync_commit($int,$token,static function($pdo) use($rows,$userId,$marketplace,$from,$started):array {
            // Yalnızca eksiksiz yeniden alınmış dönemin eski kimliksiz kayıtları aktif tablodan arşive taşınır.
            $legacy=$pdo->prepare("SELECT * FROM settlements WHERE user_id=? AND marketplace=? AND provider_id IS NULL AND transaction_date>=? AND transaction_date<=?
                AND transaction_type IN ('Sale','DeliveryFee','Return','Discount','Coupon','Satış','Kargo','İade','İndirim','Kupon')");
            $legacy->execute([$userId,$marketplace,$from,$started]);$old=$legacy->fetchAll();
            $archive=$pdo->prepare('INSERT OR IGNORE INTO settlement_legacy_archive(source_id,user_id,marketplace,payload) VALUES (?,?,?,?)');
            $delete=$pdo->prepare('DELETE FROM settlements WHERE id=?');
            foreach ($old as $row) {
                $archive->execute([$row['id'],$userId,$marketplace,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                $delete->execute([$row['id']]);
            }
            $insert=$pdo->prepare('INSERT INTO settlements(user_id,marketplace,provider_id,barcode,transaction_type,commission_rate,commission_amount,seller_revenue,debt,credit,order_number,shipment_package_id,payment_period,transaction_date,order_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,marketplace,provider_id) DO UPDATE SET barcode=excluded.barcode,transaction_type=excluded.transaction_type,commission_rate=excluded.commission_rate,commission_amount=excluded.commission_amount,seller_revenue=excluded.seller_revenue,debt=excluded.debt,credit=excluded.credit,order_number=excluded.order_number,shipment_package_id=excluded.shipment_package_id,payment_period=excluded.payment_period,transaction_date=excluded.transaction_date,order_date=excluded.order_date');
            foreach ($rows as $id=>$row) {
                $numbers=[];
                foreach (['commissionRate','commissionAmount','sellerRevenue','debt','credit'] as $key) {
                    if (!isset($row[$key]) || !is_numeric($row[$key]) || !is_finite((float)$row[$key])) throw new SyncFailure('Finans kaydında geçersiz tutar/oran var.');
                    $numbers[]=(float)$row[$key];
                }
                $insert->execute([$userId,$marketplace,(string)$id,(string)($row['barcode']??''),$row['transactionType'],...$numbers,(string)($row['orderNumber']??''),(string)($row['shipmentPackageId']??''),(int)($row['paymentPeriod']??0),$row['transactionDate'],$row['orderDate']]);
            }
            // Her barkod için mevcut finans kayıtlarının en güncel geçerli satış komisyonu.
            $rates=$pdo->prepare("UPDATE products SET rate=(SELECT s.commission_rate FROM settlements s WHERE s.user_id=products.user_id AND s.marketplace=products.marketplace AND s.barcode=products.barcode AND s.transaction_type='Sale' AND s.commission_rate BETWEEN 0 AND 100 ORDER BY s.transaction_date DESC,s.provider_id DESC LIMIT 1)
                WHERE user_id=? AND marketplace=? AND barcode<>'' AND EXISTS(SELECT 1 FROM settlements s WHERE s.user_id=products.user_id AND s.marketplace=products.marketplace AND s.barcode=products.barcode AND s.transaction_type='Sale' AND s.commission_rate BETWEEN 0 AND 100)");
            $rates->execute([$userId,$marketplace]);
            $pdo->prepare('UPDATE integrations SET last_settlements_sync=? WHERE user_id=? AND marketplace=?')->execute([$started,$userId,$marketplace]);
            return ['ok'=>true,'records'=>count($rows),'rates_updated'=>$rates->rowCount(),'archived'=>count($old),'errors'=>[]];
        });
    },$client);
}
