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
            $sourceHash=null;
            if($module==='komisyon'&&!empty($schema['apply_columns'])&&strtolower(pathinfo((string)$_FILES['csv']['name'],PATHINFO_EXTENSION))==='xlsx')
                $sourceHash=tariff_source_save((int)$u['id'],(string)$_FILES['csv']['tmp_name']);
            $saved=analysis_save($db,(int)$u['id'],$kind,$module,$schema,$rows,$products,target_rules($db,(int)$u['id']),$sourceHash);
            flash_set('success','Analiz kaydedildi.');e_redirect($url);
        }catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Dosya analiz edilemedi.';}
    }elseif(($_POST['action']??'')==='download'&&in_array($_POST['scope']??null,['tariff_selected','tariff_best3','tariff_best4'],true)){
        $tmp=null;
        try{
            if($module!=='komisyon'||($_POST['tariff_confirm']??'')!=='1')throw new RuntimeException('Önce fiyat ve dönemleri kontrol edip Excel hazırlamayı onaylayın.');
            $q=$db->prepare("SELECT stats_json FROM analysis_jobs WHERE user_id=? AND kind=? AND module=?");$q->execute($args);$meta=json_decode((string)$q->fetchColumn(),true);
            $hash=$meta['tariff_source']??'';if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals($hash,(string)($_POST['tariff_hash']??'')))throw new RuntimeException('Tarife değişti veya orijinal XLSX yok. Dosyayı yeniden yükleyin.');
            $source=tariff_source_path((int)$u['id'],$hash);if(!is_file($source)||hash_file('sha256',$source)!==$hash)throw new RuntimeException('Orijinal Excel bulunamadı.');
            $auto=$_POST['scope']!=='tariff_selected';$days=$_POST['scope']==='tariff_best3'?3:4;$picks=$_POST['tariff']??[];$promoOk=($_POST['promo_confirm']??'')==='1';
            if(!is_array($picks)||count($picks)>100)throw new RuntimeException('Bu sayfadan en fazla 100 seçim yapılabilir.');
            $picks=array_filter($picks,static fn($v)=>is_string($v)&&$v!=='');
            if(!$auto&&!$picks)throw new RuntimeException('Bu sayfadan kademe seçin.');
            $q=$db->prepare("SELECT row_no,payload_json FROM analysis_rows WHERE user_id=? AND kind=? AND module=? ORDER BY row_no");$q->execute($args);
            $selected=[];$used=[];$seen=[];$now=time();
            while($row=$q->fetch()){
                $r=json_decode($row['payload_json'],true);$id=(int)$row['row_no'];$bc='b:'.$r['barcode'];
                if(isset($seen[$bc]))throw new RuntimeException('Dosyada tekrarlayan barkod var; aktarım yapılmadı.');$seen[$bc]=true;
                $choice=null;
                if($auto){foreach(array_keys($r['tiers']) as $key){if(!str_starts_with($key,'period'.$days.'_'))continue;$c=tariff_apply_select($r,$key,true,false,$now);if($c!==null&&($choice===null||$c['profit']>$choice['profit']))$choice=$c;}}
                elseif(isset($picks[$id])){$used[$id]=true;$choice=tariff_apply_select($r,$picks[$id],false,$promoOk,$now);if($choice===null)throw new RuntimeException('Seçilen kademe uygulanamıyor: dönem, maliyet veya promosyon onayını kontrol edin.');}
                if($choice!==null)$selected[$bc]=$choice;
            }
            if(!$auto&&count($used)!==count($picks))throw new RuntimeException('Seçimler analizle eşleşmedi.');
            $tmp=tempnam(sys_get_temp_dir(),'tariff-');if(!$tmp)throw new RuntimeException('Excel hazırlanamadı.');
            tariff_export_xlsx($source,$tmp,$selected);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="trendyol-tarife-secimleri.xlsx"');header('Content-Length: '.filesize($tmp));readfile($tmp);unlink($tmp);exit;
        }catch(Throwable $e){if($tmp&&is_file($tmp))unlink($tmp);$error=$e instanceof RuntimeException?$e->getMessage():'Tarife Excel dosyası hazırlanamadı.';}
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
<?php if(!empty($stats['tariff_source'])):?><div class="note" style="margin:12px 0"><h3>Tarife uygula — Trendyol Excel</h3><p>Her ürün için bir kademe seçin. Yeni TSF ve dönem orijinal Excel'e yazılır; mağazada fiyat değişmez. Toplu en kârlı seçim, seçilen dönemde pozitif ve varsa hedefi karşılayan promosyonu olmayan ürünleri kapsar. Açık uçlarda yalnız sınır senaryosu değerlendirilir.</p><p>Promosyonlu ürünlerde mevcut komisyona esas fiyat / TSF oranının değişmediği varsayılır; otomatik toplu seçime dahil edilmez. Trendyol panelinde fiyatı ve komisyonu doğrulayın.</p><input type="hidden" name="tariff_hash" value="<?=h($stats['tariff_source'])?>"><label><input type="checkbox" name="tariff_confirm" value="1"> Seçilen fiyatları ve dönemleri kontrol ettim; Excel hazırla.</label><br><label><input type="checkbox" name="promo_confirm" value="1"> Promosyon oranı varsayımını ve değişme riskini kabul ediyorum.</label><div style="display:flex;gap:8px;flex-wrap:wrap"><button name="scope" value="tariff_selected">Seçilen tarifeleri Excel'e yaz</button><button name="scope" value="tariff_best3">3 gün: en kârlı uygunlar</button><button name="scope" value="tariff_best4">4 gün: en kârlı uygunlar</button></div></div><?php endif;?>
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
<?php if(!empty($stats['tariff_source'])&&!empty($t['application'])): $app=$t['application'];?><br><small>Yeni TSF: <?=tl((float)$app['tsf'])?> · esas fiyat <?=tl((float)$app['effective'])?><?=$app['promotion_assumption']?' (promosyon varsayımı)':''?></small><?php if(tariff_period_open((string)$t['period'],time())):?><br><label><input type="radio" name="tariff[<?=(int)$row['row_no']?>]" value="<?=h($key)?>">Bu tarifeyi seç</label><?php else:?><br><small>Dönem geçmiş veya doğrulanamadı</small><?php endif;?><?php endif;?>
<br><small><?= h($t['reason']) ?></small><?php if(isset($t['target_met'])): ?><br><small><?= $t['target_met']?'Hedef karşılanıyor':'Hedef altında' ?></small><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</table></form>
<p>Sayfa <?= $page ?> / <?= $pages ?>
<?php $sep=str_contains($url,'?')?'&':'?';if($page>1): ?><a href="<?= h($url.$sep.'page='.($page-1)) ?>">Önceki</a><?php endif; ?>
<?php if($page<$pages): ?><a href="<?= h($url.$sep.'page='.($page+1)) ?>">Sonraki</a><?php endif; ?></p>
</div><?php endif; ?></div>
<?php require __DIR__.'/footer.php'; ?>
