<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/billing.php';

$u = require_login();
$pro = is_pro($u);
$iban = setting('iban');
$accountName = setting('account_name');
$price = (float) setting('pro_price');
$iyzicoKey = setting('iyzico_api_key');
$iyzicoSecret = setting('iyzico_secret_key');
$iyzicoSandbox = setting('iyzico_sandbox') === '1';
$iyzicoReady = checkout_enabled() && $iyzicoKey !== '' && $iyzicoSecret !== '' && checkout_minor($price)>0 && empty($u['is_demo']);

// Ödeme bildirimi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        db()->prepare("INSERT INTO payments (user_id, amount, reference, status) VALUES (?, ?, ?, 'pending')")
            ->execute([$u['id'], $price, 'KF-' . $u['id'] . '-' . date('ymd')]);
        flash_set('info', 'Ödemen bildirildi. Onaylandığında Pro planın aktif olacak.');
    }
    e_redirect('odeme.php');
}

// Kartlı ödeme (iyzico) başlat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'iyzico_pay') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Oturum süresi doldu.');
    } elseif (!$iyzicoReady) {
        flash_set('error', 'Kartlı ödeme henüz yapılandırılmadı.');
    } else {
        $bName = trim($_POST['b_name'] ?? '');
        $bSurname = trim($_POST['b_surname'] ?? '');
        $bPhone = trim($_POST['b_phone'] ?? '');
        $bIdentity = preg_replace('/\D/', '', trim($_POST['b_identity'] ?? ''));
        $bAddress = trim($_POST['b_address'] ?? '');
        $bCity = trim($_POST['b_city'] ?? '');
        $bZip = trim($_POST['b_zip'] ?? '');

        if ($bName === '' || $bSurname === '' || $bPhone === '' || $bIdentity === '' || $bAddress === '' || $bCity === '') {
            flash_set('error', 'Kartlı ödeme için ad, soyad, telefon, TC kimlik no ve adres alanlarının tümünü doldurun.');
        } elseif (strlen($bIdentity) !== 11) {
            flash_set('error', 'TC kimlik numarası 11 haneli olmalı.');
        } else {
            // Telefonu iyzico formatına çevir: +90XXXXXXXXXX
            $digits = preg_replace('/\D/', '', $bPhone);
            if (strlen($digits) === 10) $digits = '90' . $digits;
            elseif (strlen($digits) === 11 && $digits[0] === '0') $digits = '90' . substr($digits, 1);
            elseif (strlen($digits) !== 12 || substr($digits, 0, 2) !== '90') $digits = '';
            if (strlen($digits) !== 12) {
                flash_set('error', 'Geçerli bir GSM numarası girin (örn. 05XX XXX XX XX).');
            } else {
                require_once __DIR__ . '/../includes/iyzico.php';
                $callbackUrl = checkout_origin() . '/iyzico-callback.php';
                $basketId = 'KF-' . bin2hex(random_bytes(16));
                $conversationId = 'cf-' . bin2hex(random_bytes(16));
                $buyer = [
                    'id' => 'U' . $u['id'],
                    'name' => mb_substr($bName, 0, 30),
                    'surname' => mb_substr($bSurname, 0, 30),
                    'identityNumber' => $bIdentity,
                    'email' => $u['email'],
                    'gsmNumber' => '+' . $digits,
                    'registrationAddress' => mb_substr($bAddress, 0, 200),
                    'city' => mb_substr($bCity, 0, 30),
                    'country' => 'Türkiye',
                    'zipCode' => $bZip !== '' ? mb_substr($bZip, 0, 10) : '34000',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ];
                $address = [
                    'address' => mb_substr($bAddress, 0, 200),
                    'zipCode' => $bZip !== '' ? mb_substr($bZip, 0, 10) : '34000',
                    'contactName' => mb_substr($bName . ' ' . $bSurname, 0, 60),
                    'city' => mb_substr($bCity, 0, 30),
                    'country' => 'Türkiye',
                ];
                $params = [
                    'locale' => 'tr',
                    'conversationId' => $conversationId,
                    'price' => number_format($price, 2, '.', ''),
                    'paidPrice' => number_format($price, 2, '.', ''),
                    'currency' => 'TRY',
                    'basketId' => $basketId,
                    'paymentGroup' => 'PRODUCT',
                    'callbackUrl' => $callbackUrl,
                    'buyer' => $buyer,
                    'shippingAddress' => $address,
                    'billingAddress' => $address,
                    'basketItems' => [[
                        'id' => 'PRO',
                        'name' => APP_NAME . ' Pro Üyelik',
                        'category1' => 'Hizmet',
                        'itemType' => 'VIRTUAL',
                        'price' => number_format($price, 2, '.', ''),
                    ]],
                ];
                $client = new IyzicoClient($iyzicoKey, $iyzicoSecret, $iyzicoSandbox);
                $res = $client->initializeCheckoutForm($params);
                if ($res['ok'] && checkout_valid_token($res['token']??null)) {
                    $url=$res['paymentPageUrl']??'';$host=parse_url($url,PHP_URL_HOST);
                    if(parse_url($url,PHP_URL_SCHEME)==='https' && in_array($host,['api.iyzipay.com','sandbox-api.iyzipay.com','checkout.iyzipay.com','sandbox-checkout.iyzipay.com'],true)){
                        try{
                            checkout_create(db(),(int)$u['id'],$res['token'],$basketId,$conversationId,checkout_minor($price),$iyzicoSandbox,checkout_merchant($iyzicoKey,$iyzicoSecret));
                            header('Location: '.$url);exit;
                        }catch(Throwable $e){flash_set('error','Ödeme oturumu kaydedilemedi.');e_redirect('odeme.php');}
                    }
                }
                flash_set('error', 'Kartlı ödeme başlatılamadı: ' . ($res['error'] ?? 'Bilinmeyen hata'));
            }
        }
    }
    e_redirect('odeme.php');
}

