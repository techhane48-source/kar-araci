<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
$u=require_login();$uid=(int)$u['id'];$db=db();$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null))$error='Oturum süresi doldu.';
    else try{
        $scope=(string)($_POST['scope']??'');$key=$scope==='store'?'':trim((string)($_POST['key']??''));
        if(($_POST['action']??'')==='delete'){
            $db->prepare("DELETE FROM profit_targets WHERE user_id=? AND scope=? AND scope_key=?")->execute([$uid,$scope,$key]);
        }else{
            $kind=(string)($_POST['kind']??'');$v=target_rule_value($kind,(string)($_POST['value']??''));
            target_rule_save($db,$uid,$scope,$key,$kind,$v);
        }
        e_redirect('kar-hedefleri.php');
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
}
$rules=target_rules($db,$uid);$search=trim((string)($_GET['q']??''));$cat=trim((string)($_GET['category']??''));
$where="user_id=? AND marketplace='trendyol'";$args=[$uid];
if($search!==''){$where.=" AND (name LIKE ? OR barcode LIKE ?)";$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
if($cat!==''){$where.=" AND category=?";$args[]=$cat;}
$q=$db->prepare("SELECT COUNT(*) FROM products WHERE $where");$q->execute($args);$total=(int)$q->fetchColumn();
$pages=max(1,(int)ceil($total/50));$page=max(1,min($pages,(int)($_GET['page']??1)));
$export=($_GET['export']??'')==='csv';
$q=$db->prepare("SELECT * FROM products WHERE $where ORDER BY id".($export?'':' LIMIT 50 OFFSET '.(($page-1)*50)));$q->execute($args);
if($export){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="kar-hedefleri.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');
    fputcsv($out,['Barkod','Ürün','Kategori','Kural','Mevcut fiyat','Tahmini kâr','En düşük hedef fiyat','Hedef fiyatta tahmini kâr','Durum'],';','"','');
    while($p=$q->fetch()){$s=target_suggestion($p,target_for_product($rules,$p));fputcsv($out,[csv_safe_text($p['barcode']),csv_safe_text($p['name']),csv_safe_text($p['category']),$s['rule']?csv_safe_text(target_label($s['rule'])):'',$p['price'],$s['current_profit'],$s['price'],$s['target_profit'],$s['state']],';','"','');}
    fclose($out);exit;
}
$products=$q->fetchAll();$pageTitle='Kâr Hedefleri — '.APP_NAME;require __DIR__.'/../includes/header.php';?>
<div class="wrap"><div class="toolbar"><h1>Kâr hedefleri ve fiyat önerileri</h1></div>
<p class="hint">Ürün kuralı kategori kuralından, kategori kuralı mağaza kuralından önceliklidir. En düşük hedef fiyat tahmini maliyetlerle hesaplanır. Burada kayıtlı ürün fiyatı veya Trendyol satış fiyatı değiştirilmez.</p>
<?php if($error):?><div class="alert alert-error"><?=h($error)?></div><?php endif;?>
<div class="card"><h2>Hedef tanımla veya güncelle</h2><form method="post">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<label>Kapsam <select name="scope"><option value="store">Mağaza</option><option value="category">Kategori</option><option value="product">Barkod</option></select></label>
<label>Kategori adı veya barkod <input name="key" maxlength="250" placeholder="Mağaza için boş"></label>
<label>Hedef <select name="kind"><option value="margin">Satışa göre marj %</option><option value="roi">Maliyete göre kâr %</option><option value="profit">Kâr TL</option></select></label>
<label>Değer <input name="value" inputmode="decimal" required></label><button>Kaydet</button></form></div>
<div class="card table-card"><h2>Kurallar</h2><table><tr><th>Kapsam</th><th>Değer</th><th>İşlem</th></tr>
<?php foreach($rules as $scope=>$group)foreach($group as $key=>$rule):?><tr><td><?=h($key===''?'Tüm Trendyol ürünleri':(string)$key)?></td><td><?=h(target_label($rule))?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="scope" value="<?=h($scope)?>"><input type="hidden" name="key" value="<?=h((string)$key)?>"><button name="action" value="delete">Kaldır</button></form></td></tr><?php endforeach;?></table></div>
<div class="card table-card"><h2>Fiyat önerileri (<?=$total?> ürün)</h2>
<form method="get"><input name="q" value="<?=h($search)?>" placeholder="Barkod / ürün"><input name="category" value="<?=h($cat)?>" placeholder="Tam kategori adı"><button>Filtrele</button><button name="export" value="csv">CSV indir</button></form>
<table><tr><th>Barkod / ürün</th><th>Hedef</th><th>Mevcut fiyat</th><th>Tahmini kâr</th><th>En düşük hedef fiyat</th><th>Hedef kâr</th><th>Durum</th></tr>
<?php foreach($products as $p):$s=target_suggestion($p,target_for_product($rules,$p));?><tr><td><?=h($p['barcode'])?> / <?=h($p['name'])?></td><td><?=$s['rule']?h(target_label($s['rule'])):'—'?></td><td><?=tl((float)$p['price'])?></td>
<?php foreach(['current_profit','price','target_profit'] as $k):?><td><?=$s[$k]===null?'—':tl((float)$s[$k])?></td><?php endforeach;?><td><?=h($s['state'])?></td></tr><?php endforeach;?></table>
<p>Sayfa <?=$page?> / <?=$pages?> <?php if($page>1):?><a href="?q=<?=urlencode($search)?>&amp;category=<?=urlencode($cat)?>&amp;page=<?=$page-1?>">Önceki</a><?php endif;?> <?php if($page<$pages):?><a href="?q=<?=urlencode($search)?>&amp;category=<?=urlencode($cat)?>&amp;page=<?=$page+1?>">Sonraki</a><?php endif;?></p></div></div>
<?php require __DIR__.'/../includes/footer.php';?>
