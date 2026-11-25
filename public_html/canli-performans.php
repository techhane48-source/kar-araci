<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

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
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $pid = (int)($_POST['pid'] ?? 0);
        $cost = (float)str_replace(',', '.', (string)($_POST['cost'] ?? '0'));
        if ($pid > 0) {
            db()->prepare("UPDATE products SET cost = ? WHERE id = ? AND user_id = ?")
               ->execute([$cost, $pid, $u['id']]);
            flash_set('success', 'Maliyet güncellendi.');
        }
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

// Network jobs run via explicit POST or CLI, never on GET. Show actual freshness.
$autoSyncSt=db()->prepare("SELECT last_orders_sync FROM integrations WHERE user_id=? AND marketplace=?");
$autoSyncSt->execute([$u['id'],$mp]);$lastOrdersSync=(int)$autoSyncSt->fetchColumn();

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
$orders = $q->fetchAll();

$todayStart = strtotime('today');
$yesterdayStart = $todayStart - 86400;
$now = time();

$today = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0, 'profitCount' => 0, 'profitSale'=>0.0];
$yesterday = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0];
$hourly = array_fill(0, 24, ['sale' => 0.0, 'profit' => 0.0, 'count' => 0]);
$week = []; // dayKey (Y-m-d) => [sale, profit, count]

foreach ($orders as $o) {
    if (!order_is_active_sale($o)) continue;
    $o['lines'] = json_decode($o['lines'] ?? '[]', true) ?: [];
    $day = strtotime(date('Y-m-d', (int)$o['order_date']));
    $dayKey = date('Y-m-d', (int)$o['order_date']);
    if (!isset($week[$dayKey])) $week[$dayKey] = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0];

    $pr = calc_order_profit($o, $byBarcode);
    $profit = $pr['ok'] ? (float)$pr['profit'] : 0.0;
    $sale = (float)$o['total_price'];

    $week[$dayKey]['sale'] += $sale;
    $week[$dayKey]['count']++;

    if ($day >= $todayStart) {
        $today['sale'] += $sale;
        $today['count']++;
        $hour = (int) date('G', (int)$o['order_date']);
        $hourly[$hour]['sale'] += $sale;
        $hourly[$hour]['count']++;
        if ($pr['ok']) {
            $today['profit'] += $profit;
            $today['profitCount']++;
            $today['profitSale'] += $sale;
            $hourly[$hour]['profit'] += $profit;
        }
    } elseif ($day >= $yesterdayStart) {
        $yesterday['sale'] += $sale;
        $yesterday['count']++;
        if ($pr['ok']) $yesterday['profit'] += $profit;
    }
}

