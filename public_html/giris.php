<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

if (current_user()) {
    e_redirect('dashboard.php');
}

$error = '';

// 2FA adımı: kullanıcı/şifre doğrulandı ama TOTP bekleniyor
$pending = $_SESSION['2fa_pending'] ?? null;
if ($pending && (int)($pending['expires_at'] ?? 0)<time()) { unset($_SESSION['2fa_pending']); $pending=null; }
if ($pending && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === '2fa') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Oturum süresi doldu, tekrar deneyin.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if (!auth_take_attempt(db(),'totp:'.$pending['id'],5,300,time())) {
            unset($_SESSION['2fa_pending']);$pending=null;$error='Çok fazla deneme. Yeniden giriş yapın.';
        } elseif (totp_verify((string)$pending['totp_secret'], $code)) {
            session_boot();
            session_regenerate_id(true);
            unset($_SESSION['csrf']);
            $_SESSION['uid'] = (int)$pending['id'];
            unset($_SESSION['2fa_pending']);
            if ($pending['is_admin'] && $pending['must_change_password']) {
                e_redirect('admin.php');
            }
            $next = $pending['next'] ?? '';
            e_redirect(safe_login_next($next));
        } else {
            $error = 'Doğrulama kodu hatalı veya süresi doldu.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== '2fa') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Oturum süresi doldu, tekrar deneyin.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = db()->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if (!login_attempt_allowed($email)) {
            $error='Çok fazla giriş denemesi. 15 dakika sonra tekrar deneyin.';
        } elseif ($u && password_verify($password, $u['password_hash']) && !($u['is_admin'] && $password==='1234')) {
            if ($u['totp_enabled'] && $u['totp_secret']) {
                // 2FA aktif: kodu sor
                session_boot();
                $_SESSION['2fa_pending'] = [
                    'id' => (int)$u['id'],
                    'expires_at'=>time()+300,
                    'totp_secret' => $u['totp_secret'],
                    'is_admin' => (int)$u['is_admin'],
                    'must_change_password' => (int)$u['must_change_password'],
                    'next' => $_POST['next'] ?? '',
                ];
                $pending = $_SESSION['2fa_pending'];
            } else {
                session_boot();
                session_regenerate_id(true);
                unset($_SESSION['csrf']);
                $_SESSION['uid'] = (int)$u['id'];
                if ($u['is_admin'] && $u['must_change_password']) {
                    e_redirect('admin.php');
                }
                $next = $_POST['next'] ?? '';
                e_redirect(safe_login_next($next));
            }
        } else {
            $error = 'E-posta veya şifre hatalı.';
        }
    }
}

$pageTitle = 'Giriş — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap ui-auth-wrap"><div class="ui-auth-intro"><span class="ui-auth-mark"><?=ui_icon('finance')?></span><h1>Covisca</h1><p>Mağazanızı, satışlarınızı ve kârınızı tek yerden yönetin.</p><p class="ui-muted">Trendyol bağlantınızı hesabınızı oluşturduktan sonra yapabilirsiniz.</p></div><div class="card auth-card">
    <?php if ($pending): ?>
      <h2>İki faktörlü doğrulama</h2>
      <div class="sub" style="margin:6px 0 16px">Kimlik doğrulayıcı uygulamanızdaki (Google Authenticator vb.) 6 haneli kodu girin.</div>
      <?php if ($error): ?><div class="flash flash-error" style="margin:0 0 16px"><?= h($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="2fa">
        <div class="row">
          <label>Doğrulama Kodu</label>
          <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus style="font-size:22px;letter-spacing:6px;text-align:center">
        </div>
        <button type="submit" class="btn-primary" style="width:100%">Doğrula</button>
      </form>
      <div class="auth-alt"><a href="cikis.php">İptal et, baştan başla</a></div>
    <?php else: ?>
      <h2>Giriş yap</h2>
      <?php if ($error): ?><div class="flash flash-error" style="margin:0 0 16px"><?= h($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= h($_GET['next'] ?? '') ?>">
        <div class="row">
          <label for="auth-email">E-posta</label>
          <input type="email" id="auth-email" autocomplete="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="row">
          <label for="auth-password">Şifre</label>
          <input type="password" id="auth-password" autocomplete="current-password" name="password" required>
        </div>
        <button type="submit" class="btn-primary" style="width:100%">Giriş Yap</button>
      </form>
      <div class="auth-alt">Hesabın yok mu? <a href="kayit.php">Ücretsiz kayıt ol</a></div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
