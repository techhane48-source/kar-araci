<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

// Tek tıkla demo hesabı: örnek ürünlerle dolu geçici bir kullanıcı oluşturur ve oturum açar.
// Demo hesapları is_demo=1 bayrağıyla işaretlenir; gerçek kullanıcı değildir.

if (current_user()) {
    e_redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD']!=='POST'||!csrf_verify($_POST['csrf'] ?? null)) {
    flash_set('error', 'Oturum süresi doldu.');
    e_redirect('index.php');
}

// Benzersiz demo e-posta
$email = 'demo' . bin2hex(random_bytes(12)) . '@demo.karfiyat';
$name = 'Demo Kullanıcı';

$pdo=db();$pdo->exec('BEGIN IMMEDIATE');
try {
db()->prepare("INSERT INTO users (email, name, password_hash, plan, is_demo, plan_expires) VALUES (?, ?, ?, 'pro', 1, date('now','+1 day'))")
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
$ins = db()->prepare("INSERT INTO products (user_id, barcode,name, marketplace, category, rate, cost, price, cargo, service_fee, stopaj, return_rate)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$order=db()->prepare("INSERT INTO orders(user_id,marketplace,order_number,package_number,status,total_price,order_date,lines) VALUES(?,?,?,?,?,?,?,?)");
foreach ($sample as $i=>$s) {
    $barcode='DEMO-'.($i+1);
    $ins->execute([$uid,$barcode,$s[0],$s[1],$s[2],category_rate($s[2],$s[1]),$s[3],$s[4],$s[5],$s[6],$s[7],$s[8]]);
    $order->execute([$uid,$s[1],'DEMO-ORDER-'.($i+1),'DEMO-PACKAGE-'.($i+1),'Delivered',$s[4],time()-$i*3600,json_encode([['barcode'=>$barcode,'quantity'=>1,'price'=>$s[4]]],JSON_THROW_ON_ERROR)]);
}
$pdo->exec('COMMIT');
}catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}

session_boot();
$_SESSION['uid'] = $uid;
session_regenerate_id(true);
$_SESSION['auth_version']=(int)db()->query('SELECT auth_version FROM users WHERE id='.(int)$uid)->fetchColumn();
flash_set('success', 'Demo hesabın 1 saat açık. Örnek ürün ve siparişlerle analizleri deneyebilirsin; gerçek mağaza bağlantısı ve ödeme işlemi yapılmaz.');
e_redirect('dashboard.php');
