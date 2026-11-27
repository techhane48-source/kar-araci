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

// --- Otomatik sipariş senkronu (müşteri butona basmadan; son senkron > 10 dk ise) ---
$autoSyncSt = db()->prepare("SELECT last_orders_sync FROM integrations WHERE user_id = ? AND marketplace = ?");
$autoSyncSt->execute([$u['id'], $mp]);
$lastOrdersSync = $autoSyncSt->fetchColumn();
if (!$lastOrdersSync || (int)$lastOrdersSync < time() - 600) {
    sync_orders($u['id'], $mp, 7); // hata olsa bile sayfa mevcut (eski) veriyle açılır
}

// Son 7 gün siparişlerin kâr kayıtları (motor; iptaller hariç)
$weekStart = strtotime('today') - 6 * 86400;
ensure_order_profits((int)$u['id'], $mp, $weekStart);
$q = db()->prepare("SELECT order_number, package_number, status, order_date, sale, profit, missing_cost, is_final, cargo_src, cargo_note FROM order_profits
                    WHERE user_id = ? AND marketplace = ? AND order_date >= ? AND is_cancelled = 0 ORDER BY order_date DESC");
$q->execute([$u['id'], $mp, $weekStart]);
$orders = $q->fetchAll();

$todayStart = strtotime('today');
$yesterdayStart = $todayStart - 86400;
$now = time();

$today = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0, 'profitCount' => 0];
$yesterday = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0];
$hourly = array_fill(0, 24, ['sale' => 0.0, 'profit' => 0.0, 'count' => 0]);
$week = []; // dayKey (Y-m-d) => [sale, profit, count]

foreach ($orders as $o) {
    $ts = (int)$o['order_date'];
    $dayKey = date('Y-m-d', $ts);
    if (!isset($week[$dayKey])) $week[$dayKey] = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0];
    $ok = (int)$o['missing_cost'] === 0 && $o['profit'] !== null;
    $profit = $ok ? (float)$o['profit'] : 0.0;
    $sale = (float)$o['sale'];

    $week[$dayKey]['sale'] += $sale;
    $week[$dayKey]['profit'] += $profit;
    $week[$dayKey]['count']++;

    if ($ts >= $todayStart) {
        $today['sale'] += $sale;
        $today['count']++;
        $hour = (int) date('G', $ts);
        $hourly[$hour]['sale'] += $sale;
        $hourly[$hour]['count']++;
        if ($ok) {
            $today['profit'] += $profit;
            $today['profitCount']++;
            $hourly[$hour]['profit'] += $profit;
        }
    } elseif ($ts >= $yesterdayStart && $ts <= $now - 86400) {
        // "Dün bu saatte": dünün aynı saatine kadar olan kısım
        $yesterday['sale'] += $sale;
        $yesterday['count']++;
        if ($ok) $yesterday['profit'] += $profit;
    }
}

$deltaSale = $yesterday['sale'] > 0 ? ($today['sale'] - $yesterday['sale']) / $yesterday['sale'] * 100 : null;
$avgMargin = $today['profitCount'] > 0 && $today['sale'] > 0 ? $today['profit'] / $today['sale'] * 100 : 0.0;

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

  <div class="kpi-row">
    <div class="kpi"><div class="k-label">Bugün Satış</div><div class="k-value"><?= tl($today['sale']) ?></div></div>
    <div class="kpi"><div class="k-label">Bugün Net Kâr</div><div class="k-value <?= $today['profit'] < 0 ? 'neg' : '' ?>"><?= tl($today['profit']) ?></div></div>
    <div class="kpi"><div class="k-label">Sipariş</div><div class="k-value"><?= $today['count'] ?></div></div>
    <div class="kpi"><div class="k-label">Ort. Kâr Marjı</div><div class="k-value">%<?= number_format($avgMargin, 1, ',', '.') ?></div></div>
  </div>

  <?php if ($deltaSale !== null): ?>
    <p class="hint" style="margin:8px 0">Dün bu saate göre satış <b class="<?= $deltaSale >= 0 ? 'pos' : 'neg' ?>">%<?= number_format($deltaSale, 1, ',', '.') ?></b> (dün <?= tl($yesterday['sale']) ?>).</p>
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
      <tr><th>Saat</th><th>Satış</th><th>Net Kâr</th><th>Sipariş</th></tr>
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
    $byBarcode = products_by_barcode((int)$u['id'], $mp);
    $tq = db()->prepare("SELECT lines FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ?");
    $tq->execute([$u['id'], $mp, $todayStart]);
    foreach ($tq->fetchAll() as $o) {
        foreach ((json_decode((string)$o['lines'], true) ?: []) as $l) {
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
      <?php foreach (array_slice($orders, 0, 25) as $o): $pr = ['ok' => (int)$o['missing_cost'] === 0 && $o['profit'] !== null, 'profit' => (float)$o['profit']]; ?>
        <tr>
          <td style="font-size:12px"><?= h($o['order_number'] ?: $o['package_number'] ?: '—') ?></td>
          <td style="font-size:12px"><?= date('d.m H:i', (int)$o['order_date']) ?></td>
          <td><?= tl((float)$o['sale']) ?></td>
          <td>
            <?php if ($pr['ok']): ?><span class="<?= $pr['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($pr['profit']) ?></span> <?= src_badge((int)$o['is_final'] ? 'kesin' : 'tahmini', 'Kargo: ' . $o['cargo_note']) ?>
            <?php else: ?><span class="hint">maliyet eksik</span><?php endif; ?>
          </td>
          <td style="font-size:12px"><?= h($o['status'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="5" class="hint">Sipariş yok.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
