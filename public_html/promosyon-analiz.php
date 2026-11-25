<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/xlsx.php';

$u = require_login();
$pro = is_pro($u);
if (!$pro) { flash_set('error', 'Promosyon Kârlılık Analizi Pro plan özelliğidir.'); e_redirect('odeme.php'); }

// Modüller: her biri aynı Excel round-trip akışı; fark "Kolon Seçimi"
$modules = [
    'komisyon' => ['title' => 'Ürün Komisyon Tarifesi', 'col' => '4. Fiyat Aralığı', 'desc' => 'Trendyol Ürün Komisyon Tarifesi Excel yükle. Her fiyat kademesindeki kârlılığı hesaplar.'],
    'plus' => ['title' => 'Plus Komisyon Tarifesi', 'col' => 'Trendyol Plus Fiyat Aralığı', 'desc' => 'Trendyol Plus Komisyon Tarifesi Excel yükle.'],
    'etiket' => ['title' => 'Avantajlı Ürün Etiketi', 'col' => 'Süper Avantaj Fiyat Aralığı', 'desc' => 'Avantajlı Ürün Etiketleri Excel yükle.'],
    'flash' => ['title' => 'Flaş Ürünler', 'col' => '24 saatlik / 3 saatlik', 'desc' => 'Flaş Ürünler Excel yükle.'],
    'indirim' => ['title' => 'İndirimler', 'col' => 'Kampanya Maksimum Fiyatı', 'desc' => 'İndirimler Excel yükle (bölge/çeşit/tür/min sepet).'],
    'kampanya' => ['title' => 'Kampanyalar', 'col' => 'Kampanya Maksimum Fiyatı', 'desc' => 'Kampanyalar Excel yükle (bölge/karşılama oranı/çeşit/tür/min sepet).'],
];
$mod = $_GET['mod'] ?? 'komisyon';
if (!isset($modules[$mod])) $mod = 'komisyon';

function parse_price2(string $raw): float { return parse_money_input($raw) ?? 0.0; }

