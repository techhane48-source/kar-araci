<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/xlsx.php';

$u = require_login();
$pro = is_pro($u);
header('Cache-Control: no-store, private');

// Direct cost entry: explicit historical scope, exact tenant-owned product, no file needed.
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='direct_cost'){
    if(!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null)){http_response_code(403);exit('Geçersiz oturum.');}
    try{
        foreach(['pid','cost','vat','scope'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Maliyet, KDV ve geçerlilik kapsamı gerekli.');
        if(!in_array($_POST['vat'],['1','10','20'],true))throw new InvalidArgumentException('Alış KDV oranı %1, %10 veya %20 olmalıdır.');
        if(!ctype_digit($_POST['pid']))throw new InvalidArgumentException('Ürün geçersiz.');
        $uid=(int)$u['id'];$pid=(int)$_POST['pid'];$scope=$_POST['scope'];
        if(!in_array($scope,['today','first_sale'],true))throw new InvalidArgumentException('Geçerlilik kapsamını seçin.');
        $res=with_marketplace_lock($uid,'trendyol',static function()use($uid,$pid,$scope){
            $q=db()->prepare("SELECT barcode FROM products WHERE user_id=? AND id=? AND marketplace='trendyol' AND barcode<>''");$q->execute([$uid,$pid]);$bc=$q->fetchColumn();
            if($bc===false)throw new InvalidArgumentException('Ürün bulunamadı.');
            $values=$scope==='first_sale'?cost_history_first_sale_values([(string)$bc],$_POST['cost'],$_POST['vat']):cost_history_group_values([(string)$bc],date('Y-m-d'),$_POST['cost'],$_POST['vat']);
            return ['ok'=>true]+cost_history_save(db(),$uid,$values,false,$scope==='first_sale'?'first_sale':'manual',true);
        });
        if(empty($res['ok']))throw new InvalidArgumentException($res['error']??'Maliyet kaydedilemedi.');
        flash_set('success','Maliyet ve alış KDV oranı kaydedildi.');
    }catch(InvalidArgumentException|RuntimeException $e){flash_set('error',$e->getMessage());}
    catch(Throwable $e){flash_set('error','Maliyet kaydedilemedi; önceki kayıtlar korundu.');}
    e_redirect('urunler.php?q='.urlencode(is_string($_POST['q']??null)?$_POST['q']:'').'&page='.max(1,(int)($_POST['page']??1)).'&open='.max(0,(int)($_POST['open']??0)).'&vp='.max(1,(int)($_POST['vp']??1)));
}

// ---- Maliyet şablonu indir (Melontik formatı: 14 kolon) ----
if (($_GET['export'] ?? '') === 'maliyet') {
    $bos = (($_GET['bos'] ?? '') === '1');
    $format = (($_GET['format'] ?? 'xlsx') === 'csv') ? 'csv' : 'xlsx';
    $sql = "SELECT * FROM products WHERE user_id = ? AND barcode != '' AND marketplace='trendyol'";
    if ($bos) $sql .= ' AND (cost = 0 OR cost IS NULL)';
    $sql .= ' ORDER BY id';
    $st = db()->prepare($sql);
    $st->execute([$u['id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="maliyetler.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Barkod','Model Kodu','Stok kodu','Kategori İsmi','Ürün Adı','Trendyol  Satış Fiyatı','Stok','Ürün Maliyeti ( KDV Dahil)','Maliyet KDV Oranı','Para Birimi','Ürün Desisi','Teslimat Tipi','Ekstra Maliyet (%)','Ekstra Maliyet (TL)']);
        foreach ($rows as $r) {
            $desi = (float)($r['kesilen_desi'] ?? 0);
            if ($desi <= 0) $desi = desi_from_dims((float)($r['en'] ?? 0), (float)($r['boy'] ?? 0), (float)($r['yukseklik'] ?? 0));
            fputcsv($out, [
                csv_safe_text($r['barcode'] ?? ''), csv_safe_text($r['model_kodu'] ?? ''), '', csv_safe_text($r['category'] ?? ''), csv_safe_text($r['name'] ?? ''),
                (float)($r['price'] ?? 0), (int)($r['quantity'] ?? 0),
                (float)($r['cost'] ?? 0) > 0 ? (float)$r['cost'] : '',
                (float)($r['cost_vat'] ?? $r['kdv'] ?? 20),
                'TRY', $desi > 0 ? round($desi, 2) : '',
                (!empty($r['cargo_type']) && $r['cargo_type'] !== 'seller') ? $r['cargo_type'] : 'standart',
                0, (float)($r['extra_fee'] ?? 0),
            ]);
        }
        fclose($out);
        exit;
    }

    // XLSX (Melontik birebir)
    $tmpPath = sys_get_temp_dir() . '/maliyet_' . uniqid() . '.xlsx';
    xlsx_write_maliyet($rows, $tmpPath);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="maliyetler.xlsx"');
    header('Content-Length: ' . filesize($tmpPath));
    readfile($tmpPath);
    @unlink($tmpPath);
    exit;
}

// ---- Toplu maliyet içe aktar (Melontik formatı; barkod ile eşleştirip günceller) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_maliyet') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Oturum süresi doldu.');
        e_redirect('urunler.php');
    }
    if (!$pro) {
        flash_set('error', 'Toplu maliyet içe aktarma Pro plan özelliğidir.');
        e_redirect('odeme.php');
    }
    try{
        [$headers,$rows]=analysis_read_upload($_FILES['csv']??[]);
        $res=with_marketplace_lock((int)$u['id'],'trendyol',static function()use($u,$headers,$rows){
            return ['ok'=>true,'stats'=>catalog_cost_import(db(),(int)$u['id'],$headers,$rows)];
        });
        if(!$res['ok'])throw new RuntimeException($res['error']);
        $stats=$res['stats'];
        flash_set('success',$stats['updated'].' ürün güncellendi; '.$stats['unmatched'].' barkod eşleşmedi, '.$stats['blank'].' boş maliyet atlandı. Fiyat, stok ve fatura desisi değiştirilmedi.');
    }catch(Throwable $e){flash_set('error',$e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'Dosya işlenemedi; değişiklikler geri alındı.');}
    e_redirect('urunler.php');
}

