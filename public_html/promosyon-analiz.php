<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tariff.php';

$u = require_login();
if (!is_pro($u)) { flash_set('error', 'Tarife ve promosyon analizi Pro plan özelliğidir.'); e_redirect('odeme.php'); }
$uid = (int)$u['id'];
$mp = 'trendyol';

$uploadDir = dirname(DB_PATH) . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0700, true);

$state = $_SESSION['tariff'] ?? null;
if ($state && (!is_file($state['path'] ?? '') || ($state['uid'] ?? 0) !== $uid)) { $state = null; unset($_SESSION['tariff']); }
if ($state) {
    $state['analysis'] = json_decode((string)@file_get_contents($state['path'] . '.json'), true);
    if (!is_array($state['analysis'])) { $state = null; unset($_SESSION['tariff']); }
}
// Bir günden eski yüklemeleri temizle
foreach (glob($uploadDir . DIRECTORY_SEPARATOR . 'tarife-*') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);

// --- Yükle ve analiz et ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { flash_set('error', 'Oturum süresi doldu, tekrar deneyin.'); e_redirect('promosyon-analiz.php'); }
    $f = $_FILES['xlsx'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        flash_set('error', 'Excel dosyası seçin.'); e_redirect('promosyon-analiz.php');
    }
    if ($f['size'] > 20 * 1024 * 1024) { flash_set('error', 'Dosya 20 MB\'tan büyük.'); e_redirect('promosyon-analiz.php'); }
    if ($state) { @unlink($state['path']); @unlink($state['path'] . '.json'); }
    $dest = $uploadDir . DIRECTORY_SEPARATOR . 'tarife-' . $uid . '-' . bin2hex(random_bytes(6)) . '.xlsx';
    if (!move_uploaded_file($f['tmp_name'], $dest)) { flash_set('error', 'Dosya kaydedilemedi.'); e_redirect('promosyon-analiz.php'); }
    try {
        $a = tariff_analyze($uid, $mp, $dest);
    } catch (Throwable $e) {
        @unlink($dest);
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Dosya okunamadı. Trendyol\'dan indirdiğiniz .xlsx dosyasını olduğu gibi yükleyin.');
        e_redirect('promosyon-analiz.php');
    }
    file_put_contents($dest . '.json', json_encode($a, JSON_UNESCAPED_UNICODE));
    $_SESSION['tariff'] = ['uid' => $uid, 'path' => $dest, 'name' => basename((string)$f['name']), 'at' => time()];
    e_redirect('promosyon-analiz.php');
}

// --- Sıfırla ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    if (csrf_verify($_POST['csrf'] ?? '') && $state) { @unlink($state['path']); @unlink($state['path'] . '.json'); }
    unset($_SESSION['tariff']);
    e_redirect('promosyon-analiz.php');
}

// --- Trendyol formatında indir ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { flash_set('error', 'Oturum süresi doldu, tekrar deneyin.'); e_redirect('promosyon-analiz.php'); }
    if (!$state) { flash_set('error', 'Önce bir tarife Excel\'i yükleyin.'); e_redirect('promosyon-analiz.php'); }
    $a = $state['analysis'];
    $pick = $_POST['pick'] ?? [];
    $writes = [];
    foreach ($a['rows'] as $row) {
        $keys = array_filter(array_map('strval', (array)($pick[$row['row']] ?? [])), fn($k) => $k !== '');
        foreach ($row['options'] as $o) {
            if (in_array($o['key'], $keys, true) && $o['write']) $writes[$row['row']] = ($writes[$row['row']] ?? []) + $o['write'];
        }
    }
    if (!$writes) { flash_set('error', 'Hiçbir üründe seçenek seçilmedi.'); e_redirect('promosyon-analiz.php'); }
    $tmp = tempnam(sys_get_temp_dir(), 'kf');
    try {
        tariff_export($state['path'], $a['sheet'], $writes, $tmp);
    } catch (Throwable $e) {
        @unlink($tmp); flash_set('error', 'Excel oluşturulamadı.'); e_redirect('promosyon-analiz.php');
    }
    $name = preg_replace('/\.xlsx$/i', '', $state['name']) . '-secimler.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