// --- Analiz ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { flash_set('error', 'Oturum süresi doldu.'); e_redirect('promosyon-analiz.php?mod=' . $mod); }
    if (empty($_FILES['csv']['tmp_name'])) { flash_set('error', 'CSV dosyası seçin.'); e_redirect('promosyon-analiz.php?mod=' . $mod); }
    $mp = $_POST['mp'] ?? 'trendyol';
    $file = fopen($_FILES['csv']['tmp_name'], 'r');
    $firstLine = fgets($file); rewind($file);
    $delim = (substr_count((string)$firstLine, ';') > substr_count((string)$firstLine, ',')) ? ';' : ',';
    $header = fgetcsv($file, null, $delim, '"', '\\');
    if ($header === false) { flash_set('error', 'Dosya boş.'); e_redirect('promosyon-analiz.php?mod=' . $mod); }
    $header = array_map(fn($x) => strtolower(trim($x)), $header);
    $fold = fn($x) => strtr($x, ['ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g','ı'=>'i','İ'=>'i','ö'=>'o','Ö'=>'o','ş'=>'s','Ş'=>'s','ü'=>'u','Ü'=>'u']);
    $norm = fn($x) => preg_replace('/[^a-z0-9]/', '', $fold($x));
    $normHeader = array_map($norm, $header);
    $idx = static function (array $names) use ($normHeader): ?int {
        foreach ($names as $n) { $k = array_search($n, $normHeader, true); if ($k !== false) return $k; }
        return null;
    };
    // Barkod
    $bIdx = $idx(['barkod', 'barcode', 'sku']);
    // Tier columns: find any column containing 'fiyat' keyword variants
    $tierCols = [];
    $tierRates = [];
    foreach ($header as $i => $col) {
        $c = $norm($col);
        if (preg_match('/^(\d+)(?:fiyat|price)/', $c, $match)) $tierCols[$i] = $col;
        if (preg_match('/^(\d+)(?:komisyon|commission)/', $c, $match)) $tierRates[$match[1]] = $i;
        if (str_contains($c, 'fiyat') || str_contains($c, 'price')) {
            // skip base sale price column if it's just "satis"/"fiyat" without tier keyword
            $tierCols[$i] = $col;
        }
    }
    // If no tier columns found, treat a single 'fiyat' column as one tier
    if (!$tierCols) {
        $pIdx = $idx(['fiyat', 'price', 'satis', 'satisfiyati']);
        if ($pIdx !== null) $tierCols[$pIdx] = $header[$pIdx];
    }

    if ($bIdx === null || !$tierCols) {
        flash_set('error', 'CSV "barkod" ve en az bir "fiyat" kolonu içermeli.');
        e_redirect('promosyon-analiz.php?mod=' . $mod);
    }

    $stmt = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
    $stmt->execute([$u['id'], $mp]);
    $byBarcode = [];
    foreach ($stmt->fetchAll() as $p) if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;

    $rows = [];
    while (($row = fgetcsv($file, null, $delim, '"', '\\')) !== false) {
        $barcode = trim($row[$bIdx] ?? '');
        if ($barcode === '') continue;
        $p = $byBarcode[$barcode] ?? null;
        $cost = $p ? (float)$p['cost'] : null;
        $hasCost = $p !== null && $cost > 0;
        $tiers = [];
        $best = null;
        foreach ($tierCols as $colIdx => $colName) {
            $price = parse_price2($row[$colIdx] ?? '');
            $t = ['price' => $price, 'profit' => null, 'margin' => null, 'ratio' => null, 'col' => $colName];
            $rate = (float)($p['rate'] ?? 0);
            $rateKnown = !in_array($mod, ['komisyon','plus'], true);
            if (preg_match('/^(\d+)(?:fiyat|price)/', $norm($colName), $match) && isset($tierRates[$match[1]])) {
                $parsedRate = parse_money_input(str_replace('%','', (string)($row[$tierRates[$match[1]]] ?? '')));
                if ($parsedRate !== null && $parsedRate >= 0 && $parsedRate <= 100) {
                    $rate = $parsedRate; $rateKnown = true;
                }
            }
            if ($hasCost && $price > 0 && $rateKnown) {
                $r = product_profit($p, $price, $rate);
                $t = ['price' => $price, 'profit' => $r['profit'], 'margin' => $r['margin'], 'ratio' => $r['ratio'], 'col' => $colName];
                if ($best === null || $r['profit'] > $best) $best = $r['profit'];
            }
            $tiers[$colName] = $t;
        }
        $rows[] = ['barcode' => $barcode, 'name' => $p['name'] ?? $barcode, 'cost' => $cost, 'matched' => $p !== null, 'tiers' => $tiers, 'best' => $best];
    }
    fclose($file);
    $_SESSION['promo_rows'] = $rows;
    $_SESSION['promo_tiers'] = array_values($tierCols);
    $_SESSION['promo_mod'] = $mod;
    $_SESSION['promo_mp'] = $mp;
    e_redirect('promosyon-analiz.php?mod=' . $mod);
}

