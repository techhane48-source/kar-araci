<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/private_cost_scenario.php';
$u=require_login();$uid=(int)$u['id'];header('Cache-Control: no-store, private');
$phrase='';$cost='';$vat='';$days=30;$error='';$candidates=[];$result=null;$chosen=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify($_POST['csrf']??null)){http_response_code(403);exit('Geçersiz oturum.');}
    try{
        foreach(['phrase','cost','vat','days','action'] as $key)if(isset($_POST[$key])&&!is_string($_POST[$key]))throw new InvalidArgumentException('Geçersiz girdi.');
        $phrase=trim($_POST['phrase']??'');$cost=trim($_POST['cost']??'');$vat=trim($_POST['vat']??'');
        $days=(int)($_POST['days']??30);if(!in_array($days,[7,15,30,90],true))throw new InvalidArgumentException('Geçersiz tarih aralığı.');
        $q=db()->prepare("SELECT * FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode<>'' ORDER BY name,barcode LIMIT 20001");$q->execute([$uid]);$products=$q->fetchAll();
        if(count($products)>20000)throw new InvalidArgumentException('Katalog sınırı aşıldı.');
        $catalog=array_map(static fn($p)=>['barcode'=>(string)$p['barcode'],'title'=>(string)$p['name']],$products);
        $candidates=private_cost_candidates($catalog,$phrase);
        if(count($candidates)>200)throw new InvalidArgumentException('En fazla 200 aday; aramanızı daraltın.');
        if(($_POST['action']??'')==='calculate'){
            $chosen=$_POST['barcodes']??[];if(!is_array($chosen)||!$chosen||count($chosen)>200)throw new InvalidArgumentException('En az bir ürün seçin.');
            $allowed=array_fill_keys(array_column($candidates,'barcode'),true);
            foreach($chosen as $bc)if(!is_string($bc)||!isset($allowed[$bc]))throw new InvalidArgumentException('Ürün seçimi geçersiz.');
            $gross=parse_money_input($cost);if($gross===null||$gross<=0)throw new InvalidArgumentException('KDV dahil maliyet girin.');
            $purchaseVat=$vat===''?null:parse_money_input($vat);if($vat!==''&&($purchaseVat===null||$purchaseVat<0||$purchaseVat>100))throw new InvalidArgumentException('KDV oranı geçersiz.');
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
<h1>Özel maliyet senaryosu</h1>
<p class="hint">Mevcut kayıtlı Trendyol verileriyle geçici hesap. Maliyet geçmişini veya mağaza verilerini değiştirmez. İade ve düzeltme kanıtları eksikse kesin kâr gösterilmez.</p>
<?php if($error!==''): ?><p class="flash flash-error" role="alert"><?=h($error)?></p><?php endif; ?>
<div class="card">
<form method="post">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<label>Ürün adı <input name="phrase" value="<?=h($phrase)?>" required minlength="3" maxlength="150"></label>
<label>KDV dahil birim maliyet (TL) <input name="cost" value="<?=h($cost)?>" inputmode="decimal"></label>
<label>Alış KDV oranı (%) — bilinmiyorsa boş bırakın <input name="vat" value="<?=h($vat)?>" inputmode="decimal"></label>
<label>Sipariş tarihi <select name="days"><?php foreach([7,15,30,90] as $day): ?><option value="<?=$day?>" <?=$day===$days?'selected':''?>>Son <?=$day?> gün</option><?php endforeach; ?></select></label>
<button class="btn-primary" name="action" value="preview">Ürünleri bul</button>
<?php if($candidates&&count($candidates)<=200): ?>
<div class="table-card"><table><thead><tr><th>Seç</th><th>Barkod</th><th>Ürün</th></tr></thead><tbody>
<?php foreach($candidates as $p): ?><tr><td><input type="checkbox" name="barcodes[]" aria-label="<?=h($p['title'].' '.$p['barcode'])?>" value="<?=h($p['barcode'])?>" <?=!$chosen||in_array($p['barcode'],$chosen,true)?'checked':''?>></td><td><?=h($p['barcode'])?></td><td><?=h($p['title'])?></td></tr><?php endforeach; ?>
</tbody></table></div>
<button class="btn-primary" name="action" value="calculate">Seçili ürünleri hesapla</button>
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
