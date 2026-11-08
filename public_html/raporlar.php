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
$orders = array_values(array_filter($orders, 'order_is_sale'));
foreach ($orders as &$o) $o['lines'] = json_decode($o['lines'] ?? '[]', true) ?: [];
unset($o);

// ---- Rapor verileri ----
$reportRows = [];
$reportCols = [];
$toplam = ['ciro' => 0.0, 'kar' => 0.0, 'maliyet' => 0.0];

if ($rapor === 'order') {
    $reportCols = ['Sipariş No', 'Tarih', 'Tutar', 'Tahmini Kâr', 'Kâr Oranı', 'Kâr Marjı'];
    foreach ($orders as $o) {
        $sale = (float)$o['total_price'];
        $pr = calc_order_profit($o, $byBarcode);
        $reportRows[] = [
            $o['order_number'],
            date('d.m.Y', $o['order_date']),
            $sale,
            $pr['ok'] ? $pr['profit'] : null,
            $pr['ok'] ? $pr['ratio'] : null,
            $pr['ok'] ? $pr['margin'] : null,
        ];
        $toplam['ciro'] += $sale;
        if ($pr['ok']) { $toplam['kar'] += $pr['profit']; $toplam['maliyet'] += $pr['cost']; }
    }
} elseif ($rapor === 'product' || $rapor === 'category') {
    $isProduct = $rapor === 'product';
    $reportCols = $isProduct ? ['Barkod','Ürün','Adet','Ciro','Maliyet','Tahmini Kâr','Kâr Marjı']
        : ['Kategori','Adet','Ciro','Maliyet','Tahmini Kâr'];
    foreach (aggregate_profit_rows($orders,$byBarcode,$rapor) as $a) {
        $reportRows[] = $isProduct
            ? [$a['key'],$a['name'],$a['quantity'],$a['sale'],$a['cost'],$a['profit'],$a['margin']]
            : [$a['key'],$a['quantity'],$a['sale'],$a['cost'],$a['profit']];
    }
} elseif ($rapor === 'return') {
    $reportCols = ['Barkod', 'Ürün', 'İade Finans Kaydı', 'İade Tutarı', 'Tahmini Kargo Gideri', 'İade/Satış %'];
    // Satış toplamı barkod bazında (iade-satış oranı için)
    $saleMap = [];
    foreach ($orders as $o) {
        foreach (calc_order_profit($o, $byBarcode)['lines'] as $l) {
            $bc = $l['barcode'];
            $saleMap[$bc] = ($saleMap[$bc] ?? 0) + $l['sale'];
        }
    }
    $agg = [];
    $rows = db()->query("SELECT barcode, COUNT(*) c, SUM(debt) tutar FROM settlements WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND transaction_type IN ('İade','Return') AND transaction_date >= $fromTs GROUP BY barcode")->fetchAll();
    foreach ($rows as $r) {
        $bc = $r['barcode'];
        $p = $byBarcode[$bc] ?? null;
        $adet = (int)$r['c'];
        $iade = (float)$r['tutar'];
        $kargoZarari = $p ? (float)$p['cargo'] * $adet : 0.0;
        $sale = $saleMap[$bc] ?? 0.0;
        $oran = $sale > 0 ? $iade / $sale * 100 : null;
        $agg[] = [$bc, $p['name'] ?? $bc, $adet, $iade, $kargoZarari, $oran];
        $toplam['ciro'] += $iade;
        $toplam['maliyet'] += $kargoZarari;
        $toplam['kar'] += $adet;
    }
    usort($agg, fn($a, $b) => $b[3] <=> $a[3]);
    $reportRows = $agg;
} elseif ($rapor === 'ads') {
    $reportCols = ['Barkod', 'Ürün', 'Reklam Harcaması', 'Bakiye', 'Influencer Kesintisi', 'İlgili Ürünlerin Satışları', 'Reklama Atfedilen Kâr', 'Reklam/Satış %'];
    // Satış toplamı barkod bazında
    $saleMap = [];
    foreach ($orders as $o) {
        foreach (calc_order_profit($o, $byBarcode)['lines'] as $l) {
            $bc = $l['barcode'];
            $saleMap[$bc] = ($saleMap[$bc] ?? 0) + $l['sale'];
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
        $netKar = null; // Reklama atıf verisi olmadan kâr uydurulmaz.
        $oran = $sale > 0 ? $toplamReklam / $sale * 100 : null;
        $agg[] = [$bc, $p['name'] ?? $bc, $harcama, $bakiye, $influencer, $sale > 0 ? $sale : null, $netKar, $oran];
        $toplam['ciro'] += $toplamReklam;
        $toplam['maliyet'] += $sale;
        // Reklam kaydı tek başına ürünün net kârını veya atfedilen satışını göstermez.
    }
    usort($agg, fn($a, $b) => $b[6] <=> $a[6]);
    $reportRows = $agg;
} elseif ($rapor === 'buybox') {
    $reportCols = ['Barkod', 'Ürün', 'Satış Fiyatı', 'Kategori En Düşük', 'Fiyat Farkı'];
    // Yalnızca kendi ürünlerinin kategori içi fiyat karşılaştırmasıdır.
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
    $rows = db()->query("SELECT barcode, transaction_type, SUM(debt)-SUM(credit) tutar FROM settlements WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND transaction_type IN ('Kupon','İndirim','Coupon','Discount','CouponCancel','DiscountCancel') AND transaction_date >= $fromTs GROUP BY barcode, transaction_type")->fetchAll();
    foreach ($rows as $r) {
        $bc = $r['barcode'];
        if (!isset($aggMap[$bc])) $aggMap[$bc] = ['kupon' => 0.0, 'indirim' => 0.0];
        if (in_array($r['transaction_type'], ['Kupon','Coupon','CouponCancel'], true)) $aggMap[$bc]['kupon'] += (float)$r['tutar'];
        else $aggMap[$bc]['indirim'] += (float)$r['tutar'];
    }
    $saleMap = [];
    foreach ($orders as $o) {
        foreach (calc_order_profit($o, $byBarcode)['lines'] as $l) {
            $bc = $l['barcode'];
            $saleMap[$bc] = ($saleMap[$bc] ?? 0) + $l['sale'];
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

// Ana raporların toplamları aynı, maliyeti tam sipariş kümesinden gelir.
$isProfitReport = in_array($rapor,['order','product','category'],true);
$missingOrders = 0;
$knownSale = 0.0;
if ($isProfitReport) {
    $toplam = ['ciro'=>0.0,'kar'=>0.0,'maliyet'=>0.0];
    foreach ($orders as $order) {
        $toplam['ciro'] += (float)$order['total_price'];
        $pr = calc_order_profit($order,$byBarcode);
        if ($pr['ok']) {
            $knownSale += $pr['sale'];
            $toplam['kar'] += $pr['profit'];
            $toplam['maliyet'] += $pr['cost'];
        } else $missingOrders++;
    }
}
$percentCols = ['order'=>[4,5], 'product'=>[6], 'category'=>[], 'return'=>[5], 'ads'=>[7], 'campaign'=>[6], 'buybox'=>[]][$rapor];

// CSV indir
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rapor-' . $rapor . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    write_csv($out, $reportCols);
    foreach ($reportRows as $r) write_csv($out, array_map(fn($v) => is_float($v) ? number_format($v, 2, '.', '') : $v, $r));
    fclose($out);
    exit;
}

$raporLabels = [
    'order' => 'Sipariş Kârlılık Analizi',
    'product' => 'Ürün Kârlılık Analizi',
    'category' => 'Kategori Kârlılık Analizi',
    'return' => 'İade Finans Kayıtları',
    'ads' => 'Reklam Giderleri',
    'campaign' => 'Kampanya Kârlılık Analizi',
    'buybox' => 'Kategori İçi Fiyat Karşılaştırması',
];

$per = 50;
$totalRows = count($reportRows);
$totalPages = max(1, (int)ceil($totalRows / $per));
$page = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$pageRows = array_slice($reportRows, ($page - 1) * $per, $per);

$pageTitle = 'Raporlar — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Raporlar</h1>
  <p class="hint">Siparişlerin ilk bilinen maliyet ve gider varsayımları aktarımda sabitlenir; eksikler sonraki senkronlarda tamamlanır. Bunlar sipariş tarihindeki gerçek alış maliyeti doğrulaması değildir. Eski, sabitlenmemiş kayıtlarda güncel ürün ayarları kullanılır.</p>
  <p class="muted">Kârlılık analizleri · <a href="raporlar.php?r=<?= h($rapor) ?>&mp=<?= urlencode($mp) ?>&days=<?= $days ?>&export=1">⬇ Raporu İndir (CSV)</a></p>
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
  <?php if ($isProfitReport): ?>
  <p class="hint">Tahmini kâr, ürün kartındaki adet başı giderlerle hesaplanır; reklam, ceza ve diğer finans giderleri dahil değildir. İptal/iade paketleri dışarıda tutulur.</p>
  <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px">
    <div>Ciro: <b><?= tl($toplam['ciro']) ?></b></div>
    <div>Kârı hesaplanan ciro: <b><?= tl($knownSale) ?></b></div>
    <div>Ürün maliyeti: <b><?= tl($toplam['maliyet']) ?></b></div>
    <div>Tahmini kâr: <b><?= $knownSale > 0 ? tl($toplam['kar']) : '—' ?></b></div>
  </div>
  <?php if ($missingOrders): ?><p class="hint"><?= $missingOrders ?> siparişin maliyeti veya hesaplama girdileri eksik. Bu siparişler kâr toplamına alınmaz; ilgili satırlarda eksik toplam yerine — gösterilir.</p><?php endif; ?>
  <?php elseif ($rapor === 'return'): ?>
    <p>İade tutarı: <?= tl($toplam['ciro']) ?> · Finans kaydı: <?= (int)$toplam['kar'] ?></p>
    <p class="hint">Finans kayıt sayısı ürün adedi değildir. İade tutarı doğrudan net zarar sayılmaz; geri dönen ürünün durumu ve gerçek giderler ayrıca değerlendirilmelidir.</p>
  <?php elseif ($rapor === 'ads'): ?>
    <p>Reklam ve influencer giderleri: <?= tl($toplam['ciro']) ?></p>
    <p class="hint">Ürün satışları reklamdan geldiği doğrulanmış satışlar değildir. Atıf verisi olmadan reklama ait kâr hesaplanmaz.</p>
  <?php elseif ($rapor === 'campaign'): ?>
    <p>Kupon ve indirim kayıtları: <?= tl($toplam['ciro']) ?></p>
  <?php else: ?>
    <p class="hint">Kendi ürünlerinizin kategori içi fiyat karşılaştırmasıdır. Rakip teklif veya Buybox sahipliği verisi içermez.</p>
  <?php endif; ?>

    <table>
      <tr><?php foreach ($reportCols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr>
      <?php if (!$reportRows): ?><tr><td colspan="<?= count($reportCols) ?>" class="muted">Kayıt yok.</td></tr><?php endif; ?>
      <?php foreach ($pageRows as $r): ?>
        <tr>
          <?php foreach ($r as $i => $v): ?>
            <?php if ($v === null): ?><td class="muted">—</td>
            <?php elseif (is_float($v)): ?><td class="tar"><?= in_array($i,$percentCols,true) ? '%' . number_format($v,2,',','.') : tl($v) ?></td>
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
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
