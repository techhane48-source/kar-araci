<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();

// Bağlı pazaryerleri
$ints = db()->prepare("SELECT marketplace FROM integrations WHERE user_id = ?");
$ints->execute([$u['id']]);
$mps = array_column($ints->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

// Tarih aralığı (gün)
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [1, 7, 15, 30], true)) $days = 30;
$fromTs = strtotime('today') - ($days - 1) * 86400;
$aralikLabel = $days === 1 ? 'Bugün' : ('Son ' . $days . ' gün');

// Ürünler (barkod -> ürün) — maliyet girdisi kârlılığın temeli
$ps = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
$ps->execute([$u['id'], $mp]);
$byBarcode = [];
$toplamUrun = 0;
$maliyetGirilen = 0;
foreach ($ps->fetchAll() as $p) {
    $toplamUrun++;
    if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;
    if ((float)$p['cost'] > 0) $maliyetGirilen++;
}

// Siparişler
$q = db()->prepare("SELECT * FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ? ORDER BY order_date DESC");
$q->execute([$u['id'], $mp, $fromTs]);
$orders = $q->fetchAll();

// Aggregasyon
$ciro = 0.0; $maliyetliCiro = 0.0;
$urunMaliyeti = 0.0; $komisyon = 0.0; $kargo = 0.0;
$hizmet = 0.0; $stopaj = 0.0; $netKdv = 0.0;
$reklam = 0.0; $ceza = 0.0; $erkenOdeme = 0.0; $diger = 0.0;
$siparis = 0; $maliyetliSiparis = 0; $urunAdet = 0;

foreach ($orders as $o) {
    if (!order_is_active_sale($o)) continue;
    $o['lines'] = json_decode($o['lines'] ?? '[]', true) ?: [];
    $sale = (float)$o['total_price'];
    $ciro += $sale;
    $siparis++;
    foreach ($o['lines'] as $l) $urunAdet += max(1, (int)($l['quantity'] ?? 1));

    $pr = calc_order_profit($o, $byBarcode);
    if ($pr['ok']) {
        $maliyetliCiro += $sale;
        $maliyetliSiparis++;
        $urunMaliyeti += $pr['cost'];
        $komisyon += $pr['commission'];
        $kargo += $pr['cargo'];
        $hizmet += $pr['service_fee'];
        $stopaj += $pr['stopaj'];
        $netKdv += $pr['net_kdv'];
    }
}

// Bugün saatlik (dashboard mini grafik)
$todayHourly = array_fill(0, 24, 0.0);
foreach ($orders as $o) {
    if (!order_is_active_sale($o)) continue;
    if ((int)$o['order_date'] >= strtotime('today')) {
        $todayHourly[(int)date('G', (int)$o['order_date'])] += (float)$o['total_price'];
    }
}

// Brüt & Net kâr (Melontik ayrımı)
$brutKar = $maliyetliCiro - $urunMaliyeti - $komisyon - $kargo - $hizmet - $stopaj - $netKdv;
$netKar = $brutKar - $reklam - $ceza - $erkenOdeme - $diger;
$karSatis = $maliyetliCiro > 0 ? $netKar / $maliyetliCiro * 100 : 0.0;
$karMaliyet = $urunMaliyeti > 0 ? $netKar / $urunMaliyeti * 100 : 0.0;
$ortKomisyon = $maliyetliCiro > 0 ? $komisyon / $maliyetliCiro * 100 : 0.0;

// Funnel (Kâr Performansı) — maliyetli ciro üzerinden (dengeli)
$funnel = [
    'ciro'          => $maliyetliCiro,
    'kargoSonrasi'  => $maliyetliCiro - $kargo,
    'pzSonrasi'     => $maliyetliCiro - $kargo - $komisyon - $hizmet,
    'vergiSonrasi'  => $maliyetliCiro - $kargo - $komisyon - $hizmet - $stopaj - $netKdv,
    'kar'           => $brutKar,
];

