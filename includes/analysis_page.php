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
            if((($module==='komisyon'&&!empty($schema['apply_columns']))||isset($schema['campaign_kind']))&&strtolower(pathinfo((string)$_FILES['csv']['name'],PATHINFO_EXTENSION))==='xlsx')
                $sourceHash=tariff_source_save((int)$u['id'],(string)$_FILES['csv']['tmp_name']);
            $saved=analysis_save($db,(int)$u['id'],$kind,$module,$schema,$rows,$products,target_rules($db,(int)$u['id']),$sourceHash);
            flash_set('success','Analiz kaydedildi.');e_redirect($url);
        }catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Dosya analiz edilemedi.';}
    }elseif(($_POST['action']??'')==='download'&&($_POST['scope']??'')==='campaign_draft'){
        $tmp=null;
        try{
            if(!in_array($module,['plus','flash','etiket'],true)||($_POST['campaign_confirm']??'')!=='1')throw new RuntimeException('Önce taslak dosyayı hazırlamayı onaylayın.');
            $jq=$db->prepare("SELECT stats_json,created_at FROM analysis_jobs WHERE user_id=? AND kind=? AND module=?");$jq->execute($args);$j=$jq->fetch();
            $st=json_decode($j['stats_json']??'{}',true);$hash=$st['campaign_source']??'';
            if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals($hash,(string)($_POST['campaign_hash']??'')))throw new RuntimeException('Dosya değişti. Yeniden analiz edin.');
            $now=time();$at=(int)$j['created_at'];if(campaign_day($at)!==campaign_day($now))throw new RuntimeException('Güncel Trendyol Excel dosyasını yeniden yükleyin.');
            $path=tariff_source_path((int)$u['id'],$hash);if(!is_file($path)||hash_file('sha256',$path)!==$hash)throw new RuntimeException('Orijinal dosya bulunamadı.');
            $data=xlsx_read($path,true);$schema=analysis_schema($data['headers'],$module);$cols=campaign_export_columns($data['headers'],$module);
            $picks=$_POST['campaign_pick']??[];if(!is_array($picks)||count($picks)>100)throw new RuntimeException('En fazla 100 teklif seçin.');
            $picks=array_filter($picks,static fn($v)=>is_string($v)&&$v!=='');if(!$picks)throw new RuntimeException('Önce uygun bir teklif seçin.');
            $raws=[];$bcs=[];$seen=[];$n=0;
            foreach($data['rows'] as $ix=>$raw){
                if(!campaign_export_clean($raw,$cols,$module))throw new RuntimeException('Dosyada önceden doldurulmuş fiyat veya seçim var. Temiz orijinal Excel indirin; mevcut seçimler yanlışlıkla tekrar gönderilmemeli.');
                $bc=trim((string)($raw[$schema['barcode']]??''));if($bc==='')continue;$n++;
                if(isset($picks[$n])){if(isset($seen['b:'.$bc]))throw new RuntimeException('Aynı barkod için birden fazla teklif seçildi.');$seen['b:'.$bc]=true;$bcs[]=$bc;$raws[$n]=[$raw,$data['row_numbers'][$ix]];}
            }
            if(count($raws)!==count($picks))throw new RuntimeException('Seçilen teklifler dosyayla eşleşmedi.');
            $ctx=campaign_link_context($db,(int)$u['id'],$bcs,$now);
            $pq=$db->prepare("SELECT * FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode IN (".implode(',',array_fill(0,count($bcs),'?')).")");$pq->execute(array_merge([$u['id']],$bcs));$products=[];
            while($pr=$pq->fetch()){$k='b:'.$pr['barcode'];if(array_key_exists($k,$products))$products[$k]=null;else $products[$k]=$pr;}
            $rules=target_rules($db,(int)$u['id']);$patches=[];
            foreach($raws as $rn=>[$raw,$excelRow]){
                if(!preg_match('/^([a-z0-9_]+):([a-f0-9]{64})$/D',$picks[$rn],$m))throw new RuntimeException('Teklif seçimi geçersiz.');
                $bc=trim((string)$raw[$schema['barcode']]);$pr=$products['b:'.$bc]??null;
                if(!$pr)throw new RuntimeException('Ürün eşleşmesi değişti.');
                $r=analysis_row($schema,$raw,[$bc=>$pr],$module,$rules);
                $quote=campaign_link_quotes($r,$module,$ctx['b:'.$bc]??[],$pr,$at,$now,target_for_product($rules,$pr))[$m[1]]??null;
                if(!$quote||!hash_equals(campaign_quote_token($quote,$hash,$rn,$m[1]),$m[2]))throw new RuntimeException('Fiyat, maliyet veya teklif dönemi değişti. Sayfayı yenileyin.');
                $plan=campaign_export_plan($module,$m[1],$quote,$raw,$cols);if(!$plan)throw new RuntimeException('Bu teklif için güvenli Excel taslağı oluşturulamadı.');
                $patches[$excelRow]=$plan;
            }
            $tmp=tempnam(sys_get_temp_dir(),'campaign-');if(!$tmp)throw new RuntimeException('Excel hazırlanamadı.');campaign_patch_xlsx($path,$tmp,$patches);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="trendyol-'.$module.'-kontrol-taslagi.xlsx"');header('Content-Length: '.filesize($tmp));readfile($tmp);unlink($tmp);exit;
        }catch(Throwable $e){if($tmp&&is_file($tmp))unlink($tmp);$error=$e instanceof RuntimeException?$e->getMessage():'Excel taslağı hazırlanamadı.';}
    }elseif(($_POST['action']??'')==='download'&&in_array($_POST['scope']??null,['tariff_selected','tariff_best3','tariff_best4'],true)){
        $tmp=null;
        try{
            if($module!=='komisyon'||($_POST['tariff_confirm']??'')!=='1')throw new RuntimeException('Önce fiyat ve dönemleri kontrol edip Excel hazırlamayı onaylayın.');
            $q=$db->prepare("SELECT stats_json,created_at FROM analysis_jobs WHERE user_id=? AND kind=? AND module=?");$q->execute($args);$sourceJob=$q->fetch();$meta=json_decode((string)($sourceJob['stats_json']??''),true);if(!$sourceJob||campaign_day((int)$sourceJob['created_at'])!==campaign_day(time()))throw new RuntimeException('Güncel Trendyol tarife dosyasını yeniden yükleyin.');
            $hash=$meta['tariff_source']??'';if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals($hash,(string)($_POST['tariff_hash']??'')))throw new RuntimeException('Tarife değişti veya orijinal XLSX yok. Dosyayı yeniden yükleyin.');
            $source=tariff_source_path((int)$u['id'],$hash);if(!is_file($source)||hash_file('sha256',$source)!==$hash)throw new RuntimeException('Orijinal Excel bulunamadı.');
            $auto=$_POST['scope']!=='tariff_selected';$days=$_POST['scope']==='tariff_best3'?3:4;$picks=$_POST['tariff']??[];$promoOk=($_POST['promo_confirm']??'')==='1';
            if(!is_array($picks)||count($picks)>100)throw new RuntimeException('Bu sayfadan en fazla 100 seçim yapılabilir.');
            $picks=array_filter($picks,static fn($v)=>is_string($v)&&$v!=='');
            if(!$auto&&!$picks)throw new RuntimeException('Bu sayfadan kademe seçin.');
            $q=$db->prepare("SELECT row_no,payload_json FROM analysis_rows WHERE user_id=? AND kind=? AND module=? ORDER BY row_no");$q->execute($args);
            // Recheck current costs, rules and original source before preparing a price file.
            $data=xlsx_read($source,true);$schema=analysis_schema($data['headers'],$module);$rawRows=[];
            foreach($data['rows'] as $raw){
                $bc='b:'.trim((string)($raw[$schema['barcode']]??''));
                if($bc==='b:')continue;
                if(isset($rawRows[$bc]))throw new RuntimeException('Dosyada tekrarlayan barkod var.');
                $rawRows[$bc]=$raw;
            }
            $products=[];$duplicates=[];$pq=$db->prepare("SELECT * FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode!=''");$pq->execute([$u['id']]);
            while($product=$pq->fetch()){$bc=$product['barcode'];if(isset($products[$bc]))$duplicates[$bc]=true;$products[$bc]=$product;}
            foreach($duplicates as $bc=>$unused)unset($products[$bc]);
            $rules=target_rules($db,(int)$u['id']);
            $selected=[];$used=[];$seen=[];$now=time();
            while($row=$q->fetch()){
                $r=json_decode($row['payload_json'],true);$id=(int)$row['row_no'];$bc='b:'.$r['barcode'];
                if(isset($seen[$bc]))throw new RuntimeException('Dosyada tekrarlayan barkod var; aktarım yapılmadı.');$seen[$bc]=true;
                $choice=null;
                if($auto||isset($picks[$id])){
                    if(!isset($rawRows[$bc])||json_encode(analysis_row($schema,$rawRows[$bc],$products,$module,$rules),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)!==json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))
                        throw new RuntimeException('Ürün veya analiz ayarları değişti. Güncel Excel ile yeniden analiz edin.');
                }
                if(($auto||isset($picks[$id]))&&!campaign_normal_draft_current($r,$products[$r['barcode']]??[],(int)$sourceJob['created_at'],$now)){if($auto)continue;throw new RuntimeException('Güncel matrah, kayıtlı fiyat, promosyon veya kargo bilgisi doğrulanamadı.');}
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
$linked=[];$linkedProducts=[];$linkedNow=time();$linkedRules=$kind==='promotion'?target_rules($db,(int)$u['id']):[];
if($kind==='promotion'&&$rows){
    $bcs=[];foreach($rows as $item){$payload=json_decode($item['payload_json'],true);if(is_string($payload['barcode']??null)&&$payload['barcode']!=='')$bcs[]=$payload['barcode'];}
    $bcs=array_values(array_unique($bcs));
    if($bcs){
        $linked=campaign_link_context($db,(int)$u['id'],$bcs,$linkedNow);
        $pq=$db->prepare("SELECT * FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode IN (".implode(',',array_fill(0,count($bcs),'?')).")");
        $pq->execute(array_merge([$u['id']],$bcs));while($product=$pq->fetch()){$k='b:'.$product['barcode'];if(array_key_exists($k,$linkedProducts))$linkedProducts[$k]=null;else $linkedProducts[$k]=$product;}
    }
}
$title=$kind==='bulk'?'Toplu Kârlılık Analizi':'Promosyon Kârlılık Analizi';
$pageTitle=$title.' — '.APP_NAME;
require __DIR__.'/header.php';
?>
<div class="wrap"><div class="toolbar"><div><h1><?=h($kind==='promotion'?$modules[$module]:$title)?></h1><p class="ui-muted">Dosyanızı yükleyin, kârı karşılaştırın, uygun fiyatı seçin.</p></div></div>
<p class="hint">Trendyol XLSX veya CSV, en fazla 20.000 satır ve 12 fiyat kademesi. Sonuçlar tahmindir; orijinal kampanya yükleme dosyası değildir. Kampanya katkısı ve sepet şartları ayrıca doğrulanmalıdır.</p>
<?php if($kind==='promotion'): ?><div class="card"><?php foreach($modules as $k=>$label): ?><a class="<?= $module===$k?'btn-primary':'btn-ghost' ?>" href="?mod=<?= h($k) ?>"><?= h($label) ?></a> <?php endforeach; ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<?php $flash=flash_get();if($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<div class="card ui-upload"><h2>1. Dosyanızı yükleyin</h2><p class="ui-muted">Trendyol'dan indirdiğiniz orijinal dosyayı seçin. Kolonları silmeniz gerekmez.</p><form class="ui-upload-form" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="analyze">
<label class="ui-dropzone">Excel veya CSV dosyası<input type="file" name="csv" accept=".xlsx,.csv" required></label><button class="btn-primary">Kârı hesapla</button></form>
<details class="ui-disclosure"><summary>Dosya ve hesaplama kuralları</summary><div class="ui-detail-body"><p class="hint">Örnek başlıklar: Barkod; Fiyat; 1. Fiyat Aralığı; 1. Komisyon Oranı. Promosyonlarda eşleşen komisyon kolonu yoksa kâr hesaplanmaz. Fiyat aralığının alt ve üst uçları ayrı senaryodur; otomatik seçime girmez. Fark, kayıtlı satış fiyatı ve kayıtlı komisyonla hesaplanan tahmini kâra göredir. Trendyol 3 ve 4 günlük tarife dosyalarında açık uçlu fiyat kademeleri yalnız bilinen sınırda hesaplanır. Tarih aralığı satırda gösterilir; otomatik kampanya seçimi yapılmaz.</p></div></details></div>
<?php if($job): ?>
<section class="ui-kpi-grid" aria-label="Analiz özeti"><?php ui_kpi('products','Dosyadaki satır',(string)$stats['rows'],'Yüklenen dosya');?><?php ui_kpi('check','Eşleşen ürün',(string)$stats['matched'],$stats['unmatched'].' eşleşmeyen');?><?php ui_kpi('wallet','Maliyeti eksik',(string)$stats['missing_cost'],'Kâr hesabı için gerekli');?><?php ui_kpi('finance','Pozitif senaryo',(string)$stats['profitable'],'Mevcut analiz kuralları');?></section><div class="card" style="margin-top:16px"><h2>2. Sonuçları karşılaştırın — <?= (int)$stats['rows'] ?> satır</h2>
<p><?= (int)$stats['matched'] ?> eşleşen · <?= (int)$stats['unmatched'] ?> eşleşmeyen · <?= (int)$stats['missing_cost'] ?> maliyeti eksik · <?= (int)$stats['profitable'] ?> pozitif senaryo · <?= isset($stats['meets_target'])?(int)$stats['meets_target']:'—' ?> hedefi karşılayan satır</p>
<p class="hint">Analiz tarihi: <?= date('d.m.Y H:i',(int)$job['created_at']) ?>. Ürün ayarları veya <a href="kar-hedefleri.php">kâr kuralları</a> değiştiğinde tekrar analiz edin. Hedef değerlendirmesi yükleme anındaki kurallara dayanır; kampanya katılım garantisi değildir. Barkodlar Excel'de metin olarak içe aktarılmalıdır.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="download">
<?php if(!empty($stats['campaign_source'])):?><div class="ui-tariff-actions"><h3>Excel kontrol taslağı</h3><p>Yalnız pozitif ve güncel eşleşmiş koşullu senaryolar seçilebilir. Dosya Trendyol tarafından kabul edilmiş sayılmaz; panelde fiyat, dönem, komisyon ve kampanya koşullarını kontrol edin. Avantajlı ürün taslağında dönem sonuna kadar kilitleme seçeneği Hayır olarak yazılır.</p><input type="hidden" name="campaign_hash" value="<?=h($stats['campaign_source'])?>"><label><input type="checkbox" name="campaign_confirm" value="1"> Seçtiğim tekliflerin kontrol taslağını hazırla</label><button class="btn-primary" name="scope" value="campaign_draft">Excel taslağını indir</button></div><?php endif;?>
<details class="ui-disclosure"><summary>Analiz raporunu indir (CSV)</summary><div class="ui-detail-body actions"><button class="btn-ghost" name="scope" value="all">Tüm raporu indir</button><button class="btn-ghost" name="scope" value="profitable">Pozitif senaryoları indir</button><button class="btn-ghost" name="scope" value="target">Hedefi karşılayanları indir</button><button class="btn-ghost" name="scope" value="selected">Bu sayfanın seçilenlerini indir</button></div></details>
<?php if(!empty($stats['tariff_source'])):?><div class="ui-tariff-actions"><h3>3. Trendyol'a yükleme dosyası hazırla</h3><p>Her ürün için bir kademe seçin. Yeni TSF ve dönem orijinal Excel'e yazılır; mağazada fiyat değişmez. Toplu en kârlı seçim, seçilen dönemde pozitif ve varsa hedefi karşılayan promosyonu olmayan ürünleri kapsar. Açık uçlarda yalnız sınır senaryosu değerlendirilir.</p><p>Promosyonlu ürünlerde indirim finansmanı ve yeni matrah doğrulanmadan fiyat dosyası hazırlanmaz. Trendyol panelinde fiyatı ve komisyonu doğrulayın. Eski analizleri yeniden yükleyin.</p><input type="hidden" name="tariff_hash" value="<?=h($stats['tariff_source'])?>"><label><input type="checkbox" name="tariff_confirm" value="1"> Seçilen fiyatları ve dönemleri kontrol ettim; Excel hazırla.</label><br><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn-primary" name="scope" value="tariff_selected">Seçilen tarifeleri Excel'e yaz</button><button class="btn-ghost" name="scope" value="tariff_best3">3 gün: en kârlı uygunlar</button><button class="btn-ghost" name="scope" value="tariff_best4">4 gün: en kârlı uygunlar</button></div></div><?php endif;?>
<?php foreach($rows as $row):$r=json_decode($row['payload_json'],true);$lk='b:'.$r['barcode'];$ctx=$linked[$lk]??[];$product=$linkedProducts[$lk]??null;$quotes=$product?campaign_link_quotes($r,$module,$ctx,$product,(int)$job['created_at'],$linkedNow,target_for_product($linkedRules,$product)):[];$snapshot=campaign_snapshot($r['campaign_meta']??[],$product);$normalDraft=$module==='komisyon'&&$product&&campaign_normal_draft_current($r,$product,(int)$job['created_at'],$linkedNow);$rank=$normalDraft?campaign_rank_normal($r,$linkedNow):campaign_rank_quotes($module,$quotes); ?>
<article class="ui-analysis-product"><div class="ui-analysis-product-head"><div><h3><?=h($r['name']?:'Eşleşmeyen ürün')?></h3><p class="hint">Barkod: <?=h($r['barcode'])?></p></div><label class="check"><input type="checkbox" name="sel[]" value="<?=(int)$row['row_no']?>"> Rapora ekle</label></div>
<?php if(!empty($r['campaign_meta'])):$m=$r['campaign_meta'];?><div class="ui-analysis-baseline"><?php foreach(['tsf'=>'Dosyadaki satış fiyatı','basis'=>'Normal komisyona esas fiyat','customer'=>'Normal müşteri fiyatı','plus_basis'=>'Plus komisyona esas fiyat','plus_customer'=>'Plus müşteri fiyatı','current_rate'=>'Güncel komisyon (%)','stock'=>'Stok','tariff'=>'Komisyon tarifesi'] as $mk=>$ml):if(($m[$mk]??'')!==''):?><span><?=h($ml)?> <strong><?=h($m[$mk])?></strong></span><?php endif;endforeach;?></div><?php if(!empty($m['promo'])):?><details class="ui-tier-detail"><summary>Dosyadaki mevcut promosyonlar</summary><p class="hint" style="white-space:pre-wrap"><?=h($m['promo'])?></p></details><?php endif;endif;?>
<?php if(in_array($module,['komisyon','plus','flash','etiket'],true)):?><div class="ui-linked"><strong>Aynı gün yüklenen eşleşen teklifler</strong><div class="actions"><?php foreach(['komisyon'=>'Ürün tarifesi','plus'=>'Plus tarifesi','etiket'=>'Avantajlı ürün'] as $cm=>$cl):if($cm===$module)continue;?><a class="btn-ghost" href="?mod=<?=h($cm)?>"><?=h($cl)?>: <?=isset($ctx[$cm])?'Eşleşti':'Güncel benzersiz kayıt yok'?></a><?php endforeach;?><?php if($module!=='flash'):?><a class="btn-ghost" href="?mod=flash">Flaş: <?=(int)($ctx['flash_count']??0)?> teklif</a><?php endif;?></div><p class="hint">Eşleşme, katılım onayı değildir. Kâr karşılaştırması yalnız promosyon olmayan, fiyatı ve dönemi uyumlu kayıtlar için koşullu olarak gösterilir.</p></div><?php endif;?>
<?php if($snapshot!==null):?><div class="ui-linked"><strong>Excel anındaki normal müşteri fiyatı ve kârı</strong><div class="ui-analysis-baseline"><span>Müşteri fiyatı <strong><?=tl($snapshot['customer'])?></strong></span><span>Komisyon matrahı <strong><?=tl($snapshot['basis'])?></strong></span><span>Komisyon (%<?=number_format($snapshot['rate'],2,',','.')?>) <strong><?=tl($snapshot['commission'])?></strong></span><span>Tahmini birim kâr <strong><?=$snapshot['profit']===null?'—':tl($snapshot['profit'])?></strong></span></div><p class="hint"><?=h($snapshot['reason'])?> Güncel Plus oranı ayrı doğrulanmadıkça Plus kârı hesaplanmaz.</p></div><?php endif;?>
<div class="ui-analysis-baseline"><span>Alış maliyeti <strong><?=$r['cost']===null?'—':tl((float)$r['cost'])?></strong></span><span>Kayıtlı satış <strong><?=isset($r['baseline_price'])?tl((float)$r['baseline_price']):'—'?></strong></span><span>Mevcut tahmini kâr <strong><?=isset($r['baseline_profit'])?tl((float)$r['baseline_profit']):'—'?></strong></span></div>
<?php $groups=[];foreach($columns as $key=>$def){$group=str_starts_with($key,'period3_')?'3 günlük dönem':(str_starts_with($key,'period4_')?'4 günlük dönem':'Fiyat seçenekleri');$groups[$group][$key]=$def;}?>
<?php foreach($groups as $group=>$defs):?><h4 class="ui-tier-group"><?=h($group)?></h4><div class="ui-tier-grid"><?php foreach($defs as $key=>$def):$t=$r['tiers'][$key];$app=$t['application']??null;?>
<div class="ui-tier"><?php if(isset($rank[$key])):?><p class="hint"><strong>Hesaplanan seçenekler içinde en yüksek birim kâr</strong> · dönem ve uygunluk koşullu</p><?php endif;?><div class="ui-tier-head"><strong><?=h($def['label'])?></strong><?php if($t['rate']!==null):?><span class="ui-status neutral">%<?=number_format((float)$t['rate'],2,',','.')?> komisyon</span><?php endif;?></div>
<?php if(!empty($t['period'])):?><p class="hint"><?=h($t['period'])?></p><?php endif;?>
<p class="hint"><?=!empty($r['campaign_meta'])?'Dosya teklif fiyatı / sınırı':'Esas fiyat'?></p><strong><?=$t['price']===null?'—':tl((float)$t['price'])?></strong>
<p class="hint">Tahmini kâr</p><strong class="ui-tier-profit <?=$t['profit']===null?'':($t['profit']<0?'neg':'pos')?>"><?=$t['profit']===null?'—':tl((float)$t['profit'])?></strong>
<?php if($app):?><p class="hint">Yeni satış fiyatı (TSF)</p><strong><?=tl((float)$app['tsf'])?></strong><p class="hint">Tahmini esas fiyat: <?=tl((float)$app['effective'])?><?=$app['promotion_assumption']?' · promosyon varsayımı':''?></p><?php endif;?>
<?php if(!empty($stats['tariff_source'])&&$app&&$normalDraft):if(tariff_period_open((string)$t['period'],time())):?><label class="ui-tier-pick"><input type="radio" name="tariff[<?=(int)$row['row_no']?>]" value="<?=h($key)?>"> Bu tarifeyi seç</label><?php else:?><p class="hint">Dönem geçmiş veya doğrulanamadı</p><?php endif;endif;?>
<?php if(isset($quotes[$key])):$cq=$quotes[$key];?><div class="ui-linked"><p class="hint">Tarife uygulanırsa · <?=h($cq['period'])?></p><p>Normal: <?=tl($cq['normal_price'])?> · %<?=number_format($cq['normal_rate'],2,',','.')?> komisyon</p><strong><?=tl($cq['normal_profit'])?> tahmini kâr</strong><?php if($cq['plus_profit']!==null):?><p>Plus: <?=tl($cq['plus_price'])?> · %<?=number_format($cq['plus_rate'],2,',','.')?> komisyon</p><strong><?=tl($cq['plus_profit'])?> tahmini kâr</strong><?php endif;?>
<?php if(($cq['target_met']??null)===false):?><p class="hint">Kâr hedefi karşılanmıyor; taslak seçimi kapalı.</p><?php endif;?>
<?php if(!empty($stats['campaign_source'])&&($cq['target_met']??null)!==false&&($module==='plus'?($cq['plus_profit']??0):$cq['normal_profit'])>0):?><label class="ui-tier-pick"><input type="radio" name="campaign_pick[<?=(int)$row['row_no']?>]" value="<?=h($key.':'.campaign_quote_token($cq,$stats['campaign_source'],(int)$row['row_no'],$key))?>"> Taslağa ekle</label><?php endif;?></div><?php endif;?>
<details class="ui-tier-detail"><summary>Hesap ayrıntıları</summary><?php if(isset($t['min_price'])):?><p class="hint">Alt uç <?=tl((float)$t['min_price'])?> / kâr <?=isset($t['min_endpoint_profit'])?tl((float)$t['min_endpoint_profit']):'—'?></p><?php endif;?><?php if(isset($t['profit_delta'])):?><p class="hint">Mevcut kâra fark: <?=tl((float)$t['profit_delta'])?></p><?php endif;?><?php if(isset($t['rate_source'])):?><p class="hint">Komisyon: <?=$t['rate_source']==='file'?'dosya':'kayıtlı ürün'?></p><?php endif;?><p class="hint"><?=h($t['reason'])?></p><?php if(isset($t['target_met'])):?><p class="hint"><?=$t['target_met']?'Hedef karşılanıyor':'Hedef altında'?></p><?php endif;?><?php if(isset($t['application_target'])):?><p class="hint"><?=$t['application_target']?'Fiyat hedefi karşılanıyor':'Fiyat hedefin altında'?></p><?php endif;?></details>
</div><?php endforeach;?></div><?php endforeach;?>
<?php if(!empty($stats['tariff_source'])):?><label class="check"><input type="radio" name="tariff[<?=(int)$row['row_no']?>]" value="" checked> Bu ürün için tarife seçme</label><?php endif;?>
<?php if(!empty($stats['campaign_source'])):?><label class="check"><input type="radio" name="campaign_pick[<?=(int)$row['row_no']?>]" value="" checked> Bu ürün için seçim yapma</label><?php endif;?>
</article><?php endforeach;?>
<?php if(!empty($stats['tariff_source'])):?><button class="btn-primary" name="scope" value="tariff_selected">Seçilen tarifeleri Excel'e yaz</button><?php endif;?></form>
<p>Sayfa <?= $page ?> / <?= $pages ?>
<?php $sep=str_contains($url,'?')?'&':'?';if($page>1): ?><a href="<?= h($url.$sep.'page='.($page-1)) ?>">Önceki</a><?php endif; ?>
<?php if($page<$pages): ?><a href="<?= h($url.$sep.'page='.($page+1)) ?>">Sonraki</a><?php endif; ?></p>
</div><?php endif; ?></div>
<?php require __DIR__.'/footer.php'; ?>
