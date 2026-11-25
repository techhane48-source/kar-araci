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
    if (!csrf_verify($_POST['csrf'] ?? '')) {
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
                    try {
                        save_integration((int)$u['id'], $mp, $sellerId, $apiKey, $apiSecret, $stage);
                        $ok = 'Mağazanız başarıyla bağlandı!';
                        $int = get_integration((int)$u['id'], $mp);
                        $hasConnection = true;
                    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
                }
            }
        } elseif ($action === 'sync') {
            $res = sync_products((int)$u['id'], $mp);
            if ($res['ok']) {
                $ok = "Senkronizasyon tamamlandı: {$res['inserted']} yeni ürün eklendi, {$res['updated']} ürün güncellendi, {$res['skipped']} ürün atlandı (toplam {$res['total']} ürün çekildi).";
            } else {
                $error = 'Senkronizasyon hatası: ' . ($res['error'] ?? 'Bilinmeyen hata');
            }
        } elseif ($action === 'settlements') {
            $res = sync_settlements((int)$u['id'], $mp);
            if ($res['ok']) {
                $ok = "Cari hesap ekstresi (resmi komisyon/kargo/hakediş) çekildi: {$res['records']} işlem işlendi (tekrar gelen kayıtlar güncellendi). Geçmiş komisyonlar ürün ayarlarını değiştirmez.";
                $docs = sync_trendyol_documents((int)$u['id'], 30);
                if ($docs['ok']) $ok .= " {$docs['documents']} finansal belge, {$docs['cargo_items']} kargo kalemi işlendi.";
                else { $error = 'Finansal belgeler tamamlanmadı: ' . ($docs['error'] ?? 'Bilinmeyen hata'); $ok = ''; }
            } else {
                $error = 'Cari hesap ekstresi hatası: ' . ($res['error'] ?? 'Bilinmeyen hata');
            }
        } elseif ($action === 'disconnect') {
            try { delete_integration((int)$u['id'], $mp); $int = null; $hasConnection = false; $ok = 'Mağaza bağlantısı kaldırıldı.'; }
            catch (RuntimeException $ex) { $error = $ex->getMessage(); }
        } elseif ($action === 'update_price' && $int) {
            $barcode = trim((string)($_POST['barcode'] ?? ''));
            $sale = parse_money_input((string)($_POST['new_price'] ?? ''));
            $q = db()->prepare("SELECT id FROM products WHERE user_id=? AND marketplace='trendyol' AND barcode=?");
            $q->execute([$u['id'],$barcode]);
            if ($sale === null || $sale <= 0 || !$q->fetchColumn()) {
                $error = 'Geçerli bir ürün ve pozitif fiyat girin.';
            } else {
                $client = integration_client($int);
                $current = $client->getProduct($barcode);
                if (!$current['ok'] || !isset($current['product']['listPrice'],$current['product']['salePrice'])) {
                    $error = 'Güncel Trendyol fiyatı doğrulanamadı. Değişiklik gönderilmedi.';
                } elseif (abs((float)$current['product']['salePrice']-$sale)<0.005) {
                    $ok = 'Fiyat zaten güncel; yeni istek gönderilmedi.';
                } else {
                    $list = max($sale,(float)$current['product']['listPrice']);
                    $res=$client->updatePriceAndInventory([['barcode'=>$barcode,'salePrice'=>$sale,'listPrice'=>$list]]);
                    if (!$res['ok']) $error=$res['error'];
                    else {
                        db()->prepare("INSERT INTO trendyol_price_requests(user_id,batch_id,seller_id,barcode,sale_price,list_price,created_at)
                            VALUES(?,?,?,?,?,?,?)")->execute([$u['id'],$res['batch_id'],$int['seller_id'],$barcode,$sale,$list,time()]);
                        $ok='Fiyat talebi sıraya alındı. Trendyol sonucu doğrulanana kadar başarılı sayılmaz.';
                    }
                }
            }
        } elseif ($action === 'check_price' && $int) {
            $id=(string)($_POST['batch_id']??'');
            $q=db()->prepare("SELECT * FROM trendyol_price_requests WHERE user_id=? AND seller_id=? AND batch_id=?");
            $q->execute([$u['id'],$int['seller_id'],$id]);$job=$q->fetch();
            if (!$job) $error='İşlem bulunamadı.';
            elseif ((int)$job['created_at'] < time()-14400) $error='Sorgu süresi doldu. Güncel ürün fiyatını kontrol edin.';
            else {
                $res=integration_client($int)->getBatchRequestResult($id);
                if (!$res['ok']) $error=$res['error'];
                else {
                    $item=null;
                    foreach($res['items'] as $v) if (($v['requestItem']['barcode']??'')===$job['barcode']) $item=$v;
                    $status=(string)($item['status']??'PENDING');
                    if (!in_array($status,['SUCCESS','FAILED'],true)) $status='PENDING';
                    $reason=implode('; ',array_map('strval',(array)($item['failureReasons']??[])));
                    db()->prepare("UPDATE trendyol_price_requests SET status=?,reason=? WHERE user_id=? AND batch_id=?")->execute([$status,$reason,$u['id'],$id]);
                    if ($status==='FAILED') $error='İşlem reddedildi: '.$reason;
                    else $ok=$status==='SUCCESS'?'Trendyol fiyat güncellemesini onayladı. Ürünleri senkronize edin.':'İşlem henüz bekliyor.';
                }
            }
        }
    }
}

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

