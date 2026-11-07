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

// Toplu yeniden fiyatlama
$rep = null;
$repApplied = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repricing'])) {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Oturum süresi doldu. Sayfayı yenileyin.');
    }
    $tip = $_POST['tip'] ?? 'marj';
    $deger = (float)($_POST['deger'] ?? 0);
    $apply = isset($_POST['apply']);
    $rep = ['tip' => $tip, 'deger' => $deger, 'uygulandi' => false, 'sayi' => 0, 'failed' => 0, 'rows' => []];

    $targets = db()->prepare("SELECT * FROM products WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND cost > 0 AND price > 0");
    $targets->execute();
    foreach ($targets->fetchAll() as $p) {
        $cost = (float)$p['cost'];
        $cargo = (float)$p['cargo'];
        $rate = (float)$p['rate'];
        $kdv = (float)($p['kdv'] ?? 20.0);
        $extra = (float)$p['extra_fee'];
        $service = (float)($p['service_fee'] ?? 0);
        $ret = (float)$p['return_rate'];
        $tp = 0.0; $tm = 0.0;
        if ($tip === 'tutar') $tp = $deger;
        elseif ($tip === 'oran') $tp = $cost * $deger / 100.0;   // kâr oranı = kâr/maliyet
        else $tm = $deger;                                        // kâr marjı = kâr/satış
        $r = calc_product_price($p, $tp, $tm);
        if (!$r['ok']) { $rep['failed']++; continue; }
        if ($apply) {
            db()->prepare("UPDATE products SET price = ? WHERE id = ?")->execute([$r['price'], $p['id']]);
        }
        $rep['rows'][] = [
            'barcode' => $p['barcode'], 'name' => $p['name'],
            'old' => (float)$p['price'], 'new' => round($r['price'], 2),
            'kar' => round($r['profit'], 2),
        ];
    }
    $rep['sayi'] = count($rep['rows']);
    if ($apply) { $rep['uygulandi'] = true; flash_set('success', $rep['sayi'] . ' ürünün fiyatı güncellendi.'); header('Location: kar-marji.php?mp=' . urlencode($mp)); exit; }
}

// CSV indir (yeniden fiyatlama önizleme)
if (isset($_GET['export']) && $_GET['export'] === 'repricing' && $rep) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="yeniden-fiyatlama.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    write_csv($out, ['Barkod', 'Ürün', 'Eski Fiyat', 'Yeni Fiyat', 'Tahmini Kâr']);
    foreach ($rep['rows'] as $r) write_csv($out, [$r['barcode'], $r['name'], $r['old'], $r['new'], $r['kar']]);
    fclose($out);
    exit;
}

// Ürün kârlılık hesabı (tek ürün)
function urun_kar(array $p): array { return calc_product_profit($p); }

$pageTitle = 'Kâr Marjı Listesi — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Kâr Marjı Listesi</h1>
  <p class="muted">TSF satış fiyatı üzerinden kârlılık; hedef kâra göre toplu yeniden fiyatlama.</p>
</div>

<div class="card">
  <h2>Toplu Yeniden Fiyatlama</h2>
  <p class="hint" style="margin:4px 0 10px">Maliyeti girilmiş ürünlerde hedef kâra göre yeni satış fiyatı çözülür (geri alınamaz — önce raporu indir).</p>
  <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="repricing" value="1">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Hedef
      <select name="tip" style="padding:8px">
        <option value="marj">Kâr Marjı (%) = kâr/satış</option>
        <option value="oran">Kâr Oranı (%) = kâr/maliyet</option>
        <option value="tutar">Kâr Tutarı (₺)</option>
      </select>
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Değer
      <input type="number" name="deger" step="0.01" value="30" style="padding:8px;width:120px">
    </label>
    <button type="submit" class="btn-primary" style="padding:8px 14px">Hesapla / Önizle</button>
  </form>

  <?php if ($rep && !$rep['uygulandi']): ?>
    <div class="flash flash-info" style="margin-top:12px">
      <strong><?= $rep['sayi'] ?> ürün</strong> hedefe göre fiyatlanacak.
      <?php if ($rep['failed']): ?><span class="neg"><?= $rep['failed'] ?> üründe hedefe ulaşılamıyor; bu ürünler değiştirilmez.</span><?php endif; ?>
      <a href="kar-marji.php?export=repricing&mp=<?= urlencode($mp) ?>" class="btn-ghost" style="margin-left:8px">⬇ Raporu İndir (CSV)</a>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="repricing" value="1">
        <input type="hidden" name="tip" value="<?= h($rep['tip']) ?>">
        <input type="hidden" name="deger" value="<?= h((string)$rep['deger']) ?>">
        <button type="submit" name="apply" value="1" class="btn-primary" style="margin-left:8px" onclick="return confirm('<?= $rep['sayi'] ?> ürünün fiyatı geri alınamaz şekilde güncellenecek. Emin misin?')">Uygula</button>
      </form>
    </div>
    <table style="margin-top:10px">
      <tr><th>Barkod</th><th>Ürün</th><th class="tar">Eski Fiyat</th><th class="tar">Yeni Fiyat</th><th class="tar">Tahmini Kâr</th></tr>
      <?php foreach (array_slice($rep['rows'], 0, 20) as $r): ?>
        <tr><td><?= h($r['barcode']) ?></td><td><?= h($r['name']) ?></td><td class="tar"><?= tl($r['old']) ?></td><td class="tar" style="color:var(--accent)"><?= tl($r['new']) ?></td><td class="tar"><?= tl($r['kar']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($rep['sayi'] > 20): ?><p class="hint">İlk 20 gösteriliyor (toplam <?= $rep['sayi'] ?>).</p><?php endif; ?>
  <?php endif; ?>
</div>

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
        $service = (float)($p['service_fee'] ?? 0); $ret = (float)$p['return_rate'];
        $detayR = calc_product_profit($p);
        $stopaj = $detayR['stopaj'] ?? 0;
        if (!$detayR['ok']) $detayR = null;
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
