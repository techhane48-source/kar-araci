<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/xlsx.php';

$u = require_login();
$uid = (int)$u['id'];
$pro = is_pro($u);

/** Bu kullanıcının bağlı pazaryerlerinde son 120 günün kârını yeniden hesaplar. */
function recompute_all(int $uid): int
{
    $n = 0;
    $st = db()->prepare("SELECT DISTINCT marketplace FROM orders WHERE user_id = ?");
    $st->execute([$uid]);
    foreach (array_column($st->fetchAll(), 'marketplace') as $mp) {
        $n += recompute_order_profits($uid, (string)$mp, time() - 120 * 86400)['count'];
    }
    return $n;
}

function parse_num_tr($s): ?float
{
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace(['₺', 'TL', ' '], '', $s);
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s);   // 1.234,56
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : null;
}

$carriers = ['TEX' => 'Trendyol Express', 'ARAS' => 'Aras', 'YURTICI' => 'Yurtiçi', 'PTT' => 'PTT', 'SURAT' => 'Sürat', 'MNG' => 'MNG / DHL eCommerce', 'HOROZ' => 'Horoz'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { flash_set('error', 'Oturum süresi doldu, tekrar deneyin.'); e_redirect('ayarlar.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'genel') {
        save_user_settings($uid, [
            'zero_negative_vat' => isset($_POST['zero_negative_vat']),
            'apply_stopaj' => isset($_POST['apply_stopaj']),
            'stopaj_rate' => parse_num_tr($_POST['stopaj_rate'] ?? '1') ?? 1,
            'fixed_cost_per_order' => parse_num_tr($_POST['fixed_cost_per_order'] ?? '0') ?? 0,
            'apply_return_rate' => isset($_POST['apply_return_rate']),
            'default_return_rate' => parse_num_tr($_POST['default_return_rate'] ?? '0') ?? 0,
            'default_carrier' => array_key_exists($_POST['default_carrier'] ?? '', $carriers) ? $_POST['default_carrier'] : 'TEX',
            'barem_enabled' => isset($_POST['barem_enabled']),
            'min_margin' => parse_num_tr($_POST['min_margin'] ?? '10') ?? 10,
        ]);
        $n = recompute_all($uid);
        flash_set('success', "Ayarlar kaydedildi. $n siparişin kârı yeni ayarlarla yeniden hesaplandı.");
        e_redirect('ayarlar.php');
    }

    if ($action === 'tarife') {
        $carrier = array_key_exists($_POST['carrier'] ?? '', $carriers) ? $_POST['carrier'] : 'TEX';
        $vatIncluded = isset($_POST['kdv_dahil']);
        $rows = [];
        if (!empty($_FILES['dosya']['tmp_name'])) {
            $name = strtolower($_FILES['dosya']['name'] ?? '');
            if (str_ends_with($name, '.xlsx')) {
                try { $x = xlsx_read($_FILES['dosya']['tmp_name']); $rows = array_merge([$x['headers']], $x['rows']); }
                catch (Throwable $e) { flash_set('error', 'Excel okunamadı: ' . $e->getMessage()); e_redirect('ayarlar.php#kargo'); }
            } else {
                $fh = fopen($_FILES['dosya']['tmp_name'], 'r');
                $first = (string)fgets($fh); rewind($fh);
                $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
                while (($r = fgetcsv($fh, null, $delim, '"', '\\')) !== false) $rows[] = $r;
                fclose($fh);
            }
        } elseif (trim($_POST['yapistir'] ?? '') !== '') {
            foreach (preg_split('/\R/', trim($_POST['yapistir'])) as $line) {
                $rows[] = preg_split('/[\t;]+|\s{2,}/', trim($line));
            }
        }
        // Kolonları bul: tek "desi" kolonu ya da "desi başlangıç / bitiş"; fiyat kolonu
        $parsed = [];
        $norm = fn($x) => preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower((string)$x), ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'i̇' => 'i']));
        $hdr = $rows ? array_map($norm, $rows[0]) : [];
        $iFrom = $iTo = $iDesi = $iPrice = null;
        foreach ($hdr as $i => $h) {
            if ($iFrom === null && (str_contains($h, 'desibas') || str_contains($h, 'mindesi') || $h === 'desiden')) $iFrom = $i;
            elseif ($iTo === null && (str_contains($h, 'desibit') || str_contains($h, 'maxdesi') || $h === 'desiye')) $iTo = $i;
            elseif ($iDesi === null && str_contains($h, 'desi')) $iDesi = $i;
            elseif ($iPrice === null && (str_contains($h, 'fiyat') || str_contains($h, 'ucret') || str_contains($h, 'tutar') || str_contains($h, 'price'))) $iPrice = $i;
        }
        $hasHeader = $iPrice !== null && ($iDesi !== null || $iFrom !== null);
        if (!$hasHeader) { $iDesi = 0; $iPrice = 1; $iFrom = $iTo = null; }
        foreach (array_slice($rows, $hasHeader ? 1 : 0) as $r) {
            $price = parse_num_tr($r[$iPrice] ?? '');
            if ($iFrom !== null) { $from = parse_num_tr($r[$iFrom] ?? ''); $to = parse_num_tr($r[$iTo ?? $iFrom] ?? ''); }
            else { $from = $to = parse_num_tr($r[$iDesi] ?? ''); }
            if ($price === null || $from === null || $price <= 0) continue;
            if ($to === null || $to < $from) $to = $from;
            $parsed[] = [$from, $to, $vatIncluded ? round($price / 1.2, 4) : $price];
        }
        if (!$parsed) {
            flash_set('error', 'Tarifede satır bulunamadı. İlk kolon desi, ikinci kolon fiyat olmalı (ya da "Desi" ve "Fiyat" başlıkları).');
            e_redirect('ayarlar.php#kargo');
        }
        usort($parsed, fn($a, $b) => $a[0] <=> $b[0]);
        $db = db();
        $db->beginTransaction();
        $db->prepare("DELETE FROM cargo_tariffs WHERE user_id = ? AND carrier = ?")->execute([$uid, $carrier]);
        $ins = $db->prepare("INSERT INTO cargo_tariffs (user_id, carrier, desi_from, desi_to, price_ex_vat) VALUES (?,?,?,?,?)");
        foreach ($parsed as [$f, $t, $p]) $ins->execute([$uid, $carrier, $f, $t, $p]);
        $db->commit();
        $n = recompute_all($uid);
        flash_set('success', count($parsed) . " kademeli {$carriers[$carrier]} tarifesi kaydedildi. $n siparişin kargo tahmini güncellendi.");
        e_redirect('ayarlar.php#kargo');
    }

    if ($action === 'tarife_sil') {
        $carrier = (string)($_POST['carrier'] ?? '');
        db()->prepare("DELETE FROM cargo_tariffs WHERE user_id = ? AND carrier = ?")->execute([$uid, $carrier]);
        recompute_all($uid);
        flash_set('success', 'Tarife silindi.');
        e_redirect('ayarlar.php#kargo');
    }

    if ($action === 'barem') {
        $carrier = array_key_exists($_POST['carrier'] ?? '', $carriers) ? $_POST['carrier'] : 'TEX';
        $db = db();
        $db->beginTransaction();
        $db->prepare("DELETE FROM cargo_barem WHERE user_id = ? AND carrier = ?")->execute([$uid, $carrier]);
        $ins = $db->prepare("INSERT INTO cargo_barem (user_id, carrier, min_price, max_price, max_desi, price_ex_vat) VALUES (?,?,?,?,?,?)");
        $k = 0;
        foreach (($_POST['b_min'] ?? []) as $i => $min) {
            $min = parse_num_tr($min); $max = parse_num_tr($_POST['b_max'][$i] ?? ''); $md = parse_num_tr($_POST['b_desi'][$i] ?? '');
            $pr = parse_num_tr($_POST['b_fiyat'][$i] ?? '');
            if ($min === null || $max === null || $pr === null || $max <= $min) continue;
            $ins->execute([$uid, $carrier, $min, $max, $md ?? 10, $pr]);
            $k++;
        }
        $db->commit();
        $n = recompute_all($uid);
        flash_set('success', "$k barem satırı kaydedildi. $n siparişin kargo tahmini güncellendi.");
        e_redirect('ayarlar.php#barem');
    }
}

