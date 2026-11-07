<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

// Tek tıkla demo hesabı: örnek ürünlerle dolu geçici bir kullanıcı oluşturur ve oturum açar.
// Demo hesapları is_demo=1 bayrağıyla işaretlenir; gerçek kullanıcı değildir.

if (current_user()) {
    e_redirect('dashboard.php');
}

if (!csrf_verify($_POST['csrf'] ?? $_GET['csrf'] ?? null)) {
    flash_set('error', 'Oturum süresi doldu.');
    e_redirect('index.php');
}

// Benzersiz demo e-posta
$email = 'demo' . time() . '@demo.karfiyat';
$name = 'Demo Kullanıcı';

db()->prepare("INSERT INTO users (email, name, password_hash, plan, is_demo) VALUES (?, ?, ?, 'pro', 1)")
    ->execute([$email, $name, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
$uid = (int) db()->lastInsertId();

// Kendi referral kodu
db()->prepare("UPDATE users SET referral_code = ? WHERE id = ?")
    ->execute([generate_referral_code($uid), $uid]);

// Örnek ürünler (demo) — [name, marketplace, category, cost, price, cargo, service_fee, stopaj, return_rate]
$sample = [
    ['Kadın Bot 38', 'trendyol', 'Ayakkabı & Çanta', 200, 400, 50, 8, 3, 3],
    ['Nevresim Takımı', 'hepsiburada', 'Ev Tekstili', 150, 350, 40, 6, 2, 2],
    ['Kablosuz Kulaklık', 'amazon', 'Elektronik (genel)', 300, 550, 25, 5, 4, 4],
    ['Akıllı Saat', 'n11', 'Kol Saati', 500, 900, 30, 5, 6, 3],
    ['Kot Pantolon', 'trendyol', 'Giyim', 180, 320, 45, 5, 2, 6],
    ['Blender Seti', 'hepsiburada', 'Küçük Ev Aletleri', 250, 480, 35, 6, 2, 2],
];
$ins = db()->prepare("INSERT INTO products (user_id, name, marketplace, category, rate, cost, price, cargo, service_fee, stopaj, return_rate)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($sample as $s) {
    $ins->execute([$uid, $s[0], $s[1], $s[2], category_rate($s[2], $s[1]), $s[3], $s[4], $s[5], $s[6], $s[7], $s[8]]);
}

session_boot();
$_SESSION['uid'] = $uid;
flash_set('success', 'Demo hesabına hoş geldin! 6 örnek ürünle dolu. Gerçek hesabın için istediğin zaman ücretsiz kayıt olabilirsin.');
e_redirect('dashboard.php');
