<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/private_cost_scenario.php';
$u=require_login();$uid=(int)$u['id'];header('Cache-Control: no-store, private');
$phrase='';$cost='';$vat='';$days=30;$date='';$error='';$candidates=[];$result=null;$chosen=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null)){http_response_code(403);exit('Geçersiz oturum.');}
    try{
        foreach(['phrase','cost','vat','days','action','date','confirm_save','replace','update_catalog','first_sale'] as $key)if(isset($_POST[$key])&&!is_string($_POST[$key]))throw new InvalidArgumentException('Geçersiz girdi.');
        $phrase=trim($_POST['phrase']??'');$cost=trim($_POST['cost']??'');$vat=trim($_POST['vat']??'');$date=trim($_POST['date']??'');
        if($vat!==''&&!in_array($vat,['1','10','20'],true))throw new InvalidArgumentException('Alış KDV oranı %1, %10 veya %20 olmalıdır.');
        $days=(int)($_POST['days']??30);if(!in_array($days,[7,15,30,90],true))throw new InvalidArgumentException('Geçersiz tarih aralığı.');
        $q=db()->prepare("SELECT * FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode<>'' ORDER BY name,barcode LIMIT 20001");$q->execute([$uid]);$products=$q->fetchAll();
        if(count($products)>20000)throw new InvalidArgumentException('Katalog sınırı aşıldı.');
        $catalog=array_map(static fn($p)=>['barcode'=>(string)$p['barcode'],'title'=>(string)$p['name']],$products);
        $candidates=private_cost_candidates($catalog,$phrase);
        if(count($candidates)>200)throw new InvalidArgumentException('En fazla 200 aday; aramanızı daraltın.');
        $action=$_POST['action']??'';
        if(in_array($action,['calculate','save_group'],true)){
            $chosen=$_POST['barcodes']??[];if(!is_array($chosen)||!$chosen||count($chosen)>200)throw new InvalidArgumentException('En az bir ürün seçin.');
            $allowed=array_fill_keys(array_column($candidates,'barcode'),true);
            foreach($chosen as $bc)if(!is_string($bc)||!isset($allowed[$bc]))throw new InvalidArgumentException('Ürün seçimi geçersiz.');
            $gross=parse_money_input($cost);if($gross===null||$gross<=0)throw new InvalidArgumentException('KDV dahil maliyet girin.');
            $purchaseVat=$vat===''?null:parse_money_input($vat);if($vat!==''&&($purchaseVat===null||$purchaseVat<0||$purchaseVat>100))throw new InvalidArgumentException('KDV oranı geçersiz.');
            if($action==='save_group'){
                if($vat==='')throw new InvalidArgumentException('Kaydetmek için alış KDV oranını seçin.');
                // Direct panel cost entry is available to every signed-in account; file import stays Pro.
                if(($_POST['confirm_save']??'')!=='1')throw new InvalidArgumentException('Kalıcı tarihli maliyet kaydı için açık onay gerekli.');
                $firstSale=($_POST['first_sale']??'')==='1';
                if($firstSale&&$date!=='')throw new InvalidArgumentException('İlk satış seçeneğinde tarih alanını boş bırakın.');
                $values=$firstSale?cost_history_first_sale_values($chosen,$cost,$vat):cost_history_group_values($chosen,$date,$cost,$vat);
                $replace=($_POST['replace']??'')==='1';
                $updateCatalog=($_POST['update_catalog']??'')==='1';
                $saved=with_marketplace_lock($uid,'trendyol',static fn()=>['ok'=>true]+cost_history_save(db(),$uid,$values,$replace,$firstSale?'first_sale':'group',$updateCatalog));
                if(empty($saved['ok']))throw new InvalidArgumentException($saved['error']??'Maliyet kaydedilemedi.');
                flash_set('success',$saved['inserted'].' maliyet sürümü kaydedildi; '.$saved['unchanged'].' aynı kayıt atlandı; '.$saved['catalog_updated'].' güncel katalog maliyeti güncellendi.');
                e_redirect('maliyet-gecmisi.php');
            }
            $q=db()->prepare("SELECT * FROM orders WHERE user_id=? AND marketplace='trendyol' AND order_date>=? ORDER BY order_date DESC LIMIT 20001");
            $q->execute([$uid,strtotime('today')-($days-1)*86400]);$orders=$q->fetchAll();
            if(count($orders)>20000)throw new InvalidArgumentException('Sipariş sınırı aşıldı; daha kısa aralık seçin.');
            foreach($orders as &$o)$o['lines']=json_decode((string)$o['lines'],true);unset($o);
            if($purchaseVat!==null)$orders=commission_profit_overlay(db(),$uid,cargo_profit_overlay(db(),$uid,$orders));
            $result=private_local_cost_scenario($products,$orders,$chosen,$gross,$purchaseVat);
        }
    }catch(InvalidArgumentException $e){$error=$e->getMessage();$result=null;}
    catch(Throwable $e){$error='Senaryo hesaplanamadı; kayıtları kontrol edin.';$result=null;}
}
$pageTitle='Maliyet Senaryosu — '.APP_NAME;require __DIR__.'/../includes/header.php';
?>
<div class="wrap">
<h1>Özel maliyet senaryosu</h1><p><a href="urunler.php">Ürünlerim</a> · Panelden toplu maliyet girin; Excel gerekmez.</p>
<p class="hint">Mevcut kayıtlı Trendyol verileriyle geçici hesap. Hesapla işlemi hiçbir maliyeti değiştirmez. Tarihli maliyeti kaydetmek ayrı ve açık onay gerektiren yerel işlemdir. Mağazaya yazma yapılmaz.</p>
<?php if($error!==''): ?><p class="flash flash-error" role="alert"><?=h($error)?></p><?php endif; ?>
<div class="card">
<form method="post">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<label>Ürün adı <input name="phrase" value="<?=h($phrase)?>" required minlength="3" maxlength="150"></label>
<label>KDV dahil birim maliyet (TL) <input name="cost" value="<?=h($cost)?>" inputmode="decimal"></label>
<label>Alış faturası KDV oranı <select name="vat"><option value="">Seçin (yalnız önizleme için boş olabilir)</option><?php foreach(['1','10','20'] as $v):?><option value="<?=$v?>" <?=$vat===$v?'selected':''?>>%<?=$v?></option><?php endforeach;?></select></label>
<label>Sipariş tarihi <select name="days"><?php foreach([7,15,30,90] as $day): ?><option value="<?=$day?>" <?=$day===$days?'selected':''?>>Son <?=$day?> gün</option><?php endforeach; ?></select></label>
<button class="btn-primary" name="action" value="preview">Ürünleri bul</button>
<?php if($candidates&&count($candidates)<=200): ?>
<div class="table-card"><table><thead><tr><th>Seç</th><th>Barkod</th><th>Ürün</th></tr></thead><tbody>
<?php foreach($candidates as $p): ?><tr><td><input type="checkbox" name="barcodes[]" aria-label="<?=h($p['title'].' '.$p['barcode'])?>" value="<?=h($p['barcode'])?>" <?=!$chosen||in_array($p['barcode'],$chosen,true)?'checked':''?>></td><td><?=h($p['barcode'])?></td><td><?=h($p['title'])?></td></tr><?php endforeach; ?>
</tbody></table></div>
<button class="btn-primary" name="action" value="calculate">Seçili ürünleri hesapla</button>
<fieldset style="margin-top:16px"><legend>Tarihli toplu maliyet kaydı (isteğe bağlı)</legend>
<p class="hint">Yalnızca işaretli barkodlar için geçerli tarih ve alış KDV oranı girin. Önceki maliyetler korunur; kayıt geçmiş raporları etkileyebilir. İlk satıştan beri aynı maliyet seçeneği tüm satış geçmişini kapsar; gerçek ilk satış tarihini bildiğini iddia etmez. Çelişen tarihli kayıt varsa işlem durur. Güncel katalog maliyeti ayrı kutuyla bugün veya ilk satış kapsamı için güncellenebilir.</p>
<label>Geçerlilik tarihi <input type="date" name="date" value="<?=h($date)?>" max="<?=h((new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d'))?>"></label>
<label><input type="checkbox" name="first_sale" value="1"> İlk satıştan bugüne aynı maliyet (tarih alanını boş bırakın).</label>
<label><input type="checkbox" name="confirm_save" value="1"> Seçili ürünlerde belirtilen tarih veya ilk satış kapsamı için bu maliyetin geçerli olduğunu onaylıyorum.</label>
<label><input type="checkbox" name="replace" value="1"> Aynı tarihli farklı maliyeti düzelt (eski sürümü koru).</label>
<label><input type="checkbox" name="update_catalog" value="1"> Bugün geçerli maliyeti ürünlerim, fiyatlandırma ve uyarılar için de uygula.</label>
<button class="btn-primary" name="action" value="save_group">Tarihli maliyetleri kaydet</button>
</fieldset>
<?php elseif($phrase!==''&&$error===''): ?><p class="hint">Eşleşen ürün yok.</p><?php endif; ?>
</form></div>
<?php if($result!==null): ?>
<section class="card table-card" aria-labelledby="scenario-result"><h2 id="scenario-result">Senaryo sonucu</h2>
<table><tbody>
<?php foreach(['eligible_packages'=>'Hesap kapsamındaki paket','units'=>'Ürün adedi','mixed_packages_excluded'=>'Karma paket (hariç)','other_packages_excluded'=>'Diğer kapsam dışı paket','gross_sales'=>'Brüt satış','hypothetical_gross_cost'=>'Varsayımsal brüt maliyet','gross_difference_before_deductions'=>'Kesintiler öncesi fark','estimated_packages'=>'Vergili tahmin hesaplanan paket','estimated_sale'=>'Tahmini kâr kapsamındaki ciro','estimated_profit'=>'Tahmini sipariş kârı'] as $key=>$label): ?>
<tr><th scope="row"><?=h($label)?></th><td><?=$result[$key]===null?'—':(is_float($result[$key])?tl($result[$key]):h((string)$result[$key]))?></td></tr>
<?php endforeach; ?></tbody></table>
<?php if($vat===''): ?><p class="hint">Alış KDV oranı bilinmediği için vergi sonrası tahmin gösterilmedi.</p><?php endif; ?>
<?php foreach($result['estimate_gaps'] as $reason=>$n): ?><p class="hint"><?=h($reason)?>: <?=$n?> paket</p><?php endforeach; ?>
<p class="hint">Kesin net kâr: doğrulanmadı. Geçmiş maliyet yerine senaryo maliyeti kullanılmıştır; mağaza genel giderleri ve banka mutabakatı dahil değildir.</p>
</section><?php endif; ?>
</div><?php require __DIR__.'/../includes/footer.php'; ?>
