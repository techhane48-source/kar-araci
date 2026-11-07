<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

/**
 * iyzico CheckoutForm callback'i. Ödeme sonrası iyzico bu sayfaya token ile
 * yönlendirir; sonucu doğrulayıp başarılıysa Pro planı aktif eder.
 */

$u = require_login();

$token = $_POST['token'] ?? $_GET['token'] ?? '';
if ($token === '') {
    flash_set('error', 'Ödeme doğrulama bilgisi eksik.');
    e_redirect('odeme.php');
}

$iyzicoKey = setting('iyzico_api_key');
$iyzicoSecret = setting('iyzico_secret_key');
$iyzicoSandbox = setting('iyzico_sandbox') === '1';

if ($iyzicoKey === '' || $iyzicoSecret === '') {
    flash_set('error', 'Kartlı ödeme yapılandırılmamış.');
    e_redirect('odeme.php');
}

require_once __DIR__ . '/../includes/iyzico.php';
$client = new IyzicoClient($iyzicoKey, $iyzicoSecret, $iyzicoSandbox);
$res = $client->retrieveCheckoutFormResult($token);

if (!$res['ok'] || !is_array($res['result'])) {
    flash_set('error', 'Ödeme doğrulanamadı: ' . ($res['error'] ?? 'Bilinmeyen hata'));
    e_redirect('odeme.php');
}

$result = $res['result'];
$paymentStatus = $result['paymentStatus'] ?? '';
$fraudStatus = (int)($result['fraudStatus'] ?? 0);
$reference = 'IYZ-' . substr($token, 0, 12);

// Çift ödeme koruması: aynı token zaten onaylanmışsa tekrar işleme
$dup = db()->prepare("SELECT id FROM payments WHERE reference = ? AND status = 'approved'");
$dup->execute([$reference]);
if ($dup->fetch()) {
    flash_set('info', 'Bu ödeme zaten kaydedilmiş.');
    e_redirect('odeme.php');
}

if ($paymentStatus === 'SUCCESS' && $fraudStatus === 1) {
    $amount = (float) setting('pro_price');

    // 1 aylık Pro tanımla (mevcut süre varsa üzerine ekle) — admin onayıyla aynı mantık
    $target = db()->prepare("SELECT plan_expires, referred_by FROM users WHERE id = ?");
    $target->execute([$u['id']]);
    $t = $target->fetch();
    $base = ($t['plan_expires'] && $t['plan_expires'] >= date('Y-m-d')) ? $t['plan_expires'] : date('Y-m-d');
    $expires = date('Y-m-d', strtotime($base . ' +1 month'));

    db()->prepare("UPDATE users SET plan = 'pro', plan_expires = ? WHERE id = ?")
        ->execute([$expires, $u['id']]);
    db()->prepare("INSERT INTO payments (user_id, amount, reference, status, reviewed_at)
                   VALUES (?, ?, ?, 'approved', datetime('now'))")
        ->execute([$u['id'], $amount, $reference]);

    // Partner hakedişi (yönlendiren kullanıcıya %15)
    if (!empty($t['referred_by'])) {
        $commission = $amount * 0.15;
        $month = date('Y-m');
        db()->prepare("INSERT INTO partner_commissions (partner_id, referred_user_id, amount, month, status)
                       VALUES (?, ?, ?, ?, 'pending')")
            ->execute([(int)$t['referred_by'], (int)$u['id'], $commission, $month]);
    }

    flash_set('success', "Ödemen alındı! Pro planın $expires tarihine kadar aktif.");
} else {
    flash_set('error', 'Ödeme başarısız oldu veya doğrulanamadı. Tekrar deneyin.');
}

e_redirect('odeme.php');
