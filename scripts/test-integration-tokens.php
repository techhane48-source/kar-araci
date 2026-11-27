<?php
declare(strict_types=1);

// Isolated DB only; never runs against production data.
$path = tempnam(sys_get_temp_dir(), 'kf-integration-');
if ($path === false) throw new RuntimeException('Cannot allocate test database');
define('DB_PATH', $path);
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "OK: $label\n";
}
try {
    require __DIR__ . '/../includes/db.php';
    require __DIR__ . '/../includes/api_tokens.php';
    $pdo = db();
    $pdo->exec("INSERT INTO settings (key,value) VALUES ('integration_test_marker','preserve')");
    $pdo->exec('DROP TABLE tariff_bands');
    $pdo->exec('DROP TABLE api_tokens');
    $pdo->exec('PRAGMA user_version = 12');
    migrate();
    migrate();
    check((int)$pdo->query('PRAGMA user_version')->fetchColumn() === 13, 'v12 to v13 and repeat migration');
    check(setting('integration_test_marker') === 'preserve', 'existing settings preserved');
    check($pdo->query('PRAGMA quick_check')->fetchColumn() === 'ok', 'SQLite integrity');
    $uid = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    check($uid > 0, 'existing user');
    $token = api_token_create($uid, 'Integration test');
    $row = $pdo->query('SELECT id,token_hash FROM api_tokens')->fetch();
    check($row['token_hash'] === hash('sha256', $token), 'only token hash stored');
    check((int)api_token_user($token)['id'] === $uid, 'token resolves owner');
    check(api_token_user('invalid') === null, 'invalid token rejected');
    api_token_delete($uid + 1, (int)$row['id']);
    check(api_token_user($token) !== null, 'cross-user deletion rejected');
    api_token_delete($uid, (int)$row['id']);
    check(api_token_user($token) === null, 'owner revokes token');
    check(count(api_tokens_list($uid)) === 0, 'revoked token not listed');
} finally {
    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (is_file($file)) unlink($file);
    }
}
