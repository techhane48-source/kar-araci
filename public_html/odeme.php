<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);
$iban = setting('iban');
$accountName = setting('account_name');
$price = (float) setting('pro_price');
$iyzicoKey = setting('iyzico_api_key');
$iyzicoSecret = setting('iyzico_secret_key');
$iyzicoSandbox = setting('iyzico_sandbox') === '1';
$iyzicoReady = $iyzicoKey !== '' && $iyzicoSecret !== '';
try {application_base_url(getenv('KARFIYAT_APP_URL')?:setting('app_url'));} catch (RuntimeException $e) {$iyzicoReady=false;}

// Ödeme bildirimi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Oturum süresi doldu.');
    } elseif (!is_finite($price)||$price<=0||$iban===''||$accountName===''||!empty($u['is_demo'])) {
        flash_set('error','Havale bildirimi bu hesap için kullanılamıyor.');
    } else {
        $pdo=db();$pdo->exec('BEGIN IMMEDIATE');
        try {
        $pending=$pdo->prepare("SELECT id FROM payments WHERE user_id=? AND status='pending'");$pending->execute([$u['id']]);
        if (!$pending->fetchColumn()) db()->prepare("INSERT INTO payments (user_id, amount, reference, status) VALUES (?, ?, ?, 'pending')")
            ->execute([$u['id'], $price, 'KF-' . $u['id'] . '-' . date('ymd')]);
        $pdo->exec('COMMIT');
        }catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
        flash_set('info', 'Ödemen bildirildi. Onaylandığında Pro planın aktif olacak.');
    }
    e_redirect('odeme.php');
}

// Re-query an existing owned checkout. This operation never creates a new charge.
if ($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='recheck') {
    if(!csrf_verify($_POST['csrf']??null)||!empty($u['is_demo']))flash_set('error','Bu işlem için oturum doğrulanamadı.');
    else {
        $token=$_POST['checkout_token']??'';$q=db()->prepare('SELECT token FROM checkout_sessions WHERE user_id=? AND token=?');$q->execute([$u['id'],is_string($token)?$token:'']);
        if(!$q->fetchColumn())flash_set('error','Ödeme kaydı bulunamadı.');
        else {$result=verify_checkout($token);flash_set($result['ok']?'success':'info',$result['ok']?'Ödemeniz doğrulandı.':'Ödeme kontrol bekliyor. Yeniden ödeme yapmadan bu işlemin sonucunu kontrol edin.');}
    }
    e_redirect('odeme.php');
}

// Kartlı ödeme (iyzico) başlat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'iyzico_pay') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Oturum süresi doldu.');
    } elseif (!is_finite($price) || $price <= 0) {
        flash_set('error', 'Geçerli bir üyelik ücreti tanımlanmamış.');
    } elseif (!$iyzicoReady || !empty($u['is_demo'])) {
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
                $callbackUrl=application_base_url(getenv('KARFIYAT_APP_URL')?:setting('app_url')).'/iyzico-callback.php';
                $providerContext=checkout_provider_context($iyzicoKey,$iyzicoSecret,$iyzicoSandbox);
                $basketId = 'KF-' . $u['id'] . '-' . bin2hex(random_bytes(16));
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
                    'conversationId' => 'cf-' . uniqid(),
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
                if ($res['ok'] && ($res['paymentPageUrl'] ?? '') !== '' && ($res['token'] ?? '') !== '') {
                    db()->prepare('INSERT INTO checkout_sessions (token, user_id, basket_id, amount, provider_context) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$res['token'], $u['id'], $basketId, $params['price'], $providerContext]);
                    header('Location: ' . $res['paymentPageUrl']);
                    exit;
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

$cq=db()->prepare('SELECT token,status,error_code,created_at FROM checkout_sessions WHERE user_id=? ORDER BY created_at DESC LIMIT 10');$cq->execute([$u['id']]);$checkouts=$cq->fetchAll();
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

    <?php if (empty($u['is_demo'])): ?>
    <div class="card">
      <h2><?= $pro ? 'Pro Süresini Uzat' : 'Pro’ya Yükselt' ?> — <?= h(number_format($price, 2, ',', '.')) ?> ₺/ay</h2>
      <p style="color:var(--muted);font-size:14px;margin-bottom:16px">
        Sınırsız ürün kaydı ve analiz araçları. Yenileme otomatik değildir; ödeme mevcut bitiş tarihinize bir ay ekler. Yenilemezseniz dönem sonunda ücretsiz plana geçersiniz.
      </p>

      <?php if ($pendingPayment): ?>
        <div class="flash flash-info" style="margin:0 0 16px">
          Ödemen bildirildi (<span class="pay-ref"><?= h($pendingPayment['reference']) ?></span>).
          Onay sürecinde. Onaylanınca Pro planın otomatik açılır.
        </div>
      <?php elseif (!$iyzicoReady && ($iban === '' || $accountName === '')): ?>
        <div class="flash flash-error" style="margin:0 0 16px">Ödeme bilgileri henüz yapılandırılmadı. Lütfen daha sonra tekrar deneyin.</div>
      <?php else: ?>
        <?php if ($iban!=='' && $accountName!==''): ?><div class="row">
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

        <?php endif; ?>
        <?php if ($iyzicoReady): ?>
        <?php if ($iban!==''&&$accountName!==''): ?><div style="margin:22px 0 12px;text-align:center;color:var(--muted);font-size:13px">— veya —</div><?php endif; ?>
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
<?php if($checkouts): ?><div class="wrap"><div class="card"><h2>Kartlı Ödeme Durumları</h2><p>Kontrol düğmesi mevcut ödemeyi sorgular; yeni tahsilat başlatmaz.</p><div style="overflow-x:auto"><table><tr><th>Tarih</th><th>Durum</th><th>İşlem</th></tr><?php foreach($checkouts as $c):$labels=['pending'=>'Kontrol bekliyor','review'=>'İnceleme bekliyor','failed'=>'Sağlayıcı işlemi onaylamadı','approved'=>'Doğrulandı']; ?><tr><td><?= h($c['created_at']) ?></td><td><?= h($labels[$c['status']]??'Kontrol bekliyor') ?></td><td><?php if($c['status']!=='approved'): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="recheck"><input type="hidden" name="checkout_token" value="<?= h($c['token']) ?>"><button class="btn-ghost">Durumu Yeniden Kontrol Et</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div></div></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
