<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
$u = require_login();

$mps = array_column(db()->query("SELECT marketplace FROM integrations WHERE user_id = {$u['id']}")->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

$rapor = $_GET['r'] ?? 'order';
$allowed = ['order', 'product', 'category', 'return', 'ads', 'campaign', 'buybox'];
if (!in_array($rapor, $allowed, true)) $rapor = 'order';

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 15, 30, 90], true)) $days = 30;
$fromTs = strtotime('today') - ($days - 1) * 86400;

// Ürünler (barkod -> ürün)
$byBarcode = [];
foreach (db()->query("SELECT * FROM products WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp))->fetchAll() as $p) {
    if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;
}

// Siparişler
$orders = db()->query("SELECT * FROM orders WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND order_date >= $fromTs ORDER BY order_date DESC")->fetchAll();
foreach ($orders as &$o) $o['lines'] = json_decode($o['lines'] ?? '[]', true) ?: [];
unset($o);

// ---- Rapor verileri ----
$reportRows = [];
$reportCols = [];
$orderFilter = '';
$toplam = ['ciro' => 0.0, 'kar' => 0.0, 'maliyet' => 0.0];

if ($rapor === 'order') {
    // Sipariş kârlılığı: kâr motorunun kayıtlarından (tahmini → kesin), sayfalı.
    ensure_order_profits((int)$u['id'], $mp, $fromTs);
    $orderFilter = in_array($_GET['f'] ?? '', ['zarar', 'maliyetsiz', 'tahmini', 'kesin'], true) ? $_GET['f'] : '';
    $tt = order_profit_totals((int)$u['id'], $mp, $fromTs, time() + 86400);
    $toplam = ['ciro' => $tt['sale'], 'kar' => $tt['profit'], 'maliyet' => $tt['cost']];
    $reportCols = ['Sipariş No', 'Tarih', 'Durum', 'Satış', 'Ürün Maliyeti', 'Komisyon', 'Komisyon Kaynağı', 'Kargo', 'Kargo Kaynağı',
                   'Hizmet Bedeli', 'Stopaj', 'Net KDV', 'Sabit Gider', 'İade Kaybı', 'Net Kâr', 'Kâr Marjı (%)'];
    $rowOf = fn(array $o) => [
        $o['order_number'], date('d.m.Y H:i', (int)$o['order_date']), $o['status'], (float)$o['sale'], (float)$o['cost'],
        (float)$o['commission'], $o['commission_src'], (float)$o['cargo'], $o['cargo_src'], (float)$o['service_fee'],
        (float)$o['stopaj'], (float)$o['net_vat'], (float)$o['fixed_cost'], (float)$o['return_loss'],
        $o['profit'] !== null ? (float)$o['profit'] : null, $o['margin'] !== null ? (float)$o['margin'] : null,
    ];
    if (isset($_GET['export'])) {
        $all = order_profit_page((int)$u['id'], $mp, $fromTs, time() + 86400, 1, 100000, $orderFilter);
        foreach ($all['rows'] as $o) $reportRows[] = $rowOf($o);
    } else {
        $opPage = max(1, (int)($_GET['page'] ?? 1));
        $op = order_profit_page((int)$u['id'], $mp, $fromTs, time() + 86400, $opPage, 50, $orderFilter);
    }
} elseif ($rapor === 'product') {
    $reportCols = ['Barkod', 'Ürün', 'Adet', 'Ciro', 'Maliyet', 'Kâr', 'Kâr Marjı'];
    $agg = [];
    foreach ($orders as $o) {
        $sale = (float)$o['total_price'];
        $pr = calc_order_profit($o, $byBarcode);
        foreach ($o['lines'] as $l) {
            $bc = $l['barcode'] ?? '';
            $nm = $l['productName'] ?? ($byBarcode[$bc]['name'] ?? $bc);
            $qty = max(1, (int)($l['quantity'] ?? 1));
            if (!isset($agg[$bc])) $agg[$bc] = ['name' => $nm, 'adet' => 0, 'ciro' => 0.0, 'maliyet' => 0.0, 'kar' => 0.0];
            $agg[$bc]['adet'] += $qty;
            $agg[$bc]['ciro'] += (float)($l['price'] ?? 0) * $qty;
            if (isset($byBarcode[$bc]) && (float)$byBarcode[$bc]['cost'] > 0) {
                $agg[$bc]['maliyet'] += (float)$byBarcode[$bc]['cost'] * $qty;
            }
        }
        if ($pr['ok']) { /* sipariş bazlı kâr ürünlere dağıtılmadı; ürün kârı aşağıda oranla */ }
    }
    // Ürün kârını basitçe hesapla: ciro - maliyet - tahmini kesintiler yerine maliyet girdisi olanlarda oran bazlı
    foreach ($agg as $bc => $a) {
        $marj = $a['ciro'] > 0 && $a['maliyet'] > 0 ? (($a['ciro'] - $a['maliyet']) / $a['ciro']) * 100 : null;
        $kar = ($a['maliyet'] > 0) ? ($a['ciro'] - $a['maliyet']) : null;
        $reportRows[] = [$bc, $a['name'], $a['adet'], $a['ciro'], $a['maliyet'] > 0 ? $a['maliyet'] : null, $kar, $marj];
        $toplam['ciro'] += $a['ciro']; $toplam['maliyet'] += $a['maliyet'];
        if ($kar !== null) $toplam['kar'] += $kar;
    }
} elseif ($rapor === 'category') {
    $reportCols = ['Kategori', 'Adet', 'Ciro', 'Maliyet', 'Kâr'];
    $agg = [];
    foreach ($orders as $o) {
        foreach ($o['lines'] as $l) {
            $bc = $l['barcode'] ?? '';
            $p = $byBarcode[$bc] ?? null;
            $cat = $p['category'] ?? 'Bilinmeyen';
            $qty = max(1, (int)($l['quantity'] ?? 1));
            if (!isset($agg[$cat])) $agg[$cat] = ['adet' => 0, 'ciro' => 0.0, 'maliyet' => 0.0];
            $agg[$cat]['adet'] += $qty;
            $agg[$cat]['ciro'] += (float)($l['price'] ?? 0) * $qty;
            if ($p && (float)$p['cost'] > 0) $agg[$cat]['maliyet'] += (float)$p['cost'] * $qty;
        }
    }
    foreach ($agg as $cat => $a) {
        $kar = $a['maliyet'] > 0 ? ($a['ciro'] - $a['maliyet']) : null;
        $reportRows[] = [$cat, $a['adet'], $a['ciro'], $a['maliyet'] > 0 ? $a['maliyet'] : null, $kar];
        $toplam['ciro'] += $a['ciro']; $toplam['maliyet'] += $a['maliyet'];
        if ($kar !== null) $toplam['kar'] += $kar;
    }
} elseif ($rapor === 'return') {
    $reportCols = ['Barkod', 'Ürün', 'İade Adedi', 'İade Tutarı', 'Kargo Zararı', 'Mikro İade', 'Toplam İade Zararı', 'İade/Satış %'];
    // Satış toplamı barkod bazında (iade-satış oranı için)
    $saleMap = [];
    foreach ($orders as $o) {
        foreach ($o['lines'] as $l) {
            $bc = $l['barcode'] ?? '';
            $saleMap[$bc] = ($saleMap[$bc] ?? 0) + (float)($l['price'] ?? 0) * max(1, (int)($l['quantity'] ?? 1));
        }
    }
    $agg = [];
    $rows = db()->query("SELECT barcode, COUNT(*) c, SUM(debt) tutar FROM settlements WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND transaction_type = 'İade' AND transaction_date >= $fromTs GROUP BY barcode")->fetchAll();
    foreach ($rows as $r) {
        $bc = $r['barcode'];
        $p = $byBarcode[$bc] ?? null;
        $adet = (int)$r['c'];
        $iade = (float)$r['tutar'];
        $maliyet = $p ? (float)$p['cost'] * $adet : 0.0;
        // Kargo zararı: iade başına kargo ücreti (ürün cargo * adet)
        $kargoZarari = $p ? (float)$p['cargo'] * $adet : 0.0;
        // Mikro iade: iade tutarının %2'si (tahmini mikro iade kaybı)
        $mikroIade = $iade * 0.02;
        $toplamZarar = $iade + $maliyet + $kargoZarari + $mikroIade;
        $sale = $saleMap[$bc] ?? 0.0;
        $oran = $sale > 0 ? $iade / $sale * 100 : null;
        $agg[] = [$bc, $p['name'] ?? $bc, $adet, $iade, $kargoZarari, $mikroIade, $toplamZarar, $oran];
        $toplam['ciro'] += $iade;
        $toplam['maliyet'] += $maliyet + $kargoZarari;
        $toplam['kar'] += $toplamZarar;
    }
    usort($agg, fn($a, $b) => $b[3] <=> $a[3]);
    $reportRows = $agg;
} elseif ($rapor === 'ads') {
    $reportCols = ['Barkod', 'Ürün', 'Reklam Harcaması', 'Bakiye', 'Influencer Kesintisi', 'Reklam Satışı', 'Net Kâr', 'Reklam/Satış %'];
    // Satış toplamı barkod bazında
    $saleMap = [];
    foreach ($orders as $o) {
        foreach ($o['lines'] as $l) {
            $bc = $l['barcode'] ?? '';
            $saleMap[$bc] = ($saleMap[$bc] ?? 0) + (float)($l['price'] ?? 0) * max(1, (int)($l['quantity'] ?? 1));
        }
    }
    // Reklam harcaması: settlements'ta reklam ile ilgili transaction_type'lar
    $adRows = db()->query("SELECT barcode, transaction_type, SUM(debt) tutar FROM settlements WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND (transaction_type = 'Reklam' OR transaction_type = 'Ads' OR transaction_type LIKE '%reklam%' OR transaction_type LIKE '%influencer%') AND transaction_date >= $fromTs GROUP BY barcode, transaction_type")->fetchAll();
    $adMap = [];
    foreach ($adRows as $r) {
        $bc = $r['barcode'];
        if (!isset($adMap[$bc])) $adMap[$bc] = ['harcama' => 0.0, 'influencer' => 0.0, 'bakiye' => 0.0];
        $tt = strtolower($r['transaction_type']);
        if (str_contains($tt, 'influencer')) $adMap[$bc]['influencer'] += (float)$r['tutar'];
        elseif (str_contains($tt, 'bakiye')) $adMap[$bc]['bakiye'] += (float)$r['tutar'];
        else $adMap[$bc]['harcama'] += (float)$r['tutar'];
    }
    $agg = [];
    foreach ($adMap as $bc => $d) {
        $p = $byBarcode[$bc] ?? null;
        $sale = $saleMap[$bc] ?? 0.0;
        $harcama = $d['harcama'];
        $influencer = $d['influencer'];
        $bakiye = $d['bakiye'];
        $toplamReklam = $harcama + $influencer;
        $netKar = $sale - $toplamReklam;
        $oran = $sale > 0 ? $toplamReklam / $sale * 100 : null;
        $agg[] = [$bc, $p['name'] ?? $bc, $harcama, $bakiye, $influencer, $sale > 0 ? $sale : null, $netKar, $oran];
        $toplam['ciro'] += $toplamReklam;
        $toplam['maliyet'] += $sale;
        $toplam['kar'] += $netKar;
    }
    usort($agg, fn($a, $b) => $b[6] <=> $a[6]);
    $reportRows = $agg;
} elseif ($rapor === 'buybox') {
    $reportCols = ['Barkod', 'Ürün', 'Satış Fiyatı', 'Kategori En Düşük', 'Fiyat Farkı'];
    // Buybox durumu: barkodunda "buybox" işareti olan ürünler buybox sahibi sayılır;
    // fiyat farkı = ürün fiyatı ile aynı kategorideki en düşük fiyat arasındaki fark.
    // Kategori bazında en düşük fiyat hesapla.
    $minByCat = [];
    foreach ($byBarcode as $bc => $p) {
        $cat = $p['category'] ?? 'Diğer';
        $pr = (float)($p['price'] ?? 0);
        if ($pr <= 0) continue;
        if (!isset($minByCat[$cat]) || $pr < $minByCat[$cat]) $minByCat[$cat] = $pr;
    }
    $agg = [];
    foreach ($byBarcode as $bc => $p) {
        $price = (float)($p['price'] ?? 0);
        $cat = $p['category'] ?? 'Diğer';
        $minPrice = $minByCat[$cat] ?? $price;
        $diff = $price - $minPrice;
        $agg[] = [$bc, $p['name'] ?? $bc, $price, $minPrice, $diff];
        $toplam['ciro'] += $price;
        $toplam['maliyet'] += $minPrice;
        $toplam['kar'] += $diff;
    }
    // büyükten küçüğe fiyat farkına göre sırala
    usort($agg, fn($a, $b) => $b[4] <=> $a[4]);
    $reportRows = $agg;
} elseif ($rapor === 'campaign') {
    $reportCols = ['Barkod', 'Ürün', 'Kupon', 'İndirim', 'Toplam Kampanya', 'Satış', 'Kampanya/Satış %'];
    $aggMap = [];
    $rows = db()->query("SELECT barcode, transaction_type, SUM(debt) tutar FROM settlements WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND transaction_type IN ('Kupon','İndirim') AND transaction_date >= $fromTs GROUP BY barcode, transaction_type")->fetchAll();
    foreach ($rows as $r) {
        $bc = $r['barcode'];
        if (!isset($aggMap[$bc])) $aggMap[$bc] = ['kupon' => 0.0, 'indirim' => 0.0];
        if ($r['transaction_type'] === 'Kupon') $aggMap[$bc]['kupon'] += (float)$r['tutar'];
        else $aggMap[$bc]['indirim'] += (float)$r['tutar'];
    }
    $saleMap = [];
    foreach ($orders as $o) {
        foreach ($o['lines'] as $l) {
            $bc = $l['barcode'] ?? '';
            $saleMap[$bc] = ($saleMap[$bc] ?? 0) + (float)($l['price'] ?? 0) * max(1, (int)($l['quantity'] ?? 1));
        }
    }
    $agg = [];
    foreach ($aggMap as $bc => $d) {
        $p = $byBarcode[$bc] ?? null;
        $toplamK = $d['kupon'] + $d['indirim'];
        $sale = $saleMap[$bc] ?? 0.0;
        $oran = $sale > 0 ? $toplamK / $sale * 100 : null;
        $agg[] = [$bc, $p['name'] ?? $bc, $d['kupon'], $d['indirim'], $toplamK, $sale > 0 ? $sale : null, $oran];
        $toplam['ciro'] += $toplamK;
        $toplam['maliyet'] += $sale;
    }
    // büyükten küçüğe sırala
    usort($agg, fn($a, $b) => $b[4] <=> $a[4]);
    $reportRows = $agg;
}

// CSV indir
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rapor-' . $rapor . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $reportCols);
    foreach ($reportRows as $r) fputcsv($out, array_map(fn($v) => is_float($v) ? number_format($v, 2, '.', '') : $v, $r));
    fclose($out);
    exit;
}

