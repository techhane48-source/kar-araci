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

- Yönetici hesabı ilk kurulumda rastgele, dışarıya açıklanmayan parola ile oluşturulur. Sunucu yöneticisi güvenli bir parola sıfırlama prosedürü uygulamalıdır; varsayılan 1234 yoktur.
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
- Trendyol ürün okuma V2 approved endpoint'i, sipariş akış servisi, finans ve iade okumaları geliştirme dalında kullanılmaktadır. Canlı satıcıyla doğrulama tamamlanmadan üretime geçmeyin.

## Otomatik senkron (cron)

`scripts/sync.php` tüm kullanıcıların bağlı mağazalarını senkronize eder. Hostinger'da
**Cron Jobs**'tan beş dakikada bir şunu ekleyin (yalnızca canlıya geçiş onayından sonra):

```
*/5 * * * * php /home/KULLANICI_ADI/scripts/sync.php auto
```

Hostinger arayüzünde zamanlama alanı ayrıysa komut kısmına yalnızca `php /home/KULLANICI_ADI/scripts/sync.php auto` girin. Web ve CLI aynı veritabanı, şifreleme anahtarı ve dosya izinlerini kullanmalı. Uygulama otomatik olarak uygun işleri seçer: sipariş 15 dk, iade 30 dk, ürün 60 dk, finans/belge/son ödeme sayfası 6 saat. İlk sorgu geçmiş 30 günü hedefler; kesinti sonrası sınırlı örtüşen tarih aralığı kullanır. Bunlar hedef aralıklardır, sağlayıcı garantisi değildir. Kullanıcıların her biri için cron kurulmaz. Sunucu çalıştırıcısı gerçek kurulana kadar mağaza ekranı etkin olduğunu iddia etmez. Manuel komutlar korunur; otomasyon fiyat veya iade onayı göndermez.

(Yol hesap kökünüzdeki `scripts/sync.php` olmalı; `public_html` içinde değil.)

## Yeni özellikler

- **2FA** (Google Authenticator): Hesabım → 2FA Kur.
- **Demo hesabı**: ana sayfadaki "Demo Hesabını Gör" butonu.
- **Partner programı**: Hesabım'daki yönlendirme linkiyle gelen her Pro abonelikte %15 hakediş.
- **Chrome eklentisi**: `chrome-eklenti/` klasörünü `chrome://extensions` → "Paketlenmemiş öğe yükle" ile kur.
