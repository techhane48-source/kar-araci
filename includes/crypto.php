<?php
declare(strict_types=1);

/** The database and its encryption key must be backed up together, outside the web root. */
function enc_key(): string
{
    $path=dirname(defined('DB_PATH')?DB_PATH:dirname(__DIR__).'/data/app.sqlite').'/.enc-key';
    if (is_file($path)) {
        $key=file_get_contents($path);
        if (!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new RuntimeException('Şifreleme anahtarı geçersiz; yedeği geri yükleyin.');
        @chmod($path,0600);return $key;
    }
    if (function_exists('db') && defined('DB_PATH') && is_file(DB_PATH)) {
        foreach (['integrations'=>['api_key','api_secret'],'checkout_sessions'=>['provider_context']] as $table=>$columns) {
            $exists=db()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=".db()->quote($table))->fetchColumn();
            if (!$exists) continue;
            $available=array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name');
            foreach ($columns as $column) if(in_array($column,$available,true)) {
                $stored=db()->query("SELECT $column FROM $table WHERE $column IS NOT NULL AND $column<>'' LIMIT 1")->fetchColumn();
                if($stored===false)continue;
                $legacy=getenv('KARFIYAT_LEGACY_KEY')?:dirname(__DIR__).'/data/.enc-key';
                if(!str_starts_with((string)$stored,'v2:')&&$legacy!==$path&&is_file($legacy)) {
                    $old=file_get_contents($legacy);
                    if(is_string($old)&&preg_match('/^[a-f0-9]{64}$/D',$old)){enc_publish_key($path,$old);return enc_key();}
                }
                throw new RuntimeException('Şifreli kayıtlar var, anahtar eksik; veritabanıyla eşleşen anahtar yedeğini geri yükleyin.');
            }
        }
        if(db()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='settings'")->fetchColumn()&&db()->query("SELECT 1 FROM settings WHERE key IN ('iyzico_api_key','iyzico_secret_key') AND value LIKE 'secret:%' LIMIT 1")->fetchColumn())throw new RuntimeException('Ödeme ayarları şifreli, anahtar eksik; eşleşen yedeği geri yükleyin.');
    }
    if (!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true)&&!is_dir(dirname($path))) throw new RuntimeException('Şifreleme anahtarı dizini açılamadı.');
    enc_publish_key($path,bin2hex(random_bytes(32)));
    return enc_key();
}

function enc_publish_key(string $path,string $key): void
{
    $temp=tempnam(dirname($path),'.enc-key-');
    if($temp===false)throw new RuntimeException('Şifreleme anahtarı oluşturulamadı.');
    try {
        chmod($temp,0600);
        if(file_put_contents($temp,$key)!==64)throw new RuntimeException('Şifreleme anahtarı yazılamadı.');
        if(!@link($temp,$path)) {
            if(is_file($path))return;
            throw new RuntimeException('Şifreleme anahtarı oluşturulamadı.');
        }
    } finally {unlink($temp);}
}

function encrypt_string(string $plaintext): string
{
    if($plaintext==='')return '';
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plaintext,'aes-256-gcm',hex2bin(enc_key()),OPENSSL_RAW_DATA,$iv,$tag,'karfiyat-v2',16);
    if($cipher===false)throw new RuntimeException('Kimlik bilgileri şifrelenemedi.');
    return 'v2:'.base64_encode($iv.$tag.$cipher);
}

function decrypt_string(string $ciphertext): string
{
    if($ciphertext==='')return '';
    $key=hex2bin(enc_key());
    if(str_starts_with($ciphertext,'v2:')) {
        $data=base64_decode(substr($ciphertext,3),true);
        if($data===false||strlen($data)<29)return '';
        $plain=openssl_decrypt(substr($data,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16),'karfiyat-v2');
    } else {
        // Previously stored CBC records remain readable; new writes always use authenticated encryption.
        $data=base64_decode($ciphertext,true);
        if($data===false||strlen($data)<32)return '';
        $plain=openssl_decrypt(substr($data,16),'aes-256-cbc',$key,OPENSSL_RAW_DATA,substr($data,0,16));
    }
    return $plain===false?'':$plain;
}
