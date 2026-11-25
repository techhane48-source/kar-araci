<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
$u=require_login();if(!is_pro($u))e_redirect('odeme.php');
$db=db();$uid=(int)$u['id'];$error='';
$q=$db->prepare("SELECT seller_id FROM integration_bindings WHERE user_id=? AND marketplace='trendyol'");$q->execute([$uid]);$seller=(string)($q->fetchColumn()?:'');
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify($_POST['csrf']??null))$error='Oturum süresi doldu.';
    elseif($seller==='')$error='Mağaza bağlı değil.';
    else try{
        if(!is_string($_POST['reference']??null))throw new InvalidArgumentException('Referans gerekli.');
        if(($_POST['action']??'')==='delete'){
            $db->prepare("DELETE FROM payout_receipts WHERE user_id=? AND seller_id=? AND reference=?")->execute([$uid,$seller,$_POST['reference']]);
        }else{
            foreach(['payment','day','amount'] as $k)if(!is_string($_POST[$k]??null))throw new InvalidArgumentException('Eksik bilgi.');
            bank_receipt_save($db,$uid,$seller,$_POST['payment'],$_POST['reference'],$_POST['day'],$_POST['amount']);
        }
        e_redirect('banka-mutabakat.php');
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
}
$page=max(1,min(100000,(int)($_GET['page']??1)));
$q=$db->prepare("SELECT * FROM trendyol_payouts WHERE user_id=? AND seller_id=? ORDER BY payout_date DESC,payment_order_id LIMIT 51 OFFSET ".(($page-1)*50));$q->execute([$uid,$seller]);$rows=$q->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
$receipt=$db->prepare("SELECT * FROM payout_receipts WHERE user_id=? AND seller_id=? AND payment_order_id=? ORDER BY received_day,reference");
$pageTitle='Banka dekontu karşılaştırması — '.APP_NAME;require __DIR__.'/../includes/header.php';?>
<div class="wrap"><div class="toolbar"><h1>Banka dekontu karşılaştırması</h1></div>
<p class="hint">Resmî ödeme emri ile kullanıcı tarafından girilen TL dekontlarını karşılaştırır. Aynı referans tekrar sayılmaz. Birden fazla dekont eklenebilir. Otomatik banka bağlantısı veya kesin banka doğrulaması değildir. IBAN ya da kişisel bilgi girmeyin.</p>
<p><a href="hakedis-desi.php?tab=hakedis">Ödeme emirleri</a></p><?php if($error):?><div class="alert alert-error"><?=h($error)?></div><?php endif;?>
<?php foreach($rows as $r):$receipt->execute([$uid,$seller,$r['payment_order_id']]);$receipts=$receipt->fetchAll();$a=bank_receipt_summary((float)$r['amount'],$receipts);?>
<div class="card table-card" style="margin:16px 0"><h2><?=h($r['payment_order_id'])?> · <?=date('d.m.Y',(int)$r['payout_date'])?></h2>
<p>Resmî ödeme: <?=tl((float)$r['amount'])?> · Girilen: <?=$a['entered']===null?'—':tl($a['entered'])?> · Fark: <?=$a['difference']===null?'—':tl($a['difference'])?> · <?=h($a['state'])?></p>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="payment" value="<?=h($r['payment_order_id'])?>">
<label>Dekont referansı<input name="reference" maxlength="128" required></label><label>Tarih<input type="date" name="day" required></label><label>Tutar (TL)<input name="amount" inputmode="decimal" required></label><button>Kaydet</button></form>
<table><tr><th>Referans</th><th>Tarih</th><th>Tutar</th><th>İşlem</th></tr>
<?php foreach($receipts as $b):?><tr><td><?=h($b['reference'])?></td><td><?=h($b['received_day'])?></td><td><?=tl((int)$b['amount_units']/10000.0)?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="reference" value="<?=h($b['reference'])?>"><button name="action" value="delete">Kaldır</button></form></td></tr><?php endforeach;?></table></div><?php endforeach;?>
<?php if(!$rows):?><div class="card">Resmî ödeme emri bulunamadı. Önce hakediş ekranından çekin.</div><?php endif;?>
<p>Sayfa <?=$page?> <?php if($page>1):?><a href="?page=<?=$page-1?>">Önceki</a><?php endif;?> <?php if($more):?><a href="?page=<?=$page+1?>">Sonraki</a><?php endif;?></p></div>
<?php require __DIR__.'/../includes/footer.php';?>