// Ürün silme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $pid = (int) ($_POST['pid'] ?? 0);
        db()->prepare("DELETE FROM products WHERE id = ? AND user_id = ?")->execute([$pid, $u['id']]);
        flash_set('success', 'Ürün silindi.');
    }
    e_redirect('urunler.php');
}

// CSV içe aktar (Pro gerektirir)
$importResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Oturum süresi doldu.');
        e_redirect('urunler.php');
    }
    if (!$pro) {
        flash_set('error', 'Toplu CSV içe aktarma Pro plan özelliğidir.');
        e_redirect('odeme.php');
    }
    if (empty($_FILES['csv']['tmp_name'])) {
        flash_set('error', 'CSV dosyası seçin.');
        e_redirect('urunler.php');
    }
    $file = fopen($_FILES['csv']['tmp_name'], 'r');
    $importResult = ['ok' => 0, 'skip' => 0, 'errors' => []];
    $header = fgetcsv($file, null, ',', '"', '\\'); // PHP 8.4: escape zorunlu
    if ($header === false) {
        $importResult['errors'][] = 'Dosya boş veya okunamadı.';
    } else {
        $header = array_map(fn($x) => strtolower(trim($x)), $header);
        $map = ['name' => 'name', 'urun' => 'name', 'urun_adi' => 'name', 'marketplace' => 'marketplace',
                'pazaryeri' => 'marketplace', 'category' => 'category', 'kategori' => 'category',
                'cost' => 'cost', 'alis' => 'cost', 'price' => 'price', 'satis' => 'price',
                'cargo' => 'cargo', 'kargo' => 'cargo', 'service_fee' => 'service_fee', 'hizmet_bedeli' => 'service_fee',
                'hizmet' => 'service_fee', 'stopaj' => 'stopaj', 'return_rate' => 'return_rate', 'iade' => 'return_rate'];
        $cols = [];
        foreach ($header as $i => $col) {
            if (isset($map[$col])) $cols[$map[$col]] = $i;
        }
        $mpMap = ['trendyol' => 'trendyol', 'hepsiburada' => 'hepsiburada', 'amazon' => 'amazon', 'n11' => 'n11'];
        $ins = db()->prepare("INSERT INTO products (user_id, name, marketplace, category, rate, cost, price, cargo, service_fee, stopaj, return_rate)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $line = 1;
        while (($row = fgetcsv($file, null, ',', '"', '\\')) !== false) {
            $line++;
            $mp = strtolower(trim($row[$cols['marketplace']] ?? 'trendyol'));
            if (!isset($mpMap[$mp])) { $importResult['skip']++; continue; }
            $category = trim($row[$cols['category']] ?? '');
            if ($category === '') { $importResult['skip']++; continue; }
            $name = trim($row[$cols['name']] ?? 'Ürün');
            $rate = category_rate($category, $mpMap[$mp]);
            $ins->execute([
                $u['id'], mb_substr($name ?: 'Ürün', 0, 120), $mpMap[$mp], $category, $rate,
                (float) ($row[$cols['cost'] ?? 0] ?? 0),
                (float) ($row[$cols['price'] ?? 0] ?? 0),
                (float) ($row[$cols['cargo'] ?? 0] ?? 0),
                (float) ($row[$cols['service_fee'] ?? 0] ?? 0),
                (float) ($row[$cols['stopaj'] ?? 0] ?? 0),
                (float) ($row[$cols['return_rate'] ?? 0] ?? 0),
            ]);
            $importResult['ok']++;
        }
    }
    fclose($file);
    flash_set($importResult['ok'] > 0 ? 'success' : 'error',
        $importResult['ok'] > 0
            ? "{$importResult['ok']} ürün içe aktarıldı" . ($importResult['skip'] ? ", {$importResult['skip']} atlandı" : '') . '.'
            : (implode(' ', $importResult['errors']) ?: 'Hiç ürün aktarılamadı. CSV başlıklarını kontrol edin.'));
    e_redirect('urunler.php');
}

