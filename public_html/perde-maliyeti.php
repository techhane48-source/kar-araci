<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/curtain_cost.php';
$u=require_login();$uid=(int)$u['id'];header('Cache-Control: no-store, private');
$pid=(int)($_GET['pid']??0);$fabric='';$sewing='';$vat='';$scope='';$preview=null;$error='';
$q=db()->prepare("SELECT name,variant_model_code FROM products WHERE user_id=? AND marketplace='trendyol' AND id=?");$q->execute([$uid,$pid]);$p=$q->fetch();
if(!$p){http_response_code(404);exit('Ürün bulunamadı.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null)){http_response_code(403);exit('Geçersiz oturum.');}
    try{
        foreach(['fabric','sewing','vat','scope','action'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Geçersiz girdi.');
        $fabric=$_POST['fabric'];$sewing=$_POST['sewing'];$vat=$_POST['vat'];$scope=$_POST['scope'];
        $preview=curtain_cost_preview(db(),$uid,$pid,$fabric,$sewing,$vat,$scope);
        if($_POST['action']==='save'){
            if(($_POST['confirm']??'')!=='1'||!is_string($_POST['review']??null)||!hash_equals($preview['hash'],$_POST['review']))throw new InvalidArgumentException('Önizlemeyi kontrol edip tekrar onaylayın.');
            $saved=with_marketplace_lock($uid,'trendyol',static function()use($uid,$pid,$fabric,$sewing,$vat,$scope){
                $fresh=curtain_cost_preview(db(),$uid,$pid,$fabric,$sewing,$vat,$scope);
                if(!hash_equals($fresh['hash'],$_POST['review'])||!$fresh['rows'])throw new InvalidArgumentException('Varyantlar değişti veya hesaplanabilen ürün yok.');
                if($fresh['missing'])throw new InvalidArgumentException('Ölçüsü belirsiz varyantlar var; toplu kayıt yapılmadı.');
                return ['ok'=>true]+cost_history_save(db(),$uid,$fresh['rows'],false,$scope==='first_sale'?'first_sale':'group',true);
            });
            if(empty($saved['ok']))throw new InvalidArgumentException($saved['error']??'Maliyet kaydedilemedi.');
            flash_set('success',$saved['inserted'].' yeni maliyet, '.$saved['unchanged'].' aynı kayıt; '.$saved['catalog_updated'].' katalog güncellemesi.');
            e_redirect('maliyet-gecmisi.php');
        }elseif($_POST['action']!=='preview')throw new InvalidArgumentException('Geçersiz işlem.');
    }catch(InvalidArgumentException|RuntimeException $e){$error=$e->getMessage();}
    catch(Throwable $e){$error='İşlem tamamlanamadı; kayıtlar kontrol edilmeli.';}
}
$pageTitle='Perde maliyeti — '.APP_NAME;require __DIR__.'/../includes/header.php';?>
<div class="wrap"><h1>Perde maliyeti gir</h1><p><a href="urunler.php">Ürünlerime dön</a></p>
<div class="card"><h2><?=h($p['name'])?></h2><p>Model: <?=h((string)($p['variant_model_code']??'Senkron gerekli'))?></p>
<p class="hint">İlk ölçü en (cm), ikinci ölçü boy kabul edilir. Kumaş ve dikim fiyatları KDV dahil metre başınadır; tek ortak alış KDV oranı kullanılır. Boy, pile veya kanat katsayısı uygulanmaz. Modelin onaylı katalog varyantları barkodla eşleştirilir.</p>
<?php if($error):?><p class="flash flash-error" role="alert"><?=h($error)?></p><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<label>Kumaş alış fiyatı / metre (TL)<input name="fabric" inputmode="decimal" required value="<?=h($fabric)?>"></label>
<label>Dikim maliyeti / metre (TL)<input name="sewing" inputmode="decimal" required value="<?=h($sewing)?>"></label>
<label>Alış KDV oranı<select name="vat" required><option value="">Seçin</option><?php foreach(['1','10','20'] as $v):?><option value="<?=$v?>" <?=$vat===$v?'selected':''?>>%<?=$v?></option><?php endforeach;?></select></label>
<label>Geçerlilik<select name="scope" required><option value="">Seçin</option><option value="today" <?=$scope==='today'?'selected':''?>>Bugünden itibaren</option><option value="first_sale" <?=$scope==='first_sale'?'selected':''?>>İlk satıştan itibaren</option></select></label>
<button class="btn-primary" name="action" value="preview">Tüm varyantları hesapla</button>
<?php if($preview):?>
<h2><?=count($preview['rows'])?> / <?=$preview['total']?> varyant hesaplandı</h2><p>Metre maliyeti: <?=tl($preview['rate'])?></p>
<div class="table-card"><table><thead><tr><th>Barkod</th><th>Ebat</th><th>Mevcut maliyet</th><th>Yeni maliyet</th></tr></thead><tbody>
<?php foreach($preview['display'] as $r):?><tr><td><?=h($r['barcode'])?></td><td><?=h($r['variant_size'])?></td><td><?=tl((float)$r['cost'])?></td><td><?=tl($r['calculated'])?></td></tr><?php endforeach;?>
<?php foreach($preview['missing'] as $r):?><tr><td><?=h($r['barcode'])?></td><td><?=h((string)($r['variant_size']??'—'))?></td><td><?=tl((float)$r['cost'])?></td><td>Ölçü doğrulanamadı</td></tr><?php endforeach;?>
</tbody></table></div>
<?php if($preview['missing']):?><p class="hint">Eksik ölçüler nedeniyle toplu kayıt kapalı. Trendyol varyant ölçülerini ve senkronu kontrol edin.</p>
<?php elseif($preview['rows']):?><input type="hidden" name="review" value="<?=h($preview['hash'])?>"><label><input type="checkbox" name="confirm" value="1"> Modeldeki tüm hesaplanan barkodların maliyetini onaylıyorum.</label><button class="btn-primary" name="action" value="save">Tüm maliyetleri kaydet</button><?php endif;?>
<?php endif;?></form></div></div>
<?php require __DIR__.'/../includes/footer.php';?>
