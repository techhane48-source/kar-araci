<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
$u=require_login();if(!is_pro($u))e_redirect('odeme.php');
$uid=(int)$u['id'];$db=db();$error='';
$search=is_string($_GET['q']??null)?trim($_GET['q']):'';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null))$error='Oturum süresi doldu.';
    else try{
        foreach(['invoice','parcel','type','desi','amount'] as $k)if(!is_string($_POST[$k]??null))throw new InvalidArgumentException('Geçersiz giriş.');
        [$d,$a]=cargo_expectation($_POST['desi'],$_POST['amount']);
        cargo_save_expectation($db,$uid,$_POST['invoice'],$_POST['parcel'],$_POST['type'],$d,$a);
        e_redirect('kargo-kontrol.php?q='.urlencode($_POST['invoice']));
    }catch(InvalidArgumentException $ex){$error=$ex->getMessage();}
}
if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="kargo-kontrol.csv"');echo "\xEF\xBB\xBF";
    $out=fopen('php://output','w');fputcsv($out,['Fatura','Paket kimliği','Sipariş','Tür','Fatura desisi','Beklenen desi','Desi farkı','Fatura tutarı','Beklenen tutar','Tutar farkı','Durum'],';','"','');
    $q=cargo_audit_query($db,$uid,$search,0);
    while($r=$q->fetch()){$a=cargo_audit_row($r);fputcsv($out,[csv_safe_text($r['invoice_serial']),csv_safe_text($r['parcel_id']),csv_safe_text($r['order_number']),csv_safe_text($r['shipment_type']),$r['desi'],$r['expected_desi'],$a['desi_diff'],$r['amount'],$r['expected_amount'],$a['amount_diff'],$a['label']],';','"','');}
    fclose($out);exit;
}
$page=max(1,min(100000,(int)($_GET['page']??1)));$rows=cargo_audit_query($db,$uid,$search,51,($page-1)*50)->fetchAll();
$more=count($rows)>50;$rows=array_slice($rows,0,50);$pageTitle='Kargo fatura kontrolü — '.APP_NAME;require __DIR__.'/../includes/header.php';?>
<div class="wrap"><div class="toolbar"><h1>Kargo fatura kontrolü</h1></div>
<p class="hint">Fatura seri numarası ve kargo paket kimliğine göre karşılaştırma. Beklenen fatura desisi ve KDV dahil tutar, kendi taşıyıcı sözleşmeniz ve paket belgenizden girilir. Ürün ölçüsü tek başına fazla ücret kanıtı değildir; sipariş paketi kimliği ile parcelUniqueId aynı değildir.</p>
<?php if($error):?><div class="alert alert-error"><?=h($error)?></div><?php endif;?>
<div class="card"><form method="get"><input name="q" value="<?=h($search)?>" placeholder="Tam fatura, kargo paketi veya sipariş no"><button>Filtrele</button><button name="export" value="csv">CSV indir</button></form><a href="hakedis-desi.php">Hakediş & Desi</a></div>
<div class="card table-card"><table><tr><th>Fatura / paket / sipariş</th><th>Tür</th><th>Fatura desisi / tutarı</th><th>Beklenen değerler</th><th>Fark / durum</th></tr>
<?php foreach($rows as $r):$a=cargo_audit_row($r);?><tr><td><?=h($r['invoice_serial'])?><br><?=h($r['parcel_id'])?><br><?=h($r['order_number'])?></td><td><?=h($r['shipment_type'])?></td><td><?=h((string)$r['desi'])?> / <?=tl((float)$r['amount'])?></td><td>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="invoice" value="<?=h($r['invoice_serial'])?>"><input type="hidden" name="parcel" value="<?=h($r['parcel_id'])?>"><input type="hidden" name="type" value="<?=h($r['shipment_type'])?>">
<input name="desi" inputmode="decimal" placeholder="Beklenen desi" required value="<?=h((string)($r['expected_desi']??''))?>"><input name="amount" inputmode="decimal" placeholder="Beklenen TL (opsiyonel)" value="<?=h((string)($r['expected_amount']??''))?>"><button>Kaydet</button></form></td>
<td><?=h($a['label'])?><br>Desi: <?=$a['desi_diff']===null?'—':h((string)$a['desi_diff'])?><br>Tutar: <?=$a['amount_diff']===null?'—':tl($a['amount_diff'])?></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="5">Fatura kalemi yok; mağaza finans senkronunu kontrol edin.</td></tr><?php endif;?></table>
<p>Sayfa <?=$page?> <?php if($page>1):?><a href="?q=<?=urlencode($search)?>&amp;page=<?=$page-1?>">Önceki</a><?php endif;?> <?php if($more):?><a href="?q=<?=urlencode($search)?>&amp;page=<?=$page+1?>">Sonraki</a><?php endif;?></p></div></div>
<?php require __DIR__.'/../includes/footer.php';?>
