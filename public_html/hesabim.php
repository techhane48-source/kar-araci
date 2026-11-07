<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Oturum süresi doldu.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'name') {
            db()->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([mb_substr(trim($_POST['name'] ?? ''), 0, 80), $u['id']]);
            $ok = 'Ad güncellendi.';
        } elseif ($action === 'password') {
            $cur = $_POST['current'] ?? '';
            $new = $_POST['new'] ?? '';
            if (!password_verify($cur, $u['password_hash'])) {
                $error = 'Mevcut şifre hatalı.';
            } elseif (strlen($new) < 6) {
                $error = 'Yeni şifre en az 6 karakter olmalı.';
            } else {
                db()->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
                $ok = 'Şifre güncellendi.';
            }
        } elseif ($action === '2fa_setup') {
            // 2FA sırrı üret (henüz aktifleştirilmez)
            $secret = totp_base32_encode(random_bytes(20));
            db()->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 0 WHERE id = ?")->execute([$secret, $u['id']]);
            $_SESSION['2fa_setup_secret'] = $secret;
            $u = current_user();
        } elseif ($action === '2fa_confirm') {
            $secret = $_SESSION['2fa_setup_secret'] ?? $u['totp_secret'] ?? '';
            $code = trim($_POST['code'] ?? '');
            if ($secret && totp_verify($secret, $code)) {
                db()->prepare("UPDATE users SET totp_enabled = 1 WHERE id = ?")->execute([$u['id']]);
                unset($_SESSION['2fa_setup_secret']);
                $ok = 'İki faktörlü doğrulama aktifleştirildi.';
            } else {
                $error = 'Kod hatalı. Tekrar deneyin.';
            }
            $u = current_user();
        } elseif ($action === '2fa_disable') {
            $code = trim($_POST['code'] ?? '');
            if ($u['totp_secret'] && totp_verify($u['totp_secret'], $code)) {
                db()->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?")->execute([$u['id']]);
                $ok = 'İki faktörlü doğrulama kapatıldı.';
            } else {
                $error = 'Kod hatalı, kapatılamadı.';
            }
            $u = current_user();
        }
    }
    $u = current_user();
}

// Partner kodu (yoksa üret)
if (empty($u['referral_code'])) {
    $code = generate_referral_code((int)$u['id']);
    db()->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$code, $u['id']]);
    $u = current_user();
}

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$referralUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/kayit.php?ref=' . $u['referral_code'];

// Partner istatistikleri
$partnerStats = db()->prepare(
    "SELECT
        COUNT(DISTINCT referred_user_id) AS refs,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS paid,
        COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_amt
     FROM partner_commissions WHERE partner_id = ?"
);
$partnerStats->execute([$u['id']]);
$ps = $partnerStats->fetch();

$pageTitle = 'Hesabım — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar"><h1>Hesabım</h1></div>
  <?php if ($error): ?><div class="flash flash-error" style="margin:0 0 16px"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="flash flash-success" style="margin:0 0 16px"><?= h($ok) ?></div><?php endif; ?>

  <div class="grid">
    <div class="card">
      <h2>Profil</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="name">
        <div class="row"><label>E-posta</label><input type="email" value="<?= h($u['email']) ?>" disabled></div>
        <div class="row"><label>Ad</label><input type="text" name="name" value="<?= h($u['name']) ?>"></div>
        <button type="submit" class="btn-primary">Kaydet</button>
      </form>
    </div>

    <div class="card">
      <h2>Şifre Değiştir</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="password">
        <div class="row"><label>Mevcut Şifre</label><input type="password" name="current" required></div>
        <div class="row"><label>Yeni Şifre</label><input type="password" name="new" required minlength="6"></div>
        <button type="submit" class="btn-primary">Güncelle</button>
      </form>
    </div>

    <div class="card">
      <h2>İki Faktörlü Doğrulama (2FA)</h2>
      <?php if ($u['totp_enabled']): ?>
        <div class="flash flash-success" style="margin:0 0 16px">Aktif ✓ Hesabın 2FA ile korunuyor.</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="2fa_disable">
          <div class="row"><label>Kapatmak için mevcut kodu gir</label><input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></div>
          <button type="submit" class="btn-danger">2FA'yı Kapat</button>
        </form>
      <?php elseif (($_SESSION['2fa_setup_secret'] ?? '') !== '' || ($u['totp_secret'] ?? '') !== ''): ?>
        <?php $setupSecret = $_SESSION['2fa_setup_secret'] ?? $u['totp_secret']; ?>
        <div class="row">
          <label>1) Kimlik doğrulayıcı uygulamasına bu anahtarı gir (veya QR tara):</label>
          <div class="pay-ref" style="word-break:break-all"><?= h($setupSecret) ?></div>
          <div class="hint">Google Authenticator, Authy, Microsoft Authenticator…</div>
        </div>
        <div class="row">
          <label>2) Uygulamadaki 6 haneli kodu gir ve onayla:</label>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="2fa_confirm">
            <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required style="font-size:20px;letter-spacing:6px;text-align:center">
            <button type="submit" class="btn-primary" style="width:100%;margin-top:10px">Aktifleştir</button>
          </form>
        </div>
      <?php else: ?>
        <p class="hint" style="margin-bottom:14px">Hesabına ikinci bir güvenlik katmanı ekle. Girişte şifre + uygulama kodu istenir.</p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="2fa_setup">
          <button type="submit" class="btn-ghost">2FA Kur</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Partner Programı</h2>
      <p class="hint" style="margin-bottom:12px">Yönlendirdiğin her abonenin ödemesinden KDV hariç %15 hakediş kazan.</p>
      <div class="row">
        <label>Yönlendirme linkin</label>
        <div class="pay-ref" style="word-break:break-all;font-size:13px"><?= h($referralUrl) ?></div>
      </div>
      <div class="kpi-row" style="grid-template-columns:repeat(3,1fr)">
        <div class="kpi"><div class="k-label">Yönlendirilen</div><div class="k-value"><?= (int)$ps['refs'] ?></div></div>
        <div class="kpi"><div class="k-label">Bekleyen Hakediş</div><div class="k-value"><?= tl((float)$ps['pending_amt']) ?></div></div>
        <div class="kpi"><div class="k-label">Ödenen</div><div class="k-value pos"><?= tl((float)$ps['paid']) ?></div></div>
      </div>
      <div class="hint">Hakedişler, yönlendirdiğin kullanıcı Pro aboneliği yaptığında kayda geçer ve onaylandığında ödenir.</div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
