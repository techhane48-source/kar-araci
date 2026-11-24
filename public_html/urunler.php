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
    $sql = "SELECT * FROM products WHERE user_id = ? AND barcode != ''";
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
        write_csv($out, ['Barkod','Model Kodu','Stok kodu','Kategori İsmi','Ürün Adı','Trendyol  Satış Fiyatı','Stok','Ürün Maliyeti ( KDV Dahil)','Maliyet KDV Oranı','Para Birimi','Ürün Desisi','Teslimat Tipi','Ekstra Maliyet (%)','Ekstra Maliyet (TL)']);
        foreach ($rows as $r) {
            $desi = (float)($r['kesilen_desi'] ?? 0);
            if ($desi <= 0) $desi = desi_from_dims((float)($r['en'] ?? 0), (float)($r['boy'] ?? 0), (float)($r['yukseklik'] ?? 0));
            write_csv($out, [
                $r['barcode'] ?? '', $r['model_kodu'] ?? '', '', $r['category'] ?? '', $r['name'] ?? '',
                (float)($r['price'] ?? 0), (int)($r['quantity'] ?? 0),
                (float)($r['cost'] ?? 0) > 0 ? (float)$r['cost'] : '',
                (float)($r['kdv'] ?? 20),
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
    if (empty($_FILES['csv']['tmp_name'])) {
        flash_set('error', 'CSV dosyası seçin.');
        e_redirect('urunler.php');
    }
    $tmpName = $_FILES['csv']['tmp_name'];
    $origName = $_FILES['csv']['name'] ?? '';
    $isXlsx = (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'xlsx');

    $rows = [];
    $header = [];
    $updated = 0; $notFound = 0; $invalid = 0;
    if ($isXlsx) {
        try {
            $parsed = xlsx_read($tmpName);
            $header = $parsed['headers'];
            $rows = $parsed['rows'];
        } catch (\Throwable $e) {
            flash_set('error', 'XLSX okunamadı: ' . $e->getMessage());
            e_redirect('urunler.php');
        }
    } else {
        $file = fopen($tmpName, 'r');
        $firstLine = (string)fgets($file);
        rewind($file);
        $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $header = fgetcsv($file, null, $delim, '"', '\\');
        $rows = [];
        while (($r = fgetcsv($file, null, $delim, '"', '\\')) !== false) $rows[] = $r;
        fclose($file);
    }
    // Türkçe karakter fold + normalize (büyük/küçük harf dahil)
    $norm = static function (string $x): string {
        $x = strtolower(trim($x)); // ASCII lowercase (Türkçe büyük harfler strtr ile)
        $x = strtr($x, ['Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u', 'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);
        return preg_replace('/[^a-z0-9]/', '', $x);
    };

    if (!$header) {
        flash_set('error', 'Dosya boş veya okunamadı.');
        e_redirect('urunler.php');
    } else {
        $normHeader = array_map($norm, $header);
        $idx = static function (array $names) use ($normHeader): ?int {
            foreach ($names as $n) {
                $k = array_search($n, $normHeader, true);
                if ($k !== false) return $k;
            }
            return null;
        };
        $bIdx  = $idx(['barkod', 'barcode', 'sku']);
        $cIdx  = $idx(['urunmaliyetikdvdahil', 'maliyet', 'cost', 'alis', 'alisfiyati']);
        if ($bIdx === null || $cIdx === null) {
            flash_set('error', 'Başlıklarda "Barkod" ve "Ürün Maliyeti (KDV Dahil)" kolonları bulunmalı.');
            e_redirect('urunler.php');
        } else {
            $kIdx  = $idx(['maliyetkdvorani', 'kdvorani', 'kdv']);
            $dIdx  = $idx(['desi']);
            $mIdx  = $idx(['marka']);
            $mkIdx = $idx(['modelkodu', 'model']);
            $rIdx  = $idx(['renk']);
            $bdIdx = $idx(['beden']);
            $sIdx  = $idx(['stokadet', 'stok', 'adet', 'quantity']);
            $iIdx  = $idx(['iadeorani', 'iade', 'returnrate']);
            $tIdx  = $idx(['teslimattipi', 'kargotipi', 'cargotype']);
            $eIdx  = $idx(['ekstragider', 'ekstra', 'extrafee']);

            $num = static function (?string $s): ?float {
                $s = trim((string)$s);
                if ($s === '') return null;
                $s = str_replace(',', '.', $s);
                return is_numeric($s) ? (float)$s : null;
            };

            foreach ($rows as $row) {
                $barcode = trim((string)($row[$bIdx] ?? ''));
                $costVal = $num($row[$cIdx] ?? null);
                if ($barcode === '' || $costVal === null) continue;
                if (!is_finite($costVal) || $costVal < 0 || $costVal > PROFIT_MAX_AMOUNT) {
                    $invalid++;
                    continue;
                }

                $sets = ['cost = ?'];
                $params = [$costVal];
                $add = function (string $col, $val) use (&$sets, &$params) {
                    $sets[] = "$col = ?";
                    $params[] = $val;
                };
                if (($v = $num($kIdx !== null ? ($row[$kIdx] ?? null) : null)) !== null) $add('kdv', $v);
                if (($v = $num($dIdx !== null ? ($row[$dIdx] ?? null) : null)) !== null) $add('kesilen_desi', $v);
                if (($v = $sIdx !== null ? trim((string)($row[$sIdx] ?? '')) : '') !== '' && is_numeric(str_replace(['.', ','], '', $v))) $add('quantity', (int)str_replace(['.', ','], '', $v));
                if (($v = $num($iIdx !== null ? ($row[$iIdx] ?? null) : null)) !== null) $add('return_rate', $v);
                if (($v = $eIdx !== null ? trim((string)($row[$eIdx] ?? '')) : '') !== '' && ($v = $num($v)) !== null) $add('extra_fee', $v);
                if ($mIdx !== null && ($v = trim((string)($row[$mIdx] ?? ''))) !== '') $add('marka', $v);
                if ($mkIdx !== null && ($v = trim((string)($row[$mkIdx] ?? ''))) !== '') $add('model_kodu', $v);
                if ($rIdx !== null && ($v = trim((string)($row[$rIdx] ?? ''))) !== '') $add('renk', $v);
                if ($bdIdx !== null && ($v = trim((string)($row[$bdIdx] ?? ''))) !== '') $add('beden', $v);
                if ($tIdx !== null && ($v = trim((string)($row[$tIdx] ?? ''))) !== '') $add('cargo_type', $v);

                $params[] = $u['id'];
                $params[] = $barcode;
                $upd = db()->prepare("UPDATE products SET " . implode(', ', $sets) .
                    " WHERE id = (SELECT id FROM products WHERE user_id = ? AND barcode = ? GROUP BY barcode HAVING COUNT(*) = 1)");
                $upd->execute($params);
                if ($upd->rowCount() > 0) $updated++; else $notFound++;
            }
        }
    }
    $message = $updated > 0 ? "$updated ürünün maliyeti güncellendi." : 'Hiçbir ürün güncellenmedi.';
    if ($notFound > 0) $message .= " $notFound satırda barkod bulunamadı veya birden fazla ürünle eşleşti.";
    if ($invalid > 0) $message .= " $invalid satırda maliyet geçersiz.";
    flash_set($updated > 0 ? 'success' : 'error', $message);
    e_redirect('urunler.php');
}

// Tek ürünün KDV dahil birim alış maliyetini güncelle.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_cost') {
    $redirect = 'urunler.php?' . http_build_query([
        'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
        'page' => filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1,
    ]);
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || !csrf_verify($csrf)) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        save_product_cost((int)$u['id'], $_POST['pid'] ?? null, $_POST['cost'] ?? null);
    }
    e_redirect($redirect);
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
                (float) (isset($cols['cost']) ? ($row[$cols['cost']] ?? 0) : 0),
                (float) (isset($cols['price']) ? ($row[$cols['price']] ?? 0) : 0),
                (float) (isset($cols['cargo']) ? ($row[$cols['cargo']] ?? 0) : 0),
                (float) (isset($cols['service_fee']) ? ($row[$cols['service_fee']] ?? 0) : 0),
                (float) (isset($cols['stopaj']) ? ($row[$cols['stopaj']] ?? 0) : 0),
                (float) (isset($cols['return_rate']) ? ($row[$cols['return_rate']] ?? 0) : 0),
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
$totStmt = db()->prepare("SELECT cost, price, cargo, rate, kdv, extra_fee, service_fee, stopaj, stopaj_mode, return_rate FROM products WHERE $where");
$totStmt->execute($args);
foreach ($totStmt->fetchAll() as $tp) {
    if ((float)$tp['cost'] <= 0) $maliyetsiz++;
    $rr = calc_product_profit($tp);
    if ($rr['ok']) $totalProfit += $rr['profit'];
    $totalRevenue += (float)$tp['price'];
    $totalCommission += $rr['commission'] ?? 0;
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
    $r = calc_product_profit($p);
    $p['_profit'] = $r['profit'];
    $p['_margin'] = $r['margin'];
    $p['_ratio'] = $r['ratio'];
    $p['_has_cost'] = $r['ok'];
}
unset($p);

$pageTitle = 'Ürünlerim — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
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
      <tr><th>Ürün</th><th>Barkod</th><th>Pazaryeri</th><th>Kategori</th><th>Satış</th><th>Maliyet (KDV dahil)</th><th>Net Kâr</th><th>Kâr Oranı</th><th>Kâr Marjı</th><th></th></tr>
      <?php foreach ($products as $p): ?>
      <tr>
        <td><?= h($p['name']) ?><?= !$p['_has_cost'] ? ' <span class="hint" style="font-size:11px;color:var(--muted)">(maliyet veya hesaplama girdisi eksik)</span>' : '' ?></td>
        <td style="font-size:11px;color:var(--muted)"><?= $p['barcode'] !== '' ? h($p['barcode']) : '—' ?></td>
        <td><?= h(marketplace_label($p['marketplace'])) ?></td>
        <td style="font-size:12px"><?= h($p['category']) ?></td>
        <td><?= tl((float)$p['price']) ?></td>
        <td>
          <form method="post" class="product-cost-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_cost">
            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
            <input type="text" inputmode="decimal" name="cost" required placeholder="0,00"
                   aria-label="<?= h($p['name'] . ' — KDV dahil birim alış maliyeti') ?>"
                   value="<?= (float)$p['cost'] > 0 ? number_format((float)$p['cost'], 2, ',', '') : '' ?>">
            <button type="submit" class="btn-ghost">Kaydet</button>
          </form>
        </td>
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
