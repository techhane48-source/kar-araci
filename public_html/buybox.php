<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
$u=require_login();if(!is_pro($u)){flash_set('error','Buybox kontrolü Pro plan özelliğidir.');e_redirect('odeme.php');}
$page=max(1,(int)($_GET['page']??1));$count=db()->prepare("SELECT COUNT(*) FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode<>''");$count->execute([$u['id']]);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);$q=db()->prepare("SELECT barcode,name,price FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode<>'' ORDER BY id LIMIT 10 OFFSET ?");$q->execute([$u['id'],($page-1)*10]);$products=$q->fetchAll();$result=null;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify($_POST['csrf']??null))$error='Oturum süresi doldu.';
    elseif(!empty($u['is_demo']))$error='Demo hesabı gerçek mağazaya bağlanmaz.';
    else try{
        $int=get_integration((int)$u['id'],'trendyol');if(!$int)throw new RuntimeException('Önce Trendyol mağazanızı bağlayın.');
        $client=integration_client($int);$result=$client->getBuyboxInformation(array_values(array_unique(array_column($products,'barcode'))));
        if(!$result['ok']){$error=$result['error'];$result=null;}
    }catch(Throwable $e){$error='Buybox bilgisi alınamadı; mağaza bağlantınızı kontrol edin.';}
}
$pageTitle='Buybox — '.APP_NAME;require __DIR__.'/../includes/header.php';
?>
<div class="wrap"><h1>Trendyol Buybox</h1><p>Satıcı sırası ve Buybox fiyatı Trendyol’dan sorgulanır. En düşük fiyat tek başına Buybox kazanma garantisi değildir.</p>
<?php if($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><button class="btn-primary" <?= !$products?'disabled':'' ?>>Bu Sayfanın Buybox Bilgisini Getir</button></form>
<?php if($result): ?><p class="hint">Sorgu: <?= date('d.m.Y H:i:s',$result['checked_at']) ?>. Fiyat güncellemesi yapılmadı.</p><?php endif; ?>
<div class="card" style="margin:18px 0;overflow-x:auto"><table><thead><tr><th>Ürün / barkod</th><th>Satış fiyatınız</th><th>Buybox sırası</th><th>Buybox fiyatı</th><th>Birden fazla satıcı</th></tr></thead><tbody>
<?php foreach($products as $p):$r=$result['rows'][$p['barcode']]??null; ?><tr><td><?= h($p['name']) ?><br><span class="hint"><?= h($p['barcode']) ?></span></td><td><?= tl((float)$p['price']) ?></td><td><?= isset($r['buyboxOrder'])&&$r['buyboxOrder']>0?(int)$r['buyboxOrder']:'Bilinmiyor' ?></td><td><?= isset($r['buyboxPrice'])?tl((float)$r['buyboxPrice']):'Bilinmiyor' ?></td><td><?= isset($r['hasMultipleSeller'])?($r['hasMultipleSeller']?'Evet':'Hayır'):'Bilinmiyor' ?></td></tr><?php endforeach; ?>
<?php if(!$products): ?><tr><td colspan="5">Önce Trendyol ürünlerinizi senkronize edin.</td></tr><?php endif; ?></tbody></table></div>
<nav aria-label="Buybox sayfaları"><?php if($page>1): ?><a href="?page=<?= $page-1 ?>">Önceki</a><?php endif; ?> <?= $page ?> / <?= $pages ?> <?php if($page<$pages): ?><a href="?page=<?= $page+1 ?>">Sonraki</a><?php endif; ?></nav></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