$s = user_settings($uid);
$tariffs = db()->prepare("SELECT carrier, COUNT(*) n, MIN(desi_from) dmin, MAX(desi_to) dmax, MIN(price_ex_vat) pmin, MAX(price_ex_vat) pmax
                          FROM cargo_tariffs WHERE user_id = ? GROUP BY carrier");
$tariffs->execute([$uid]);
$tariffs = $tariffs->fetchAll();
$baremCarrier = array_key_exists($_GET['barem'] ?? '', $carriers) ? $_GET['barem'] : $s['default_carrier'];
$bst = db()->prepare("SELECT * FROM cargo_barem WHERE user_id = ? AND carrier = ? ORDER BY min_price");
$bst->execute([$uid, $baremCarrier]);
$barem = $bst->fetchAll();
$fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',');

$pageTitle = 'Ayarlar — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar"><h1>Hesaplama Ayarları</h1></div>
  <p class="hint" style="max-width:760px;margin-bottom:16px">Buradaki her değişiklikten sonra son 120 günün siparişleri otomatik olarak yeniden hesaplanır. Faturası gelmiş (kesinleşmiş) kalemler değişmez; yalnızca tahminler güncellenir.</p>

  <div class="grid" style="align-items:start">
    <div class="card">
      <h2>Kâr hesabı</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="genel">
        <label class="check"><input type="checkbox" name="zero_negative_vat" <?= $s['zero_negative_vat'] ? 'checked' : '' ?>> Negatif net KDV'yi 0 al</label>
        <p class="hint">Kesintilerin KDV'si satış KDV'sinden büyük çıkarsa fark sonraki dönemlere devreder; işaretliyken o siparişin kârına eklenmez.</p>
        <label class="check"><input type="checkbox" name="apply_stopaj" <?= $s['apply_stopaj'] ? 'checked' : '' ?>> Stopaj düş</label>
        <div class="field"><label>Stopaj oranı (%)</label><input type="text" inputmode="decimal" name="stopaj_rate" value="<?= h($fmt($s['stopaj_rate'])) ?>" style="width:120px"></div>
        <div class="field"><label>Sipariş başı sabit gider (₺, KDV dahil)</label><input type="text" inputmode="decimal" name="fixed_cost_per_order" value="<?= h($fmt($s['fixed_cost_per_order'])) ?>" style="width:160px"><p class="hint">Koli, bant, etiket, paketleme işçiliği.</p></div>
        <label class="check"><input type="checkbox" name="apply_return_rate" <?= $s['apply_return_rate'] ? 'checked' : '' ?>> Kesinleşmemiş siparişlerde beklenen iade kaybını düş</label>
        <div class="field"><label>Varsayılan iade oranı (%)</label><input type="text" inputmode="decimal" name="default_return_rate" value="<?= h($fmt($s['default_return_rate'])) ?>" style="width:120px"><p class="hint">Kayıp = iade oranı × (gidiş + dönüş kargosu + hizmet bedeli). İade gerçekleşince gerçek tutar kullanılır.</p></div>
        <div class="field"><label>Minimum kâr marjı uyarısı (%)</label><input type="text" inputmode="decimal" name="min_margin" value="<?= h($fmt($s['min_margin'])) ?>" style="width:120px"></div>
        <div class="field"><label>Varsayılan kargo firması</label>
          <select name="default_carrier" style="width:auto"><?php foreach ($carriers as $k => $v): ?><option value="<?= $k ?>" <?= $s['default_carrier'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
        </div>
        <label class="check"><input type="checkbox" name="barem_enabled" <?= $s['barem_enabled'] ? 'checked' : '' ?>> Düşük tutarlı sepetlerde barem kargo fiyatını kullan</label>
        <button type="submit" class="btn-primary" style="margin-top:12px">Kaydet ve yeniden hesapla</button>
      </form>
    </div>

    <div>
      <div class="card" id="kargo">
        <h2>Kargo tarifesi (desiye göre)</h2>
        <p class="hint">Kargo faturası gelmemiş siparişlerde kargo bu tablodan tahmin edilir. Trendyol satıcı panelindeki anlaşmalı kargo fiyat listesini yükle: ilk kolon <b>desi</b>, ikinci kolon <b>fiyat</b>. Excel (.xlsx), CSV ya da kopyala-yapıştır olur.</p>
        <?php if ($tariffs): ?>
        <table style="margin:8px 0 14px">
          <tr><th>Firma</th><th>Kademe</th><th>Desi</th><th>Fiyat (KDV hariç)</th><th></th></tr>
          <?php foreach ($tariffs as $t): ?>
          <tr><td><?= h($carriers[$t['carrier']] ?? $t['carrier']) ?></td><td><?= (int)$t['n'] ?></td><td><?= $fmt($t['dmin']) ?>–<?= $fmt($t['dmax']) ?></td><td><?= tl((float)$t['pmin']) ?> – <?= tl((float)$t['pmax']) ?></td>
            <td><form method="post" onsubmit="return confirm('Bu tarife silinsin mi?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="tarife_sil"><input type="hidden" name="carrier" value="<?= h($t['carrier']) ?>"><button class="btn-ghost" type="submit">Sil</button></form></td></tr>
          <?php endforeach; ?>
        </table>
        <?php else: ?>
          <div class="alert alert-info" style="margin:8px 0 14px">Henüz kargo tarifesi yok. Tarife yüklenene kadar kargo, ürün kartındaki kargo tutarından tahmin edilir; o da yoksa 0 alınır ve sipariş "kargo bilgisi yok" diye işaretlenir.</div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="tarife">
          <div class="field"><label>Kargo firması</label><select name="carrier" style="width:auto"><?php foreach ($carriers as $k => $v): ?><option value="<?= $k ?>" <?= $s['default_carrier'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Dosya</label><input type="file" name="dosya" accept=".xlsx,.csv,.txt"></div>
          <div class="field"><label>ya da yapıştır (her satır: desi ve fiyat)</label><textarea name="yapistir" rows="5" placeholder="1;77,54&#10;2;83,10&#10;3;89,90"></textarea></div>
          <label class="check"><input type="checkbox" name="kdv_dahil"> Fiyatlar KDV dahil</label>
          <button type="submit" class="btn-primary" style="margin-top:10px">Tarifeyi kaydet</button>
        </form>
      </div>

      <div class="card" id="barem" style="margin-top:16px">
        <h2>Barem kargo fiyatları</h2>
        <p class="hint">Düşük tutarlı sepetlerde Trendyol desiden bağımsız sabit kargo fiyatı uygular. Aralıkları ve fiyatları satıcı panelindeki güncel duyurudan gir (KDV hariç). Sepet bu aralıktaysa ve desi sınırı aşılmıyorsa tarife yerine barem kullanılır.</p>
        <form method="get" style="margin:8px 0"><select name="barem" style="width:auto" onchange="this.form.submit()"><?php foreach ($carriers as $k => $v): ?><option value="<?= $k ?>" <?= $baremCarrier === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="barem">
          <input type="hidden" name="carrier" value="<?= h($baremCarrier) ?>">
          <table>
            <tr><th>Sepet en az (₺)</th><th>Sepet en çok (₺)</th><th>En fazla desi</th><th>Kargo (KDV hariç ₺)</th></tr>
            <?php $bRows = $barem ?: []; for ($i = 0; $i < max(3, count($bRows) + 1); $i++): $b = $bRows[$i] ?? null; ?>
            <tr>
              <td><input type="text" inputmode="decimal" name="b_min[]" value="<?= $b ? h($fmt($b['min_price'])) : '' ?>" style="width:100px"></td>
              <td><input type="text" inputmode="decimal" name="b_max[]" value="<?= $b ? h($fmt($b['max_price'])) : '' ?>" style="width:100px"></td>
              <td><input type="text" inputmode="decimal" name="b_desi[]" value="<?= $b ? h($fmt($b['max_desi'])) : '' ?>" placeholder="10" style="width:80px"></td>
              <td><input type="text" inputmode="decimal" name="b_fiyat[]" value="<?= $b ? h($fmt($b['price_ex_vat'])) : '' ?>" style="width:110px"></td>
            </tr>
            <?php endfor; ?>
          </table>
          <button type="submit" class="btn-primary" style="margin-top:10px">Baremi kaydet</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
