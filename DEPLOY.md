# Kâr&Fiyat — Kurulum

Pazaryeri net kâr hesaplama ve fiyat optimizasyonu SaaS'ı. PHP 8.3 (test edilen sürüm) ve PDO SQLite, mbstring, OpenSSL, cURL, ZIP, SimpleXML gerektirir.

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
- **Kart (iyzico):** Varsayılan olarak kapalıdır. Yalnız ayrı ödeme güvenlik ve sandbox kabulü, HTTPS origin ve sunucu yapılandırması tamamlanınca etkinleştirilmelidir. API anahtarını girmek tek başına yeterli değildir.
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


## Buybox and payout detail automation
Buybox scans one 50-barcode chunk every target five minutes, using a durable per-seller keyset cursor. Duplicate catalog barcodes are excluded; failed requests do not advance the cursor. Each chunk has its own actual observation time and full response coverage check. Missing results are not a fresh price. A 20,000-product store needs at least 400 chunks (roughly 33 hours at ideal cadence), so the one-hour freshness threshold still blocks stale price recommendations. This is not a real-time full-catalog guarantee. No prices are changed.
One previously fetched, completed payment order is checked per 30-minute target tick. Unchecked orders come first; recent checked orders are revisited after 24 hours. Historical list pages not yet fetched are not covered, and a failed old payment may block following details until resolved. No bank verification. Cron deployment and provider quota/load validation remain prerequisites.

## Yayın öncesi tek seferlik kabul

1. Canlı DB ve `.enc-key` dosyasının güvenli yedeğini al; izole kopyayı geri yükle, kopyada v10 şema geçişini çalıştır. Web kökü yalnız public_html olmalı.
2. `php scripts/release-preflight.php /ozel/app.sqlite` ile çalışma ortamını kontrol et. `application_preflight_passed` yalnız yerel temel kontrolleri ifade eder. `operational` içindeki mağaza bağlama/anahtar kontrolleri ve cron sinyalleri ayrıca değerlendirilir. Anahtarın içeriğini paylaşma.
3. Özel ortamda gerçek tarihli maliyet/KDV, resmî kargo tarifesi, fatura ve banka kanıtlarıyla mutabakat yap. Eksik veriler kesin kâr olarak sunulmaz.
4. GitHub regresyonu ve izole Chromium masaüstü/mobil kontrolü başarılı olmalı. Gerçek sunucuda yetki ve görünüm ayrıca incelenmeli.
5. Kullanıcının açık yayın onayından sonra kurulum ve cron; ödeme ve pazaryeri yazma özellikleri ayrı onay gerektirir. Sorun halinde cron durdurulur ve uyumlu kod/veritabanı yedeğiyle geri dönülür; yeni canlı veriler kaybolmamalıdır.

### Hostinger Business hedefi
hPanel > Websites > Manage site > Cron Jobs alanında Custom komut olarak PHP CLI ve siteye ait mutlak scripts/sync.php auto yolunu kullanın. PHP 8.3 ve gerekli uzantıları gerçek hesapta doğrulayın. Beş dakikalık zamanlama UTC kullanır; göreli beş dakika aralığını değiştirmez. Site kökü, SSH/CLI yolu ve kaynak limitleri hesaba göre kontrol edilmeli. Banka dekontu ekranı isteğe bağlıdır; ilk operasyonel yayını tek başına bloke etmez. Banka doğrulanmadıkça banka mutabakatı tamamlandı veya kesin net kâr doğrulandı denmez. Referans: https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/ .

Bundled standard desi tariff now contains 5 October 2026 PDF reference; no manual installation needed for those rates. New date-covered operator tariff overrides it. The PDF excludes barem shipments; obtain separate official barem terms and rates before claiming exact low-price freight. Last-known reference does not prove later changes did not occur.

For low-value original packages, the secondary barem table rechecked through 27 November 2026 is an estimate only; the faster rate is shown as a separate scenario, not automatically deducted. Obtain a current official barem reference for later dates. Invoices replace estimates when the existing scope and amount checks pass.