$deltaSale = $yesterday['sale'] > 0 ? ($today['sale'] - $yesterday['sale']) / $yesterday['sale'] * 100 : null;
$avgMargin = $today['profitSale'] > 0 ? $today['profit'] / $today['profitSale'] * 100 : null;
$excludedCurrency = count(array_filter($orders, static fn($o)=>!order_is_domestic_try($o)));

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

  <p class="hint">Son başarılı sipariş senkronu: <?= $lastOrdersSync > 0 ? date("d.m.Y H:i", $lastOrdersSync) : "Henüz yok" ?><?= $lastOrdersSync > 0 && $lastOrdersSync < time()-900 ? " — veriler eski olabilir" : "" ?>.</p>
  <div class="kpi-row">
    <div class="kpi"><div class="k-label">Bugün Satış</div><div class="k-value"><?= tl($today['sale']) ?></div></div>
    <div class="kpi"><div class="k-label">Bugün Tahmini Kâr</div><div class="k-value <?= $today['profit'] < 0 ? 'neg' : '' ?>"><?= $today['profitCount'] > 0 ? tl($today['profit']) : '—' ?></div></div>
    <div class="kpi"><div class="k-label">Sipariş</div><div class="k-value"><?= $today['count'] ?></div></div>
    <div class="kpi"><div class="k-label">Ort. Kâr Marjı</div><div class="k-value"><?= $avgMargin === null ? '—' : '%'.number_format($avgMargin,1,',','.') ?></div></div>
  </div>

  <p class="hint">Kâr kapsamı: <?= $today['profitCount'] ?>/<?= $today['count'] ?> sipariş; <?= tl($today['profitSale']) ?> ciro. <?= $excludedCurrency ?> paket ülke/para birimi doğrulaması dışında. Eski kayıtlar için yeniden senkron gerekir.</p>
  <?php if ($deltaSale !== null): ?>
    <p class="hint" style="margin:8px 0">Dünün tamamına göre bugünkü satış <b class="<?= $deltaSale >= 0 ? 'pos' : 'neg' ?>">%<?= number_format($deltaSale, 1, ',', '.') ?></b> (dün <?= tl($yesterday['sale']) ?>).</p>
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
        <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:2px" title="<?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?>:00 — Satış <?= tl($d['sale']) ?> / Kâr <?= tl($d['profit']) ?>">
          <div style="width:100%;background:var(--accent-soft);border-radius:3px 3px 0 0;height:<?= max(2, $sh) ?>%;position:relative">
            <?php if ($d['profit'] > 0): ?><div style="position:absolute;bottom:0;left:0;right:0;background:var(--green);height:<?= max(1, $ph) ?>%;border-radius:3px 3px 0 0"></div><?php endif; ?>
          </div>
          <?php if ($h % 3 === 0): ?><div style="font-size:9px;color:var(--muted-2)"><?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?></div><?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
    <p class="hint" style="margin-bottom:10px">Mavi: satış, yeşil: kâr (satış ölçeğinde). Saat etiketleri her 3 saatte bir.</p>
    <table>
      <tr><th>Saat</th><th>Satış</th><th>Tahmini Kâr</th><th>Sipariş</th></tr>
      <?php foreach ($hourly as $h => $d): if ($d['count'] === 0) continue; ?>
        <tr>
          <td><?= str_pad((string)$h, 2, '0', STR_PAD_LEFT) ?>:00</td>
          <td><?= tl($d['sale']) ?></td>
          <td class="<?= $d['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($d['profit']) ?></td>
          <td><?= $d['count'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!array_filter(array_column($hourly, 'count'))): ?><tr><td colspan="4" class="hint">Bugün henüz sipariş yok. "Siparişleri Senkronize Et" ile güncelleyin.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Son 7 Gün</h2>
    <table>
      <tr><th>Gün</th><th>Satış</th><th>Net Kâr</th><th>Sipariş</th></tr>
      <?php foreach ($week as $day => $d): ?>
        <tr>
          <td><?= h($day) ?></td>
          <td><?= tl($d['sale']) ?></td>
          <td class="<?= $d['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($d['profit']) ?></td>
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
        $lines = is_array($o['lines']) ? $o['lines'] : (json_decode($o['lines'] ?? '[]', true) ?: []);
        foreach ($lines as $l) {
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
      <tr><th>Sipariş No</th><th>Tarih</th><th>Satış</th><th>Net Kâr</th><th>Durum</th></tr>
      <?php foreach (array_slice($orders, 0, 25) as $o): $o['lines'] = json_decode($o['lines'] ?? '[]', true) ?: []; $pr = calc_order_profit($o, $byBarcode); ?>
        <tr>
          <td style="font-size:12px"><?= h($o['order_number'] ?: $o['package_number'] ?: '—') ?></td>
          <td style="font-size:12px"><?= date('d.m H:i', (int)$o['order_date']) ?></td>
          <td><?= order_is_domestic_try($o) ? tl((float)$o['total_price']) : h((string)($o['currency_code'] ?? 'Belirsiz')) . ' ' . number_format((float)$o['total_price'],2,',','.') ?></td>
          <td>
            <?php if ($pr['ok']): ?><span class="<?= $pr['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($pr['profit']) ?></span>
            <?php else: ?><span class="hint"><?= h($pr['reason'] ?? 'Veri eksik') ?></span><?php endif; ?>
          </td>
          <td style="font-size:12px"><?= h($o['status'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="5" class="hint">Sipariş yok.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
