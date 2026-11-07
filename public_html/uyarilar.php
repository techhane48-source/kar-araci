<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);

$mps = array_column(db()->query("SELECT marketplace FROM integrations WHERE user_id = {$u['id']}")->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

$ps = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
$ps->execute([$u['id'], $mp]);
$products = $ps->fetchAll();

$fromTs = time() - 30 * 86400;
$os = db()->prepare("SELECT * FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ?");
$os->execute([$u['id'], $mp, $fromTs]);
$orders = $os->fetchAll();
foreach ($orders as &$o) $o['lines'] = json_decode($o['lines'] ?? '[]', true) ?: [];
unset($o);

$byBarcode = [];
foreach ($products as $p) if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;

$alerts = [
    'maliyet' => [],
    'zarar' => [],
    'komisyon' => [],
    'stok' => [],
    'desi' => [],
    'iade' => [],
    'siparis' => [],
    'cari' => [],
];

foreach ($products as $p) {
    $cost = (float)$p['cost'];
    $price = (float)$p['price'];
    if ($cost <= 0) {
        $alerts['maliyet'][] = $p;
    } else {
        $r = calc_profit($cost, $price, (float)$p['cargo'], (float)$p['rate'],
            (float)($p['kdv'] ?: 20), (float)$p['extra_fee'], (float)($p['service_fee'] ?: trendyol_service_fee()),
            auto_stopaj($price, (float)($p['kdv'] ?: 20), 1.0), (float)$p['return_rate']);
        if ($r['profit'] < 0) $alerts['zarar'][] = array_merge($p, ['_profit' => $r['profit']]);
    }
    if ((float)$p['rate'] <= 0) $alerts['komisyon'][] = $p;
    if ((int)$p['quantity'] <= 0) $alerts['stok'][] = $p;
    $beklenen = desi_from_dims((float)$p['en'], (float)$p['boy'], (float)$p['yukseklik']);
    $kesilen = (float)$p['kesilen_desi'];
    if ($beklenen > 0 && $kesilen > $beklenen + 0.01) $alerts['desi'][] = array_merge($p, ['_beklenen' => $beklenen, '_kesilen' => $kesilen]);
    if ((float)$p['return_rate'] > 15) $alerts['iade'][] = $p;
}

foreach ($orders as $o) {
    $pr = calc_order_profit($o, $byBarcode);
    if (!$pr['ok'] && $pr['sale'] > 0) $alerts['siparis'][] = array_merge($o, ['_sale' => $pr['sale']]);
}

if (count($orders) === 0) {
    $alerts['cari'][] = ['msg' => 'Son 30 günde sipariş senkron edilmemiş.'];
}

$totalAlerts = 0;
foreach ($alerts as $list) $totalAlerts += count($list);

$pageTitle = 'Uyarılar — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Uyarılar</h1>
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <select name="mp" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($mps as $c): ?>
          <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= h(marketplace_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if ($totalAlerts === 0): ?>
    <div class="card" style="padding:32px;text-align:center">
      <h2 style="color:var(--green)">Tüm kontroller temiz ✓</h2>
      <p class="hint">Maliyet, kârlılık, stok, desi ve komisyon kontrollerinde uyarı bulunamadı.</p>
    </div>
  <?php else: ?>
    <div class="alert alert-error" style="margin-bottom:16px">
      <b><?= $totalAlerts ?></b> uyarı tespit edildi. Aşağıdaki kalemleri inceleyin.
    </div>
  <?php endif; ?>

  <?php
  $sections = [
    'maliyet' => ['title' => 'Maliyeti Girilmemiş Ürünler', 'desc' => 'Kâr hesaplaması için alış maliyeti zorunlu.'],
    'zarar' => ['title' => 'Zarar Eden Ürünler', 'desc' => 'Net kârı negatif olan ürünler.'],
    'komisyon' => ['title' => 'Komisyon Oranı Eksik (0%)', 'desc' => 'Komisyon oranı girilmemiş; kâr hesabı hatalı.'],
    'stok' => ['title' => 'Stokta Olmayan Ürünler', 'desc' => 'Stok adedi 0 olan ürünler.'],
    'desi' => ['title' => 'Fazla Kargo (Desi) Kesilenler', 'desc' => 'Kesilen desi, olması gerekenden büyük.'],
    'iade' => ['title' => 'Yüksek İade Oranı (> %15)', 'desc' => 'İade kaybı kârı düşürüyor.'],
    'siparis' => ['title' => 'Maliyeti Eksik Siparişler', 'desc' => 'Maliyeti girilmemiş ürün içeren siparişler.'],
    'cari' => ['title' => 'Sipariş Senkronu Eksik', 'desc' => 'Son 30 günde sipariş senkron edilmemiş.'],
  ];
  foreach ($sections as $key => $sec):
    $list = $alerts[$key];
    if (!$list) continue;
  ?>
  <div class="card table-card" style="margin:16px 0">
    <h2><?= h($sec['title']) ?> <span class="hint" style="font-weight:400">(<?= count($list) ?>)</span></h2>
    <p class="hint" style="margin-bottom:10px"><?= h($sec['desc']) ?></p>
    <?php if ($key === 'cari'): ?>
      <p><?= h($list[0]['msg']) ?> <a href="canli-performans.php">Canlı Performans →</a></p>
    <?php elseif ($key === 'siparis'): ?>
      <table>
        <tr><th>Sipariş No</th><th>Tarih</th><th>Satış</th></tr>
        <?php foreach (array_slice($list, 0, 50) as $o): ?>
        <tr>
          <td style="font-size:12px"><?= h($o['order_number'] ?: $o['package_number'] ?: '—') ?></td>
          <td style="font-size:12px"><?= date('d.m.Y H:i', (int)$o['order_date']) ?></td>
          <td><?= tl((float)$o['_sale']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php elseif ($key === 'desi'): ?>
      <table>
        <tr><th>Ürün</th><th>Beklenen Desi</th><th>Kesilen Desi</th><th>Fazla</th></tr>
        <?php foreach (array_slice($list, 0, 50) as $p): ?>
        <tr>
          <td><?= h($p['name']) ?></td>
          <td><?= number_format($p['_beklenen'], 2, ',', '.') ?></td>
          <td><?= number_format($p['_kesilen'], 2, ',', '.') ?></td>
          <td class="neg">+<?= number_format($p['_kesilen'] - $p['_beklenen'], 2, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php elseif ($key === 'zarar'): ?>
      <table>
        <tr><th>Barkod</th><th>Ürün</th><th>Satış</th><th>Maliyet</th><th>Net Kâr</th></tr>
        <?php foreach (array_slice($list, 0, 50) as $p): ?>
        <tr>
          <td style="font-size:12px"><?= h($p['barcode']) ?></td>
          <td><?= h($p['name']) ?></td>
          <td><?= tl((float)$p['price']) ?></td>
          <td><?= tl((float)$p['cost']) ?></td>
          <td class="neg"><?= tl($p['_profit']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <table>
        <tr><th>Barkod</th><th>Ürün</th><th>Kategori</th><th>Stok</th><th>Maliyet</th></tr>
        <?php foreach (array_slice($list, 0, 50) as $p): ?>
        <tr>
          <td style="font-size:12px"><?= h($p['barcode']) ?></td>
          <td><?= h($p['name']) ?></td>
          <td class="muted"><?= h($p['category'] ?: '—') ?></td>
          <td><?= (int)$p['quantity'] ?></td>
          <td><?= (float)$p['cost'] > 0 ? tl((float)$p['cost']) : '<span class="muted">boş</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php if (count($list) > 50): ?>
      <p class="hint" style="margin-top:8px">İlk 50 gösteriliyor (toplam <?= count($list) ?>).</p>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>