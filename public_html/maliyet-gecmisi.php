<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
$u=require_login();$uid=(int)$u['id'];$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null))$error='Oturum süresi doldu.';
    else{
        try{
            $replace=($_POST['replace']??'')==='1';
            if(($_POST['action']??'')==='import'){
                if(!is_pro($u))throw new InvalidArgumentException('Toplu içe aktarma Pro özelliğidir.');
                [$headers,$rows]=analysis_read_upload($_FILES['csv']??[]);
                $values=cost_history_values($headers,$rows);$source='import';
            }else{
                $values=cost_history_values(['Barkod','Geçerlilik Tarihi','Maliyet','Maliyet KDV Oranı'],[[
                  (string)($_POST['barcode']??''),(string)($_POST['date']??''),(string)($_POST['cost']??''),(string)($_POST['vat']??'')
                ]]);$source='manual';
            }
            $res=with_marketplace_lock($uid,'trendyol',static fn()=>['ok'=>true]+cost_history_save(db(),$uid,$values,$replace,$source));
            if(!$res['ok'])throw new InvalidArgumentException($res['error']);
            flash_set('success',$res['inserted'].' maliyet sürümü kaydedildi; '.$res['unchanged'].' aynı kayıt atlandı.');e_redirect('maliyet-gecmisi.php');
        }catch(InvalidArgumentException|RuntimeException $e){$error=$e->getMessage();}
        catch(Throwable $e){$error='Maliyet kaydedilemedi. Önceki kayıtlar korundu.';}
    }
}
if(($_GET['export']??'')==='template'){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="maliyet-gecmisi-sablon.csv"');
    echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,['Barkod','Geçerlilik Tarihi','Maliyet','Maliyet KDV Oranı'],';','"','');fclose($out);exit;
}
$search=trim((string)($_GET['q']??''));$filter='user_id=?';$args=[$uid];
if($search!==''){$filter.=' AND barcode LIKE ?';$args[]='%'.$search.'%';}
$q=db()->prepare("SELECT COUNT(*) FROM product_cost_history WHERE $filter");$q->execute($args);$total=(int)$q->fetchColumn();
$pages=max(1,(int)ceil($total/100));$page=max(1,min($pages,(int)($_GET['page']??1)));
$q=db()->prepare("SELECT * FROM product_cost_history WHERE $filter ORDER BY effective_at DESC,id DESC LIMIT 100 OFFSET ".(($page-1)*100));$q->execute($args);$rows=$q->fetchAll();
$pageTitle='Maliyet Geçmişi — '.APP_NAME;require __DIR__.'/../includes/header.php';?>
<div class="wrap"><div class="toolbar"><h1>Maliyet geçmişi</h1><a href="urunler.php">Ürünlerim</a> · <a href="maliyet-senaryosu.php">Ürün grubuna toplu maliyet</a></div>
<p class="hint">Trendyol barkodu için belirtilen tarihten itibaren geçerli KDV dahil birim alış maliyetini kaydedin. Sipariş tarihinde geçerli son sürüm kullanılır. Bu kullanıcı beyanıdır, fatura doğrulaması değildir. Güncel katalog maliyetini veya ilk gözlem kaydını değiştirmez; geçmiş raporların sonucunu etkiler.</p>
<?php if($error):?><div class="alert alert-error"><?=h($error)?></div><?php endif;?>
<div class="card"><h2>Tek maliyet kaydı</h2><form method="post">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save">
<label>Barkod <input name="barcode" required maxlength="128"></label>
<label>Geçerlilik tarihi <input type="date" name="date" required max="<?=date('Y-m-d')?>"></label>
<label>KDV dahil birim maliyet <input name="cost" required inputmode="decimal"></label>
<label>Alış KDV % <input name="vat" required inputmode="decimal"></label>
<label><input type="checkbox" name="replace" value="1"> Aynı tarihteki kaydı düzelt (önceki sürüm saklanır)</label>
<button class="btn-primary">Kaydet</button></form></div>
<?php if(is_pro($u)):?><div class="card"><h2>Toplu maliyet geçmişi</h2><a href="?export=template">CSV şablonu indir</a>
<p class="hint">En fazla 20.000 satır. Tarihleri YYYY-AA-GG, barkodları metin olarak kaydedin. Hatalı veya eşleşmeyen bir satır bütün aktarımı durdurur.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="import">
<input type="file" name="csv" accept=".csv,.xlsx" required><label><input type="checkbox" name="replace" value="1"> Aynı tarihli maliyetleri düzelt</label><button>Aktar</button></form></div><?php endif;?>
<div class="card table-card"><h2>Kayıt sürümleri (<?=$total?>)</h2><form method="get"><input name="q" value="<?=h($search)?>" placeholder="Barkod"><button>Ara</button></form>
<table><tr><th>Barkod</th><th>Geçerli tarih</th><th>Maliyet</th><th>Alış KDV</th><th>Sürüm</th><th>Kayıt zamanı</th></tr>
<?php foreach($rows as $r):?><tr><td><?=h($r['barcode'])?></td><td><?=date('d.m.Y',(int)$r['effective_at'])?></td><td><?=tl((float)$r['cost'])?></td><td>%<?=number_format((float)$r['cost_vat'],2,',','.')?></td><td><?=(int)$r['id']?></td><td><?=date('d.m.Y H:i',(int)$r['recorded_at'])?></td></tr><?php endforeach;?></table>
<p>Sayfa <?=$page?> / <?=$pages?> <?php if($page>1):?><a href="?q=<?=urlencode($search)?>&amp;page=<?=$page-1?>">Önceki</a><?php endif;?> <?php if($page<$pages):?><a href="?q=<?=urlencode($search)?>&amp;page=<?=$page+1?>">Sonraki</a><?php endif;?></p></div></div>
<?php require __DIR__.'/../includes/footer.php';?>
