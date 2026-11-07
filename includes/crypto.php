<?php
declare(strict_types=1);

/**
 * API kimlik bilgilerini (satıcı API secret vb.) şifreli saklamak için yardımcılar.
 * Anahtar ilk çalıştırmada data/.enc-key dosyasına rastgele üretilir ve web kökünden
 * dışarıda tutulur. Veritabanı çalınsa bile anahtar olmadan çözülemez.
 */

function enc_key(): string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.enc-key';
    if (file_exists($path)) {
        $key = file_get_contents($path);
        if ($key !== false && strlen($key) === 64) {
            return $key;
        }
    }
    $key = bin2hex(random_bytes(32)); // 64 hex karakter = 32 bayt
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0775, true);
    }
    @file_put_contents($path, $key);
    return $key;
}

function encrypt_string(string $plaintext): string
{
    if ($plaintext === '') return '';
    $key = hex2bin(enc_key());
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function decrypt_string(string $ciphertext): string
{
    if ($ciphertext === '') return '';
    $key = hex2bin(enc_key());
    $data = base64_decode($ciphertext);
    if ($data === false || strlen($data) < 17) return '';
    $iv = substr($data, 0, 16);
    $cipher = substr($data, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}
