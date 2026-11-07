<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Şifre komut argümanında veya logda bulunmaz; standart girdiden okunur.
$email = trim($argv[1] ?? '');
$reset = ($argv[2] ?? '') === '--reset';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Kullanım: php scripts/create-admin.php eposta [--reset] (şifre standart girdiden)\n");
    exit(1);
}
$password = rtrim((string)fgets(STDIN), "\r\n");
if (strlen($password) < 12) {
    fwrite(STDERR, "Yönetici şifresi en az 12 karakter olmalı.\n");
    exit(1);
}
require_once __DIR__ . '/../includes/db.php';
$pdo = db();
$pdo->exec('BEGIN IMMEDIATE');
try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    if ($existing && !$reset) throw new RuntimeException('Hesap zaten var. Bilinçli sıfırlama için --reset kullanın.');
    if ($existing) {
        $pdo->prepare("UPDATE users SET password_hash = ?, is_admin = 1, must_change_password = 0, plan = 'pro', plan_expires = NULL, totp_enabled = 0, totp_secret = NULL, auth_version = auth_version + 1 WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
    } else {
        $pdo->prepare("INSERT INTO users (email, name, password_hash, plan, is_admin) VALUES (?, 'Yönetici', ?, 'pro', 1)")
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
    }
    $pdo->exec('COMMIT');
    fwrite(STDOUT, "Yönetici hesabı hazır.\n");
} catch (Throwable $e) {
    $pdo->exec('ROLLBACK');
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
