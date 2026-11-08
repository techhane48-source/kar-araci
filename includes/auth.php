<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ---- Oturum + kimlik doğrulama ----

function session_boot(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('karfiyat');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(?string $token): bool
{
    session_boot();
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

function current_user(): ?array
{
    session_boot();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['uid']]);
    $u = $stmt->fetch();
    if($u && !empty($u['is_demo']) && ((strtotime($u['created_at'].' UTC')?:0)+3600<time())) {
        unset($_SESSION['uid'],$_SESSION['auth_version']);
        return null;
    }
    if ($u && (int)($_SESSION['auth_version'] ?? 0) !== (int)$u['auth_version']) {
        unset($_SESSION['uid'], $_SESSION['auth_version'], $_SESSION['2fa_pending'], $_SESSION['2fa_setup']);
        return null;
    }
    return $u ?: null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: giris.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
    return $u;
}

function is_pro(?array $u): bool
{
    if (!$u) return false;
    if ($u['plan'] !== 'pro') return false;
    if ($u['plan_expires'] !== null && $u['plan_expires'] < date('Y-m-d')) return false;
    return true;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function e_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $msg): void
{
    session_boot();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): ?array
{
    session_boot();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ---- 2FA (TOTP, RFC 6238 — Google Authenticator uyumlu) ----

function totp_base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buffer = 0; $bits = 0;
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
        $buffer = ($buffer << 8) | ord($bin[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $out .= $alphabet[($buffer >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    if ($bits > 0) {
        $out .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $out;
}

function totp_base32_decode(string $s): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Z2-7]/', '', $s));
    $out = '';
    $buffer = 0; $bits = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $v = strpos($alphabet, $s[$i]);
        if ($v === false) continue;
        $buffer = ($buffer << 5) | $v;
        $bits += 5;
        while ($bits >= 8) {
            $out .= chr(($buffer >> ($bits - 8)) & 255);
            $bits -= 8;
        }
    }
    return $out;
}

function totp_code(string $secret, int $timeSlice = 0): string
{
    $key = totp_base32_decode($secret);
    if ($timeSlice <= 0) $timeSlice = (int) floor(time() / 30);
    $counter = pack('N', 0) . pack('N', $timeSlice); // 8-byte big-endian (RFC 4226)
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $bin = ((ord($hash[$offset]) & 0x7F) << 24)
         | ((ord($hash[$offset+1]) & 0xFF) << 16)
         | ((ord($hash[$offset+2]) & 0xFF) << 8)
         | (ord($hash[$offset+3]) & 0xFF);
    return str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    // ±1 zaman dilimi toleransı (30 sn'lik pencere)
    for ($w = -1; $w <= 1; $w++) {
        if (hash_equals(totp_code($secret, (int)floor(time()/30) + $w), $code)) {
            return true;
        }
    }
    return false;
}

function generate_referral_code(int $userId): string
{
    return strtoupper(substr(md5('kf' . $userId . random_bytes(8)), 0, 8));
}