$raporLabels = [
    'order' => 'Sipariş Kârlılık Analizi',
    'product' => 'Ürün Kârlılık Analizi',
    'category' => 'Kategori Kârlılık Analizi',
    'return' => 'İade Zarar Analizi',
    'ads' => 'Reklam Kârlılık Analizi',
    'campaign' => 'Kampanya Kârlılık Analizi',
    'buybox' => 'Fiyat Pozisyonu (Buybox)',
];

$per = 50;
$totalRows = count($reportRows);
$totalPages = max(1, (int)ceil($totalRows / $per));
$page = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$pageRows = array_slice($reportRows, ($page - 1) * $per, $per);

$pageTitle = 'Raporlar — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
<div class="page-head">
  <h1>Raporlar</h1>
  <p class="muted">Kârlılık analizleri · <a href="raporlar.php?r=<?= h($rapor) ?>&mp=<?= urlencode($mp) ?>&days=<?= $days ?><?= $rapor === 'order' && $orderFilter ? '&f=' . $orderFilter : '' ?>&export=1">⬇ Raporu İndir (CSV)</a></p>
</div>

<div class="card">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
    <input type="hidden" name="mp" value="<?= h($mp) ?>">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Rapor Seçin
      <select name="r" onchange="this.form.submit()" style="padding:8px">
        <?php foreach ($raporLabels as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $rapor === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Tarih
      <select name="days" onchange="this.form.submit()" style="padding:8px">
        <?php foreach ([7 => 'Son 7 gün', 15 => 'Son 15 gün', 30 => 'Son 30 gün', 90 => 'Son 90 gün'] as $d => $lbl): ?>
          <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
</div>

<div class="card">
  <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px">
    <div><div class="muted" style="font-size:12px">Ciro</div><div style="font-weight:700"><?= tl($toplam['ciro']) ?></div></div>
    <?php if ($rapor !== 'return'): ?>
      <div><div class="muted" style="font-size:12px">Ürün Maliyeti</div><div style="font-weight:700"><?= tl($toplam['maliyet']) ?></div></div>
      <div><div class="muted" style="font-size:12px">Kâr</div><div style="font-weight:700;color:<?= $toplam['kar'] < 0 ? '#e11d48' : '#16a34a' ?>"><?= tl($toplam['kar']) ?></div></div>
    <?php else: ?>
      <div><div class="muted" style="font-size:12px">İade Adedi</div><div style="font-weight:700"><?= $toplam['kar'] ?></div></div>
    <?php endif; ?>
  </div>

    <?php if ($rapor === 'order'): ?>
    <div class="final-bar">
      <div class="final-bar-track"><div class="final-bar-fill" style="width:<?= $tt['costed'] > 0 ? number_format($tt['final_orders'] / $tt['costed'] * 100, 1, '.', '') : 0 ?>%"></div></div>
      <span><b><?= (int)$tt['final_orders'] ?></b> / <?= (int)$tt['costed'] ?> sipariş kesinleşti · kesin kâr <b><?= tl($tt['final_profit']) ?></b></span>
    </div>
    <div class="btn-group" style="margin:10px 0">
      <?php foreach (['' => 'Tümü', 'zarar' => 'Zarar edenler', 'maliyetsiz' => 'Maliyeti eksik', 'tahmini' => 'Tahmini', 'kesin' => 'Kesinleşmiş'] as $fk => $fl): ?>
        <a class="btn-ghost <?= $orderFilter === $fk ? 'btn-active' : '' ?>" style="text-decoration:none" href="raporlar.php?r=order&mp=<?= urlencode($mp) ?>&days=<?= $days ?>&f=<?= $fk ?>"><?= $fl ?></a>
      <?php endforeach; ?>
    </div>
    <table>
      <tr><th>Sipariş</th><th>Tarih</th><th class="tar">Satış</th><th class="tar">Komisyon</th><th class="tar">Kargo</th><th class="tar">Net Kâr</th><th class="tar">Marj</th><th></th></tr>
      <?php if (!$op['rows']): ?><tr><td colspan="8" class="muted">Bu aralıkta sipariş yok.</td></tr><?php endif; ?>
      <?php foreach ($op['rows'] as $o): $cancel = (int)$o['is_cancelled'] === 1; ?>
      <tr<?= $cancel ? ' style="opacity:.5"' : '' ?>>
        <td style="font-family:monospace;font-size:12px"><?= h($o['order_number']) ?><?= $cancel ? ' <span class="src-badge badge-missing">iptal</span>' : '' ?></td>
        <td><?= date('d.m.Y H:i', (int)$o['order_date']) ?></td>
        <td class="tar"><?= tl((float)$o['sale']) ?></td>
        <td class="tar"><?= tl((float)$o['commission']) ?> <?= src_badge($o['commission_src']) ?></td>
        <td class="tar"><?= tl((float)$o['cargo']) ?> <?= src_badge($o['cargo_src'], $o['cargo_note']) ?></td>
        <td class="tar" style="font-weight:700;color:<?= $o['profit'] === null ? 'inherit' : ((float)$o['profit'] < 0 ? '#e11d48' : '#16a34a') ?>"><?= $o['profit'] === null ? '<a href="urunler.php" title="Bu siparişteki bir ürünün maliyeti girilmemiş">maliyet gir</a>' : tl((float)$o['profit']) ?></td>
        <td class="tar"><?= $o['margin'] === null ? '—' : '%' . number_format((float)$o['margin'], 1, ',', '.') ?></td>
        <td>
          <?php if ($o['profit'] !== null && !$cancel): ?>
          <details class="row-detail"><summary class="btn-ghost" style="padding:2px 8px">Hesap</summary>
            <table class="breakdown" style="min-width:300px;margin-top:6px">
              <tr><td>Satış (KDV dahil)</td><td><?= tl((float)$o['sale']) ?></td></tr>
              <tr><td>− Ürün maliyeti</td><td><?= tl((float)$o['cost']) ?></td></tr>
              <tr><td>− Komisyon <?= src_badge($o['commission_src']) ?></td><td><?= tl((float)$o['commission']) ?></td></tr>
              <tr><td>− Kargo <?= src_badge($o['cargo_src']) ?><br><span class="hint"><?= h($o['cargo_note']) ?></span></td><td><?= tl((float)$o['cargo']) ?></td></tr>
              <tr><td>− Hizmet bedeli</td><td><?= tl((float)$o['service_fee']) ?></td></tr>
              <tr><td>− Stopaj</td><td><?= tl((float)$o['stopaj']) ?></td></tr>
              <?php if ((float)$o['fixed_cost'] > 0): ?><tr><td>− Sabit gider</td><td><?= tl((float)$o['fixed_cost']) ?></td></tr><?php endif; ?>
              <?php if ((float)$o['return_loss'] > 0): ?><tr><td>− İade kaybı</td><td><?= tl((float)$o['return_loss']) ?></td></tr><?php endif; ?>
              <tr><td>− Net KDV<br><span class="hint">satış <?= tl((float)$o['sale_vat']) ?> − alış <?= tl((float)$o['cost_vat']) ?> − kesinti <?= tl((float)$o['fee_vat']) ?></span></td><td><?= tl((float)$o['net_vat']) ?></td></tr>
              <tr class="total"><td>= Net kâr</td><td><?= tl((float)$o['profit']) ?></td></tr>
            </table>
          </details>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($op['pages'] > 1): ?>
      <div class="pager">
        <?php if ($opPage > 1): ?><a class="btn-ghost" href="raporlar.php?r=order&mp=<?= urlencode($mp) ?>&days=<?= $days ?>&f=<?= $orderFilter ?>&page=<?= $opPage - 1 ?>">← Önceki</a><?php endif; ?>
        <span class="muted" style="font-size:13px"><?= $op['total'] ?> sipariş · sayfa <?= $opPage ?>/<?= $op['pages'] ?></span>
        <?php if ($opPage < $op['pages']): ?><a class="btn-ghost" href="raporlar.php?r=order&mp=<?= urlencode($mp) ?>&days=<?= $days ?>&f=<?= $orderFilter ?>&page=<?= $opPage + 1 ?>">Sonraki →</a><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php else: ?>
    <table>
      <tr><?php foreach ($reportCols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr>
      <?php if (!$reportRows): ?><tr><td colspan="<?= count($reportCols) ?>" class="muted">Kayıt yok.</td></tr><?php endif; ?>
      <?php foreach ($pageRows as $r): ?>
        <tr>
          <?php foreach ($r as $i => $v): ?>
            <?php if ($v === null): ?><td class="muted">—</td>
            <?php elseif (is_float($v)): ?><td class="tar"><?= tl($v) ?></td>
            <?php elseif ($i === 0): ?><td style="font-family:monospace;font-size:12px"><?= h((string)$v) ?></td>
            <?php else: ?><td><?= h((string)$v) ?></td><?php endif; ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($totalPages > 1): ?>
      <div class="pager" style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <span class="muted" style="font-size:13px"><?= $totalRows ?> kayıt · sayfa <?= $page ?>/<?= $totalPages ?></span>
        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++):
        ?>
          <a href="raporlar.php?r=<?= h($rapor) ?>&mp=<?= urlencode($mp) ?>&days=<?= $days ?>&page=<?= $i ?>" class="<?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>" style="padding:4px 10px"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
