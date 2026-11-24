<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/order_accounting.php';

$u = require_login();
$pro = is_pro($u);
if (!$pro) {
    flash_set('error', 'Canlı Performans Pro plan özelliğidir.');
    e_redirect('odeme.php');
}

// Kullanıcının bağladığı pazaryerleri
$ints = db()->prepare("SELECT marketplace FROM integrations WHERE user_id = ?");
$ints->execute([$u['id']]);
$mps = array_column($ints->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];

$mp = $_POST['mp'] ?? $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

// --- Maliyet kaydet (inline; Bugün Sipariş Alan Ürünler) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_cost') {
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || !csrf_verify($csrf)) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        save_product_cost((int)$u['id'], $_POST['pid'] ?? null, $_POST['cost'] ?? null, $mp);
    }
    e_redirect('canli-performans.php?mp=' . urlencode($mp));
}

// --- Senkronize et ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $r = sync_orders($u['id'], $mp, 7);
        if ($r['ok']) {
            flash_set('success', ($r['count'] ?? 0) . ' sipariş senkronize edildi.');
        } else {
            flash_set('error', $r['error'] ?? 'Senkron başarısız.');
        }
    }
    e_redirect('canli-performans.php?mp=' . $mp);
}

// --- Otomatik sipariş senkronu (müşteri butona basmadan; son senkron > 10 dk ise) ---
$autoSyncSt = db()->prepare("SELECT user_id, marketplace, seller_id, stage, last_orders_sync FROM integrations WHERE user_id = ? AND marketplace = ?");
$autoSyncSt->execute([$u['id'], $mp]);
$integration = $autoSyncSt->fetch();
$lastOrdersSync = $integration['last_orders_sync'] ?? null;
$useCargoEvidence = $mp === 'trendyol' && $integration !== false;
if (!$lastOrdersSync || (int)$lastOrdersSync < time() - 600) {
    sync_orders($u['id'], $mp, 7); // hata olsa bile sayfa mevcut (eski) veriyle açılır
}

// Ürünler (barkod -> ürün)
$ps = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
$ps->execute([$u['id'], $mp]);
$byBarcode = [];
foreach ($ps->fetchAll() as $p) {
    if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;
}

// Son 7 gün siparişler
$weekStart = strtotime('today') - 6 * 86400;
$q = db()->prepare("SELECT * FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ? ORDER BY order_date DESC");
$q->execute([$u['id'], $mp, $weekStart]);
$orders = array_values(array_filter($q->fetchAll(), 'order_is_sale'));
foreach ($orders as &$order) $order['lines'] = json_decode($order['lines'] ?? '[]', true) ?: [];
unset($order);

$todayStart = strtotime('today');
$yesterdayStart = $todayStart - 86400;
$now = time();

$today = ['sale' => 0.0, 'profit' => 0.0, 'knownSale' => 0.0, 'count' => 0, 'profitCount' => 0];
$yesterday = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0];
$hourly = array_fill(0, 24, ['sale' => 0.0, 'profit' => 0.0, 'count' => 0, 'knownCount' => 0, 'missing' => false]);
$cargoByOrder = [];
$profitByPackage = [];
$week = []; // dayKey (Y-m-d) => [sale, profit, count]