// Bekleyen ödeme var mı
$pending = db()->prepare("SELECT * FROM payments WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$pending->execute([$u['id']]);
$pendingPayment = $pending->fetch();

$pageTitle = 'Planım — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar"><h1>Planım</h1></div>

  <div class="grid">
    <div class="card">
      <h2>Mevcut Plan</h2>
      <div style="margin-bottom:16px">
        <span class="plan-badge <?= $pro ? 'plan-pro' : 'plan-free' ?>"><?= $pro ? 'PRO' : 'ÜCRETSİZ' ?></span>
        <?php if ($pro && $u['plan_expires']): ?>
          <span class="hint" style="display:inline-block;margin-left:8px">Bitiş: <?= h($u['plan_expires']) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($pro): ?>
        <p style="color:var(--muted);font-size:14px">Pro plan aktif. Sınırsız ürün kaydı ve toplu CSV içe aktarma kullanılabilir.</p>
      <?php else: ?>
        <p style="color:var(--muted);font-size:14px">Ücretsiz plan <?= FREE_PRODUCT_LIMIT ?> ürünle sınırlıdır ve CSV içe aktarma içermez.</p>
      <?php endif; ?>
    </div>

    <?php if (!$pro): ?>
    <div class="card">
      <h2>Pro'ya Yükselt — <?= h(number_format($price, 2, ',', '.')) ?> ₺/ay</h2>
      <p style="color:var(--muted);font-size:14px;margin-bottom:16px">
        Sınırsız ürün kaydı, toplu CSV içe aktarma ve tüm ürünlerinin tek tabloda kârlılık analizi.
      </p>

      <?php if ($pendingPayment): ?>
        <div class="flash flash-info" style="margin:0 0 16px">
          Ödemen bildirildi (<span class="pay-ref"><?= h($pendingPayment['reference']) ?></span>).
          Onay sürecinde. Onaylanınca Pro planın otomatik açılır.
        </div>
      <?php elseif (!$iyzicoReady && ($iban === '' || $accountName === '')): ?>
        <div class="flash flash-error" style="margin:0 0 16px">Ödeme bilgileri henüz yapılandırılmadı. Lütfen daha sonra tekrar deneyin.</div>
      <?php else: ?>
        <div class="row">
          <label>Hesap Sahibi</label>
          <div class="pay-ref"><?= h($accountName) ?></div>
        </div>
        <div class="row">
          <label>IBAN</label>
          <div class="pay-ref"><?= h($iban) ?></div>
        </div>
        <div class="row">
          <label>Ödeme Referansı (açıklamaya yaz)</label>
          <div class="pay-ref">KF-<?= (int)$u['id'] ?>-<?= date('ymd') ?></div>
          <div class="hint">Bu referansı havale açıklamasına yaz; böylece ödemen eşleştirilir.</div>
        </div>
        <form method="post" onsubmit="return confirm('Ödemeyi yaptınız mı? Havale henüz ulaşmamışsa onay gecikebilir.')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="report">
          <button type="submit" class="btn-primary" style="width:100%">Ödeme Yaptım, Bildir</button>
        </form>

        <?php if ($iyzicoReady): ?>
        <div style="margin:22px 0 12px;text-align:center;color:var(--muted);font-size:13px">— veya —</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="iyzico_pay">
          <div class="row"><label>Ad</label><input type="text" name="b_name" required></div>
          <div class="row"><label>Soyad</label><input type="text" name="b_surname" required></div>
          <div class="row"><label>GSM Numarası</label><input type="tel" name="b_phone" placeholder="05XX XXX XX XX" required></div>
          <div class="row"><label>TC Kimlik No</label><input type="text" name="b_identity" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" required></div>
          <div class="row"><label>Adres</label><input type="text" name="b_address" required></div>
          <div class="two">
            <div class="row"><label>Şehir</label><input type="text" name="b_city" value="İstanbul" required></div>
            <div class="row"><label>Posta Kodu</label><input type="text" name="b_zip" placeholder="34000"></div>
          </div>
          <button type="submit" class="btn-primary" style="width:100%">Kredi Kartı ile Öde</button>
          <div class="hint">Güvenli kartlı ödeme iyzico üzerinden yapılır. Pro planın anında aktif olur. TC kimlik no, iyzico güvenlik doğrulaması ve e-fatura için gereklidir; veritabanımızda saklanmaz.</div>
        </form>
        <?php else: ?>
        <div style="margin:20px 0 0;text-align:center;color:var(--muted);font-size:13px">Kartlı ödeme yakında — şu an IBAN ile ödeyebilirsin.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
