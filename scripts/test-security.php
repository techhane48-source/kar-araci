<?php
declare(strict_types=1);

$path = getenv('KARFIYAT_DB');
if (!$path || realpath($path) === realpath(__DIR__ . '/../data/app.sqlite')) {
    throw new RuntimeException('Ayrı test veritabanı için KARFIYAT_DB ayarlayın.');
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tracker-http.php';
$failed = 0;
function check(bool $ok, string $label): void {
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $failed++;
}

// Gerçek ağ isteği yapılmadan URL ve IP politikası sınanır.
foreach (['https://www.trendyol.com/urun?x=1', 'https://www.amazon.com.tr/dp/123'] as $url) {
    check(tracker_host($url) !== '', 'Pazaryeri HTTPS adresi kabul edilir');
}
foreach (['http://www.trendyol.com', 'file:///etc/passwd', 'https://127.0.0.1',
    'https://localhost', 'https://169.254.169.254', 'https://[::1]',
    'https://www.trendyol.com.evil.example', 'https://evil.example@www.trendyol.com',
    'https://www.trendyol.com:8443', 'https://www.trendyol.com./',
    "https://www.trendyol.com\\@localhost", "https://www.trendyol.com\n"] as $url) {
    try { tracker_host($url); $blocked = false; }
    catch (InvalidArgumentException $e) { $blocked = true; }
    check($blocked, 'Geçersiz/özel/kimlik içeren URL reddedilir');
}
foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254',
    '100.64.0.1', '192.0.2.1', '198.18.0.1', '224.0.0.1', '0.0.0.0', '::1'] as $ip) {
    check(!tracker_public_ipv4($ip), 'Özel/rezerve IP reddedilir: ' . $ip);
}
check(tracker_public_ipv4('93.184.216.34'), 'Genel IPv4 kabul edilir');
check(tracker_link('javascript:alert(1)') === '#', 'Eski geçersiz link pasifleştirilir');

$jar = tempnam(sys_get_temp_dir(), 'kf-security-cookie-');
$ch = curl_init();
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEJAR=>$jar,
    CURLOPT_COOKIEFILE=>$jar, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>10]);
function request(string $page, ?array $data = null): array {
    global $ch;
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8090/' . $page);
    curl_setopt($ch, CURLOPT_POST, $data !== null);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException(curl_error($ch));
    return ['status'=>curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body'=>$body];
}
function token(string $body): string {
    if (!preg_match('/name="csrf" value="([^"]+)"/', $body, $m)) throw new RuntimeException('CSRF bulunamadı.');
    return $m[1];
}
function userRow(int $id): array {
    $q=db()->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); return $q->fetch();
}

// 2FA aktif kullanıcıda yeniden kurma/kurulum onayı mevcut korumayı değiştirmemeli.
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$email = 'security-' . bin2hex(random_bytes(6)) . '@example.test';
db()->prepare("INSERT INTO users (email, password_hash, totp_secret, totp_enabled) VALUES (?, ?, ?, 1)")
    ->execute([$email, password_hash('Security-Test-Only!', PASSWORD_DEFAULT), $secret]);
$id=(int)db()->lastInsertId();
db()->prepare("UPDATE users SET plan='pro' WHERE id=?")->execute([$id]);
$r=request('giris.php'); $csrf=token($r['body']);
$r=request('giris.php',['csrf'=>$csrf,'email'=>$email,'password'=>'Security-Test-Only!']);
check(str_contains($r['body'], 'Doğrulama Kodu'), 'Girişte TOTP istenir');
$r=request('giris.php',['csrf'=>$csrf,'action'=>'2fa','code'=>totp_code($secret)]);
check($r['status']===302, 'TOTP ile giriş yapılır');
$r=request('hesabim.php'); $csrf=token($r['body']);
$count = db()->query('SELECT COUNT(*) FROM trackers')->fetchColumn();
request('pazar-zekasi.php',['csrf'=>$csrf,'action'=>'add','name'=>'Unsafe','url'=>'https://127.0.0.1']);
check(db()->query('SELECT COUNT(*) FROM trackers')->fetchColumn() === $count, 'HTTP eylemi geçersiz takip kaydetmez');
request('pazar-zekasi.php',['csrf'=>$csrf,'action'=>'add','name'=>'Fixture','url'=>'https://www.trendyol.com/fixture']);
check((int)db()->query('SELECT COUNT(*) FROM trackers')->fetchColumn() === (int)$count+1, 'HTTP eylemi izinli takip kaydeder');
request('hesabim.php',['csrf'=>$csrf,'action'=>'2fa_setup','current'=>'Security-Test-Only!']);
$after=userRow($id);
check((int)$after['totp_enabled']===1 && $after['totp_secret']===$secret, 'Aktif 2FA yeniden kurma ile kapatılamaz');
request('hesabim.php',['csrf'=>$csrf,'action'=>'2fa_confirm','code'=>totp_code($secret)]);
$after=userRow($id);
check((int)$after['totp_enabled']===1 && $after['totp_secret']===$secret, 'Doğrudan onay mevcut 2FA sırrını değiştirmez');
request('hesabim.php',['csrf'=>$csrf,'action'=>'2fa_disable','code'=>'invalid']);
check((int)userRow($id)['totp_enabled']===1, 'Yanlış kodla 2FA kapatılamaz');
request('hesabim.php',['csrf'=>$csrf,'action'=>'2fa_disable','code'=>totp_code($secret)]);
check((int)userRow($id)['totp_enabled']===0, 'Mevcut doğru kodla 2FA kapatılır');
$r=request('hesabim.php',['csrf'=>$csrf,'action'=>'2fa_setup','current'=>'wrong']);
check(!str_contains($r['body'],'class="pay-ref" style="word-break:break-all">'), 'Yanlış şifreyle yeni sır üretilmez');
$r=request('hesabim.php',['csrf'=>$csrf,'action'=>'2fa_setup','current'=>'Security-Test-Only!']);
check((int)userRow($id)['totp_enabled']===0 && userRow($id)['totp_secret']===null, 'Onaylanmamış sır DB’ye yazılmaz');
preg_match('/class="pay-ref" style="word-break:break-all">([A-Z2-7]+)</', $r['body'], $m);
$new=$m[1]??'';
check($new!=='' && $new!==$secret, 'Şifreyle yeni kurulum başlatılır');
$r=request('hesabim.php',['csrf'=>$csrf,'action'=>'2fa_confirm','code'=>totp_code($new)]);
$after=userRow($id);
check((int)$after['totp_enabled']===1 && $after['totp_secret']===$new, 'Yeni sır yalnızca geçerli kodla kalıcı olur');
// CLI sıfırlamasının kullandığı sürüm değişimi, eski oturumu düşürmeli.
db()->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$id]);
check(request('hesabim.php')['status']===302, 'Kimlik sürümü değişince eski oturum geçersizleşir');
curl_close($ch); unlink($jar);
echo $failed ? "$failed test başarısız\n" : "TÜM TESTLER GEÇTİ\n";
exit($failed ? 1 : 0);