foreach ($orders as $o) {
    $day = strtotime(date('Y-m-d', (int)$o['order_date']));
    $dayKey = date('Y-m-d', (int)$o['order_date']);
    if (!isset($week[$dayKey])) $week[$dayKey] = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0, 'knownCount' => 0, 'missing' => false];

    if (!$useCargoEvidence) {
        $pr = calc_order_profit($o, $byBarcode);
    } else {
        $orderNumber = $o['order_number'];
        if ($orderNumber !== '' && $o['source_seller_id'] === $integration['seller_id']
            && $o['source_stage'] !== null && (int)$o['source_stage'] === (int)$integration['stage']) {
            $cargoByOrder[$orderNumber] ??= order_cargo_profit_view(
                $integration,
                order_accounting_packages((int)$u['id'], $orderNumber),
                order_cargo_records($integration, $orderNumber)
            );
        }
        $cargo = $cargoByOrder[$orderNumber] ?? [];
        $pr = ($cargo['calculation'] ?? ['ok' => false]) + ['cargo_source' => $cargo['source'] ?? 'pending'];
        if (!$pr['ok']) {
            $beforeCargo = calc_order_profit($o, $byBarcode, 0.0);
            $pr['before_cargo'] = $beforeCargo['ok'] ? $beforeCargo['profit'] : null;
            $pr['pending_reason'] = match (true) {
                !$beforeCargo['ok'] => 'Maliyet veya hesap girdisi eksik',
                $orderNumber === '' => 'Trendyol sipariş numarası eksik',
                $o['source_seller_id'] === null => 'Siparişin yeniden senkronize edilmesi gerekiyor',
                $cargo === [] || !$cargo['scope_matches'] => 'Siparişin mağaza veya ortamı doğrulanamadı',
                $pr['cargo_source'] === 'invoice' => 'Fatura sipariş düzeyinde · Paket kargosu ayrıştırılamadı',
                $pr['cargo_source'] === 'history' => 'Kargo tahmini var · Sipariş maliyet kaydı bekleniyor',
                default => 'Kargo faturası bekleniyor veya fatura kalemleri doğrulanamadı',
            };
            if ($cargo === [] && $o['source_seller_id'] !== null) $pr['before_cargo'] = null;
        }
    }
    $profitByPackage[$o['id']] = $pr;
    $profit = $pr['ok'] ? (float)$pr['profit'] : 0.0;
    $sale = (float)$o['total_price'];

    $week[$dayKey]['sale'] += $sale;
    $week[$dayKey]['count']++;
    if ($pr['ok']) { $week[$dayKey]['profit'] += $profit; $week[$dayKey]['knownCount']++; }
    else $week[$dayKey]['missing'] = true;

    if ($day >= $todayStart) {
        $today['sale'] += $sale;
        $today['count']++;
        $hour = (int) date('G', (int)$o['order_date']);
        $hourly[$hour]['sale'] += $sale;
        $hourly[$hour]['count']++;
        if (!$pr['ok']) $hourly[$hour]['missing'] = true;
        if ($pr['ok']) {
            $today['profit'] += $profit;
            $today['profitCount']++;
            $today['knownSale'] += $sale;
            $hourly[$hour]['profit'] += $profit;
            $hourly[$hour]['knownCount']++;
        }
    } elseif ($day >= $yesterdayStart) {
        $yesterday['sale'] += $sale;
        $yesterday['count']++;
        if ($pr['ok']) $yesterday['profit'] += $profit;
    }
}

$deltaSale = $yesterday['sale'] > 0 ? ($today['sale'] - $yesterday['sale']) / $yesterday['sale'] * 100 : null;
$avgMargin = $today['profitCount'] > 0 && $today['knownSale'] > 0 ? $today['profit'] / $today['knownSale'] * 100 : 0.0;

