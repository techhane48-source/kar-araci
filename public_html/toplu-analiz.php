<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);
if (!$pro) {
    flash_set('error', 'Toplu kârlılık analizi Pro plan özelliğidir.');
    e_redirect('odeme.php');
}

const TIER_DEFS = [
    'super' => 'Süper Avantaj',
    'cok' => 'Çok Avantaj',
    'avantaj' => 'Avantaj',
];

$error = '';
$mp = $_POST['mp'] ?? ($_SESSION['toplu_mp'] ?? 'trendyol');
if (!in_array($mp, ['trendyol', 'hepsiburada', 'amazon', 'n11'], true)) $mp = 'trendyol';

function parse_price(string $raw): float
{
    return (float) str_replace(',', '.', str_replace(' ', '', trim($raw)));
}

// --- İndirme: seçilen barkodlar + oturumdaki analiz satırları → CSV ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu. Lütfen tekrar deneyin.');
        e_redirect('toplu-analiz.php');
    }
    $rows = $_SESSION['toplu_rows'] ?? [];
    $tierKeys = $_SESSION['toplu_tiers'] ?? ['fiyat'];
    $sel = array_fill_keys(array_map('strval', $_POST['sel'] ?? []), true);
    if (!$rows) { flash_set('error', 'Önce bir CSV analiz edin.'); e_redirect('toplu-analiz.php'); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="karfiyat-secimler.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $head = ['barkod', 'urun_adi', 'maliyet'];
    foreach ($tierKeys as $k) {
        $head[] = ($k === 'fiyat' ? 'fiyat' : $k . '_fiyat');
        $head[] = ($k === 'fiyat' ? 'net_kar' : $k . '_net_kar');
    }
    $head[] = 'secim';
    write_csv($out, $head, ';');
    foreach ($rows as $r) {
        $isSel = isset($sel[(string)$r['barcode']]) ? 1 : 0;
        $line = [$r['barcode'], $r['name'], $r['cost'] !== null ? number_format($r['cost'], 2, ',', '') : ''];
        foreach ($tierKeys as $k) {
            $t = $r['tiers'][$k] ?? null;
            $line[] = $t && $t['price'] > 0 ? number_format($t['price'], 2, ',', '') : '';
            $line[] = $t && $t['profit'] !== null ? number_format($t['profit'], 2, ',', '') : '';
        }
        $line[] = $isSel;
        write_csv($out, $line, ';');
    }
    fclose($out);
    exit;
}

// --- Analiz: CSV yükle + barkod eşleştir + kâr hesapla (tek veya çok kademe) → oturuma yaz → PRG ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $error = 'Oturum süresi doldu. Lütfen tekrar deneyin.';
    } elseif (empty($_FILES['csv']['tmp_name'])) {
        $error = 'CSV dosyası seçin.';
    } else {
        $file = fopen($_FILES['csv']['tmp_name'], 'r');
        $firstLine = fgets($file);
        rewind($file);
        $delim = (substr_count((string)$firstLine, ';') > substr_count((string)$firstLine, ',')) ? ';' : ',';
        $header = fgetcsv($file, null, $delim, '"', '\\');
        if ($header === false) {
            $error = 'Dosya boş veya okunamadı.';
        } else {
            $header = array_map(fn($x) => strtolower(trim($x)), $header);
            $fold = fn($x) => strtr($x, ['ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g','ı'=>'i','İ'=>'i','ö'=>'o','Ö'=>'o','ş'=>'s','Ş'=>'s','ü'=>'u','Ü'=>'u']);
            $norm = fn($x) => preg_replace('/[^a-z0-9]/', '', $fold($x));
            $map = [];
            $tiers = []; // key => sütun indeksi (super/cok/avantaj)
            foreach ($header as $i => $col) {
                $c = $norm($col);
                if (in_array($c, ['barkod', 'barcode', 'barkodno'], true)) $map['barcode'] = $i;
                elseif (in_array($c, ['urun', 'urunadi', 'name', 'urunad'], true)) $map['name'] = $i;
                elseif (str_contains($c, 'superavantaj')) $tiers['super'] = $i;
                elseif (str_contains($c, 'cokavantaj')) $tiers['cok'] = $i;
                elseif (str_contains($c, 'avantaj') && str_contains($c, 'fiyat')) $tiers['avantaj'] = $i;
                elseif (in_array($c, ['fiyat', 'price', 'satis', 'satisfiyati', 'satisfiyat'], true)) $map['price'] = $i;
            }

            $isMulti = count($tiers) > 0;
            if (!isset($map['barcode']) || (!$isMulti && !isset($map['price']))) {
                $error = 'CSV "barkod" ve (tek fiyat için "fiyat", ya da çok kademe için "süper_avantaj_fiyat / çok_avantaj_fiyat / avantaj_fiyat") kolonlarını içermeli.';
            } else {
                $stmt = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
                $stmt->execute([$u['id'], $mp]);
                $byBarcode = [];
                $byName = [];
                foreach ($stmt->fetchAll() as $p) {
                    if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;
                    $byName[mb_strtolower(trim($p['name']))] = $p;
                }

                $tierKeys = $isMulti ? array_keys($tiers) : ['fiyat'];
                $rows = [];
                while (($row = fgetcsv($file, null, $delim, '"', '\\')) !== false) {
                    $barcode = trim($row[$map['barcode']] ?? '');
                    $name = trim($row[$map['name'] ?? 0] ?? '');
                    if ($barcode === '' && $name === '') continue;

                    $p = null;
                    if ($barcode !== '' && isset($byBarcode[$barcode])) $p = $byBarcode[$barcode];
                    elseif ($name !== '' && isset($byName[mb_strtolower($name)])) $p = $byName[mb_strtolower($name)];

                    $cost = $p ? (float)$p['cost'] : null;
                    $hasCost = $p !== null && $cost > 0;

                    $rowTiers = [];
                    $best = null;
                    foreach ($tierKeys as $k) {
                        $colIdx = $isMulti ? $tiers[$k] : $map['price'];
                        $price = parse_price($row[$colIdx] ?? '');
                        $t = ['price' => $price, 'profit' => null, 'ratio' => null, 'margin' => null];
                        if ($hasCost && $price > 0) {
                            $r = calc_product_profit($p, $price);
                            if (!$r['ok']) continue;
                            $t = ['price' => $price, 'profit' => $r['profit'], 'ratio' => $r['ratio'], 'margin' => $r['margin']];
                            if ($best === null || $r['profit'] > $best) $best = $r['profit'];
                        }
                        $rowTiers[$k] = $t;
                    }
                    $rows[] = [
                        'barcode' => $barcode,
                        'name' => $name !== '' ? $name : ($p['name'] ?? ''),
                        'cost' => $cost,
                        'matched' => $p !== null,
                        'tiers' => $rowTiers,
                        'best' => $best,
                    ];
                }
                $_SESSION['toplu_rows'] = $rows;
                $_SESSION['toplu_tiers'] = $tierKeys;
                $_SESSION['toplu_mp'] = $mp;
                fclose($file);
                e_redirect('toplu-analiz.php');
            }
            fclose($file);
        }
    }
}

