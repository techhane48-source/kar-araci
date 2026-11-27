<?php
declare(strict_types=1);
require_once __DIR__.'/functions.php';
require_once __DIR__.'/analysis_import.php';
$u=require_login();
if(!is_pro($u)){flash_set('error','Toplu analiz Pro plan özelliğidir.');e_redirect('odeme.php');}
$modules=['komisyon'=>'Ürün Komisyon Tarifesi','plus'=>'Plus Komisyon Tarifesi','etiket'=>'Avantajlı Ürün Etiketi','flash'=>'Flaş Ürünler','indirim'=>'İndirimler','kampanya'=>'Kampanyalar'];
$kind=$analysisKind==='promotion'?'promotion':'bulk';
$module=$kind==='bulk'?'bulk':($_GET['mod']??'komisyon');
if($kind==='promotion'&&(!is_string($module)||!isset($modules[$module])))$module='komisyon';
$url=$kind==='bulk'?'toplu-analiz.php':'promosyon-analiz.php?mod='.urlencode($module);
$args=[(int)$u['id'],$kind,$module];$db=db();$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null))$error='Oturum süresi doldu.';
    elseif(($_POST['action']??'')==='analyze'){
        try{
            [$headers,$rows]=analysis_read_upload($_FILES['csv']??[]);
            $schema=analysis_schema($headers,$module);
            $q=$db->prepare("SELECT * FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode!=''");
            $q->execute([$u['id']]);$products=[];$duplicates=[];
            foreach($q->fetchAll() as $p){$bc=$p['barcode'];if(isset($products[$bc]))$duplicates[$bc]=true;$products[$bc]=$p;}
            foreach($duplicates as $bc=>$unused)unset($products[$bc]);
            analysis_save($db,(int)$u['id'],$kind,$module,$schema,$rows,$products,target_rules($db,(int)$u['id']));
            flash_set('success','Analiz kaydedildi.');e_redirect($url);
        }catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Dosya analiz edilemedi.';}
    }elseif(($_POST['action']??'')==='download'){
        $q=$db->prepare("SELECT * FROM analysis_jobs WHERE user_id=? AND kind=? AND module=?");$q->execute($args);$job=$q->fetch();
        if(!$job)$error='Önce analiz oluşturun.';
        else{
            $scope=$_POST['scope']??'all';$filters=$args;
            $sql="SELECT payload_json FROM analysis_rows WHERE user_id=? AND kind=? AND module=?";
            if($scope==='profitable')$sql.=" AND best_profit>0";
            elseif($scope==='target')$sql.=" AND meets_target=1";
            elseif($scope==='selected'){
                $ids=array_values(array_unique(array_filter(array_map('intval',array_slice((array)($_POST['sel']??[]),0,100)),static fn($x)=>$x>0)));
                if(!$ids)$error='Bu sayfadan satır seçin.';
                else{$sql.=" AND row_no IN (".implode(',',array_fill(0,count($ids),'?')).")";$filters=array_merge($filters,$ids);}
            }elseif($scope!=='all')$error='Geçersiz rapor seçimi.';
            if(!$error){
                $columns=json_decode($job['headers_json'],true,512,JSON_THROW_ON_ERROR);
                $q=$db->prepare($sql." ORDER BY row_no");$q->execute($filters);
                header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="karfiyat-analiz.csv"');
                echo "\xEF\xBB\xBF";$out=fopen('php://output','w');
                $head=['Barkod','Ürün','Maliyet','Hedef kuralı','Kayıtlı satış fiyatı','Kayıtlı komisyon %','Kayıtlı fiyat tahmini kâr'];foreach($columns as $def){$label=csv_safe_text($def['label']);array_push($head,$label,$label.' dönem',$label.' komisyon %',$label.' tahmini kâr',$label.' alt uç fiyat',$label.' alt uç tahmini kâr',$label.' mevcut kâra fark',$label.' komisyon kaynağı',$label.' hedef',$label.' açıklama');}
                fputcsv($out,$head,';','"','');
                while($item=$q->fetch()){
                    $r=json_decode($item['payload_json'],true);
                    $line=[csv_safe_text($r['barcode']),csv_safe_text($r['name']),$r['cost'],csv_safe_text($r['rule']??''),$r['baseline_price']??null,$r['baseline_rate']??null,$r['baseline_profit']??null];
                    foreach($columns as $key=>$unused){$t=$r['tiers'][$key];array_push($line,$t['price'],csv_safe_text($t['period']??''),$t['rate'],$t['profit'],$t['min_price']??null,$t['min_endpoint_profit']??null,$t['profit_delta']??null,$t['rate_source']??null,isset($t['target_met'])?($t['target_met']?'Evet':'Hayır'):'—',csv_safe_text($t['reason']));}
                    fputcsv($out,$line,';','"','');
                }fclose($out);exit;
            }
        }
    }
}
$q=$db->prepare("SELECT * FROM analysis_jobs WHERE user_id=? AND kind=? AND module=?");$q->execute($args);$job=$q->fetch();
$rows=[];$stats=[];$columns=[];$page=1;$pages=1;
if($job){
    $stats=json_decode($job['stats_json'],true);$columns=json_decode($job['headers_json'],true);
    $pages=max(1,(int)ceil($stats['rows']/100));$page=max(1,min($pages,(int)($_GET['page']??1)));
    $offset=($page-1)*100;
    $q=$db->prepare("SELECT row_no,payload_json FROM analysis_rows WHERE user_id=? AND kind=? AND module=? ORDER BY row_no LIMIT 100 OFFSET ".$offset);
    $q->execute($args);$rows=$q->fetchAll();
}
$title=$kind==='bulk'?'Toplu Kârlılık Analizi':'Promosyon Kârlılık Analizi';
$pageTitle=$title.' — '.APP_NAME;
require __DIR__.'/header.php';
?>
<div class="wrap"><div class="toolbar"><h1><?= h($title) ?></h1></div>
<p class="hint">Trendyol XLSX veya CSV, en fazla 20.000 satır ve 12 fiyat kademesi. Sonuçlar tahmindir; orijinal kampanya yükleme dosyası değildir. Kampanya katkısı ve sepet şartları ayrıca doğrulanmalıdır.</p>
<?php if($kind==='promotion'): ?><div class="card"><?php foreach($modules as $k=>$label): ?><a class="<?= $module===$k?'btn-primary':'btn-ghost' ?>" href="?mod=<?= h($k) ?>"><?= h($label) ?></a> <?php endforeach; ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<?php $flash=flash_get();if($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<div class="card"><h2>Dosya yükle</h2><form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="analyze">
<input type="file" name="csv" accept=".xlsx,.csv" required><button class="btn-primary">Analiz et</button></form>
<p class="hint">Örnek başlıklar: Barkod; Fiyat; 1. Fiyat Aralığı; 1. Komisyon Oranı. Promosyonlarda eşleşen komisyon kolonu yoksa kâr hesaplanmaz. Fiyat aralığının alt ve üst uçları ayrı senaryodur; otomatik seçime girmez. Fark, kayıtlı satış fiyatı ve kayıtlı komisyonla hesaplanan tahmini kâra göredir. Trendyol 3 ve 4 günlük tarife dosyalarında açık uçlu fiyat kademeleri yalnız bilinen sınırda hesaplanır. Tarih aralığı satırda gösterilir; otomatik kampanya seçimi yapılmaz.</p></div>
<?php if($job): ?>
<div class="card table-card" style="margin-top:16px"><h2>Sonuçlar — <?= (int)$stats['rows'] ?> satır</h2>
<p><?= (int)$stats['matched'] ?> eşleşen · <?= (int)$stats['unmatched'] ?> eşleşmeyen · <?= (int)$stats['missing_cost'] ?> maliyeti eksik · <?= (int)$stats['profitable'] ?> pozitif senaryo · <?= isset($stats['meets_target'])?(int)$stats['meets_target']:'—' ?> hedefi karşılayan satır</p>
<p class="hint">Analiz tarihi: <?= date('d.m.Y H:i',(int)$job['created_at']) ?>. Ürün ayarları veya <a href="kar-hedefleri.php">kâr kuralları</a> değiştiğinde tekrar analiz edin. Hedef değerlendirmesi yükleme anındaki kurallara dayanır; kampanya katılım garantisi değildir. Barkodlar Excel'de metin olarak içe aktarılmalıdır.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="download">
<div style="display:flex;gap:8px;flex-wrap:wrap"><button name="scope" value="all">Tüm raporu indir</button><button name="scope" value="profitable">Pozitif senaryoları indir</button><button name="scope" value="target">Hedefi karşılayanları indir</button><button name="scope" value="selected">Bu sayfanın seçilenlerini indir</button></div>
<table><tr><th>Seçim</th><th>Barkod</th><th>Ürün</th><th>Maliyet</th><th>Kayıtlı fiyat / tahmini kâr</th><?php foreach($columns as $def): ?><th><?= h($def['label']) ?></th><?php endforeach; ?></tr>
<?php foreach($rows as $row):$r=json_decode($row['payload_json'],true); ?><tr>
<td><input type="checkbox" name="sel[]" value="<?= (int)$row['row_no'] ?>"></td><td><?= h($r['barcode']) ?></td><td><?= h($r['name']) ?></td><td><?= $r['cost']===null?'—':tl((float)$r['cost']) ?></td><td><?= isset($r['baseline_price'])?tl((float)$r['baseline_price']):'—' ?><br><?= isset($r['baseline_profit'])?tl((float)$r['baseline_profit']):'—' ?></td>
<?php foreach($columns as $key=>$def):$t=$r['tiers'][$key]; ?><td>
<?php if(!empty($t['period'])): ?><small><?=h($t['period'])?></small><br><?php endif; ?>
<?= $t['price']===null?'—':tl((float)$t['price']) ?>
<?php if($t['rate']!==null): ?><br>%<?= number_format((float)$t['rate'],2,',','.') ?> komisyon<?php endif; ?>
<?php if($t['profit']!==null): ?><br><b class="<?= $t['profit']<0?'neg':'pos' ?>"><?= tl((float)$t['profit']) ?></b><?php endif; ?>
<?php if(isset($t['min_price'])): ?><br><small>Alt uç <?= tl((float)$t['min_price']) ?> / kâr <?= isset($t['min_endpoint_profit'])?tl((float)$t['min_endpoint_profit']):'—' ?></small><?php endif; ?>
<?php if(isset($t['profit_delta'])): ?><br><small>Mevcut kâra fark: <?= tl((float)$t['profit_delta']) ?></small><?php endif; ?>
<?php if(isset($t['rate_source'])): ?><br><small>Komisyon: <?= $t['rate_source']==='file'?'dosya':'kayıtlı ürün' ?></small><?php endif; ?>
<br><small><?= h($t['reason']) ?></small><?php if(isset($t['target_met'])): ?><br><small><?= $t['target_met']?'Hedef karşılanıyor':'Hedef altında' ?></small><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</table></form>
<p>Sayfa <?= $page ?> / <?= $pages ?>
<?php $sep=str_contains($url,'?')?'&':'?';if($page>1): ?><a href="<?= h($url.$sep.'page='.($page-1)) ?>">Önceki</a><?php endif; ?>
<?php if($page<$pages): ?><a href="<?= h($url.$sep.'page='.($page+1)) ?>">Sonraki</a><?php endif; ?></p>
</div><?php endif; ?></div>
<?php require __DIR__.'/footer.php'; ?>