$pageTitle = 'Canlı Performans — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Canlı Performans</h1>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="sync">
      <select name="mp" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($mps as $c): ?>
          <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= h(marketplace_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-primary">Siparişleri Senkronize Et</button>
    </form>
  </div>

  <?php $flash = flash_get(); if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <p class="hint">Trendyol kârı, faturadaki kargo veya karşılaştırılabilir geçmiş gönderiyle hesaplanır. Diğer giderler ayrıca doğrulanır.
  <?php if ($today['count'] > $today['profitCount']): ?>
    <?= $today['count'] - $today['profitCount'] ?> paket toplama alınmadı. Nedeni Son Siparişler bölümünde, kargo öncesi tutar varsa yanında gösterilir.
  <?php endif; ?>
  <?php if ($useCargoEvidence): ?><a href="magaza.php?mp=trendyol">Finans kayıtlarını güncelle</a>.<?php endif; ?></p>
  <div class="kpi-row">
    <div class="kpi"><div class="k-label">Bugün Satış</div><div class="k-value"><?= tl($today['sale']) ?></div></div>
    <div class="kpi"><div class="k-label">Bugün Hesaplanan Kâr</div><div class="k-value <?= $today['profit'] < 0 ? 'neg' : '' ?>"><?= $today['profitCount'] > 0 ? tl($today['profit']) : '—' ?></div></div>
    <div class="kpi"><div class="k-label">Paket</div><div class="k-value"><?= $today['count'] ?></div></div>
    <div class="kpi"><div class="k-label">Ort. Kâr Marjı</div><div class="k-value"><?= $today['profitCount'] > 0 ? '%' . number_format($avgMargin, 1, ',', '.') : '—' ?></div></div>
  </div>

  <?php if ($deltaSale !== null): ?>
    <p class="hint" style="margin:8px 0">Düne göre satış <b class="<?= $deltaSale >= 0 ? 'pos' : 'neg' ?>">%<?= number_format($deltaSale, 1, ',', '.') ?></b> (dün <?= tl($yesterday['sale']) ?>).</p>
  <?php endif; ?>

  <div class="card table-card" style="margin:16px 0">
    <h2>Bugün Saatlik</h2>
    <?php
    // Saatlik grafik (SVG bar chart) — Melontik paritesi
    $maxSaleH = max(1.0, max(array_column($hourly, 'sale')));
    $maxProfitH = max(1.0, max(array_column($hourly, 'profit')));
    ?>
    <div style="display:flex;gap:8px;align-items:flex-end;height:120px;margin:10px 0 6px;padding:0 4px">
      <?php for ($h = 0; $h < 24; $h++): $d = $hourly[$h]; $sh = $d['sale'] / $maxSaleH * 100; $ph = $d['profit'] / $maxSaleH * 100; ?>
        <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:2px" title="<?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?>:00 — Satış <?= tl($d['sale']) ?> / Kâr <?= $d['knownCount'] > 0 ? tl($d['profit']) : '—' ?><?= $d['missing'] ? ' (kısmi toplam)' : '' ?>">
          <div style="width:100%;background:var(--accent-soft);border-radius:3px 3px 0 0;height:<?= max(2, $sh) ?>%;position:relative">
            <?php if ($d['profit'] > 0): ?><div style="position:absolute;bottom:0;left:0;right:0;background:var(--green);height:<?= max(1, $ph) ?>%;border-radius:3px 3px 0 0"></div><?php endif; ?>
          </div>
          <?php if ($h % 3 === 0): ?><div style="font-size:9px;color:var(--muted-2)"><?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?></div><?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
    <p class="hint" style="margin-bottom:10px">Mavi: satış, yeşil: kâr (satış ölçeğinde). Saat etiketleri her 3 saatte bir.</p>
    <table>
      <tr><th>Saat</th><th>Satış</th><th>Hesaplanan Kâr</th><th>Paket</th></tr>
      <?php foreach ($hourly as $h => $d): if ($d['count'] === 0) continue; ?>
        <tr>
          <td><?= str_pad((string)$h, 2, '0', STR_PAD_LEFT) ?>:00</td>
          <td><?= tl($d['sale']) ?></td>
          <td class="<?= $d['profit'] < 0 ? 'neg' : 'pos' ?>"><?= $d['knownCount'] > 0 ? tl($d['profit']) : '—' ?><?= $d['missing'] ? ' (kısmi toplam)' : '' ?></td>
          <td><?= $d['count'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!array_filter(array_column($hourly, 'count'))): ?><tr><td colspan="4" class="hint">Bugün henüz sipariş yok. "Siparişleri Senkronize Et" ile güncelleyin.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Son 7 Gün</h2>
    <table>
      <tr><th>Gün</th><th>Satış</th><th>Hesaplanan Kâr</th><th>Paket</th></tr>
      <?php foreach ($week as $day => $d): ?>
        <tr>
          <td><?= h($day) ?></td>
          <td><?= tl($d['sale']) ?></td>
          <td class="<?= $d['profit'] < 0 ? 'neg' : 'pos' ?>"><?= $d['knownCount'] > 0 ? tl($d['profit']) : '—' ?><?= $d['missing'] ? ' (kısmi toplam)' : '' ?></td>
          <td><?= $d['count'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$week): ?><tr><td colspan="4" class="hint">Sipariş verisi yok. Önce senkronize edin.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Bugün Sipariş Alan Ürünler — Maliyet Düzenle</h2>
    <p class="hint" style="margin-bottom:10px">Bugünkü siparişlerde yer alan ürünlerin alış maliyetini buradan hızlıca gir/güncelle.</p>
    <?php
    $todayProducts = [];
    foreach ($orders as $o) {
        if ((int)$o['order_date'] < $todayStart) continue;
        foreach ($o['lines'] as $l) {
            $bc = $l['barcode'] ?? '';
            if ($bc === '' || isset($todayProducts[$bc])) continue;
            $p = $byBarcode[$bc] ?? null;
            if ($p) $todayProducts[$bc] = $p;
        }
    }
    ?>
    <?php if (!$todayProducts): ?>
      <p class="hint">Bugün henüz sipariş yok.</p>
    <?php else: ?>
    <table>
      <tr><th>Ürün</th><th>Barkod</th><th>Mevcut Maliyet</th><th>Yeni Maliyet</th><th></th></tr>
      <?php foreach ($todayProducts as $bc => $p): ?>
      <tr>
        <td><?= h($p['name']) ?></td>
        <td style="font-size:12px"><?= h($bc) ?></td>
        <td><?= (float)$p['cost'] > 0 ? tl((float)$p['cost']) : '<span class="hint">boş</span>' ?></td>
        <td>
          <form method="post" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_cost">
            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
            <input type="number" step="0.01" name="cost" value="<?= (float)$p['cost'] > 0 ? $p['cost'] : '' ?>" style="width:110px" required>
        </td>
        <td><button type="submit" class="btn-primary" style="padding:6px 12px">Kaydet</button></form></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Son Siparişler</h2>
    <table>
      <tr><th>Sipariş No</th><th>Tarih</th><th>Satış</th><th>Hesaplanan Kâr</th><th>Durum</th></tr>
      <?php foreach (array_slice($orders, 0, 25) as $o): $pr = $profitByPackage[$o['id']]; ?>
        <tr>
          <td style="font-size:12px">
            <?php if ($mp === 'trendyol' && $o['order_number'] !== ''): ?>
              <a href="siparis-muhasebe.php?order=<?= rawurlencode($o['order_number']) ?>"><?= h($o['order_number']) ?></a>
            <?php else: ?>
              <?= h($o['order_number'] ?: $o['package_number'] ?: '—') ?>
            <?php endif; ?>
          </td>
          <td style="font-size:12px"><?= date('d.m H:i', (int)$o['order_date']) ?></td>
          <td><?= tl((float)$o['total_price']) ?></td>
          <td>
            <?php if ($pr['ok']): ?>
              <details>
                <summary class="<?= $pr['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($pr['profit']) ?></summary>
                <?php if (isset($pr['cargo_source'])): ?>
                  <p class="hint"><?= $pr['cargo_source'] === 'invoice' ? 'Faturalı kargo' : 'Geçici kargo · Kargo faturası bekleniyor' ?></p>
                <?php endif; ?>
                <dl>
                  <?php foreach ([
                      'cost' => 'Birim maliyetlerin toplamı', 'commission' => 'Komisyon',
                      'cargo' => 'Hesapta kullanılan kargo', 'service_fee' => 'Hizmet bedeli tahmini',
                      'stopaj' => 'Stopaj', 'net_kdv' => 'Net KDV tahmini',
                      'extra_fee' => 'Ek gider', 'return_loss' => 'İade payı tahmini',
                  ] as $field => $label): ?>
                    <dt><?= h($label) ?></dt><dd><?= tl($pr[$field]) ?></dd>
                  <?php endforeach; ?>
                </dl>
              </details>
            <?php else: ?>
              <?php if (isset($pr['before_cargo'])): ?>
                <div>Kargo öncesi: <?= tl($pr['before_cargo']) ?></div>
              <?php endif; ?>
              <span class="hint"><?= h($pr['pending_reason'] ?? 'Maliyet veya hesap girdisi eksik') ?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px"><?= h($o['status'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="5" class="hint">Sipariş yok.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