// --- İndir ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { flash_set('error', 'Oturum süresi doldu.'); e_redirect('promosyon-analiz.php?mod=' . $mod); }
    $rows = $_SESSION['promo_rows'] ?? [];
    $tierCols = $_SESSION['promo_tiers'] ?? [];
    $sel = array_fill_keys(array_map('strval', $_POST['sel'] ?? []), true);
    if (!$rows) { flash_set('error', 'Önce bir CSV analiz edin.'); e_redirect('promosyon-analiz.php?mod=' . $mod); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="promosyon-secimler.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $head = ['barkod', 'urun_adi', 'maliyet'];
    foreach ($tierCols as $c) { $head[] = $c; $head[] = $c . '_net_kar'; }
    $head[] = 'secim';
    fputcsv($out, $head, ';');
    foreach ($rows as $r) {
        $isSel = isset($sel[(string)$r['barcode']]) ? 1 : 0;
        $line = [$r['barcode'], $r['name'], $r['cost'] !== null ? number_format($r['cost'], 2, ',', '') : ''];
        foreach ($tierCols as $c) {
            $t = $r['tiers'][$c] ?? null;
            $line[] = $t && $t['price'] > 0 ? number_format($t['price'], 2, ',', '') : '';
            $line[] = $t && $t['profit'] !== null ? number_format($t['profit'], 2, ',', '') : '';
        }
        $line[] = $isSel;
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

$rows = $_SESSION['promo_rows'] ?? null;
$tierCols = $_SESSION['promo_tiers'] ?? [];
$mp = $_SESSION['promo_mp'] ?? 'trendyol';

$pageTitle = 'Promosyon Kârlılık Analizi — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Promosyon Kârlılık Analizi</h1>
  </div>
  <p class="hint" style="max-width:760px">Melontik'in killer özelliği: Trendyol'dan indirdiğin promosyon Excel'ini CSV olarak kaydet ve yükle. Kâr&Fiyat, barkodu kayıtlı ürünlerinle eşleştirip <b>her fiyat kademesinin</b> net kârını hesaplar. Kârlıları işaretle, seçimleri indir, Trendyol'a geri yükle.</p>

  <div class="card" style="margin:16px 0">
    <h2>Modül Seç</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach ($modules as $k => $m): ?>
        <a href="promosyon-analiz.php?mod=<?= $k ?>" class="<?= $mod === $k ? 'btn-primary' : 'btn-ghost' ?>" style="text-decoration:none;font-size:13px"><?= h($m['title']) ?></a>
      <?php endforeach; ?>
    </div>
    <p class="hint" style="margin-top:10px"><b><?= h($modules[$mod]['title']) ?></b> — <?= h($modules[$mod]['desc']) ?> Kolon: <code><?= h($modules[$mod]['col']) ?></code></p>
  </div>

  <?php if (isset($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
  <?php endif; ?>

  <div class="card" style="margin:16px 0">
    <h2>1. CSV Yükle</h2>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="analyze">
      <div class="field" style="margin:0"><label>Pazaryeri</label>
        <select name="mp" style="width:auto">
          <?php foreach (['trendyol'=>'Trendyol','hepsiburada'=>'Hepsiburada'] as $c=>$l): ?>
            <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="margin:0"><label>CSV dosyası</label><input type="file" name="csv" accept=".csv" required style="width:auto;font-size:13px"></div>
      <button type="submit" class="btn-primary">Analiz Et</button>
    </form>
    <p class="hint" style="margin-top:10px">Başlıklar: <code>barkod</code> + fiyat kademesi kolonları (örn. <code>süper_avantaj_fiyat</code>, <code>çok_avantaj_fiyat</code>, <code>avantaj_fiyat</code>, <code>4. fiyat aralığı</code>). Ayraç virgül veya noktalı virgül.</p>
  </div>

  <?php if ($rows !== null): ?>
  <div class="card table-card" style="margin:16px 0">
    <h2>2. Sonuçlar (<?= count($rows) ?> satır)</h2>
    <?php if (!$rows): ?>
      <p class="hint">CSV'de satır bulunamadı.</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="download">
        <div style="margin-bottom:10px;display:flex;gap:10px;flex-wrap:wrap">
          <button type="button" class="btn-ghost" onclick="selectProfitable()">Kârlıları Otomatik Seç</button>
          <button type="button" class="btn-ghost" onclick="clearSel()">Seçimi Temizle</button>
          <button type="submit" class="btn-primary">Seçimleri İndir (CSV)</button>
        </div>
        <table>
          <tr><th></th><th>Barkod</th><th>Ürün</th><th>Maliyet</th>
            <?php foreach ($tierCols as $c): ?><th><?= h($c) ?> (fiyat→kâr)</th><?php endforeach; ?>
          </tr>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php if ($r['best'] !== null): ?><input type="checkbox" name="sel[]" value="<?= h($r['barcode']) ?>" data-profit="<?= $r['best'] ?>"><?php endif; ?></td>
            <td style="font-size:12px"><?= h($r['barcode']) ?></td>
            <td><?= h($r['name']) ?></td>
            <td><?= $r['cost'] !== null ? tl($r['cost']) : '<span class="hint">bulunamadı</span>' ?></td>
            <?php foreach ($tierCols as $c): $t = $r['tiers'][$c] ?? null; ?>
              <td>
                <?php if ($t && $t['profit'] !== null): ?>
                  <div style="font-size:11px;color:var(--muted)"><?= tl($t['price']) ?></div>
                  <div class="<?= $t['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($t['profit']) ?></div>
                  <div style="font-size:11px;color:var(--muted)">%<?= number_format($t['margin'], 1, ',', '.') ?> marj</div>
                <?php elseif ($r['matched']): ?><span class="hint">maliyet yok</span>
                <?php else: ?><span class="hint">ürün bulunamadı</span><?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </table>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<script>
function selectProfitable(){ document.querySelectorAll('input[name="sel[]"]').forEach(cb=>{ cb.checked = parseFloat(cb.dataset.profit) > 0; }); }
function clearSel(){ document.querySelectorAll('input[name="sel[]"]').forEach(cb=>{ cb.checked = false; }); }
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
