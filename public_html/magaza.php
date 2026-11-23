<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$error = '';
$ok = '';

// Hangi pazaryeriyle çalışılıyor
$mp = $_GET['mp'] ?? 'trendyol';
if (!in_array($mp, ['trendyol', 'hepsiburada', 'amazon', 'n11'], true)) $mp = 'trendyol';

// Şu an yalnızca Trendyol API entegrasyonu destekleniyor; diğerleri "yakında" olarak gösterilir.
$supported = ($mp === 'trendyol');

$int = get_integration((int)$u['id'], $mp);
$hasConnection = $int !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    if (!empty($u['is_demo'])) {
        $error='Demo hesabında gerçek mağaza bağlantısı ve fiyat güncellemesi yapılamaz.';
    } elseif (!csrf_verify($_POST['csrf'] ?? '')) {
        $error = 'Oturum süresi doldu. Lütfen tekrar deneyin.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'connect') {
            $sellerId = trim($_POST['seller_id'] ?? '');
            $apiKey = trim($_POST['api_key'] ?? '');
            $apiSecret = trim($_POST['api_secret'] ?? '');
            $stage = isset($_POST['stage']) && $_POST['stage'] === '1';

            if ($sellerId === '' || $apiKey === '' || $apiSecret === '') {
                $error = 'Satıcı ID, API key ve API secret alanlarının üçünü de doldurun.';
            } else {
                // Bağlantıyı gerçekten test et
                require_once __DIR__ . '/../includes/trendyol.php';
                $client = new TrendyolClient($sellerId, $apiKey, $apiSecret, $stage);
                $test = $client->testConnection();

                if (!$test['ok']) {
                    $error = 'Bağlantı kurulamadı: ' . ($test['error'] ?? 'Bilinmeyen hata');
                } else {
                    save_integration((int)$u['id'], $mp, $sellerId, $apiKey, $apiSecret, $stage);
                    $ok = 'Mağazanız başarıyla bağlandı!';
                    $int = get_integration((int)$u['id'], $mp);
                    $hasConnection = true;
                }
            }
        } elseif ($action === 'sync') {
            $res = sync_products((int)$u['id'], $mp);
            if ($res['ok']) {
                $ok = "Senkronizasyon tamamlandı: {$res['inserted']} yeni ürün eklendi, {$res['updated']} mevcut ürün güncellendi (toplam {$res['total']} ürün çekildi).";
            } else {
                $error = 'Senkronizasyon hatası: ' . ($res['error'] ?? 'Bilinmeyen hata');
            }
        } elseif ($action === 'settlements') {
            $res = sync_settlements((int)$u['id'], $mp);
            if ($res['ok']) {
                $ok = "Cari hesap ekstresi (resmi komisyon/kargo/hakediş) çekildi: {$res['records']} kayıt, {$res['rates_updated']} ürünün komisyon oranı resmi değerle güncellendi.";

            } else {
                $error = 'Cari hesap ekstresi hatası: ' . ($res['error'] ?? 'Bilinmeyen hata');
            }
        } elseif ($action === 'service_fees') {
            $res = sync_platform_service_fees((int)$u['id'], $mp);
            if ($res['ok']) {
                $ok = "Platform hizmet bedeli çekildi: {$res['records']} hareket.";
            } else {
                $error = 'Platform hizmet bedeli hatası: ' . ($res['error'] ?? 'Bilinmeyen hata');
            }
        } elseif ($action === 'disconnect') {
            delete_integration((int)$u['id'], $mp);
            $int = null;
            $hasConnection = false;
            $ok = 'Mağaza bağlantısı kaldırıldı.';
        } elseif ($action === 'update_price') {
            $barcode=trim($_POST['barcode']??'');
            $raw=str_replace(',','.',trim($_POST['new_price']??''));
            if (!is_numeric($raw)) $error='Geçerli fiyat girin.';
            else {
                $res=submit_price_update((int)$u['id'],$mp,$barcode,(float)$raw);
                if ($res['ok']) $ok='Fiyat isteği alındı; Trendyol onayı bekleniyor. Onaydan sonra yerel fiyat güncellenecek.';
                else $error=$res['error'];
            }
        } elseif ($action === 'check_price_updates') {
            $res=process_price_updates((int)$u['id'],$mp);
            if ($res['ok']) $ok="{$res['confirmed']} onaylandı, {$res['failed']} sonuçlandırılamadı/reddedildi, {$res['pending']} bekliyor.";
            else $error=$res['error'];
        }
    }
}