$pageTitle = 'Dashboard — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Dashboard</h1>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select onchange="location.href='?mp='+encodeURIComponent(this.value)+'&days=<?= $days ?>'" style="width:auto">
        <?php foreach ($mps as $c): ?>
          <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= h(marketplace_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="btn-group">
        <?php foreach ([1 => 'Bugün', 7 => '7 gün', 15 => '15 gün', 30 => '30 gün'] as $d => $lbl): ?>
          <a href="?mp=<?= $mp ?>&days=<?= $d ?>" class="btn-ghost <?= $days === $d ? 'btn-active' : '' ?>" style="text-decoration:none"><?= $lbl ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if (!$orders): ?>
    <div class="card" style="padding:32px;text-align:center">
      <h2>Henüz veri yok</h2>
      <p class="hint" style="max-width:520px;margin:8px auto">
        <?= $toplamUrun === 0
            ? 'Mağazanı bağla, siparişler ve kârlılığın otomatik gelsin. <a href="magaza.php">Mağaza Bağla →</a>'
            : 'Siparişler senkron edilmedi. <a href="canli-performans.php">Canlı Performans</a> sayfasını aç, siparişler otomatik çekilsin.' ?>
      </p>
    </div>
  <?php else: ?>

  <div class="kpi-row">
    <div class="kpi"><div class="k-label">Toplam Ciro</div><div class="k-value"><?= tl($ciro) ?></div></div>
    <div class="kpi"><div class="k-label">Maliyeti Olan Ciro</div><div class="k-value"><?= tl($maliyetliCiro) ?></div></div>
    <div class="kpi"><div class="k-label">Tahmini Brüt Kâr</div><div class="k-value <?= $brutKar < 0 ? 'neg' : '' ?>"><?= tl($brutKar) ?></div></div>
    <div class="kpi"><div class="k-label">Tahmini Net Kâr</div><div class="k-value <?= $netKar < 0 ? 'neg' : 'pos' ?>"><?= tl($netKar) ?></div></div>
    <div class="kpi"><div class="k-label">Tahmini Net Kâr / Satış</div><div class="k-value <?= $karSatis < 0 ? 'neg' : '' ?>">%<?= number_format($karSatis, 1, ',', '.') ?></div></div>
    <div class="kpi"><div class="k-label">Tahmini Net Kâr / Maliyet</div><div class="k-value <?= $karMaliyet < 0 ? 'neg' : '' ?>">%<?= number_format($karMaliyet, 1, ',', '.') ?></div></div>
  </div>

  <?php $excludedCurrency=count(array_filter($orders,static fn($o)=>!order_is_domestic_try($o))); if($excludedCurrency): ?><p class="hint"><?= $excludedCurrency ?> paket ülke/para birimi doğrulanmadığından TL cirosuna dahil edilmedi. Eski kayıtları tekrar senkronize edin.</p><?php endif; ?>
  <?php if ($maliyetliCiro < $ciro): ?>
    <p class="hint" style="margin:8px 0">
      <?= tl($ciro - $maliyetliCiro) ?> ciro <?= $siparis - $maliyetliSiparis ?> siparişte kâr hesaplanamadı — bu ürünlere <a href="urunler.php">maliyet gir</a>.
    </p>
  <?php endif; ?>

  <div class="card table-card" style="margin:16px 0">
    <h2>Kâr Performansı (<?= h($aralikLabel) ?>)</h2>
    <div style="max-width:720px;margin-top:8px">
      <?php
      $maxW = max(1.0, $funnel['ciro']);
      $funnelRows = [
        ['Ciro', $funnel['ciro']],
        ['Kargo ücreti düşülmüş tutar', $funnel['kargoSonrasi']],
        ['Pazaryeri masrafı düşülmüş tutar', $funnel['pzSonrasi']],
        ['Vergi düşülmüş tutar', $funnel['vergiSonrasi']],
        ['Ürün Maliyeti Düşülmüş Tutar (Kâr)', $funnel['kar']],
      ];
      foreach ($funnelRows as $row):
        $pct = max(0.0, $row[1] / $maxW * 100);
      ?>
        <div style="display:flex;align-items:center;gap:12px;margin:6px 0">
          <div style="width:200px;font-size:13px;color:var(--muted)"><?= $row[0] ?></div>
          <div style="flex:1;background:var(--border);border-radius:6px;height:22px;position:relative">
            <div style="position:absolute;left:0;top:0;bottom:0;border-radius:6px;background:var(--accent);width:<?= $pct ?>%"></div>
          </div>
          <div style="width:110px;text-align:right;font-weight:600;font-size:14px"><?= tl($row[1]) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Bugün Saatlik Satış</h2>
    <?php $maxH = max(1.0, max($todayHourly)); ?>
    <div style="display:flex;gap:4px;align-items:flex-end;height:90px;margin:8px 0 4px;padding:0 2px">
      <?php for ($h = 0; $h < 24; $h++): $sh = $todayHourly[$h] / $maxH * 100; ?>
        <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center" title="<?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?>:00 — <?= tl($todayHourly[$h]) ?>">
          <div style="width:100%;background:var(--accent);border-radius:2px 2px 0 0;height:<?= max(1, $sh) ?>%;opacity:<?= $todayHourly[$h] > 0 ? 1 : 0.15 ?>"></div>
        </div>
      <?php endfor; ?>
    </div>
    <p class="hint">Saat etiketleri 00-23. Bugün henüz sipariş yoksa çubuklar boş.</p>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Masraf Kalemleri</h2>
    <table>
      <tr><td>Ürün Maliyeti</td><td class="tar"><?= tl($urunMaliyeti) ?></td></tr>
      <tr><td>Komisyon</td><td class="tar"><?= tl($komisyon) ?></td></tr>
      <tr><td>Kargo Ücreti</td><td class="tar"><?= tl($kargo) ?></td></tr>
      <tr><td>Hizmet Bedeli</td><td class="tar"><?= tl($hizmet) ?></td></tr>
      <tr><td>Stopaj Kesintisi</td><td class="tar"><?= tl($stopaj) ?></td></tr>
      <tr><td>Net KDV</td><td class="tar"><?= tl($netKdv) ?></td></tr>
      <tr><td>Reklam Harcaması</td><td class="tar"><?= tl($reklam) ?> <span class="hint">(cari hesap ekstresi çekilince dolar)</span></td></tr>
      <tr><td>Ceza</td><td class="tar"><?= tl($ceza) ?></td></tr>
      <tr><td>Erken Ödeme Kesintisi</td><td class="tar"><?= tl($erkenOdeme) ?></td></tr>
      <tr><td>Diğer Faturalar</td><td class="tar"><?= tl($diger) ?></td></tr>
    </table>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Metrikler</h2>
    <table>
      <tr><th></th><th class="tar">Değer</th></tr>
      <tr><td>Sipariş sayısı</td><td class="tar"><?= $siparis ?></td></tr>
      <tr><td>Maliyet girilen sipariş</td><td class="tar"><?= $maliyetliSiparis ?></td></tr>
      <tr><td>Satılan ürün adedi</td><td class="tar"><?= $urunAdet ?></td></tr>
      <tr><td>Sipariş başına ortalama satış</td><td class="tar"><?= $siparis > 0 ? tl($ciro / $siparis) : tl(0) ?></td></tr>
      <tr><td>Ortalama komisyon oranı</td><td class="tar">%<?= number_format($ortKomisyon, 1, ',', '.') ?></td></tr>
      <tr><td>Maliyet girilen ürün (<?= $toplamUrun ?> üründen)</td><td class="tar"><?= $maliyetGirilen ?></td></tr>
    </table>
  </div>

  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
