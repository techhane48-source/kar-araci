<?php
declare(strict_types=1);
$path=tempnam(sys_get_temp_dir(),'kf-bootstrap-');
putenv('KARFIYAT_DB='.$path);
require_once __DIR__.'/../includes/auth.php';
register_shutdown_function(static function() use($path):void {
    foreach ([$path,$path.'-wal',$path.'-shm'] as $file) if(is_file($file)) unlink($file);
});
$failed=0;
function check(bool $ok,string $label):void {
    global $failed; echo ($ok?'PASS ':'FAIL ').$label."\n"; if(!$ok)$failed++;
}
check((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'Temiz kurulumda otomatik yönetici yok');
db()->prepare("INSERT INTO users(email,password_hash,is_admin,must_change_password) VALUES(?,?,1,1)")
    ->execute(['admin@karfiyat.local',password_hash('1234',PASSWORD_DEFAULT)]);
$id=(int)db()->lastInsertId();
// Eski sürümden yükseltmeyi simüle et; temiz kurulum işaretini kaldır.
db()->exec("DELETE FROM settings WHERE key='bootstrap_cleanup_v1'");
session_boot(); $_SESSION['uid']=$id;
migrate();
$u=db()->query('SELECT * FROM users WHERE id='.$id)->fetch();
check((int)$u['is_admin']===0 && !password_verify('1234',$u['password_hash']),'Eski bilinen şifreli yönetici devre dışı');
check(current_user()===null,'Eski kurulum yöneticisinin oturumu geçersiz');
$strong=password_hash('Strong-Test-Admin-Only!',PASSWORD_DEFAULT);
db()->prepare('UPDATE users SET is_admin=1,password_hash=? WHERE id=?')->execute([$strong,$id]);
db()->exec("DELETE FROM settings WHERE key='bootstrap_cleanup_v1'");
migrate();
check(db()->query('SELECT password_hash FROM users WHERE id='.$id)->fetchColumn()===$strong,'Şifresi değiştirilmiş yönetici korunur');
exit($failed?1:0);
