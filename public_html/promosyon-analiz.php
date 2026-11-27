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
        $k = (string)($pick[$row['row']] ?? '');
        if ($k === '') continue;
        foreach ($row['options'] as $o) if ($o['key'] === $k && $o['write']) { $writes[$row['row']] = $o['write']; break; }
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

  <?php if (!$a): ?>
  <div class="card tariff-steps">
    <ol>
      <li><b>Trendyol'dan Excel'i al.</b> Satıcı Paneli → Promosyon &amp; Fiyat → <i>Ürün Komisyon Tarifeleri</i>, <i>Plus Komisyon Tarifeleri</i>, <i>Avantajlı Ürün Etiketleri</i> veya <i>Flaş Ürünler</i> → <i>Excel ile Güncelle</i> → <i>Excel Oluştur</i>, sonra listedeki dosyayı indir.</li>
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
    $suggested = count(array_filter($a['rows'], fn($r) => $r['suggest'] !== null));
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
          <th>Değiştirme</th>
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
              <td class="tariff-opt<?= $o && $row['suggest'] === $key ? ' is-suggest' : '' ?>">
              <?php if (!$o): ?><span class="hint">—</span>
              <?php else: ?>
                <label>
                  <input type="radio" name="pick[<?= (int)$row['row'] ?>]" value="<?= h($key) ?>"
                         data-profit="<?= $o['worst'] === null ? '' : h((string)$o['worst']) ?>"
                         data-suggest="<?= $row['suggest'] === $key ? '1' : '' ?>"
                         <?= $row['suggest'] === $key ? 'checked' : '' ?>>
                  <span class="price"><?= tl((float)$o['price']) ?></span>
                  <?php if ($o['note'] !== ''): ?><span class="hint"><?= h($o['note']) ?></span><?php endif; ?>
                </label>
                <div class="rates">
                <?php foreach ($o['rates'] as $period => $rate): $pf = $o['profits'][$period] ?? null; ?>
                  <div><span class="hint"><?= $period !== '' ? h($period) . ' ' : '' ?><?= $fmtRate($rate) ?></span>
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
            <td class="tariff-opt"><label><input type="radio" name="pick[<?= (int)$row['row'] ?>]" value="" data-profit="" <?= $none ? 'checked' : '' ?>> <span class="hint">olduğu gibi</span></label></td>
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
  var rows = Array.prototype.map.call(form.querySelectorAll('tbody tr'), function(tr){ return tr.querySelectorAll('input[type=radio]'); });
  function sum(){
    var n = 0, total = 0;
    rows.forEach(function(rs){ rs.forEach(function(r){ if (r.checked && r.value !== '') { n++; if (r.dataset.profit !== '') total += parseFloat(r.dataset.profit); } }); });
    document.getElementById('tariffSum').textContent = n + ' ürün değişecek · seçilenlerde adet başı kâr toplamı ' + total.toLocaleString('tr-TR', {style:'currency', currency:'TRY'});
  }
  form.addEventListener('change', sum);
  form.querySelectorAll('[data-act]').forEach(function(b){ b.addEventListener('click', function(){
    var act = b.dataset.act;
    rows.forEach(function(rs){
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
