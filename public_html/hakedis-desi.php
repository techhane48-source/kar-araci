<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);
if (!$pro) {
    flash_set('error', 'Hakediş & Desi Kontrolü Pro plan özelliğidir.');
    e_redirect('odeme.php');
}

$tab = $_GET['tab'] ?? ($_POST['tab'] ?? 'otomatik');
if (!in_array($tab, ['otomatik', 'desi', 'hakedis'], true)) $tab = 'otomatik';

// Kullanıcının bağladığı pazaryerleri
$ints = db()->prepare("SELECT marketplace FROM integrations WHERE user_id = ?");
$ints->execute([$u['id']]);
$mps = array_column($ints->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_POST['mp'] ?? $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

// --- Desi kaydet ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_desi') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $ids = array_keys($_POST['en'] ?? []);
        $upd = db()->prepare("UPDATE products SET en=?, boy=?, yukseklik=?, kesilen_desi=? WHERE id=? AND user_id=?");
        $n = 0;
        foreach ($ids as $id) {
            $en = (float)($_POST['en'][$id] ?? 0);
            $boy = (float)($_POST['boy'][$id] ?? 0);
            $yuk = (float)($_POST['yukseklik'][$id] ?? 0);
            $kes = (float)($_POST['kesilen'][$id] ?? 0);
            $upd->execute([$en, $boy, $yuk, $kes, (int)$id, $u['id']]);
            $n++;
        }
        flash_set('success', $n . ' ürünün desi bilgisi kaydedildi.');
    }
    e_redirect('hakedis-desi.php?tab=desi&mp=' . $mp);
}

// --- Hakediş kontrolü ---
$hakedisResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_hakedis') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $entered = (float)($_POST['hakedis_satis'] ?? 0);
        // Son 30 gün senkron sipariş toplamı
        $q = db()->prepare("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ?");
        $q->execute([$u['id'], $mp, time() - 30 * 86400]);
        $synced = (float)$q->fetchColumn();

        // Fazla desi kesilen ürünler
        $ps = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
        $ps->execute([$u['id'], $mp]);
        $fazlaDesi = [];
        foreach ($ps->fetchAll() as $p) {
            $beklenen = desi_from_dims((float)$p['en'], (float)$p['boy'], (float)$p['yukseklik']);
            $kesilen = (float)$p['kesilen_desi'];
            if ($beklenen > 0 && $kesilen > $beklenen + 0.01) {
                $fazlaDesi[] = ['name' => $p['name'], 'beklenen' => $beklenen, 'kesilen' => $kesilen];
            }
        }

        $eksikSatis = $entered > 0 && $synced > 0 && $entered < $synced - 0.01;
        $hakedisResult = [
            'entered' => $entered,
            'synced' => $synced,
            'eksikSatis' => $eksikSatis,
            'fazlaDesi' => $fazlaDesi,
        ];
    }
}

// Ürünler (desi tabı için) — sayfalı + arama (14k+ ürün için performans)
$dq = trim($_GET['dq'] ?? '');
$dwhere = 'user_id = ? AND marketplace = ?';
$dargs = [$u['id'], $mp];
if ($dq !== '') {
    $dwhere .= ' AND (name LIKE ? OR barcode LIKE ?)';
    $dargs[] = '%' . $dq . '%';
    $dargs[] = '%' . $dq . '%';
}
$dc = db()->prepare("SELECT COUNT(*) c FROM products WHERE $dwhere");
$dc->execute($dargs);
$dtotal = (int)$dc->fetch()['c'];
$dperPage = 100;
$dpage = max(1, (int)($_GET['dpage'] ?? 1));
$dtotalPages = max(1, (int)ceil($dtotal / $dperPage));
$dpage = min($dpage, $dtotalPages);
$doffset = ($dpage - 1) * $dperPage;
$ps = db()->prepare("SELECT * FROM products WHERE $dwhere ORDER BY name LIMIT $dperPage OFFSET $doffset");
$ps->execute($dargs);
$products = $ps->fetchAll();

