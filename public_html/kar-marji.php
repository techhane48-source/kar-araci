<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
$u = require_login();

$mps = array_column(db()->query("SELECT marketplace FROM integrations WHERE user_id = {$u['id']}")->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

// Filtreler
$q = trim($_GET['q'] ?? '');
$kategori = trim($_GET['kategori'] ?? '');
$sadeceMaliyetsiz = isset($_GET['maliyetsiz']);
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;

$where = "user_id = {$u['id']} AND marketplace = " . db()->quote($mp);
$params = [];
if ($q !== '') { $where .= " AND (name LIKE ? OR barcode LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($kategori !== '') { $where .= " AND category = ?"; $params[] = $kategori; }
if ($sadeceMaliyetsiz) { $where .= " AND (cost IS NULL OR cost <= 0)"; }

// Kategori listesi
$cats = array_column(db()->query("SELECT DISTINCT category FROM products WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND category != '' ORDER BY category")->fetchAll(), 'category');

$countStmt = db()->prepare("SELECT COUNT(*) FROM products WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = db()->prepare("SELECT * FROM products WHERE $where ORDER BY (cost IS NULL OR cost <= 0) DESC, price DESC LIMIT $per OFFSET $offset");
$rows->execute($params);
$products = $rows->fetchAll();

// Price proposals are read-only; the former bulk local overwrite is retired.
// Ürün kârlılık hesabı (tek ürün)
function urun_kar(array $p): array {
    $cost = (float)$p['cost'];
    $price = (float)$p['price'];
    if ($cost <= 0 || $price <= 0) return ['ok' => false];
    $cargo = (float)$p['cargo'];
    $rate = (float)$p['rate'];
    $kdv = (float)($p['kdv'] ?? 20.0);
    $extra = (float)$p['extra_fee'];
    $service = (float)($p['service_fee'] ?? trendyol_service_fee());
    $ret = (float)$p['return_rate'];
    $stopaj = product_stopaj($p, $price);
    $r = product_profit($p);
    return ['ok' => true, 'profit' => $r['profit'], 'ratio' => $r['ratio'], 'margin' => $r['margin']];
}

$pageTitle = 'Kâr Marjı Listesi — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Kâr Marjı Listesi</h1>
  <p class="muted">TSF satış fiyatı üzerinden kârlılık; hedef kâra göre toplu yeniden fiyatlama.</p>
</div>

<div class="card"><h2>Hedef kâra göre fiyat önerileri</h2><p class="hint">Mağaza, kategori ve ürün hedefleriyle önerileri hesapla ve CSV indir. Satış fiyatları değiştirilmez.</p><a class="btn-primary" href="kar-hedefleri.php">Kâr hedefleri ve fiyat önerileri</a></div>

<?php
// ---- Nasıl Hesapladık? kırılımı ----
$detayBc = trim($_GET['detay'] ?? '');
$detayP = null;
$detayR = null;
if ($detayBc !== '') {
    foreach ($products as $pp) {
        if ($pp['barcode'] === $detayBc) { $detayP = $pp; break; }
    }
    if (!$detayP) {
        $st = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ? AND barcode = ?");
        $st->execute([$u['id'], $mp, $detayBc]);
        $detayP = $st->fetch();
    }
    if ($detayP && (float)$detayP['cost'] > 0 && (float)$detayP['price'] > 0) {
        $p = $detayP;
        $cost = (float)$p['cost']; $price = (float)$p['price'];
        $cargo = (float)$p['cargo']; $rate = (float)$p['rate'];
        $kdv = (float)($p['kdv'] ?? 20.0); $extra = (float)$p['extra_fee'];
        $service = (float)($p['service_fee'] ?? trendyol_service_fee()); $ret = (float)$p['return_rate'];
        $stopaj = product_stopaj($p, $price);
        $detayR = product_profit($p);
    }
}
?>
<?php if ($detayR): ?>
<div class="card" style="margin:16px 0;max-width:620px">
  <h2>Nasıl Hesapladık? — <?= h($detayP['name']) ?></h2>
  <p class="hint" style="margin-bottom:10px">Satış fiyatı üzerinden kâr kırılımı (tüm tutarlar KDV dahil).</p>
  <table>
    <tr><td>Satış Fiyatı</td><td class="tar"><?= tl($price) ?></td></tr>
    <tr><td>− Ürün Maliyeti (alış)</td><td class="tar neg"><?= tl($cost) ?></td></tr>
    <tr><td>− Komisyon (%<?= number_format($rate, 2) ?>)</td><td class="tar neg"><?= tl($detayR['commission']) ?></td></tr>
    <tr><td>− Kargo Ücreti</td><td class="tar neg"><?= tl($cargo) ?></td></tr>
    <tr><td>− Hizmet Bedeli</td><td class="tar neg"><?= tl($service) ?></td></tr>
    <tr><td>− Ekstra Maliyet</td><td class="tar neg"><?= tl($extra) ?></td></tr>
    <tr><td>− Stopaj (tevkifat)</td><td class="tar neg"><?= tl($stopaj) ?></td></tr>
    <tr><td>− Net KDV</td><td class="tar neg"><?= tl($detayR['net_kdv']) ?></td></tr>
    <tr><td>− İade Kaybı (%<?= number_format($ret, 1) ?>)</td><td class="tar neg"><?= tl($detayR['return_loss']) ?></td></tr>
    <tr style="font-weight:700"><td>= Net Kâr</td><td class="tar <?= $detayR['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($detayR['profit']) ?></td></tr>
    <tr><td>Kâr Marjı (kâr/satış)</td><td class="tar">%<?= number_format($detayR['margin'], 2, ',', '.') ?></td></tr>
    <tr><td>Kâr Oranı (kâr/maliyet)</td><td class="tar">%<?= number_format($detayR['ratio'], 2, ',', '.') ?></td></tr>
  </table>
  <p style="margin-top:10px"><a href="kar-marji.php?mp=<?= urlencode($mp) ?>" class="btn-ghost" style="text-decoration:none">Kapat</a></p>
</div>
<?php endif; ?>

<div class="card table-card">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
    <input type="hidden" name="mp" value="<?= h($mp) ?>">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Ürün adı / barkod ara" style="padding:8px;flex:1;min-width:180px">
    <select name="kategori" style="padding:8px">
      <option value="">Tüm Kategoriler</option>
      <?php foreach ($cats as $c): ?><option value="<?= h($c) ?>" <?= $kategori === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
    </select>
    <label style="display:flex;align-items:center;gap:4px;font-size:13px"><input type="checkbox" name="maliyetsiz" value="1" <?= $sadeceMaliyetsiz ? 'checked' : '' ?>> Maliyeti boş olanlar</label>
    <button type="submit" class="btn-ghost" style="padding:8px 12px">Filtrele</button>
  </form>

  <p class="hint"><?= $total ?> ürün · sayfa <?= $page ?>/<?= $pages ?></p>
  <table>
    <tr><th>Barkod</th><th>Ürün</th><th>Kategori</th><th class="tar">Satış Fiyatı</th><th class="tar">Maliyet</th><th class="tar">Komisyon</th><th class="tar">Kâr</th><th class="tar">Kâr Oranı</th><th class="tar">Kâr Marjı</th></tr>
    <?php foreach ($products as $p): $k = urun_kar($p); ?>
      <tr>
        <td style="font-family:monospace;font-size:12px"><a href="kar-marji.php?mp=<?= urlencode($mp) ?>&detay=<?= urlencode($p['barcode']) ?>"><?= h($p['barcode']) ?></a></td>
        <td><?= h($p['name']) ?></td>
        <td class="muted"><?= h($p['category'] ?: '—') ?></td>
        <td class="tar"><?= tl($p['price']) ?></td>
        <td class="tar"><?= (float)$p['cost'] > 0 ? tl($p['cost']) : '<span class="muted">boş</span>' ?></td>
        <td class="tar">%<?= number_format((float)$p['rate'], 1) ?></td>
        <?php if ($k['ok']): ?>
          <td class="tar" style="<?= $k['profit'] < 0 ? 'color:#e11d48' : 'color:#16a34a' ?>"><?= tl($k['profit']) ?></td>
          <td class="tar">%<?= number_format($k['ratio'], 1) ?></td>
          <td class="tar">%<?= number_format($k['margin'], 1) ?></td>
        <?php else: ?>
          <td class="tar muted" colspan="3">maliyet girilmedi</td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($pages > 1): ?>
    <div class="pager" style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?mp=<?= urlencode($mp) ?>&q=<?= urlencode($q) ?>&kategori=<?= urlencode($kategori) ?><?= $sadeceMaliyetsiz ? '&maliyetsiz=1' : '' ?>&page=<?= $i ?>" class="<?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>" style="padding:4px 10px"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