$a = $state['analysis'] ?? null;
if ($a) $a += ['multi' => false, 'campaigns' => []];
$tab = $_GET['tab'] ?? ($a ? 'excel' : 'excel');
$sim = null; $simIn = ['type' => 'yuzde', 'pct' => 10, 'min' => 500, 'tl' => 50, 'x' => 3, 'y' => 2, 'n' => 2];
if ($tab === 'indirim' && isset($_GET['type'])) {
    foreach ($simIn as $k => $v) if (isset($_GET[$k])) $simIn[$k] = $k === 'type' ? (string)$_GET[$k] : (float)str_replace(',', '.', (string)$_GET[$k]);
    if (!in_array($simIn['type'], ['yuzde', 'tl', 'xaly', 'adet'], true)) $simIn['type'] = 'yuzde';
    $simIn['pct'] = max(0, min(95, $simIn['pct']));
    $sim = discount_simulate($uid, $mp, $simIn);
}
$minMargin = (float)user_settings($uid)['min_margin'];
$fmtRate = fn(?float $r) => $r === null ? 'ürün oranı' : '%' . rtrim(rtrim(number_format($r, 1, ',', ''), '0'), ',');
$money = fn(?float $v) => $v === null ? '—' : tl($v);

$pageTitle = 'Tarife ve Promosyon Analizi — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap tariff">
  <div class="toolbar">
    <h1>Tarife ve Promosyon Analizi</h1>
  </div>

  <?php if (isset($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
  <?php endif; ?>

  <nav class="tariff-tabs">
    <a href="promosyon-analiz.php?tab=excel" class="<?= $tab !== 'indirim' ? 'on' : '' ?>">Trendyol Excel'i</a>
    <a href="promosyon-analiz.php?tab=indirim" class="<?= $tab === 'indirim' ? 'on' : '' ?>">İndirim simülasyonu</a>
  </nav>

  <?php if ($tab === 'indirim'): ?>
  <div class="card">
    <p class="hint" style="margin-top:0">Trendyol'un İndirimler sayfasında indirim oluşturmadan önce: her ürünün indirimli kârı, bugünle farkı ve zarar etmeden verilebilecek en yüksek indirim. Satıcı indirimi komisyona esas fiyatı düşürdüğü için, son yüklediğin Ürün Komisyon Tarifesi'ne göre ürün daha düşük komisyon aralığına geçebilir.</p>
    <form method="get" class="sim-form">
      <input type="hidden" name="tab" value="indirim">
      <label>İndirim türü
        <select name="type" id="simType">
          <?php foreach (['yuzde' => 'Sepette % indirim', 'tl' => 'X TL ve üzeri Y TL indirim', 'xaly' => 'X al Y öde', 'adet' => 'N adet ve üzeri % indirim'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $simIn['type'] === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select></label>
      <label data-for="yuzde adet">İndirim %<input type="number" name="pct" step="0.1" min="0" max="95" value="<?= h((string)$simIn['pct']) ?>"></label>
      <label data-for="adet">En az adet<input type="number" name="n" min="2" value="<?= (int)$simIn['n'] ?>"></label>
      <label data-for="tl">Sepet tutarı (TL)<input type="number" name="min" step="1" min="0" value="<?= h((string)$simIn['min']) ?>"></label>
      <label data-for="tl">İndirim (TL)<input type="number" name="tl" step="1" min="0" value="<?= h((string)$simIn['tl']) ?>"></label>
      <label data-for="xaly">Al<input type="number" name="x" min="2" value="<?= (int)$simIn['x'] ?>"></label>
      <label data-for="xaly">Öde<input type="number" name="y" min="1" value="<?= (int)$simIn['y'] ?>"></label>
      <button class="btn-primary">Hesapla</button>
    </form>
  </div>
  <?php if ($sim !== null):
    $withCost = array_filter($sim, fn($r) => $r['new'] !== null);
    $losers = count(array_filter($withCost, fn($r) => $r['new'] <= 0));
  ?>
  <p class="hint"><b><?= count($withCost) ?></b> ürün hesaplandı<?= $losers ? ' · <b class="neg">' . $losers . ' üründe zarar</b>' : '' ?>. Karşılaştırma aynı adetle, indirimsiz siparişe göre. En zararlı ürün en üstte.</p>
  <div class="card table-card">
    <table class="tariff-table">
      <thead><tr><th>Ürün</th><th>Fiyat</th><th>Sipariş</th><th>İndirimli birim</th><th>Komisyon</th><th>Bugün / adet</th><th>İndirimle / adet</th><th>Fark</th><th>Zararsız en fazla</th></tr></thead>
      <tbody>
      <?php foreach ($sim as $r): ?>
        <tr>
          <td class="tariff-prod"><div class="name"><?= h($r['name']) ?></div><div class="hint"><?= h($r['barcode']) ?></div></td>
          <td><?= tl($r['price']) ?></td>
          <td><?= (int)$r['qty'] ?> adet<?php if ($r['note'] !== ''): ?><div class="hint"><?= h($r['note']) ?></div><?php endif; ?></td>
          <td><?= tl($r['unit']) ?></td>
          <td><?php if ($r['new_rate'] !== null): ?>%<?= number_format($r['new_rate'], 1, ',', '') ?><?php if (abs($r['new_rate'] - $r['now_rate']) > 0.05): ?><div class="hint">bugün %<?= number_format($r['now_rate'], 1, ',', '') ?></div><?php endif; ?><?php endif; ?></td>
          <td><?= $r['now'] === null ? '—' : '<span class="' . ($r['now'] < 0 ? 'neg' : 'pos') . '">' . tl($r['now']) . '</span>' ?></td>
          <td><?= $r['new'] === null ? '—' : '<b class="' . ($r['new'] < 0 ? 'neg' : 'pos') . '">' . tl($r['new']) . '</b>' ?><?php if ($r['margin'] !== null): ?><div class="hint">%<?= number_format($r['margin'], 1, ',', '.') ?> marj</div><?php endif; ?></td>
          <td><?php if ($r['new'] !== null): $d = $r['new'] - $r['now']; ?><span class="<?= $d < 0 ? 'neg' : 'pos' ?>"><?= ($d >= 0 ? '+' : '') . tl($d) ?></span><?php endif; ?></td>
          <td><?= $r['max_pct'] === null ? '—' : '%' . number_format($r['max_pct'], 1, ',', '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php elseif (!$a): ?>
  <div class="card tariff-steps">
    <ol>
      <li><b>Trendyol'dan Excel'i al.</b> Satıcı Paneli → Promosyon &amp; Fiyat → <i>Ürün Komisyon Tarifeleri</i>, <i>Plus Komisyon Tarifeleri</i>, <i>Avantajlı Ürün Etiketleri</i> veya <i>Flaş Ürünler</i> → <i>Excel ile Güncelle</i> → <i>Excel Oluştur</i>, sonra listedeki dosyayı indir. Kampanyalar için: <i>Kampanyalar</i> sayfasında en fazla 3 kampanya işaretle → <i>Excel ile Toplu İşlem</i> → <i>Excel Oluştur</i>.</li>
      <li><b>Buraya yükle.</b> Dosyanın türü kendiliğinden tanınır. Her fiyat aralığı kendi komisyonuyla, ürününün maliyeti, KDV'si, kargo ve baremi, hizmet bedeli ve stopajla hesaplanır.</li>
      <li><b>Seç ve indir.</b> Kârlı seçenekler önceden işaretlenir. İndirdiğin dosya Trendyol'un kendi dosyasıdır, yalnızca seçtiğin satırların fiyat hücreleri doldurulur.</li>
      <li><b>Trendyol'a geri yükle.</b> Aynı pencerede <i>Excel Yükle</i>.</li>
    </ol>
    <form method="post" enctype="multipart/form-data" class="tariff-upload">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="analyze">
      <input type="file" name="xlsx" accept=".xlsx" required>
      <button type="submit" class="btn-primary">Yükle ve hesapla</button>
    </form>
    <p class="hint">Maliyeti girilmemiş ürünlerde kâr hesaplanmaz. Kargo için <a href="ayarlar.php">Ayarlar</a>'dan kargo tarifeni ve baremi yükle; yoksa ürün kartındaki kargo kullanılır.</p>
  </div>
  <?php else:
    $optHeads = [];
    foreach ($a['rows'] as $row) foreach ($row['options'] as $o) $optHeads[$o['key']] = $o['label'];
    $suggested = count(array_filter($a['rows'], fn($r) => $a['multi'] ? !empty($r['suggest_multi']) : $r['suggest'] !== null));
  ?>
  <div class="card tariff-head">
    <div>
      <div class="tariff-type"><?= h($a['title']) ?></div>
      <div class="hint"><?= h($state['name']) ?><?php if ($a['periods']): ?> · <?= h(implode(' / ', $a['periods'])) ?><?php endif; ?></div>
    </div>
    <div class="tariff-counts">
      <span><b><?= count($a['rows']) ?></b> ürün</span>
      <span><b><?= $suggested ?></b> önerilen</span>
      <?php if ($a['no_cost']): ?><span class="warn"><b><?= $a['no_cost'] ?></b> maliyetsiz</span><?php endif; ?>
      <?php if ($a['unmatched']): ?><span class="warn"><b><?= $a['unmatched'] ?></b> kayıtlı değil</span><?php endif; ?>
    </div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="reset"><button class="btn-ghost">Başka dosya</button></form>
  </div>

  <?php if ($a['type'] === 'komisyon'): ?>
    <p class="hint">Aralıklar müşterinin ödediği fiyata (sepet indirimleri düşülmüş) göredir. Her aralıkta o aralığın en yüksek fiyatı alınır ve 3 gün ile 4 gün komisyonunun <b>kötü olanıyla</b> değerlendirilir. Yeni TSF, ürünün mevcut indirim oranı korunarak yazılır; seçimler 7 günlük tarife olarak işaretlenir.</p>
  <?php elseif ($a['type'] === 'plus'): ?>
    <p class="hint">Plus fiyatı yalnız Trendyol Plus üyelerine gösterilir; diğer müşteriler mevcut fiyattan almaya devam eder.</p>
  <?php elseif ($a['type'] === 'kampanya'): ?>
    <p class="hint">Bir ürün birden fazla kampanyaya katılabilir. Katılım fiyatı kampanyanın izin verdiği en yüksek fiyattır. Kâr, indirimin Trendyol'un karşılamadığı kısmı düşülerek hesaplanır; komisyon bu tutar üzerinden ve son yüklenen komisyon tarifesindeki aralıktan alınır.</p>
  <?php elseif ($a['type'] === 'etiket'): ?>
    <p class="hint">Etiket komisyonu değiştirmez: ürünün mevcut komisyon oranı kullanılır (ekstreden öğrenilmiş oran varsa o).</p>
  <?php endif; ?>
  <p class="hint">Öneri kuralı: en az %<?= rtrim(rtrim(number_format($minMargin, 1, ',', ''), '0'), ',') ?> marj (Ayarlar'dan değişir) ve<?= $a['type'] === 'komisyon' ? ' bugünkü kârdan düşük olmayan' : '' ?> en kârlı seçenek.</p>

  <form method="post" id="tariffForm">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="export">
    <div class="tariff-actions">
      <button type="button" class="btn-ghost" data-act="suggest">Önerileri seç</button>
      <button type="button" class="btn-ghost" data-act="best">Her üründe en kârlıyı seç</button>
      <button type="button" class="btn-ghost" data-act="none">Hiçbirini değiştirme</button>
      <span class="tariff-sum" id="tariffSum"></span>
      <button type="submit" class="btn-primary">Trendyol'a yüklenecek Excel'i indir</button>
    </div>
    <div class="card table-card">
      <table class="tariff-table">
        <thead><tr>
          <th>Ürün</th><th>Bugün</th>
          <?php foreach ($optHeads as $label): ?><th><?= h($label) ?></th><?php endforeach; ?>
          <th><?= $a['multi'] ? '' : 'Değiştirme' ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($a['rows'] as $row): $none = $row['suggest'] === null; ?>
          <tr>
            <td class="tariff-prod">
              <div class="name"><?= h($row['name'] !== '' ? $row['name'] : $row['barcode']) ?></div>
              <div class="hint"><?= h($row['barcode']) ?></div>
              <?php if (!$row['matched']): ?><div class="tag warn">Kâr&amp;Fiyat'ta kayıtlı değil</div>
              <?php elseif (!$row['has_cost']): ?><div class="tag warn">maliyet girilmemiş</div><?php endif; ?>
              <?php if ($row['promos'] !== ''): ?><details class="hint"><summary>uygulanan promosyonlar</summary><?= nl2br(h($row['promos'])) ?></details><?php endif; ?>
            </td>
            <td class="tariff-now">
              <?= $money($row['current_price']) ?>
              <?php if ($row['current_rate'] !== null): ?><div class="hint"><?= $fmtRate($row['current_rate']) ?> komisyon</div><?php endif; ?>
              <?php if ($row['current_profit'] !== null): ?><div class="<?= $row['current_profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($row['current_profit']) ?></div><?php endif; ?>
            </td>
            <?php foreach ($optHeads as $key => $label):
              $o = null; foreach ($row['options'] as $x) if ($x['key'] === $key) { $o = $x; break; } ?>
              <td class="tariff-opt<?= $o && ($a['multi'] ? in_array($key, $row['suggest_multi'] ?? [], true) : $row['suggest'] === $key) ? ' is-suggest' : '' ?>">
              <?php if (!$o): ?><span class="hint">—</span>
              <?php else: ?>
                <label>
                  <?php $sug = $a['multi'] ? in_array($key, $row['suggest_multi'] ?? [], true) : $row['suggest'] === $key; ?>
                  <input type="<?= $a['multi'] ? 'checkbox' : 'radio' ?>" name="pick[<?= (int)$row['row'] ?>][]" value="<?= h($key) ?>"
                         data-profit="<?= $o['worst'] === null ? '' : h((string)$o['worst']) ?>"
                         data-suggest="<?= $sug ? '1' : '' ?>"
                         <?= !empty($o['ineligible']) ? 'disabled' : '' ?> <?= $sug ? 'checked' : '' ?>>
                  <?php if (empty($o['ineligible'])): ?><span class="price"><?= tl((float)$o['price']) ?></span><?php endif; ?>
                  <?php if ($o['note'] !== ''): ?><span class="hint"><?= h($o['note']) ?></span><?php endif; ?>
                </label>
                <?php if (isset($o['customer'])): ?><div class="hint">müşteri <?= tl((float)$o['customer']) ?> öder · sana <?= tl((float)$o['sale']) ?> kalır</div><?php endif; ?>
                <div class="rates">
                <?php foreach ($o['rates'] as $period => $rate): $pf = $o['profits'][$period] ?? null; ?>
                  <div><span class="hint"><?= $period !== '' ? h($period) . ' ' : '' ?><?= $rate === null && $o['rate_used'] !== null ? '%' . rtrim(rtrim(number_format((float)$o['rate_used'], 1, ',', ''), '0'), ',') . ' komisyon' : $fmtRate($rate) ?></span>
                    <?php if ($pf !== null): ?><b class="<?= $pf < 0 ? 'neg' : 'pos' ?>"><?= tl($pf) ?></b><?php endif; ?></div>
                <?php endforeach; ?>
                </div>
                <?php if (!empty($o['active'])): ?><div class="tag">şu an seçili</div><?php endif; ?>
                <?php if ($o['worst'] !== null && $row['current_profit'] !== null): $d = $o['worst'] - $row['current_profit']; ?>
                  <div class="hint">bugüne göre <span class="<?= $d < 0 ? 'neg' : 'pos' ?>"><?= ($d >= 0 ? '+' : '') . tl($d) ?></span><?= $a['type'] === 'plus' || $a['type'] === 'flas' ? ' · satış adedi artışıyla telafi edilmeli' : '' ?></div>
                <?php endif; ?>
                <?php if ($o['margin'] !== null): ?><div class="hint">%<?= number_format($o['margin'], 1, ',', '.') ?> marj<?= $o['cargo_note'] !== '' ? ' · kargo: ' . h($o['cargo_note']) : '' ?></div><?php endif; ?>
              <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td class="tariff-opt"><?php if (!$a['multi']): ?><label><input type="radio" name="pick[<?= (int)$row['row'] ?>][]" value="" data-profit="" <?= $none ? 'checked' : '' ?>> <span class="hint">olduğu gibi</span></label><?php else: ?><span class="hint">işaretsizler katılmaz</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
  <?php endif; ?>
</div>
<script>
(function(){
  var form = document.getElementById('tariffForm'); if (!form) return;
  var rows = Array.prototype.map.call(form.querySelectorAll('tbody tr'), function(tr){ return tr.querySelectorAll('input[type=radio],input[type=checkbox]'); });
  function sum(){
    var n = 0, total = 0;
    rows.forEach(function(rs){ rs.forEach(function(r){ if (r.checked && r.value !== '') { n++; if (r.dataset.profit !== '') total += parseFloat(r.dataset.profit); } }); });
    document.getElementById('tariffSum').textContent = n + ' ürün değişecek · seçilenlerde adet başı kâr toplamı ' + total.toLocaleString('tr-TR', {style:'currency', currency:'TRY'});
  }
  form.addEventListener('change', sum);
  form.querySelectorAll('[data-act]').forEach(function(b){ b.addEventListener('click', function(){
    var act = b.dataset.act;
    rows.forEach(function(rs){
      if (rs.length && rs[0].type === 'checkbox') {
        rs.forEach(function(r){ if (r.disabled) return;
          r.checked = act === 'suggest' ? !!r.dataset.suggest : act === 'best' ? (r.dataset.profit !== '' && parseFloat(r.dataset.profit) > 0) : false; });
        return;
      }
      var pick = rs[rs.length - 1];
      if (act === 'suggest') rs.forEach(function(r){ if (r.dataset.suggest) pick = r; });
      if (act === 'best') { var best = -Infinity; rs.forEach(function(r){ if (r.dataset.profit !== '' && parseFloat(r.dataset.profit) > best && parseFloat(r.dataset.profit) > 0) { best = parseFloat(r.dataset.profit); pick = r; } }); }
      pick.checked = true;
    });
    sum();
  }); });
  sum();
})();
</script>
<script>
(function(){ var sel = document.getElementById('simType'); if (!sel) return;
  function upd(){ document.querySelectorAll('.sim-form [data-for]').forEach(function(l){ l.style.display = (' ' + l.dataset.for + ' ').indexOf(' ' + sel.value + ' ') >= 0 ? '' : 'none'; }); }
  sel.addEventListener('change', upd); upd(); })();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