$rows = $_SESSION['toplu_rows'] ?? null;
$tierKeys = $_SESSION['toplu_tiers'] ?? ['fiyat'];
$pageTitle = 'Toplu Kârlılık Analizi — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Toplu Kârlılık Analizi</h1>
  </div>
  <p class="hint" style="max-width:760px">Trendyol'dan indirdiğin Excel'i CSV olarak kaydet ve yükle. Kâr&Fiyat, barkodu kayıtlı ürünlerinle eşleştirip <b>her fiyat kademesinin</b> net kârını hesaplar. Tek fiyat (<code>barkod; fiyat</code>) veya 3 kademe (<code>süper_avantaj_fiyat; çok_avantaj_fiyat; avantaj_fiyat</code>) desteklenir. Kârlıları işaretle, seçimleri indir.</p>

  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

  <div class="card" style="margin:16px 0">
    <h2>1. CSV Yükle</h2>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="analyze">
      <div class="field" style="margin:0"><label>Pazaryeri</label>
        <select name="mp" style="width:auto">
          <?php foreach (['trendyol'=>'Trendyol','hepsiburada'=>'Hepsiburada','amazon'=>'Amazon TR','n11'=>'n11'] as $c=>$l): ?>
            <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="margin:0"><label>CSV dosyası</label><input type="file" name="csv" accept=".csv" required style="width:auto;font-size:13px"></div>
      <button type="submit" class="btn-primary">Analiz Et</button>
    </form>
    <p class="hint" style="margin-top:10px">Başlıklar: <code>barkod</code> + tek <code>fiyat</code> ya da <code>süper_avantaj_fiyat / çok_avantaj_fiyat / avantaj_fiyat</code> (opsiyonel <code>urun_adi</code>). Ayraç virgül veya noktalı virgül.</p>
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
            <?php foreach ($tierKeys as $k): ?>
              <th><?= h($k === 'fiyat' ? 'Fiyat / Kâr' : TIER_DEFS[$k] . ' (fiyat→kâr)') ?></th>
            <?php endforeach; ?>
          </tr>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <?php if ($r['best'] !== null): ?>
                <input type="checkbox" name="sel[]" value="<?= h($r['barcode']) ?>" data-profit="<?= $r['best'] ?>">
              <?php endif; ?>
            </td>
            <td style="font-size:12px"><?= $r['barcode'] !== '' ? h($r['barcode']) : '—' ?></td>
            <td><?= h($r['name'] !== '' ? $r['name'] : '—') ?></td>
            <td><?= $r['cost'] !== null ? tl($r['cost']) : '<span class="hint">bulunamadı</span>' ?></td>
            <?php foreach ($tierKeys as $k): ?>
              <?php $t = $r['tiers'][$k] ?? null; ?>
              <td>
                <?php if ($t && $t['profit'] !== null): ?>
                  <div style="font-size:11px;color:var(--muted)"><?= tl($t['price']) ?></div>
                  <div class="<?= $t['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($t['profit']) ?></div>
                  <div style="font-size:11px;color:var(--muted)">%<?= number_format($t['margin'], 1, ',', '.') ?> marj</div>
                <?php elseif ($r['matched']): ?>
                  <span class="hint">maliyet yok</span>
                <?php else: ?>
                  <span class="hint">ürün bulunamadı</span>
                <?php endif; ?>
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
function selectProfitable(){
  document.querySelectorAll('input[name="sel[]"]').forEach(cb=>{ cb.checked = parseFloat(cb.dataset.profit) > 0; });
}
function clearSel(){
  document.querySelectorAll('input[name="sel[]"]').forEach(cb=>{ cb.checked = false; });
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