// POST sonrasında başarı/hata zamanlarını güncel bağlantıdan oku.
$int = get_integration((int)$u['id'], $mp);
$hasConnection = $int !== null;

// Fiyat yönetimi: YEREL senkronize ürünler (canlı Trendyol çekimi YOK — 14k+ ürün için performans).
// Güncel fiyat/stok bilgisi "Ürünleri Çek" ile yerel DB'ye işlenir; burada o veri gösterilir.
$priceRows = [];
$priceLiveError = '';
$priceTotalPages = 1;
$pricePage = 1;
if ($supported && $hasConnection) {
    $pcount = db()->prepare("SELECT COUNT(*) c FROM products WHERE user_id = ? AND marketplace = ?");
    $pcount->execute([(int)$u['id'], $mp]);
    $priceTotal = (int)$pcount->fetch()['c'];

    $perPage = 100;
    $pricePage = max(1, (int)($_GET['ppage'] ?? 1));
    $priceTotalPages = max(1, (int)ceil($priceTotal / $perPage));
    $pricePage = min($pricePage, $priceTotalPages);
    $offset = ($pricePage - 1) * $perPage;

    $pstmt = db()->prepare("SELECT id, name, barcode, price, cost, quantity FROM products WHERE user_id = ? AND marketplace = ? ORDER BY id LIMIT $perPage OFFSET $offset");
    $pstmt->execute([(int)$u['id'], $mp]);
    foreach ($pstmt->fetchAll() as $p) {
        $priceRows[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'price' => (float)$p['price'],
            'cost' => (float)$p['cost'],
            'barcode' => (string)$p['barcode'],
            'quantity' => (int)$p['quantity'],
            'listPrice' => (float)$p['price'],
            'livePrice' => (float)$p['price'],
        ];
    }
}

$updates=db()->prepare('SELECT * FROM price_updates WHERE user_id=? AND marketplace=? ORDER BY id DESC LIMIT 20');
$updates->execute([$u['id'],$mp]);$priceUpdates=$updates->fetchAll();

$serviceFees = [];
if ($supported && $hasConnection) {
    $sfq = db()->prepare("SELECT * FROM platform_service_fees WHERE user_id = ? AND marketplace = ? AND seller_id = ? AND stage = ? ORDER BY transaction_date DESC, provider_id DESC LIMIT 200");
    $sfq->execute([(int)$u['id'], $mp, (string)$int['seller_id'], (int)$int['stage']]);
    $serviceFees = $sfq->fetchAll();
}

