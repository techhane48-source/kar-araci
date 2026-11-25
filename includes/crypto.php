<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/** New credentials use authenticated encryption. CBC records remain readable for migration. */
function enc_key(): string
{
    $path = dirname(DB_PATH) . DIRECTORY_SEPARATOR . '.enc-key';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true) && !is_dir(dirname($path)))
        throw new RuntimeException('Anahtar dizini oluşturulamadı.');
    $lock=fopen($path.'.lock','c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Anahtar kilidi alınamadı.');
    try {
        if (is_file($path)) {
            $key=file_get_contents($path);
            if (!is_string($key) || !preg_match('/^[0-9a-f]{64}$/iD',$key))
                throw new RuntimeException('Şifreleme anahtarı geçersiz; yedekten geri yükleyin.');
            return $key;
        }
        $key=bin2hex(random_bytes(32));
        $fh=fopen($path,'x');
        if(!$fh)throw new RuntimeException('Anahtar oluşturulamadı.');
        try {
            if (!chmod($path,0600) || fwrite($fh,$key)!==64 || !fflush($fh)) throw new RuntimeException('Anahtar kaydedilemedi.');
        } finally {fclose($fh);}
        return $key;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function encrypt_string(string $plaintext): string
{
    if($plaintext==='')return '';
    $nonce=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plaintext,'aes-256-gcm',hex2bin(enc_key()),OPENSSL_RAW_DATA,$nonce,$tag,'karfiyat:v2',16);
    if($cipher===false || strlen($tag)!==16)throw new RuntimeException('API bilgisi şifrelenemedi.');
    return 'v2:'.base64_encode($nonce.$tag.$cipher);
}

function decrypt_string(string $ciphertext): string
{
    if($ciphertext==='')return '';
    $key=hex2bin(enc_key());
    if(str_starts_with($ciphertext,'v2:')){
        $data=base64_decode(substr($ciphertext,3),true);
        if($data===false||strlen($data)<29)throw new RuntimeException('Şifreli API bilgisi geçersiz.');
        $plain=openssl_decrypt(substr($data,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16),'karfiyat:v2');
    } else {
        $data=base64_decode($ciphertext,true);
        if($data===false||strlen($data)<32)throw new RuntimeException('Eski API bilgisi geçersiz.');
        $plain=openssl_decrypt(substr($data,16),'aes-256-cbc',$key,OPENSSL_RAW_DATA,substr($data,0,16));
    }
    if($plain===false)throw new RuntimeException('API bilgisi çözülemedi. Anahtar yedeğini kontrol edin.');
    return $plain;
}
