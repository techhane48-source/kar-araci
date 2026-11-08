<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/promotion.php';
$u = require_login();
if (!is_pro($u)) { flash_set('error','Promosyon Kârlılık Analizi Pro plan özelliğidir.'); e_redirect('odeme.php'); }
$modules = ['komisyon'=>'Ürün Komisyon Tarifesi','etiket'=>'Avantajlı Ürün Etiketi','flash'=>'Flaş Ürünler','plus'=>'Plus Komisyon Tarifesi','indirim'=>'İndirimler','kampanya'=>'Kampanyalar'];
$mod = is_string($_GET['mod']??null) && isset($modules[$_GET['mod']]) ? $_GET['mod'] : 'komisyon';
$supported = in_array($mod,['komisyon','etiket','flash'],true);
$url = 'promosyon-analiz.php?mod='.$mod;
$page = max(1,min(500,(int)($_GET['page']??1)));

function promo_saved_import(string $kind,int $uid): ?array {
    $m = $_SESSION['promotion_imports'][$kind]??null;
    if (!$m || (int)($m['owner']??0)!==$uid || ($m['created']??0)<time()-86400 || !is_file($m['path']??'')) return null;
    return $m;
}

session_boot();
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='upload') {
    try {
        if (!$supported) throw new RuntimeException('Bu dosya türü henüz desteklenmiyor.');
        if (!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null)) throw new RuntimeException('Oturum süresi doldu.');
        $file = $_FILES['workbook']??[];
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'')) throw new RuntimeException('XLSX dosyası alınamadı. Dosya boyutunu ve sunucunun yükleme sınırını kontrol edin.');
        if (strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION))!=='xlsx') throw new RuntimeException('Trendyol’dan indirdiğiniz XLSX dosyasını seçin.');
        $period = $mod==='flash' ? null : promo_range((string)($_POST['start']??''),(string)($_POST['end']??''));
        $book = promo_workbook($file['tmp_name']);
        promo_schema($book,$mod);
        if ($mod==='komisyon') promo_tariff($book,$period);
        $dir = dirname(DB_PATH).'/promotion-imports/'.(int)$u['id'];
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Dosya saklama alanı açılamadı.');
        $total = 0;
        foreach (glob($dir.'/*.xlsx')?:[] as $stored) {
            if (filemtime($stored)<time()-86400) @unlink($stored); else $total+=filesize($stored);
        }
        if ($total+filesize($file['tmp_name'])>50*1024*1024) throw new RuntimeException('Geçici dosya alanı dolu. Önceki dosyalar 24 saat sonra temizlenir.');
        $version = bin2hex(random_bytes(16)); $path = $dir.'/'.$version.'.xlsx';
        if (!move_uploaded_file($file['tmp_name'],$path)) throw new RuntimeException('Dosya saklanamadı.');
        chmod($path,0600);
        $old = promo_saved_import($mod,(int)$u['id']);
        $_SESSION['promotion_imports'][$mod] = ['path'=>$path,'version'=>$version,'owner'=>(int)$u['id'],'period'=>$period,'created'=>time(),'name'=>mb_substr(basename((string)$file['name']),0,160)];
        unset($_SESSION['promotion_choices'][$mod]);
        if ($old) @unlink($old['path']);
        flash_set('success','Excel okundu. Teklifleri inceleyip her satır için bir seçim yapabilirsiniz.');
    } catch (Throwable $e) { flash_set('error',$e instanceof RuntimeException?$e->getMessage():'Excel dosyası işlenemedi.'); }
    e_redirect($url);
}

