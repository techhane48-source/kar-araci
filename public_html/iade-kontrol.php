<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
$u=require_login();if(!is_pro($u))e_redirect('odeme.php');
$db=db();$uid=(int)$u['id'];$error='';
$search=is_string($_GET['q']??null)?trim($_GET['q']):'';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify($_POST['csrf']??null))$error='Oturum süresi doldu.';
    else try{
        foreach(['item','condition','recovery'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Geçersiz veri.');
        return_inventory_save($db,$uid,$_POST['item'],$_POST['condition'],$_POST['recovery']);
        e_redirect('iade-kontrol.php?q='.urlencode($search));
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
}
$page=max(1,min(100000,(int)($_GET['page']??1)));
$where="user_id=? AND status='Accepted'";$args=[$uid];
if($search!==''){$where.=" AND (order_number=? OR barcode=? OR item_id=?)";array_push($args,$search,$search,$search);}
$q=$db->prepare("SELECT * FROM trendyol_claim_items WHERE ".$where." ORDER BY modified_ms DESC,item_id LIMIT 51 OFFSET ".(($page-1)*50));$q->execute($args);$rows=$q->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
$orders=return_inventory_orders($db,$uid,$rows);$counts=return_accepted_counts($db,$uid,$rows);
$lookup=$db->prepare("SELECT * FROM return_inventory_checks WHERE user_id=? AND item_id=?");
$pageTitle='İade depo kontrolü — '.APP_NAME;require __DIR__.'/../includes/header.php';?>
<div class="wrap"><div class="toolbar"><h1>İade depo kontrolü</h1></div>
<p class="hint">Yalnızca kabul edilmiş iade kalemleri. Depoya ulaşan ürünün durumu ve maliyet bazında geri kazanılabilir değeri kullanıcı beyanıdır. Tam satışa uygun ürün için kayıtlı maliyetin tamamı, satılamaz ürün için sıfır, kısmi ürün için doğruladığınız maliyet bazlı tutar kullanılır. Bu hesap yalnızca stok değer kaybıdır; geri ödeme, KDV, kargo, hizmet, yeniden paketleme ve diğer giderler dahil değildir. Fiziksel iade kontrolü yapılmadan kayıt girmeyin.</p>
<p><a href="raporlar.php?r=claims">İade talepleri raporu</a></p>
<?php if($error):?><div class="alert alert-error"><?=h($error)?></div><?php endif;?>
<form method="get"><input name="q" value="<?=h($search)?>" placeholder="Tam sipariş no, barkod veya iade kalemi"><button>Filtrele</button></form>
<div class="card table-card"><table><tr><th>Sipariş / barkod / kalem</th><th>Kayıtlı birim maliyet</th><th>Depo durumu</th><th>Geri kazanım / stok değer kaybı</th></tr>
<?php foreach($rows as $r):$lookup->execute([$uid,$r['item_id']]);$saved=$lookup->fetch()?:null;$a=return_inventory_evidence($r,$orders[$r['outbound_package']]??null,$counts[return_inventory_key($r)]??0,$saved);?>
<tr><td><?=h($r['order_number'])?><br><?=h($r['barcode'])?><br><?=h($r['item_id'])?></td><td><?=$a['cost']===null?'—':tl($a['cost'])?></td><td>
<?php if($a['cost']!==null):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="item" value="<?=h($r['item_id'])?>">
<select name="condition" required><option value="">Depo kontrolü seçin</option><option value="resellable" <?=($a['condition']??'')==='resellable'?'selected':''?>>Tam satışa uygun</option><option value="partial" <?=($a['condition']??'')==='partial'?'selected':''?>>Kısmi değer</option><option value="unusable" <?=($a['condition']??'')==='unusable'?'selected':''?>>Satılamaz</option></select>
<input name="recovery" inputmode="decimal" placeholder="Kısmi geri kazanım TL" value="<?=($a['condition']??'')==='partial'?h((string)$a['recovery']):''?>"><button>Depo kontrolünü kaydet</button></form><?php else:?>—<?php endif;?></td>
<td><?=h($a['state'])?><br>Geri kazanım: <?=$a['recovery']===null?'—':tl($a['recovery'])?><br>Stok değer kaybı: <?=$a['inventory_loss']===null?'—':tl($a['inventory_loss'])?></td></tr>
<?php endforeach;?><?php if(!$rows):?><tr><td colspan="4">Kabul edilmiş iade kalemi yok. İade senkronunu kontrol edin.</td></tr><?php endif;?></table>
<p>Sayfa <?=$page?> <?php if($page>1):?><a href="?q=<?=urlencode($search)?>&amp;page=<?=$page-1?>">Önceki</a><?php endif;?> <?php if($more):?><a href="?q=<?=urlencode($search)?>&amp;page=<?=$page+1?>">Sonraki</a><?php endif;?></p></div></div>
<?php require __DIR__.'/../includes/footer.php';?>
