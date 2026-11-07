# Kâr&Fiyat — Kurulum

Pazaryeri net kâr hesaplama ve fiyat optimizasyonu SaaS'ı. PHP 8+ ve SQLite (PDO) gerektirir, başka bağımlılık yok.

## Klasör yerleşimi (ÖNEMLİ)

Bu proje "klasik" yerleşimdedir: kod `public_html` DIŞINDA tutulur (güvenlik).

```
hesap kökü /
├── includes/         ← PHP kod (web'den erişilemez)
├── data/             ← SQLite DB + .htaccess (Require all denied)
├── scripts/          ← geliştirme/test (isteğe bağlı)
└── public_html/      ← web kökü (index.php, assets, ...)
```

## Hostinger'a yükleme

1. Zip'i **hesap köküne** aç — `public_html` klasörünün YANINA, İÇİNE değil.
   (Dosya Yöneticisi'nde `public_html`'in bulunduğu ana dizine.)
2. `data/` klasörü yazılabilir olmalı (SQLite oraya yazar). Gerekirse 755/775 yap.
3. Veritabanı ilk istekte kendini kurar (`data/app.sqlite` otomatik oluşur).

## İlk giriş

- Yönetici: `admin@karfiyat.local` / şifre `1234`
- **İlk girişte şifre değiştirmeye zorlanır** (güvenlik kilidi). Değiştirmeden panel kullanılmaz — bu kasıtlıdır.
- Yönetim panelinden IBAN + hesap sahibi bilgisini girin; yoksa kullanıcılar ödeme yapamaz.

## Ödeme akışı

- **Havale:** Kullanıcı IBAN'a havale yapar → "Ödeme Yaptım" der → yönetici panelden onaylar → Pro 1 ay aktif olur.
- **Kart (iyzico):** Yönetim panelinden iyzico API/Secret key girilirse kullanıcılar kartla ödeyebilir; ödeme iyzico callback'iyle otomatik doğrulanıp Pro anında aktif olur (alıcı ad/soyad/GSM/TCKN/adres ödeme formunda toplanır, DB'de saklanmaz).
- Otomatik kart tahsilatı (abonelik yenileme) yoktur; yenilemede kullanıcı tekrar öder.

## Not

- Paket DB İÇERMEZ. Zip'i sonradan üstüne açmak canlı veriyi SİLMEZ
  (DB sunucuda yaşar, `data/app.sqlite`).
- Komisyon oranları 2025–2026 güncel kategori tarifelerinden derlenmiştir.

## Trendyol API entegrasyonu

Şu an yalnızca **Trendyol** API entegrasyonu aktif. Diğer pazaryerleri (Amazon, Hepsiburada,
n11, ePttAVM, ÇiçekSepeti, AliExpress) için hesap makinesinde komisyon oranları hazırdır ama
mağaza bağlama sekmesinde "yakında" olarak gösterilir (kodları iskelet olarak mevcuttur, canlı
kimlik bilgisiyle test edilmemiştir).

Kullanıcı kendi mağazasını `Mağaza Bağla` sayfasından bağlar:
satıcı ID + API key + API secret girer. Bu bilgiler **AES-256 ile şifreli** saklanır
(`data/.enc-key` anahtarı ilk çalıştırmada otomatik üretilir — bu dosyayı YEDEKLEYİN,
kaybolursa kullanıcıların bağlantıları çözülemez).

- API istekleri `includes/trendyol.php` üzerinden Basic auth + zorunlu User-Agent ile gider.
- **cURL SSL gereksinimi:** Sunucuda cURL'ün CA sertifika demeti bulunmalı.
  Eğer bağlantı "SSL sertifika doğrulaması başarısız" hatası verirse, sunucunun
  `php.ini` dosyasına `curl.cainfo` / `openssl.cafile` ile geçerli bir `cacert.pem`
  yolu tanımlayın (Hostinger'da genellikle varsayılan olarak ayarlıdır).
- Trendyol, ürün filtreleme V1'i 15 Ekim 2026'da kullanım dışı bırakıyor; V2'ye
  geçiş gerekirse `getProducts()` içindeki endpoint güncellenir.

## Otomatik senkron (cron)

`scripts/sync.php` tüm kullanıcıların bağlı mağazalarını senkronize eder. Hostinger'da
**Cron Jobs**'tan saatlik şunu ekleyin:

```
php /home/KULLANICI_ADI/scripts/sync.php
```

(Yol hesap kökünüzdeki `scripts/sync.php` olmalı; `public_html` içinde değil.)

## Yeni özellikler

- **2FA** (Google Authenticator): Hesabım → 2FA Kur.
- **Demo hesabı**: ana sayfadaki "Demo Hesabını Gör" butonu.
- **Partner programı**: Hesabım'daki yönlendirme linkiyle gelen her Pro abonelikte %15 hakediş.
- **Chrome eklentisi**: `chrome-eklenti/` klasörünü `chrome://extensions` → "Paketlenmemiş öğe yükle" ile kur.

## Ödeme güvenliği güncellemesi

- Kartlı ödeme başlatılırken `checkout_sessions` tablosuna kullanıcı, sepet,
  tam token ve beklenen TRY tutarı kaydedilir. Yeni tablo ilk istekte oluşur.
- Callback, oturum çerezine ihtiyaç duymadan iyzico API sonucunu bu kayıtla
  eşleştirir. Başarılı durum, fraud onayı, sepet, tutar, para birimi ve ödeme ID
  kontrol edilir. Kullanıcı callback isteğindeki oturumdan seçilmez.
- Kart ve havale aktivasyonları SQLite yazma işlemi içinde tamamlanır;
  ödeme, abonelik ve partner hakedişi birlikte kaydedilir veya geri alınır.
- Güncelleme öncesi başlatılmış ve `checkout_sessions` kaydı olmayan kart
  ödemeleri otomatik onaylanmaz. Dağıtım sırasında açık ödeme akışlarını
  tamamlayın; beklenmeyen tahsilatları iyzico panelinden kontrol edin.
- Canlıya geçmeden önce iyzico sandbox hesabıyla ödeme başlatma ve dönüş
  akışını ayrıca doğrulayın. Yerel testler sağlayıcıya bağlanmaz.

## Testleri ayrı veritabanında çalıştırma

PHP CLI için `pdo_sqlite`, `mbstring` ve HTTP/CSV testi için `curl` gerekir.
Üretim veritabanında test çalıştırmayın. Her akış testi için yeni bir klasör kullanın:

```bash
TEST_DIR=$(mktemp -d)
export KARFIYAT_DB="$TEST_DIR/app.sqlite"
php -S 127.0.0.1:8090 -t public_html
```

Ayrı terminalde, aynı `KARFIYAT_DB` değerini ayarladıktan sonra:

```bash
php scripts/test-akislar.php
php scripts/test-toplu-analiz.php
php scripts/totp-test.php
php scripts/test-billing.php
```

`test-billing.php` kendi geçici veritabanını oluşturur ve temizler. Diğer
betiklerde sunucu ve test süreci aynı test veritabanına bağlanmalıdır.
