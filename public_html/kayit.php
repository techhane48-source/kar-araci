<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

if (current_user()) {
    e_redirect('dashboard.php');
}

// Referans kodu yakala (partner programı)
$ref = trim($_GET['ref'] ?? '');
$referrer = null;
if ($ref !== '') {
    $stmt = db()->prepare("SELECT id FROM users WHERE referral_code = ?");
    $stmt->execute([$ref]);
    $referrer = $stmt->fetch();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Oturum süresi doldu, tekrar deneyin.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $refCode = trim($_POST['ref'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            $error = 'Geçerli bir ad, e-posta ve en az 6 karakterli şifre girin.';
        } else {
            $stmt = db()->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Bu e-posta zaten kayıtlı.';
            } else {
                // Yönlendiren kullanıcıyı bul
                $referredBy = null;
                if ($refCode !== '') {
                    $stmt = db()->prepare("SELECT id FROM users WHERE referral_code = ?");
                    $stmt->execute([$refCode]);
                    $r = $stmt->fetch();
                    if ($r) $referredBy = (int)$r['id'];
                }

                db()->prepare("INSERT INTO users (email, name, password_hash, referred_by) VALUES (?, ?, ?, ?)")
                    ->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT), $referredBy]);
                $uid = (int) db()->lastInsertId();

                // Kayıt anında kendi referral kodunu üret
                $ownCode = generate_referral_code($uid);
                db()->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$ownCode, $uid]);

                session_boot();
                $_SESSION['uid'] = $uid;
                flash_set('success', 'Hoş geldin! Ürünlerini kaydetmeye başlayabilirsin.');
                e_redirect('index.php');
            }
        }
    }
}

$pageTitle = 'Kayıt Ol — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="card auth-card">
    <h2>Ücretsiz hesap oluştur</h2>
    <div class="sub" style="margin-bottom:16px">Üyelik ücretsiz. Ürünlerini kaydet, toplu kârlılık tablosunda gör.</div>
    <?php if ($error): ?><div class="flash flash-error" style="margin:0 0 16px"><?= h($error) ?></div><?php endif; ?>
    <?php if ($referrer): ?><div class="flash flash-info" style="margin:0 0 16px">Bir partner aracılığıyla geldiniz. Kayıt olunca partnerinize bağlanacaksınız.</div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="ref" value="<?= h($ref) ?>">
      <div class="row">
        <label>Ad</label>
        <input type="text" name="name" value="<?= h($_POST['name'] ?? '') ?>" required>
      </div>
      <div class="row">
        <label>E-posta</label>
        <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="row">
        <label>Şifre (en az 6 karakter)</label>
        <input type="password" name="password" required minlength="6">
      </div>
      <button type="submit" class="btn-primary" style="width:100%">Kayıt Ol</button>
    </form>
    <div class="auth-alt">Zaten hesabın var mı? <a href="giris.php">Giriş yap</a></div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
