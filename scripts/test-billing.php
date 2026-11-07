<?php
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'kf-billing-');
putenv('KARFIYAT_DB=' . $path);
require_once __DIR__ . '/../includes/functions.php';
register_shutdown_function(static function () use ($path): void {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) unlink($file);
    }
});
$failed = 0;
function check(bool $condition, string $label): void {
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failed++;
}
function rejected(callable $action): bool {
    try { $action(); return false; } catch (Throwable $e) { return true; }
}
function scalar(string $sql): mixed { return db()->query($sql)->fetchColumn(); }

check(!csrf_verify(''), 'Boş CSRF reddedilir');
$csrf = csrf_token();
check(csrf_verify($csrf) && !csrf_verify('wrong'), 'CSRF doğrulaması');
db()->exec("INSERT INTO users (email, password_hash) VALUES ('partner@test.local', 'unused')");
$partner = (int)db()->lastInsertId();
db()->prepare("INSERT INTO users (email, password_hash, referred_by) VALUES ('buyer@test.local', 'unused', ?)")->execute([$partner]);
$buyer = (int)db()->lastInsertId();
db()->exec("INSERT INTO users (email, password_hash) VALUES ('other@test.local', 'unused')");
$other = (int)db()->lastInsertId();
db()->prepare('INSERT INTO checkout_sessions (token, user_id, basket_id, amount) VALUES (?, ?, ?, ?)')
    ->execute(['token-one', $buyer, 'basket-one', '149.00']);
$valid = ['status'=>'success', 'paymentStatus'=>'SUCCESS', 'fraudStatus'=>1,
    'basketId'=>'basket-one', 'currency'=>'TRY', 'price'=>'149.00', 'paidPrice'=>'149.00', 'paymentId'=>'payment-one'];
foreach (['status'=>'failure', 'paymentStatus'=>'FAILURE', 'fraudStatus'=>0,
    'basketId'=>'other-basket', 'currency'=>'USD', 'price'=>'1.00', 'paidPrice'=>'1.00',
    'paymentId'=>'', 'token'=>'other-token'] as $key=>$value) {
    $bad = array_replace($valid, [$key=>$value]);
    check(rejected(fn()=>complete_checkout('token-one', $bad)), "Yanlış $key reddedilir");
}
check(rejected(fn()=>complete_checkout('unknown', $valid)), 'Bilinmeyen token reddedilir');
check((int)scalar('SELECT COUNT(*) FROM payments') === 0, 'Başarısız doğrulamalar ödeme oluşturmaz');
check(scalar("SELECT plan FROM users WHERE id = $buyer") === 'free', 'Başarısız doğrulamalar planı değiştirmez');
check(complete_checkout('token-one', $valid), 'Doğrulanan ödeme onaylanır');
$expires = scalar("SELECT plan_expires FROM users WHERE id = $buyer");
check(scalar("SELECT plan FROM users WHERE id = $buyer") === 'pro', 'Kayıttaki kullanıcı Pro olur');
check(scalar("SELECT plan FROM users WHERE id = $other") === 'free', 'Başka kullanıcı etkilenmez');
check(!complete_checkout('token-one', $valid), 'Tekrar bildirim etkisizdir');
check(scalar("SELECT plan_expires FROM users WHERE id = $buyer") === $expires, 'Tekrar bildirim süreyi uzatmaz');
check((int)scalar('SELECT COUNT(*) FROM payments') === 1, 'Tek ödeme kaydı');
check((int)scalar('SELECT COUNT(*) FROM partner_commissions') === 1, 'Tek partner hakedişi');

db()->prepare('INSERT INTO checkout_sessions (token, user_id, basket_id, amount) VALUES (?, ?, ?, ?)')
    ->execute(['token-two', $buyer, 'basket-two', '149.00']);
check(rejected(fn()=>complete_checkout('token-two', array_replace($valid, ['basketId'=>'basket-two']))), 'Aynı sağlayıcı ödeme ID tekrar kullanılamaz');
check(scalar("SELECT status FROM checkout_sessions WHERE token = 'token-two'") === 'pending', 'Başarısız işlem geri alınır');
// Partner kaydı yazılamazsa ödeme ve plan da geri alınmalı.
db()->exec("CREATE TRIGGER fail_commission BEFORE INSERT ON partner_commissions BEGIN SELECT RAISE(ABORT, 'test'); END");
$second = array_replace($valid, ['basketId'=>'basket-two', 'paymentId'=>'payment-two']);
check(rejected(fn()=>complete_checkout('token-two', $second)), 'Hakediş hatası işlemi durdurur');
check(scalar("SELECT plan_expires FROM users WHERE id = $buyer") === $expires, 'Hata sonrası abonelik geri alınır');
check((int)scalar('SELECT COUNT(*) FROM payments') === 1, 'Hata sonrası ödeme geri alınır');
db()->exec('DROP TRIGGER fail_commission');
db()->exec("INSERT INTO payments (user_id, amount, reference) VALUES ($buyer, 149, 'bank-one')");
$bank = (int)db()->lastInsertId();
check(approve_bank_payment($bank) > $expires, 'Havale mevcut süreyi uzatır');
check(approve_bank_payment($bank) === '', 'Havale ikinci defa onaylanmaz');
check((int)scalar('SELECT COUNT(*) FROM partner_commissions') === 2, 'Havale hakedişi yalnızca bir kez');
// Ay sonu yenilemede Mart ayına taşma olmamalı.
db()->exec("UPDATE users SET plan_expires = '2030-01-31' WHERE id = $buyer");
db()->exec("INSERT INTO payments (user_id, amount, reference) VALUES ($buyer, 149, 'bank-month-end')");
check(approve_bank_payment((int)db()->lastInsertId()) === '2030-02-28', 'Ay sonu yenileme Şubat sonuna sınırlandırılır');

echo $failed ? "$failed test başarısız\n" : "TÜM TESTLER GEÇTİ\n";
exit($failed ? 1 : 0);
