<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
if (!$u['is_admin']) {
    e_redirect('dashboard.php');
}

// İlk girişte şifre değiştirmeye zorla (güvenlik kilidi)
if ($u['must_change_password'] && !($_POST['action'] ?? '')) {
    $pageTitle = 'Şifre Değiştir — ' . APP_NAME;
    require __DIR__ . '/../includes/header.php';
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
        $err = 'Lütfen şifreni değiştir.';
    }
    ?>
    <div class="wrap">
      <div class="card auth-card">
        <h2>Güvenlik: Varsayılan şifreyi değiştir</h2>
        <div class="sub" style="margin-bottom:16px">İlk girişte varsayılan yönetici şifresini değiştirmen gerekir.</div>
        <?php if ($err): ?><div class="flash flash-error" style="margin:0 0 16px"><?= h($err) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="password">
          <div class="row"><label>Mevcut Şifre</label><input type="password" name="current" required></div>
          <div class="row"><label>Yeni Şifre (en az 6)</label><input type="password" name="new" required minlength="6"></div>
          <button type="submit" class="btn-primary" style="width:100%">Değiştir</button>
        </form>
      </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($u['must_change_password'] && ($_POST['action'] ?? '') !== 'password') {
    http_response_code(403);
    exit('Önce varsayılan yönetici şifresini değiştirin.');
}

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $notice = 'Oturum süresi doldu.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'password') {
            $cur = $_POST['current'] ?? '';
            $new = $_POST['new'] ?? '';
            if (!password_verify($cur, $u['password_hash'])) {
                $notice = 'Mevcut şifre hatalı.';
            } elseif (strlen($new) < 6) {
                $notice = 'Yeni şifre en az 6 karakter olmalı.';
            } else {
                db()->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
                $notice = 'Şifre güncellendi.';
                $u = current_user();
            }
        } elseif ($action === 'approve' || $action === 'reject') {
            $pid = (int) ($_POST['pid'] ?? 0);
            $pay = db()->prepare("SELECT * FROM payments WHERE id = ? AND status = 'pending'");
            $pay->execute([$pid]);
            $payment = $pay->fetch();
            if ($payment) {
                if ($action === 'approve') {
                    $expires = approve_bank_payment($pid);
                    $notice = "Ödeme onaylandı, Pro plan $expires tarihine kadar aktif.";
                } else {
                    db()->prepare("UPDATE payments SET status = 'rejected', reviewed_at = datetime('now') WHERE id = ? AND status = 'pending'")
                        ->execute([$pid]);
                    $notice = 'Ödeme reddedildi.';
                }
            }
        } elseif ($action === 'settings') {
            set_setting('iban', trim($_POST['iban'] ?? ''));
            set_setting('account_name', trim($_POST['account_name'] ?? ''));
            set_setting('pro_price', trim($_POST['pro_price'] ?? (string) PRO_PRICE));
            set_setting('iyzico_api_key', trim($_POST['iyzico_api_key'] ?? ''));
            set_setting('iyzico_secret_key', trim($_POST['iyzico_secret_key'] ?? ''));
            set_setting('iyzico_sandbox', isset($_POST['iyzico_sandbox']) ? '1' : '0');
            $notice = 'Ayarlar kaydedildi.';
        } elseif ($action === 'deactivate') {
            $uid = (int) ($_POST['uid'] ?? 0);
            db()->prepare("UPDATE users SET plan = 'free', plan_expires = NULL WHERE id = ? AND is_admin = 0")
                ->execute([$uid]);
            $notice = 'Kullanıcı ücretsiz plana düşürüldü.';
        } elseif ($action === 'post_save') {
            $title = trim($_POST['title'] ?? '');
            $excerpt = trim($_POST['excerpt'] ?? '');
            $content = (string) ($_POST['content'] ?? '');
            $published = !empty($_POST['published']) ? 1 : 0;

            if ($title === '') {
                $notice = 'Başlık boş olamaz.';
            } else {
                // Türkçe karakter dostu slug üret
                $tr = ['ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'İ' => 'i',
                       'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u'];
                $slug = strtr(mb_strtolower($title, 'UTF-8'), $tr);
                $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                $slug = trim($slug, '-');
                if ($slug === '') {
                    $slug = 'yazi-' . time();
                }

                // Slug çakışmasını önle (varsa sayı ekle)
                $base = $slug;
                $i = 2;
                while (db()->query("SELECT 1 FROM posts WHERE slug = " . db()->quote($slug))->fetch()) {
                    $slug = $base . '-' . $i;
                    $i++;
                }

                db()->prepare("INSERT INTO posts (title, slug, excerpt, content, published) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$title, $slug, $excerpt, $content, $published]);
                $notice = 'Yazı kaydedildi' . ($published ? ' ve yayınlandı.' : ' (taslak).');
            }
        }
    }
    e_redirect('admin.php?' . ($notice ? 'n=' . rawurlencode($notice) : ''));
}

