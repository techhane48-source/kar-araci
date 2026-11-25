<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/xlsx.php';

$u = require_login();
$pro = is_pro($u);

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

// ---- Arama + sayfalama (14k+ ürün için performans) ----
$q = trim($_GET['q'] ?? '');
$where = 'user_id = ?';
$args = [$u['id']];
if ($q !== '') {
    $where .= ' AND (name LIKE ? OR barcode LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}

// KPI toplamları: yalnızca gerekli kolonlar, tüm ürünler üzerinden (hafif döngü)
$totalProfit = 0.0; $totalRevenue = 0.0; $totalCommission = 0.0; $totalCount = 0; $maliyetsiz = 0;
$totStmt = db()->prepare("SELECT marketplace, cost, price, cargo, rate, kdv, cost_vat, extra_fee, service_fee, stopaj, stopaj_mode, stopaj_rate, allow_negative_vat, return_rate FROM products WHERE $where");
$totStmt->execute($args);
while ($tp=$totStmt->fetch()) {
    if ((float)$tp['cost'] <= 0) $maliyetsiz++;
    $rr = product_profit($tp);
    if ((float)$tp['cost'] > 0) $totalProfit += $rr['profit'];
    $totalRevenue += (float)$tp['price'];
    $totalCommission += $rr['commission'];
    $totalCount++;
}
$totals = ['profit' => $totalProfit, 'revenue' => $totalRevenue, 'commission' => $totalCommission, 'count' => $totalCount];

// Sayfalama
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Yalnızca bu sayfanın ürünleri
$stmt = db()->prepare("SELECT * FROM products WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($args);
$products = $stmt->fetchAll();

foreach ($products as &$p) {
    $r = product_profit($p);
    $p['_profit'] = $r['profit'];
    $p['_margin'] = $r['margin'];
    $p['_ratio'] = $r['ratio'];
    $p['_has_cost'] = (float)$p['cost'] > 0;
}
unset($p);

$pageTitle = 'Ürünlerim — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap"><a class="btn-ghost" href="maliyet-gecmisi.php">Tarih bazlı maliyet geçmişi</a></div>
<div class="wrap">
  <div class="toolbar">
    <h1>Ürünlerim</h1>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Ürün adı veya barkod ara..." style="width:240px">
        <button type="submit" class="btn-ghost">Ara</button>
        <?php if ($q !== ''): ?><a href="urunler.php" class="btn-ghost" style="text-decoration:none">Temizle</a><?php endif; ?>
      </form>
      <?php if ($pro): ?>
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
      <?php else: ?>
        <a href="odeme.php" class="btn-ghost">Pro — CSV içe aktarma</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($pro && $totalCount > 0 && $maliyetsiz > 0): ?>
  <div class="alert alert-error" style="margin-bottom:14px">
    <b><?= $maliyetsiz ?></b> ürüne henüz maliyet girilmedi — kâr hesaplanamaz.
    <a href="urunler.php?export=maliyet&bos=1" style="margin-left:auto;white-space:nowrap">⬇ Maliyeti boş olanları indir → Excel'de doldur → "Maliyet Yükle"</a>
  </div>
  <?php endif; ?>

  <div class="kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
    <div class="kpi"><div class="k-label">Toplam Net Kâr</div><div class="k-value <?= $totals['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($totals['profit']) ?></div></div>
    <div class="kpi"><div class="k-label">Ürün Sayısı</div><div class="k-value"><?= $totals['count'] ?><?= !$pro ? ' / ' . FREE_PRODUCT_LIMIT : '' ?></div></div>
    <div class="kpi"><div class="k-label">Toplam Komisyon</div><div class="k-value"><?= tl($totals['commission']) ?></div></div>
  </div>

  <?php if (!$products): ?>
    <div class="card empty"><?= $q !== '' ? 'Aramanızla eşleşen ürün yok.' : 'Henüz ürün yok. <a href="index.php">Hesap makinesinden</a> ürünlerini kaydetmeye başla.' ?></div>
  <?php else: ?>
  <div class="card table-card">
    <table>
      <tr><th>Ürün</th><th>Barkod</th><th>Pazaryeri</th><th>Kategori</th><th>Satış</th><th>Net Kâr</th><th>Kâr Oranı</th><th>Kâr Marjı</th><th></th></tr>
      <?php foreach ($products as $p): ?>
      <tr>
        <td><?= h($p['name']) ?><?= !$p['_has_cost'] ? ' <span class="hint" style="font-size:11px;color:var(--muted)">(maliyet girilmedi)</span>' : '' ?></td>
        <td style="font-size:11px;color:var(--muted)"><?= $p['barcode'] !== '' ? h($p['barcode']) : '—' ?></td>
        <td><?= h(marketplace_label($p['marketplace'])) ?></td>
        <td style="font-size:12px"><?= h($p['category']) ?></td>
        <td><?= tl((float)$p['price']) ?></td>
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
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination" style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px">
    <?php if ($page > 1): ?>
      <a class="btn-ghost" style="text-decoration:none" href="urunler.php?q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>">← Önceki</a>
    <?php endif; ?>
    <span class="hint">Sayfa <?= $page ?> / <?= $totalPages ?> (<?= $totals['count'] ?> ürün)</span>
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
