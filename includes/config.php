<?php
declare(strict_types=1);

// ---- Kâr&Fiyat — temel yapılandırma ----

define('APP_NAME', 'Kâr&Fiyat');
define('APP_TAGLINE', 'Pazaryeri net kâr hesabı ve fiyat optimizasyonu');

// Veritabanı web kökü DIŞINDA tutulur (public_html'in bir üstünde data/)
define('DB_PATH', getenv('KARFIYAT_DB') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'app.sqlite');

// Abonelik
define('PRO_PRICE', 149);          // aylık Pro ücreti (TL)
define('FREE_PRODUCT_LIMIT', 5);   // ücretsiz planda kayıtlı ürün sınırı

// Yönetici yalnızca scripts/create-admin.php ile sunucu terminalinden oluşturulur.

date_default_timezone_set('Europe/Istanbul');
ini_set('display_errors', '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