$priceJobs=[];
if ($supported && $int) {
    $st=db()->prepare("SELECT * FROM trendyol_price_requests WHERE user_id=? AND seller_id=? ORDER BY created_at DESC LIMIT 30");
    $st->execute([$u['id'],$int['seller_id']]);$priceJobs=$st->fetchAll();
}
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
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
      <div class="detail-row"><span>Satıcı ID</span><strong><?= h($int['seller_id']) ?></strong></div>
      <div class="detail-row"><span>Ortam</span><strong><?= $int['stage'] ? 'Test (stage)' : 'Üretim' ?></strong></div>
      <div class="detail-row"><span>Son senkron</span><strong><?= $int['last_sync'] ? h($int['last_sync']) : 'Henüz yok' ?></strong></div>
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
      <p class="hint" style="margin-top:14px">Ürünler çekildikten sonra <a href="urunler.php">Ürünlerim</a> sayfasında görünür. Maliyet alanını her ürün için siz girersiniz (API'den maliyet gelmez). <strong>"Komisyonları Çek"</strong> ile Trendyol'un resmi cari hesap ekstresinden gerçek komisyon oranı + kargo + hakediş çekilir ve ürünlerinize işlenir (tahmin yerine resmi değer).</p>
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
                  <input type="hidden" name="quantity" value="<?= (int)$r['quantity'] ?>">
                  <input type="hidden" name="list_price" value="<?= $r['listPrice'] ?>">
                  <input type="number" step="0.01" min="0" name="new_price" placeholder="<?= $r['price'] ?>"
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
<?php if ($priceJobs): ?>
<div class="wrap"><div class="card table-card"><h2>Fiyat güncelleme talepleri</h2>
<table><tr><th>Barkod</th><th>Fiyat</th><th>Durum</th><th>Kontrol</th></tr>
<?php foreach($priceJobs as $job): ?><tr><td><?= h($job['barcode']) ?></td><td><?= tl((float)$job['sale_price']) ?></td>
<td><?= h($job['status'].' '.$job['reason']) ?></td><td>
<?php if ($job['status']==='PENDING' && (int)$job['created_at']>=time()-14400): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="check_price"><input type="hidden" name="batch_id" value="<?= h($job['batch_id']) ?>"><button type="submit">Sonucu kontrol et</button></form>
<?php endif; ?></td></tr><?php endforeach; ?></table></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