$notice = $_GET['n'] ?? '';

$users = db()->query("SELECT id, email, name, plan, plan_expires, is_admin, created_at FROM users ORDER BY id DESC")->fetchAll();
$payments = db()->query("SELECT p.*, u.email FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 50")->fetchAll();
$pendingCount = db()->query("SELECT COUNT(*) c FROM payments WHERE status = 'pending'")->fetch()['c'];
$partnerCommissions = db()->query("SELECT pc.*, u.email AS partner_email, ru.email AS referred_email
    FROM partner_commissions pc
    JOIN users u ON u.id = pc.partner_id
    JOIN users ru ON ru.id = pc.referred_user_id
    ORDER BY pc.id DESC LIMIT 50")->fetchAll();

$pageTitle = 'Yönetim Paneli — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar"><h1>Yönetim Paneli</h1></div>
  <?php if ($notice): ?><div class="flash flash-info" style="margin:0 0 16px"><?= h($notice) ?></div><?php endif; ?>

  <div class="kpi-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
    <div class="kpi"><div class="k-label">Kullanıcı</div><div class="k-value"><?= count($users) ?></div></div>
    <div class="kpi"><div class="k-label">Pro</div><div class="k-value"><?= count(array_filter($users, fn($x) => $x['plan'] === 'pro')) ?></div></div>
    <div class="kpi"><div class="k-label">Bekleyen Ödeme</div><div class="k-value"><?= (int)$pendingCount ?></div></div>
    <div class="kpi"><div class="k-label">Toplam Ürün</div><div class="k-value"><?= (int) db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'] ?></div></div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Bekleyen Ödemeler</h2>
      <?php $pends = array_filter($payments, fn($p) => $p['status'] === 'pending'); ?>
      <?php if (!$pends): ?><p class="hint">Bekleyen ödeme yok.</p><?php else: ?>
      <table>
        <tr><th>Ref</th><th>Kullanıcı</th><th>Tutar</th><th></th></tr>
        <?php foreach ($pends as $p): ?>
        <tr>
          <td class="pay-ref" style="font-size:12px"><?= h($p['reference']) ?></td>
          <td><?= h($p['email']) ?></td>
          <td><?= tl((float)$p['amount']) ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
              <button class="btn-primary" style="padding:5px 10px;font-size:12px">Onayla</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
              <button class="btn-danger" style="padding:5px 10px;font-size:12px">Reddet</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Kullanıcılar</h2>
      <table>
        <tr><th>E-posta</th><th>Plan</th><th></th></tr>
        <?php foreach ($users as $usr): ?>
        <tr>
          <td><?= h($usr['email']) ?><?= $usr['is_admin'] ? ' <span class="pro-badge">ADMIN</span>' : '' ?></td>
          <td>
            <span class="plan-badge <?= $usr['plan'] === 'pro' ? 'plan-pro' : 'plan-free' ?>"><?= strtoupper($usr['plan']) ?></span>
            <?php if ($usr['plan'] === 'pro' && $usr['plan_expires']): ?><div class="hint"><?= h($usr['plan_expires']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if (!$usr['is_admin'] && $usr['plan'] === 'pro'): ?>
            <form method="post" onsubmit="return confirm('Pro planı iptal et?')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="deactivate">
              <input type="hidden" name="uid" value="<?= (int)$usr['id'] ?>">
              <button class="btn-danger" style="padding:5px 10px;font-size:12px">İptal</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <div class="grid" style="margin-top:20px">
    <div class="card">
      <h2>Partner Hakedişleri</h2>
      <?php if (!$partnerCommissions): ?><p class="hint">Henüz partner hakedişi yok.</p><?php else: ?>
      <table>
        <tr><th>Partner</th><th>Yönlendirilen</th><th>Hakediş</th><th>Durum</th></tr>
        <?php foreach ($partnerCommissions as $pc): ?>
        <tr>
          <td><?= h($pc['partner_email']) ?></td>
          <td><?= h($pc['referred_email']) ?></td>
          <td><?= tl((float)$pc['amount']) ?></td>
          <td><span class="plan-badge <?= $pc['status'] === 'paid' ? 'plan-pro' : 'plan-free' ?>"><?= $pc['status'] === 'paid' ? 'ÖDENDİ' : 'BEKLİYOR' ?></span></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Ödeme Ayarları</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="settings">
        <div class="row"><label>Hesap Sahibi</label><input type="text" name="account_name" value="<?= h(setting('account_name')) ?>"></div>
        <div class="row"><label>IBAN</label><input type="text" name="iban" value="<?= h(setting('iban')) ?>" placeholder="TR00 0000 0000 0000 0000 0000 00"></div>
        <div class="row"><label>Pro Aylık Ücret (₺)</label><input type="number" name="pro_price" value="<?= h(setting('pro_price')) ?>" step="0.01"></div>
        <div class="row"><label>iyzico API Key</label><input type="text" name="iyzico_api_key" value="<?= h(setting('iyzico_api_key')) ?>" autocomplete="off"></div>
        <div class="row"><label>iyzico Secret Key</label><input type="text" name="iyzico_secret_key" value="<?= h(setting('iyzico_secret_key')) ?>" autocomplete="off"></div>
        <div class="row"><label><input type="checkbox" name="iyzico_sandbox" value="1" <?= setting('iyzico_sandbox') === '1' ? 'checked' : '' ?> style="width:auto;margin-right:8px"> Sandbox (test) modu</label></div>
        <button class="btn-primary">Kaydet</button>
        <div class="hint">IBAN havale için gösterilir. iyzico key'leri doldurulursa kullanıcılar kartla ödeyebilir (sandbox = test).</div>
      </form>
    </div>
    <div class="card">
      <h2>Şifre Değiştir</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="password">
        <div class="row"><label>Mevcut Şifre</label><input type="password" name="current" required></div>
        <div class="row"><label>Yeni Şifre</label><input type="password" name="new" required minlength="6"></div>
        <button class="btn-primary">Değiştir</button>
      </form>
    </div>
  </div>

  <div class="card" style="margin-top:20px">
    <h2>Blog Yazısı Ekle</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="post_save">
      <div class="row"><label>Başlık</label><input type="text" name="title" required></div>
      <div class="row"><label>Özet (excerpt)</label><input type="text" name="excerpt"></div>
      <div class="row"><label>İçerik</label><textarea name="content" rows="8" style="width:100%;min-height:120px"></textarea></div>
      <div class="row" style="flex-direction:row;align-items:center;gap:8px">
        <input type="checkbox" name="published" value="1" id="blog-published" checked>
        <label for="blog-published" style="margin:0">Yayınla</label>
      </div>
      <button class="btn-primary">Kaydet</button>
      <div class="hint">Kaydedilen yazılar <a href="blog.php">Blog</a> sayfasında görünür.</div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