$meta = $supported ? promo_saved_import($mod,(int)$u['id']) : null;
$analysis = null; $book = null; $error = null; $context = ''; $tariffMeta = null;
if ($meta) {
    try {
        $book=promo_workbook($meta['path']);$map=promo_schema($book,$mod);$barcodes=[];
        foreach ($book['records'] as $row) {
            try {$barcode=promo_cell($row,$map,'BARKOD');if($barcode!=='')$barcodes[$barcode]=true;} catch (RuntimeException $e) { /* The row's error is reported by promo_analyze. */ }
        }
        $products=[];
        foreach (array_chunk(array_keys($barcodes),400) as $chunk) {
            $q=db()->prepare("SELECT * FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode IN (".implode(',',array_fill(0,count($chunk),'?')).") ORDER BY id");
            $q->execute(array_merge([$u['id']],$chunk));
            while ($product=$q->fetch()) {
                $b=(string)$product['barcode'];
                if (array_key_exists($b,$products)) $products[$b]=['ambiguous'=>true]; else $products[$b]=$product;
            }
        }
        unset($barcodes);
        $tariffMeta=promo_saved_import('komisyon',(int)$u['id']); $tariffs=[];
        if ($tariffMeta) $tariffs=promo_tariff($mod==='komisyon'?$book:promo_workbook($tariffMeta['path']),$tariffMeta['period']);
        $analysis=promo_analyze($book,$mod,$products,$meta['period'],$tariffs);
        $context=hash('sha256',json_encode([$meta['version'],$tariffMeta['version']??null,$products],JSON_THROW_ON_ERROR));
        // Export needs only the original ZIP path and sheet name, not a second copy of every input cell.
        $book=['path'=>$book['path'],'sheet'=>$book['sheet']];
        unset($products,$tariffs);
    } catch (Throwable $e) { $error=$e instanceof RuntimeException?$e->getMessage():'Excel analizi tamamlanamadı.'; }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $temp=null;
    try {
        if (!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null)) throw new RuntimeException('Oturum süresi doldu.');
        if (!$analysis || !$meta || !$context || !is_string($_POST['version']??null) || !hash_equals($context,$_POST['version'])) throw new RuntimeException('Dosya, tarife veya ürün bilgileri değişti. Sayfayı yenileyip seçimi kontrol edin.');
        $action=$_POST['action']??'';
        if (!in_array($action,['save','download','clear'],true)) throw new RuntimeException('Geçersiz işlem.');
        $choices=$_SESSION['promotion_choices'][$mod]??[];
        if ($action==='clear') $choices=[];
        else {
            if (!is_array($_POST['choice']??[])) throw new RuntimeException('Seçim biçimi geçersiz.');
            foreach ($_POST['choice']??[] as $row=>$key) {
                if (!preg_match('/^[0-9]+$/D',(string)$row) || !is_string($key) || !isset($analysis['rows'][(int)$row]) || ($key!==''&&!isset($analysis['rows'][(int)$row]['offers'][$key]))) throw new RuntimeException('Geçersiz teklif seçimi.');
                if ($key==='') unset($choices[(int)$row]); else $choices[(int)$row]=$key;
            }
        }
        if ($action==='download') {
            $changes=promo_export_changes($analysis,$choices);
            $temp=tempnam(sys_get_temp_dir(),'kf-promo-');
            promo_export_workbook($book,$changes,$temp);
            $_SESSION['promotion_choices'][$mod]=$choices;
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="trendyol-'.$mod.'-secimler.xlsx"');
            header('Content-Length: '.filesize($temp));
            header('Cache-Control: no-store');
            readfile($temp);unlink($temp);exit;
        }
        $_SESSION['promotion_choices'][$mod]=$choices;
        flash_set('success',$action==='clear'?'Taslak seçimler temizlendi. Kaynak Excel değişmedi.':'Seçimler bu taslakta saklandı.');
    } catch (Throwable $e) {
        if ($temp && is_file($temp)) unlink($temp);
        flash_set('error',$e instanceof RuntimeException?$e->getMessage():'Dosya oluşturulamadı.');
    }
    e_redirect($url.'&page='.$page);
}

