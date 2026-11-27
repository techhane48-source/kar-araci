<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
$u=require_login();if(!is_pro($u))e_redirect('odeme.php');
$db=db();$uid=(int)$u['id'];$error='';
$search=is_string($_GET['q']??null)?trim($_GET['q']):'';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null))$error='Oturum süresi doldu.';
    else try{
        if(!is_string($_POST['item']??null)||!is_string($_POST['action']??null))throw new InvalidArgumentException('Geçersiz veri.');
        if($_POST['action']==='unusable')return_inventory_save($db,$uid,$_POST['item'],'unusable','');
        elseif($_POST['action']==='clear')return_inventory_clear($db,$uid,$_POST['item']);
        else throw new InvalidArgumentException('Geçersiz işlem.');
        e_redirect('iade-kontrol.php?q='.urlencode($search));
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
}
$page=max(1,min(100000,(int)($_GET['page']??1)));
$where="user_id=? AND status='Accepted'";$args=[$uid];
if($search!==''){$where.=" AND (order_number=? OR barcode=? OR item_id=?)";array_push($args,$search,$search,$search);}
$q=$db->prepare("SELECT * FROM trendyol_claim_items WHERE ".$where." ORDER BY modified_ms DESC,item_id LIMIT 51 OFFSET ".(($page-1)*50));$q->execute($args);$rows=$q->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
$orders=return_inventory_orders($db,$uid,$rows);$counts=return_accepted_counts($db,$uid,$rows);
$checks=return_inventory_checks($db,$uid,$rows);
$pageTitle='İade depo kontrolü — '.APP_NAME;require __DIR__.'/../includes/header.php';?>
<div class="wrap"><div class="toolbar"><h1>İade depo kontrolü</h1></div>
<p class="hint">Kabul edilmiş ve sipariş/maliyet eşleşmesi bulunan iadeler otomatik olarak satışa uygun varsayılır; normal iadeler için işlem gerekmez. Yalnız fiziksel olarak satılamaz olduğunu gördüğünüz ürünü işaretleyin. Kabul durumu fiziksel teslim veya ürün durumunun kanıtı değildir. Bu hesap stok değer kaybıdır; geri ödeme, KDV, kargo ve diğer giderler ayrıca değerlendirilir.</p>
<p><a href="raporlar.php?r=claims">İade talepleri raporu</a></p>
<?php if($error):?><div class="alert alert-error"><?=h($error)?></div><?php endif;?>
<form method="get"><input name="q" value="<?=h($search)?>" placeholder="Tam sipariş no, barkod veya iade kalemi"><button>Filtrele</button></form>
<div class="card table-card"><table><tr><th>Sipariş / barkod / kalem</th><th>Kayıtlı birim maliyet</th><th>Depo durumu</th><th>Geri kazanım / stok değer kaybı</th></tr>
<?php foreach($rows as $r):$saved=$checks[$r['item_id']]??null;$a=return_inventory_evidence($r,$orders[$r['outbound_package']]??null,$counts[return_inventory_key($r)]??0,$saved);?>
<tr><td><?=h($r['order_number'])?><br><?=h($r['barcode'])?><br><?=h($r['item_id'])?></td><td><?=$a['cost']===null?'—':tl($a['cost'])?></td><td>
<?php if($a['cost']!==null):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="item" value="<?=h($r['item_id'])?>">
<?php if(($a['condition']??'')!=='unusable'):?><button name="action" value="unusable">Satılamaz olarak işaretle</button><?php else:?><span>Satılamaz</span><?php endif;?>
<?php if($saved):?><button name="action" value="clear" class="btn-ghost">İşareti kaldır</button><?php endif;?>
</form><?php else:?>—<?php endif;?></td>
<td><?=h($a['state'])?><br>Geri kazanım: <?=$a['recovery']===null?'—':tl($a['recovery'])?><br>Stok değer kaybı: <?=$a['inventory_loss']===null?'—':tl($a['inventory_loss'])?></td></tr>
<?php endforeach;?><?php if(!$rows):?><tr><td colspan="4">Kabul edilmiş iade kalemi yok. İade senkronunu kontrol edin.</td></tr><?php endif;?></table>
<p>Sayfa <?=$page?> <?php if($page>1):?><a href="?q=<?=urlencode($search)?>&amp;page=<?=$page-1?>">Önceki</a><?php endif;?> <?php if($more):?><a href="?q=<?=urlencode($search)?>&amp;page=<?=$page+1?>">Sonraki</a><?php endif;?></p></div></div>
<?php require __DIR__.'/../includes/footer.php';?>