// Model grouping is based only on verified catalogue identity; legacy rows stay separate.
// Search matches any variant; a matched model shows all its variants.
$q = trim(is_string($_GET['q']??null)?$_GET['q']:'');
$uid=(int)$u['id'];
$keyExpr="CASE WHEN marketplace='trendyol' AND variant_model_key IS NOT NULL AND variant_model_key<>'' THEN 'm:'||variant_model_key ELSE 'p:'||id END";
$cte="WITH scoped AS (SELECT *, $keyExpr AS group_key FROM products WHERE user_id=?), matched AS (SELECT DISTINCT group_key FROM scoped";
$args=[$uid];
if($q!==''){
    $cte.=" WHERE name LIKE ? OR barcode LIKE ? OR variant_model_code LIKE ? OR variant_size LIKE ?";
    for($i=0;$i<4;$i++)$args[]='%'.$q.'%';
}
$cte.="), grouped AS (SELECT s.group_key,MIN(s.id) AS group_id,MAX(s.id) AS newest_id,COUNT(*) AS variants,SUM(CASE WHEN s.cost>0 THEN 1 ELSE 0 END) AS cost_known,MIN(s.price) AS min_price,MAX(s.price) AS max_price FROM scoped s JOIN matched m ON s.group_key=m.group_key GROUP BY s.group_key) ";
$totalProfit=0.0;$totalCommission=0.0;$totalCount=0;$maliyetsiz=0;
$totStmt=db()->prepare($cte."SELECT s.marketplace,s.cost,s.price,s.cargo,s.rate,s.kdv,s.cost_vat,s.extra_fee,s.service_fee,s.stopaj,s.stopaj_mode,s.stopaj_rate,s.allow_negative_vat,s.return_rate FROM scoped s JOIN matched m ON s.group_key=m.group_key");
$totStmt->execute($args);
while($tp=$totStmt->fetch()){
    if((float)$tp['cost']<=0)$maliyetsiz++;
    $rr=product_profit($tp);
    if((float)$tp['cost']>0)$totalProfit+=$rr['profit'];
    $totalCommission+=$rr['commission'];$totalCount++;
}
$totals=['profit'=>$totalProfit,'commission'=>$totalCommission,'count'=>$totalCount];
$st=db()->prepare($cte."SELECT COUNT(*) FROM grouped");$st->execute($args);$modelCount=(int)$st->fetchColumn();
$perPage=50;$page=max(1,(int)($_GET['page']??1));$totalPages=max(1,(int)ceil($modelCount/$perPage));$page=min($page,$totalPages);$offset=($page-1)*$perPage;
$st=db()->prepare($cte."SELECT s.*,g.group_id,g.variants,g.cost_known,g.min_price,g.max_price FROM grouped g JOIN scoped s ON s.id=g.group_id ORDER BY g.newest_id DESC LIMIT $perPage OFFSET $offset");$st->execute($args);$products=$st->fetchAll();
$open=max(0,(int)($_GET['open']??0));$vp=max(1,(int)($_GET['vp']??1));$variantPages=1;$expanded=[];
$enrich=static function(array $p):array{
    $r=product_profit($p);$p['_profit']=$r['profit'];$p['_margin']=$r['margin'];$p['_ratio']=$r['ratio'];$p['_has_cost']=(float)$p['cost']>0;return $p;
};
foreach($products as &$p){
    $p=$enrich($p);
    if((int)$p['group_id']===$open&&(int)$p['variants']>1){
        $variantPages=max(1,(int)ceil((int)$p['variants']/100));$vp=min($vp,$variantPages);$vo=($vp-1)*100;
        $st=db()->prepare("SELECT * FROM products WHERE user_id=? AND marketplace='trendyol' AND variant_model_key=? ORDER BY variant_size,barcode,id LIMIT 100 OFFSET $vo");
        $st->execute([$uid,$p['variant_model_key']]);$expanded=array_map($enrich,$st->fetchAll());
    }
}unset($p);
$url=static fn(int $pg,int $opened=0,int $vpage=1):string=>'urunler.php?q='.urlencode($q).'&page='.$pg.'&open='.$opened.'&vp='.$vpage.($opened?'#model-'.$opened:'');
$pageTitle = 'Ürünlerim — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Ürünlerim</h1><div class="actions"><a class="btn-primary" href="maliyet-senaryosu.php">Toplu maliyet gir</a><a class="btn-ghost" href="maliyet-gecmisi.php">Maliyet geçmişi</a></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Ürün, model, ebat veya barkod ara..." style="width:240px">
        <button type="submit" class="btn-ghost">Ara</button>
        <?php if ($q !== ''): ?><a href="urunler.php" class="btn-ghost" style="text-decoration:none">Temizle</a><?php endif; ?>
      </form>
      <?php if ($pro): ?>
      <details><summary>Excel / CSV (isteğe bağlı)</summary>
        <a href="urunler.php?export=maliyet" class="btn-ghost" style="text-decoration:none" title="Melontik formatında Excel indir">⬇ Maliyet Şablonu İndir (Excel)</a>
        <a href="urunler.php?export=maliyet&bos=1" class="btn-ghost" style="text-decoration:none" title="Sadece maliyeti henüz girilmemiş ürünleri indir">Sadece maliyeti boş</a>
        <a href="urunler.php?export=maliyet&format=csv" class="btn-ghost" style="text-decoration:none;font-size:12px" title="CSV olarak indir">CSV</a>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="import_maliyet">
          <input type="file" name="csv" accept=".csv,.xlsx" style="width:auto;font-size:13px" required>
          <button type="submit" class="btn-primary">Maliyet Yükle</button>
        </form>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="import">
          <input type="file" name="csv" accept=".csv,.xlsx" style="width:auto;font-size:13px" required>
          <button type="submit" class="btn-ghost">Yeni Ürün CSV</button>
        </form>
      </details>
      <?php else: ?>
        <a href="odeme.php" class="btn-ghost">Pro — isteğe bağlı dosya aktarımı</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($totalCount > 0 && $maliyetsiz > 0): ?>
  <div class="alert alert-error" style="margin-bottom:14px">
    <b><?= $maliyetsiz ?></b> ürüne henüz maliyet girilmedi — kâr hesaplanamaz.
    <a href="maliyet-senaryosu.php" style="margin-left:auto">Panelden toplu maliyet gir</a>
  </div>
  <?php endif; ?>

  <div class="kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
    <div class="kpi"><div class="k-label">Toplam Net Kâr</div><div class="k-value <?= $totals['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($totals['profit']) ?></div></div>
    <div class="kpi"><div class="k-label">Ana ürün / model</div><div class="k-value"><?=$modelCount?></div><div class="hint"><?=$totalCount?> barkod / ürün kaydı</div></div>
    <div class="kpi"><div class="k-label">Toplam Komisyon</div><div class="k-value"><?= tl($totals['commission']) ?></div></div>
  </div>

  <?php if (!$products): ?>
    <div class="card empty"><?= $q !== '' ? 'Aramanızla eşleşen ürün yok.' : 'Henüz ürün yok. <a href="index.php">Hesap makinesinden</a> ürünlerini kaydetmeye başla.' ?></div>
  <?php else: ?>
  <div class="card table-card">
    <table>
      <tr><th>Ürün</th><th>Barkod</th><th>Pazaryeri</th><th>Kategori</th><th>Satış</th><th>Alış maliyeti / KDV</th><th>Net Kâr</th><th>Kâr Oranı</th><th>Kâr Marjı</th><th></th></tr>
      <?php foreach ($products as $parent): $grouped=(int)$parent['variants']>1;$gid=(int)$parent['group_id']; ?>
      <?php if($grouped):?>
      <tr id="model-<?=$gid?>" data-model-group="<?=$gid?>">
        <td><a class="btn-ghost" href="<?=h($url($page,$open===$gid?0:$gid))?>" aria-expanded="<?=$open===$gid?'true':'false'?>"><?=$open===$gid?'▾':'▸'?> <?=h($parent['name'])?></a><div class="hint"><?=h((string)$parent['variant_model_code'])?> · <?=(int)$parent['variants']?> varyant</div></td>
        <td>—</td><td><?=h(marketplace_label($parent['marketplace']))?></td><td><?=h($parent['category'])?></td>
        <td><?=tl((float)$parent['min_price'])?><?php if((float)$parent['min_price']!==(float)$parent['max_price']):?> – <?=tl((float)$parent['max_price'])?><?php endif;?></td>
        <td><div class="hint"><?=(int)$parent['cost_known']?> / <?=(int)$parent['variants']?> maliyet girildi</div><a class="btn-ghost" href="perde-maliyeti.php?pid=<?=$gid?>">Perde maliyeti gir</a></td>
        <td colspan="4"><a class="btn-ghost" href="<?=h($url($page,$open===$gid?0:$gid))?>"><?=$open===$gid?'Varyantları gizle':'Varyantları göster'?></a></td>
      </tr>
      <?php endif;?>
      <?php foreach($grouped?($open===$gid?$expanded:[]):[$parent] as $p):?>
      <tr <?=$grouped?'data-variant-row="'.$gid.'"':''?>>
        <td><?php if($grouped):?><span class="hint">↳</span> <b><?=h((string)($p['variant_size']??'Ebat belirsiz'))?></b><div class="hint"><?=h($p['name'])?></div><?php else:?><?=h($p['name'])?><?php endif;?><?= !$p['_has_cost'] ? ' <span class="hint" style="font-size:11px;color:var(--muted)">(maliyet girilmedi)</span>' : '' ?></td>
        <td style="font-size:11px;color:var(--muted)"><?= $p['barcode'] !== '' ? h($p['barcode']) : '—' ?></td>
        <td><?= h(marketplace_label($p['marketplace'])) ?></td>
        <td style="font-size:12px"><?= h($p['category']) ?></td>
        <td><?= tl((float)$p['price']) ?></td>
        <td><?php if($p['marketplace']==='trendyol'&&$p['barcode']!==''): ?><details><summary><?= $p['_has_cost']?tl((float)$p['cost']).' / %'.h((string)$p['cost_vat']):'Maliyet gir' ?></summary><form method="post" style="min-width:180px">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="direct_cost"><input type="hidden" name="pid" value="<?=(int)$p['id']?>"><input type="hidden" name="q" value="<?=h($q)?>"><input type="hidden" name="page" value="<?=$page?>"><input type="hidden" name="open" value="<?=$open?>"><input type="hidden" name="vp" value="<?=$vp?>">
          <label>Ürün alış fiyatı (KDV dahil TL) <input name="cost" required inputmode="decimal" value="<?= $p['_has_cost']?h((string)$p['cost']):'' ?>"></label><label>Alış faturası KDV oranı <select name="vat" required><option value="">Seçin</option><?php foreach([1,10,20] as $v):?><option value="<?=$v?>" <?= $p['_has_cost']&&(float)$p['cost_vat']===(float)$v?'selected':'' ?>>%<?=$v?></option><?php endforeach;?></select></label>
          <label>Geçerlilik <select name="scope" required><option value="">Seçin</option><option value="today">Bugünden itibaren</option><option value="first_sale">İlk satıştan itibaren</option></select></label><button class="btn-primary">Maliyeti kaydet</button></form><p><a class="btn-ghost" href="perde-maliyeti.php?pid=<?=(int)$p['id']?>">Perde maliyeti gir</a></p></details><?php else: ?><?= $p['_has_cost']?tl((float)$p['cost']):'—' ?><?php endif; ?></td>
        <td class="<?= $p['_profit'] < 0 ? 'neg' : 'pos' ?>"><?= $p['_has_cost'] ? tl($p['_profit']) : '—' ?></td>
        <td class="<?= $p['_ratio'] < 0 ? 'neg' : 'pos' ?>"><?= $p['_has_cost'] ? '%' . number_format($p['_ratio'], 1, ',', '.') : '—' ?></td>
        <td class="<?= $p['_margin'] < 0 ? 'neg' : 'pos' ?>"><?= $p['_has_cost'] ? '%' . number_format($p['_margin'], 1, ',', '.') : '—' ?></td>
        <td>
          <form method="post" style="display:inline" onsubmit="return confirm('Bu ürünü sil?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn-danger">Sil</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if($grouped&&$open===$gid&&$variantPages>1):?><tr><td colspan="10"><nav aria-label="Varyant sayfaları" style="display:flex;gap:12px;align-items:center"><?php if($vp>1):?><a class="btn-ghost" href="<?=h($url($page,$gid,$vp-1))?>">Önceki varyantlar</a><?php endif;?><span>Varyant sayfası <?=$vp?> / <?=$variantPages?></span><?php if($vp<$variantPages):?><a class="btn-ghost" href="<?=h($url($page,$gid,$vp+1))?>">Sonraki varyantlar</a><?php endif;?></nav></td></tr><?php endif;?>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination" style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px">
    <?php if ($page > 1): ?>
      <a class="btn-ghost" style="text-decoration:none" href="urunler.php?q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>">← Önceki</a>
    <?php endif; ?>
    <span class="hint">Sayfa <?= $page ?> / <?= $totalPages ?> (<?=$modelCount?> ana ürün / <?=$totalCount?> barkod kaydı)</span>
    <?php if ($page < $totalPages): ?>
      <a class="btn-ghost" style="text-decoration:none" href="urunler.php?q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>">Sonraki →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="note" style="max-width:640px">
    <b>CSV formatı:</b> Başlık satırı <code>name,marketplace,category,cost,price,cargo,service_fee,stopaj,return_rate</code> olmalı.
    Türkçe eşdeğerler de kabul edilir (urun_adi, pazaryeri, kategori, alis, satis, kargo, hizmet_bedeli, stopaj, iade…).
    marketplace: <code>trendyol</code>, <code>hepsiburada</code>, <code>amazon</code> veya <code>n11</code>.
    <a href="csv-sablon.php" style="display:inline-block;margin-top:8px">⬇ Örnek CSV şablonu indir</a>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