$choices=$_SESSION['promotion_choices'][$mod]??[];
$pageTitle=$modules[$mod].' — '.APP_NAME;
require __DIR__.'/../includes/header.php';
?>
<div class="wrap">
  <h1>Promosyon Kârlılık Analizi</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
    <?php foreach ($modules as $key=>$title): ?><a class="<?= $key===$mod?'btn-primary':'btn-ghost' ?>" href="promosyon-analiz.php?mod=<?= h($key) ?>"><?= h($title) ?></a><?php endforeach; ?>
  </div>
  <?php if (isset($_SESSION['flash'])): $f=$_SESSION['flash'];unset($_SESSION['flash']); ?><div role="status" class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
  <?php if (!$supported): ?>
    <div class="card"><h2><?= h($modules[$mod]) ?></h2><p>Bu dosya türü için analiz desteği henüz hazır değil. Ürün komisyon tarifesi, avantajlı ürün etiketi ve flaş ürünler dosyalarını analiz edebilirsiniz.</p></div>
  <?php else: ?>
  <div class="card" style="margin:16px 0">
    <h2><?= h($modules[$mod]) ?> Excel’i</h2>
    <p>Trendyol’dan indirdiğiniz özgün XLSX dosyasını yükleyin. Barkodlar Trendyol ürünlerinizle eşleştirilir. Maliyet ve giderler kayıtlı ürün ayarlarınızdan alınır.</p>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload">
      <div class="field"><label for="workbook">Trendyol Excel dosyası (en fazla 10 MB)</label><input id="workbook" type="file" name="workbook" accept=".xlsx" required></div>
      <?php if ($mod!=='flash'): ?>
      <div class="field"><label for="start">Tarife başlangıcı (Türkiye saati)</label><input id="start" type="datetime-local" name="start" required></div>
      <div class="field"><label for="end">Tarife bitişi (Türkiye saati)</label><input id="end" type="datetime-local" name="end" required></div>
      <?php endif; ?>
      <button class="btn-primary" type="submit">Excel’i Analiz Et</button>
    </form>
    <p class="hint"><?= $mod==='flash'?'Teklif tarihleri Excel’deki her satırdan okunur. Aynı barkodun farklı tarihlerdeki teklifleri ayrı değerlendirilir.':'Tarih ve saatleri Trendyol’daki tarife dönemiyle aynı girin; tam gün varsayılmaz.' ?> Dosyalar bu oturumda 24 saat kullanılabilir.</p>
    <?php if ($mod!=='komisyon'): ?><p class="hint">Üründe komisyon tarifesi varsa önce <a href="promosyon-analiz.php?mod=komisyon">ürün komisyon dosyasını</a> da yükleyin. Teklif dönemini kısmen kapsayan tarifelerde tek komisyonla tahmin yapılmaz.</p><?php endif; ?>
  </div>
  <p class="hint">Gösterilen kâr teklif fiyatı üzerinden bir tahmindir. Mevcut kupon, koşullu indirim ve kampanya birleşimleri bu hesaba dahil değildir. Kargo, hizmet bedeli ve vergi ayarlarınızı kontrol edin. Excel’i indirmek Trendyol’daki fiyatları değiştirmez.</p>
  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($analysis): $rows=array_values($analysis['rows']);$total=count($rows);$pages=max(1,(int)ceil($total/100));$page=min($page,$pages);$shown=array_slice($rows,($page-1)*100,100);$ready=count(array_filter($rows,fn($r)=>!empty($r['offers']))); ?>
  <div class="card" style="margin:16px 0">
    <h2>Teklifler</h2>
    <p><?= h($meta['name']) ?> — <?= $total ?> satır; <?= $ready ?> satırda hesaplanabilir teklif, <?= $total-$ready ?> satırda eksik bilgi veya geçersiz teklif. Taslakta <?= count($choices) ?> seçim var.</p>
    <form method="post" action="<?= h($url.'&page='.$page) ?>">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="version" value="<?= h($context) ?>">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0">
        <button type="submit" name="action" value="save" class="btn-ghost">Sayfadaki Seçimleri Sakla</button>
        <button type="submit" name="action" value="clear" class="btn-ghost">Tüm Taslak Seçimleri Temizle</button>
        <button type="submit" name="action" value="download" class="btn-primary">Seçimleri İndir (XLSX)</button>
      </div>
      <p class="hint">Her satırda bir teklif seçin. Başka sayfaya geçmeden seçimlerinizi saklayın. Seçilmemiş satırların özgün değerleri korunur.</p>
      <div style="overflow-x:auto"><table>
        <thead><tr><th>Excel satırı / barkod</th><th>Ürün</th><th>Teklif seçimi — fiyat / tahmini kâr / komisyon</th><th>Bilgi</th></tr></thead>
        <tbody><?php foreach ($shown as $r): ?><tr>
          <td><?= $r['row'] ?><br><?= h($r['barcode']) ?></td><td><?= h($r['name']) ?></td>
          <td><?php if ($r['offers']): ?>
            <select aria-label="<?= h('Satır '.$r['row'].' teklif seçimi') ?>" name="choice[<?= $r['row'] ?>]" style="min-width:340px">
              <option value="">Kaynak satırı değiştirme</option>
              <?php foreach ($r['offers'] as $key=>$offer): ?><option value="<?= h((string)$key) ?>" <?= (string)($choices[$r['row']]??'')===(string)$key?'selected':'' ?>><?= h($offer['label'].' — '.tl($offer['price']).' / kâr '.tl($offer['profit']).' / komisyon %'.number_format($offer['rate'],2,',','.')) ?></option><?php endforeach; ?>
            </select>
            <?php foreach ($r['offers'] as $offer): ?><div class="hint"><?= h($offer['label'].' · '.$offer['rate_source'].' · '.date('d.m.Y H:i',$offer['period']['start']).' – '.date('d.m.Y H:i',$offer['period']['end'])) ?></div><?php endforeach; ?>
          <?php else: ?><span class="hint">Seçim için bilgi eksik</span><?php endif; ?></td>
          <td><?php if ($r['error']): ?><div><?= h($r['error']) ?></div><?php endif; ?><?php foreach (array_unique($r['warnings']) as $warning): ?><div class="hint"><?= h($warning) ?></div><?php endforeach; ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    </form>
    <nav aria-label="Teklif sayfaları" style="display:flex;gap:12px;margin-top:16px">
      <?php if ($page>1): ?><a href="<?= h($url.'&page='.($page-1)) ?>">Önceki</a><?php endif; ?><span><?= $page ?> / <?= $pages ?></span><?php if ($page<$pages): ?><a href="<?= h($url.'&page='.($page+1)) ?>">Sonraki</a><?php endif; ?>
    </nav>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__.'/../includes/footer.php'; ?>