require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <?php if ($priceUpdates): ?>
  <div class="card">
    <h2>Fiyat Güncelleme Sonuçları</h2>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="check_price_updates"><button class="btn-ghost">İşlem sonuçlarını kontrol et</button></form>
    <table><tr><th>Barkod</th><th>İstenen Fiyat</th><th>Durum</th></tr>
    <?php foreach ($priceUpdates as $update): ?>
      <tr><td><?= h($update['barcode']) ?></td><td><?= tl((float)$update['sale_price']) ?></td><td><?= h(['pending'=>'Trendyol onayı bekleniyor','confirmed'=>'Onaylandı','failed'=>'Reddedildi','stale'=>'Bağlantı değişti','unknown'=>'Mağazadan doğrulama gerekiyor','submitting'=>'Gönderim sonucu bekleniyor','reconciled'=>'Mağazadaki güncel fiyat okundu'][$update['status']]??$update['status']) ?><?= $update['failure_reason'] ? ': '.h($update['failure_reason']) : '' ?></td></tr>
    <?php endforeach; ?></table>
  </div>
  <?php endif; ?>
  <h1>Mağaza Bağlama</h1>
  <p class="hint">Pazar yerinizi bağlayın; ürünleriniz, siparişleriniz ve kârlılığınız otomatik işlensin. API bilgileriniz yalnızca size aittir ve şifreli saklanır.</p>

  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-ok"><?= h($ok) ?></div><?php endif; ?>

  <div class="tabs">
    <?php foreach (['trendyol' => 'Trendyol', 'hepsiburada' => 'Hepsiburada', 'amazon' => 'Amazon TR', 'n11' => 'n11'] as $code => $label): ?>
      <a href="?mp=<?= $code ?>" class="tab <?= $mp === $code ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$supported): ?>
    <div class="card">
      <h2><?= marketplace_label($mp) ?> entegrasyonu</h2>
      <p class="hint"><?= marketplace_label($mp) ?> API bağlantısı yakında eklenecek. Şu an yalnızca Trendyol entegrasyonu aktif — Trendyol sekmesine geçerek mağazanızı bağlayabilirsiniz.</p>
    </div>
  <?php elseif ($hasConnection): ?>
    <div class="card">
      <h2>Bağlı Mağaza</h2>
      <p class="hint">Ürün kataloğu V2 üzerinden, siparişler akış servisiyle çekilir. Sipariş geçmişi Trendyol’un son 3 aylık veri kapsamıyla sınırlıdır.</p>
      <div class="detail-row"><span>Satıcı ID</span><strong><?= h($int['seller_id']) ?></strong></div>
      <div class="detail-row"><span>Ortam</span><strong><?= $int['stage'] ? 'Test (stage)' : 'Üretim' ?></strong></div>
      <div class="detail-row"><span>Ürün senkron başlangıcı (UTC)</span><strong><?= $int['last_sync'] ? h($int['last_sync']) : 'Henüz yok' ?></strong></div>
      <div class="detail-row"><span>Son eksiksiz sipariş senkronu</span><strong><?= $int['last_orders_sync'] ? date('d.m.Y H:i', (int)$int['last_orders_sync']) : 'Henüz yok' ?></strong></div>
      <?php if ($int['orders_coverage_from']): ?><p class="hint">Son sipariş çekiminin değişiklik tarihi aralığı: <?= date('d.m.Y H:i',(int)$int['orders_coverage_from']) ?> – <?= date('d.m.Y H:i',(int)$int['last_orders_sync']) ?>.</p><?php endif; ?>
      <div class="detail-row"><span>Son eksiksiz finans senkronu</span><strong><?= $int['last_settlements_sync'] ? date('d.m.Y H:i', (int)$int['last_settlements_sync']) : 'Henüz yok' ?></strong></div>
      <?php if ($int['last_sync_error']): ?><p class="flash flash-error">Son senkron tamamlanamadı: <?= h($int['last_sync_error']) ?></p><?php endif; ?>
      <div class="actions" style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="sync">
          <button type="submit" class="btn-primary">Ürünleri Çek / Senkronize Et</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="settlements">
          <button type="submit" class="btn-ghost">Komisyonları Çek (Cari Hesap Ekstresi)</button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Bağlantıyı kaldırmak istediğinize emin misiniz?');">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="disconnect">
          <button type="submit" class="btn-danger">Bağlantıyı Kaldır</button>
        </form>
      </div>
      <p class="hint" style="margin-top:14px">Ürünler çekildikten sonra <a href="urunler.php">Ürünlerim</a> sayfasında görünür. Maliyet alanını her ürün için siz girersiniz (API'den maliyet gelmez). <strong>"Komisyonları Çek"</strong> ile Trendyol'un resmi cari hesap ekstresinden finans kayıtları alınır; ürünlerin komisyon oranları en güncel satış kaydından güncellenir. Kargo ve hakediş mutabakatı ayrıca değerlendirilmelidir.</p>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Platform Hizmet Bedeli (Trendyol)</h2>
      <div class="detail-row"><span>Son başarılı çekim</span><strong><?= $int['last_service_fees_sync'] ? date('d.m.Y H:i', (int)$int['last_service_fees_sync']) : 'Henüz çekilmedi' ?></strong></div>
      <p class="hint">Trendyol Other Financials → <em>DeductionInvoices / PlatformServiceFee</em> ile bildirilen gerçek hizmet bedeli hareketleri. Bu tutar kâr hesabındaki tahminin yerine kullanılmaz ve siparişlere dağıtılmaz; yalnızca dönem düzeyinde referanstır. Borç ve alacak ayrı gösterilir.</p>
      <?php if (($int['last_sync_error_scope'] ?? '') === 'service_fees'): ?>
        <p class="flash flash-error">Son hizmet bedeli çekimi başarısız oldu; aşağıdaki kayıtlar önceki başarılı çekime ait.</p>
      <?php endif; ?>
      <p class="hint">Tutarların para birimi sağlayıcı tarafından açıkça belirtilmiyor (belgede para birimi alanı yok); bu yüzden tutarlara ₺ etiketi konmaz ve doğrulanmış bir dönem toplamı üretilmez.</p>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="service_fees">
        <button type="submit" class="btn-ghost">Hizmet Bedellerini Çek</button>
      </form>
      <?php if (!$int['last_service_fees_sync']): ?>
        <p class="hint">Henüz platform hizmet bedeli çekilmedi.</p>
      <?php elseif (!$serviceFees): ?>
        <p class="hint">Bu dönemde platform hizmet bedeli hareketi yok (eksiksiz boş sonuç).</p>
      <?php else: ?>
        <div class="table-card">
          <table>
            <tr><th>Tarih</th><th>İşlem ID</th><th>Dekont No</th><th>Fatura Seri No</th><th>Borç</th><th>Alacak</th><th>Sipariş</th><th>Paket</th></tr>
            <?php foreach ($serviceFees as $f): ?>
            <tr>
              <td><?= $f['transaction_date'] ? date('d.m.Y H:i', (int)$f['transaction_date']) : '—' ?></td>
              <td style="font-size:12px"><?= h($f['provider_id']) ?></td>
              <td><?= $f['receipt_id'] !== null ? h((string)$f['receipt_id']) : '—' ?></td>
              <td style="font-size:12px"><?= $f['invoice_serial'] !== '' ? h($f['invoice_serial']) : '—' ?></td>
              <td><?= number_format((float)$f['debt'], 2, ',', '.') ?></td>
              <td><?= number_format((float)$f['credit'], 2, ',', '.') ?></td>
              <td><?= $f['order_number'] !== '' ? h($f['order_number']) : '—' ?></td>
              <td><?= $f['shipment_package_id'] !== '' ? h($f['shipment_package_id']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Bilgileri Güncelle</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="connect">
        <div class="field"><label>Satıcı ID</label><input type="text" name="seller_id" value="<?= h($int['seller_id']) ?>" required></div>
        <div class="field"><label>API Key</label><input type="password" name="api_key" value="<?= h($int['api_key']) ?>" required autocomplete="off"></div>
        <div class="field"><label>API Secret</label><input type="password" name="api_secret" value="<?= h($int['api_secret']) ?>" required autocomplete="off"></div>
        <label class="check"><input type="checkbox" name="stage" value="1" <?= $int['stage'] ? 'checked' : '' ?>> Test ortamı (stage) kullan</label>
        <button type="submit" class="btn-primary" style="margin-top:12px">Bağlantıyı Test Et ve Güncelle</button>
      </form>
    </div>

    <div class="card" style="margin-top:16px">
      <h2>Fiyat Yönetimi (Trendyol)</h2>
      <?php if (!$priceRows): ?>
        <p class="hint">Henüz senkronize edilmiş ürün yok. Önce yukarıdaki "Ürünleri Çek / Senkronize Et" ile ürünlerinizi aktarın.</p>
      <?php else: ?>
        <?php if ($priceLiveError): ?><div class="alert alert-error"><?= h($priceLiveError) ?></div><?php endif; ?>
        <p class="hint">Her ürünün yanına yeni satış fiyatını girin ve "Gönder"e basın. Fiyat, Trendyol'da ürünün barkodu üzerinden güncellenir. Fiyat/stok son senkronizasyondan gelir — güncel için "Ürünleri Çek"e basın.</p>
        <div class="table-card">
          <table>
            <tr><th>Ürün</th><th>Barkod</th><th>Stok</th><th>Fiyat</th><th>Maliyet</th><th>Yeni Fiyat</th><th></th></tr>
            <?php foreach ($priceRows as $r): ?>
            <tr>
              <td><?= h($r['name']) ?></td>
              <td style="font-size:12px;color:var(--muted)"><?= $r['barcode'] !== '' ? h($r['barcode']) : '—' ?></td>
              <td><?= (int)$r['quantity'] ?></td>
              <td><?= $r['livePrice'] > 0 ? tl($r['livePrice']) : '—' ?></td>
              <td><?= tl($r['cost']) ?></td>
              <td>
                <form method="post" style="display:flex;gap:8px;align-items:center">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="update_price">
                  <input type="hidden" name="barcode" value="<?= h($r['barcode']) ?>">


                  <input type="number" step="0.01" min="0.01" name="new_price" placeholder="<?= $r['price'] ?>"
                         value="<?= $r['livePrice'] > 0 ? $r['livePrice'] : '' ?>"
                         required <?= $r['barcode'] === '' ? 'disabled' : '' ?>>
                  <button type="submit" class="btn-primary" <?= $r['barcode'] === '' ? 'disabled' : '' ?>>Gönder</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
        <?php if ($priceTotalPages > 1): ?>
        <div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:14px">
          <?php if ($pricePage > 1): ?><a class="btn-ghost" style="text-decoration:none" href="?mp=<?= $mp ?>&ppage=<?= $pricePage - 1 ?>">← Önceki</a><?php endif; ?>
          <span class="hint">Sayfa <?= $pricePage ?> / <?= $priceTotalPages ?></span>
          <?php if ($pricePage < $priceTotalPages): ?><a class="btn-ghost" style="text-decoration:none" href="?mp=<?= $mp ?>&ppage=<?= $pricePage + 1 ?>">Sonraki →</a><?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>Trendyol Mağazanızı Bağlayın</h2>
      <p class="hint">Bilgilerinizi <strong>Trendyol Satıcı Paneli → Hesap Bilgilerim → Entegrasyon Bilgileri</strong> sayfasından alabilirsiniz. Bu bilgiler yalnızca master (ana) kullanıcıya görünür.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="connect">
        <div class="field"><label>Satıcı ID (sellerId)</label><input type="text" name="seller_id" placeholder="örn. 123456" required></div>
        <div class="field"><label>API Key</label><input type="password" name="api_key" placeholder="Entegrasyon bilgilerinden" required autocomplete="off"></div>
        <div class="field"><label>API Secret</label><input type="password" name="api_secret" placeholder="Entegrasyon bilgilerinden" required autocomplete="off"></div>
        <label class="check"><input type="checkbox" name="stage" value="1"> Test ortamı (stage) kullan</label>
        <button type="submit" class="btn-primary" style="margin-top:12px">Bağlantıyı Test Et</button>
      </form>
      <p class="hint" style="margin-top:14px">API bilgileriniz AES-256 ile şifrelenir; veritabanında düz metin olarak tutulmaz.</p>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