$checks = $tab === 'otomatik' ? hakedis_checks((int)$u['id'], $mp) : null;
$hasFinance = (int)db()->query("SELECT COUNT(*) FROM cargo_invoice_items WHERE user_id = " . (int)$u['id'])->fetchColumn()
            + (int)db()->query("SELECT COUNT(*) FROM settlements WHERE user_id = " . (int)$u['id'])->fetchColumn() > 0;

$pageTitle = 'Hakediş & Desi Kontrolü — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Hakediş &amp; Desi Kontrolü</h1>
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <select name="mp" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($mps as $c): ?>
          <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= h(marketplace_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
    </form>
  </div>

  <div class="tabbar" style="margin:12px 0">
    <a href="hakedis-desi.php?tab=otomatik&mp=<?= h($mp) ?>" class="tab <?= $tab === 'otomatik' ? 'active' : '' ?>">Otomatik Kontrol</a>
    <a href="hakedis-desi.php?tab=desi&mp=<?= h($mp) ?>" class="tab <?= $tab === 'desi' ? 'active' : '' ?>">Ürün Ölçüleri</a>
    <a href="hakedis-desi.php?tab=hakedis&mp=<?= h($mp) ?>" class="tab <?= $tab === 'hakedis' ? 'active' : '' ?>">Hakediş Kontrolü</a>
  </div>

  <?php $flash = flash_get(); if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <?php if ($tab === 'otomatik'): ?>
  <div class="card table-card" style="margin:16px 0">
    <h2>Otomatik hakediş kontrolü (son 60 gün)</h2>
    <?php if (!$hasFinance): ?>
      <div class="alert alert-info">Kontrol için cari hesap ekstresi ve kargo faturaları gerekiyor. <a href="magaza.php">Mağaza Bağla</a> sayfasından <b>finans verisini çek</b>; sonra her gece otomatik güncellenir.</div>
    <?php else: $n = count($checks['fazla_desi']) + count($checks['fazla_kargo']) + count($checks['eksik_hakedis']); ?>
      <?php if ($n === 0): ?>
        <div class="alert alert-success">Fazla desi, fazla kargo veya hakedişe yansımamış satış bulunmadı.</div>
      <?php else: ?>
        <p class="hint">Aşağıdaki kalemler için Trendyol satıcı destekten itiraz açabilirsin. Desi kontrolü, ürün ölçüsü ya da desisi girilmiş ürünlerde yapılır.</p>
      <?php endif; ?>

      <?php if ($checks['fazla_desi']): $sum = array_sum(array_map(fn($r) => (float)($r['diff'] ?? 0), $checks['fazla_desi'])); ?>
      <h3 style="margin-top:16px">Fazla desi kesilen gönderiler (<?= count($checks['fazla_desi']) ?>) <?= $sum > 0 ? '· tahmini fazla ödeme ' . tl($sum * 1.2) . ' KDV dahil' : '' ?></h3>
      <table>
        <tr><th>Sipariş</th><th>Tarih</th><th>Ürün</th><th class="tar">Olması gereken desi</th><th class="tar">Kesilen desi</th><th class="tar">Faturadaki kargo</th><th class="tar">Fark (KDV hariç)</th></tr>
        <?php foreach ($checks['fazla_desi'] as $r): ?>
        <tr><td style="font-family:monospace;font-size:12px"><?= h($r['order_number']) ?></td><td><?= date('d.m.Y', $r['date']) ?></td><td><?= h($r['product']) ?></td>
            <td class="tar"><?= number_format($r['expected_desi'], 2, ',', '.') ?></td><td class="tar neg"><?= number_format($r['charged_desi'], 2, ',', '.') ?></td>
            <td class="tar"><?= tl($r['charged']) ?></td><td class="tar"><?= $r['diff'] !== null ? tl($r['diff']) : '<span class="hint">tarife yok</span>' ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>

      <?php if ($checks['fazla_kargo']): ?>
      <h3 style="margin-top:16px">Tarifeden pahalı faturalanan kargolar (<?= count($checks['fazla_kargo']) ?>)</h3>
      <table>
        <tr><th>Sipariş</th><th>Tarih</th><th class="tar">Desi</th><th class="tar">Faturadaki</th><th class="tar">Tarifeye göre</th><th class="tar">Fark</th></tr>
        <?php foreach ($checks['fazla_kargo'] as $r): ?>
        <tr><td style="font-family:monospace;font-size:12px"><?= h($r['order_number']) ?></td><td><?= date('d.m.Y', $r['date']) ?></td><td class="tar"><?= number_format($r['desi'], 2, ',', '.') ?></td>
            <td class="tar"><?= tl($r['charged']) ?></td><td class="tar"><?= tl($r['expected']) ?></td><td class="tar neg"><?= tl($r['diff']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>

      <?php if ($checks['eksik_hakedis']): ?>
      <h3 style="margin-top:16px">Teslim edildiği halde hakedişe yansımayan siparişler (<?= count($checks['eksik_hakedis']) ?>)</h3>
      <table>
        <tr><th>Sipariş</th><th>Sipariş tarihi</th><th class="tar">Satış</th></tr>
        <?php foreach ($checks['eksik_hakedis'] as $r): ?>
        <tr><td style="font-family:monospace;font-size:12px"><?= h($r['order_number']) ?></td><td><?= date('d.m.Y', $r['date']) ?></td><td class="tar"><?= tl($r['sale']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php elseif ($tab === 'desi'): ?>
  <div class="card table-card" style="margin:16px 0">
    <h2>Desi Kontrolü</h2>
    <p class="hint" style="max-width:760px">Ürünün <b>en × boy × yükseklik (cm)</b> ölçülerini gir. "Olması gereken desi" otomatik hesaplanır (÷3000). Kargo firmasının kestiği <b>desi</b>'yi de gir — kesilen desi olması gerekenden büyükse <b>fazla kargo</b> kesilmiş olabilir.</p>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
      <input type="hidden" name="tab" value="desi">
      <input type="hidden" name="mp" value="<?= h($mp) ?>">
      <input type="text" name="dq" value="<?= h($dq) ?>" placeholder="Ürün adı veya barkod ara..." style="width:240px">
      <button type="submit" class="btn-ghost">Ara</button>
      <?php if ($dq !== ''): ?><a href="hakedis-desi.php?tab=desi&mp=<?= h($mp) ?>" class="btn-ghost" style="text-decoration:none">Temizle</a><?php endif; ?>
    </form>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_desi">
      <input type="hidden" name="tab" value="desi">
      <input type="hidden" name="mp" value="<?= h($mp) ?>">
      <table>
        <tr><th>Ürün</th><th>En (cm)</th><th>Boy (cm)</th><th>Yük (cm)</th><th>Olması Gereken Desi</th><th>Kesilen Desi</th><th>Durum</th></tr>
        <?php foreach ($products as $p): $beklenen = desi_from_dims((float)$p['en'], (float)$p['boy'], (float)$p['yukseklik']); $kesilen = (float)$p['kesilen_desi']; ?>
        <tr>
          <td><?= h($p['name']) ?></td>
          <td><input type="number" step="0.1" name="en[<?= $p['id'] ?>]" value="<?= (float)$p['en'] > 0 ? number_format((float)$p['en'], 1, ',', '') : '' ?>" style="width:70px"></td>
          <td><input type="number" step="0.1" name="boy[<?= $p['id'] ?>]" value="<?= (float)$p['boy'] > 0 ? number_format((float)$p['boy'], 1, ',', '') : '' ?>" style="width:70px"></td>
          <td><input type="number" step="0.1" name="yukseklik[<?= $p['id'] ?>]" value="<?= (float)$p['yukseklik'] > 0 ? number_format((float)$p['yukseklik'], 1, ',', '') : '' ?>" style="width:70px"></td>
          <td><?= $beklenen > 0 ? number_format($beklenen, 2, ',', '.') : '<span class="hint">—</span>' ?></td>
          <td><input type="number" step="0.01" name="kesilen[<?= $p['id'] ?>]" value="<?= $kesilen > 0 ? number_format($kesilen, 2, ',', '') : '' ?>" style="width:80px"></td>
          <td>
            <?php if ($beklenen > 0 && $kesilen > 0): ?>
              <?php if ($kesilen > $beklenen + 0.01): ?><span class="neg">▲ Fazla <?= number_format($kesilen - $beklenen, 2, ',', '.') ?> desi</span>
              <?php else: ?><span class="pos">● Normal</span><?php endif; ?>
            <?php else: ?><span class="hint">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="7" class="hint">Ürün yok. Önce ürünlerini senkronize et.</td></tr><?php endif; ?>
      </table>
      <?php if ($dtotalPages > 1): ?>
      <div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:14px">
        <?php if ($dpage > 1): ?><a class="btn-ghost" style="text-decoration:none" href="hakedis-desi.php?tab=desi&mp=<?= h($mp) ?>&dq=<?= urlencode($dq) ?>&dpage=<?= $dpage - 1 ?>">← Önceki</a><?php endif; ?>
        <span class="hint">Sayfa <?= $dpage ?> / <?= $dtotalPages ?> (<?= $dtotal ?> ürün)</span>
        <?php if ($dpage < $dtotalPages): ?><a class="btn-ghost" style="text-decoration:none" href="hakedis-desi.php?tab=desi&mp=<?= h($mp) ?>&dq=<?= urlencode($dq) ?>&dpage=<?= $dpage + 1 ?>">Sonraki →</a><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($products): ?><button type="submit" class="btn-primary" style="margin-top:12px">Kaydet &amp; Kontrol Et</button><?php endif; ?>
    </form>
  </div>

  <?php else: ?>
  <div class="card" style="margin:16px 0;max-width:620px">
    <h2>Hakediş Kontrolü</h2>
    <p class="hint">Trendyol hakediş dökümünde sana yansıyan <b>satış tutarını</b> gir. Kâr&Fiyat, senkron ettiğin siparişlerle karşılaştırıp <b>eksik satış bedeli</b> olup olmadığını ve desi bilgilerinden <b>fazla kargo</b> kesintisini kontrol eder.</p>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="check_hakedis">
      <input type="hidden" name="tab" value="hakedis">
      <input type="hidden" name="mp" value="<?= h($mp) ?>">
      <div class="field" style="margin:0"><label>Hakediş dönemi satış tutarı (₺)</label><input type="number" step="0.01" name="hakedis_satis" placeholder="örn. 45000.00" required style="width:200px"></div>
      <button type="submit" class="btn-primary">Kontrol Et</button>
    </form>

    <?php if ($hakedisResult): ?>
    <div style="margin-top:16px">
      <div class="alert alert-<?= ($hakedisResult['eksikSatis'] || $hakedisResult['fazlaDesi']) ? 'error' : 'success' ?>">
        <?php if (!$hakedisResult['eksikSatis'] && !$hakedisResult['fazlaDesi']): ?>
          Hakedişinize yansıyan fazla kargo faturası veya eksik satış bedeli bulunmamaktadır.
        <?php else: ?>
          Dikkat gerektiren kalemler tespit edildi:
          <ul style="margin:8px 0 0 18px">
            <?php if ($hakedisResult['eksikSatis']): ?>
              <li><b>Eksik satış bedeli:</b> girilen <?= tl($hakedisResult['entered']) ?> &lt; senkron siparişler <?= tl($hakedisResult['synced']) ?></li>
            <?php endif; ?>
            <?php foreach ($hakedisResult['fazlaDesi'] as $f): ?>
              <li><b>Fazla kargo (desi):</b> <?= h($f['name']) ?> — kesilen <?= number_format($f['kesilen'], 2, ',', '.') ?> &gt; olması gereken <?= number_format($f['beklenen'], 2, ',', '.') ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <p class="hint">Karşılaştırma: senkron sipariş toplamı <b><?= tl($hakedisResult['synced']) ?></b> (son 30 gün).</p>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
