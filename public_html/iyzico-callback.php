<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

/**
 * iyzico CheckoutForm callback'i.
 *
 * iyzico ödeme sonrası kullanıcının tarayıcısını bu sayfaya BAŞKA BİR SİTEDEN POST ile gönderir.
 * Oturum çerezi SameSite=Lax olduğu için bu istekte gelmez; bu yüzden kullanıcı oturumdan değil,
 * iyzico'dan doğrulanan ödeme sonucundaki basketId'den (KF-<kullanıcıId>-<zaman>) bulunur.
 * Sonuç her zaman iyzico API'sinden token ile sorgulanır; tarayıcıdan gelen hiçbir alana güvenilmez.
 */

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
if ($token === '') {
    e_redirect('odeme.php?odeme=eksik');
}

$iyzicoKey = setting('iyzico_api_key');
$iyzicoSecret = setting('iyzico_secret_key');
$iyzicoSandbox = setting('iyzico_sandbox') === '1';
if ($iyzicoKey === '' || $iyzicoSecret === '') {
    e_redirect('odeme.php?odeme=yapilandirilmamis');
}

require_once __DIR__ . '/../includes/iyzico.php';
$client = new IyzicoClient($iyzicoKey, $iyzicoSecret, $iyzicoSandbox);
$res = $client->retrieveCheckoutFormResult($token);
if (!$res['ok'] || !is_array($res['result'] ?? null)) {
    e_redirect('odeme.php?odeme=dogrulanamadi');
}

$result = $res['result'];
$paymentStatus = (string)($result['paymentStatus'] ?? '');
$fraudStatus = (int)($result['fraudStatus'] ?? 0);
$basketId = (string)($result['basketId'] ?? '');
$paidPrice = (float)($result['paidPrice'] ?? 0);
$paymentId = (string)($result['paymentId'] ?? '');
$expected = (float) setting('pro_price');

if ($paymentStatus !== 'SUCCESS' || $fraudStatus !== 1) {
    e_redirect('odeme.php?odeme=basarisiz');
}
if (!preg_match('/^KF-(\d+)-\d+$/', $basketId, $m)) {
    e_redirect('odeme.php?odeme=dogrulanamadi');
}
if ($paidPrice + 0.01 < $expected) {
    // Ödenen tutar plan fiyatının altında: otomatik aktif etme, yönetici incelesin.
    error_log("iyzico: eksik tutar basket=$basketId paid=$paidPrice expected=$expected");
    e_redirect('odeme.php?odeme=tutar');
}

$userId = (int)$m[1];
$reference = 'IYZ-' . ($paymentId !== '' ? $paymentId : substr(hash('sha256', $token), 0, 24));

$db = db();
$db->beginTransaction();
try {
    // Çift işleme koruması (kullanıcı sayfayı yenilerse ya da iyzico tekrar gönderirse)
    $dup = $db->prepare("SELECT id FROM payments WHERE reference = ? AND status = 'approved'");
    $dup->execute([$reference]);
    if ($dup->fetch()) {
        $db->commit();
        e_redirect('odeme.php?odeme=zaten');
    }

    $target = $db->prepare("SELECT plan_expires, referred_by FROM users WHERE id = ?");
    $target->execute([$userId]);
    $t = $target->fetch();
    if (!$t) {
        $db->rollBack();
        error_log("iyzico: kullanıcı bulunamadı basket=$basketId");
        e_redirect('odeme.php?odeme=dogrulanamadi');
    }

    $base = ($t['plan_expires'] && $t['plan_expires'] >= date('Y-m-d')) ? $t['plan_expires'] : date('Y-m-d');
    $expires = date('Y-m-d', strtotime($base . ' +1 month'));

    $db->prepare("UPDATE users SET plan = 'pro', plan_expires = ? WHERE id = ?")->execute([$expires, $userId]);
    $db->prepare("INSERT INTO payments (user_id, amount, reference, status, reviewed_at) VALUES (?, ?, ?, 'approved', datetime('now'))")
       ->execute([$userId, $paidPrice, $reference]);

    // Partner hakedişi (yönlendiren kullanıcıya %15)
    if (!empty($t['referred_by'])) {
        $db->prepare("INSERT INTO partner_commissions (partner_id, referred_user_id, amount, month, status) VALUES (?, ?, ?, ?, 'pending')")
           ->execute([(int)$t['referred_by'], $userId, $paidPrice * 0.15, date('Y-m')]);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('iyzico callback hatası: ' . $e->getMessage());
    e_redirect('odeme.php?odeme=dogrulanamadi');
}

e_redirect('odeme.php?odeme=ok');
