# Kâr&Fiyat — Kurulum

Pazaryeri net kâr hesaplama ve fiyat optimizasyonu SaaS'ı. PHP 8+ ile `pdo_sqlite`, `mbstring`, `curl`, `openssl`, `zip` ve `SimpleXML` eklentileri gerekir. Rakip tarama servisi ayrıca Node.js ve Chrome gerektirir.

## Klasör yerleşimi (ÖNEMLİ)

Bu proje "klasik" yerleşimdedir: kod `public_html` DIŞINDA tutulur (güvenlik).

```
hesap kökü /
├── config/           ← ortak takip adresi politikası
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

Otomatik veya sabit şifreli yönetici hesabı oluşturulmaz. Sunucuda, proje kökünde
şifreyi terminal geçmişine yazmadan yönetici oluşturun (Bash):

```bash
read -rsp 'Yönetici şifresi (en az 12 karakter): ' ADMIN_SETUP_PASSWORD
printf '\n'
printf '%s\n' "$ADMIN_SETUP_PASSWORD" | php scripts/create-admin.php yonetici@alanadiniz.com
unset ADMIN_SETUP_PASSWORD
```

Mevcut bir hesabı bilinçli olarak yönetici yapıp şifresini sıfırlamak için aynı
komuta `--reset` eklenir. Bu işlem hesabın 2FA'sını kapatır ve önceki oturumlarını
geçersiz kılar; yalnızca sunucuya erişen operatör kullanmalıdır.

**Eski kurulumlar:** `admin@karfiyat.local` hesabının şifresi hâlâ eski bilinen
kurulum şifresiyse ilk istekte devre dışı bırakılır; mevcut oturumları da düşürülür.
Bu hesabı CLI ile `--reset` kullanarak kurtarın veya yeni bir yönetici oluşturun.
Şifresi önceden değiştirilmiş yöneticiler korunur.

Yönetim panelinden IBAN + hesap sahibi bilgisini girin; yoksa kullanıcılar ödeme yapamaz.

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
- Ürünler V2 onaylı içerik/varyant kataloğundan ve ayrı stok/fiyat servisinden alınır.
  15 Ekim 2026'da kaldırılan V1 ürün ve sipariş yolları kullanılmaz.
  Resmî kaynaklar ve doğrulama kapsamı: [Trendyol doğrulaması](docs/TRENDYOL-DOGRULAMA.md).

## Otomatik senkron (cron)

`scripts/sync.php` bağlı mağazaların ürünlerini, siparişlerini ve finans kayıtlarını senkronize eder. Varsayılan sipariş/finans geçmişi 90 gündür (siparişler ayrıca son 3 takvim ayıyla sınırlanır); yalnızca ürünler için `--products-only`, farklı geçmiş için `--days=30` gibi seçenekler kullanılabilir. Hostinger'da
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
# Yalnızca izole test veritabanı için test yöneticisi:
printf '%s\n' 'Test-Admin-Only-2026!' | php scripts/create-admin.php admin@karfiyat.local
php -S 127.0.0.1:8090 -t public_html
```

Ayrı terminalde, aynı `KARFIYAT_DB` değerini ayarladıktan sonra:

```bash
php scripts/test-akislar.php
php scripts/test-toplu-analiz.php
php scripts/totp-test.php
php scripts/test-billing.php
php scripts/test-security.php
php scripts/test-bootstrap.php
php scripts/test-xlsx.php
node --test crawler/security.test.js
```

`test-billing.php` ve `test-bootstrap.php` kendi geçici veritabanlarını oluşturur
ve temizler; `test-xlsx.php` veritabanı kullanmaz. HTTP akış testlerinde sunucu ve
test süreci aynı test veritabanına bağlanmalıdır. Chrome ile yerel tarayıcı testi
için `crawler/README.md` içindeki komutları kullanın.

## 2FA ve güvenli takip adresleri

2FA kurulumu mevcut şifre ister; sır onaylanana kadar yalnızca oturumda tutulur
ve 10 dakika sonra geçersizleşir. Aktif 2FA yeniden kurma ile kapatılamaz;
önce mevcut TOTP koduyla kapatılmalıdır. Etkinleştirme/kapatma diğer oturumları
geçersiz kılar. Önceki sürümün tamamlanmamış kurulumları yeniden başlatılır.

`config/tracker-policy.json` yalnızca doğrudan HTTPS pazaryeri adreslerine ve
belirli CDN hostlarına izin verir. Bu klasörü de sunucuya yükleyin. URL kısaltıcılar,
özel IP'ler ve farklı portlar kabul edilmez. PHP hızlı kontrolü yönlendirme izlemez;
tarayıcıdaki son ürün/mağaza URL'sini girin. DNS genel IPv4'e çözülmeli; doğrulanan
IP bağlantıda sabitlenir. PHP sayfa yanıtı 2 MB ile sınırlıdır.

Crawler tüm Chrome HTTP(S)/WebSocket çıkışlarını yalnızca bu IP'lere tünel açan
geçici yerel proxy'den geçirir. Alt kaynaklar/redirectler de sınırlandırılır.
Chrome işletim sistemi sandbox'ı açık tutulur; üretimde çalışan bir sandbox ve
servis ağ izolasyonu sağlanmalıdır. Yeni CDN'ler gerekiyorsa politika incelenerek
host bazında genişletilir. Politika dışı kaynakların engellenmesi bazı sitelerde
fiyat çıkarımını etkileyebilir; gerçek sitelerle dağıtım testi gerekir.

## Ortak kârlılık hesabı

Web ekranları `includes/profit.php` içindeki ortak ürün/sipariş hesabını kullanır.
Tarayıcı hesap makinesi `public_html/assets/profit.js` ile çalışır; PHP/JavaScript
sonuçları karşılaştırmalı testlerle doğrulanır. Her iki dosyayı da dağıtıma ekleyin.

- `products.stopaj_mode` kolonu ilk istekte eklenir. Eski ürünlerin stopajı
  `manual` kalır: mevcut TL tutarı ve açıkça girilmiş sıfır giderler korunur.
- Hesap makinesinden kaydedilen yeni ürünlerde otomatik stopaj tercihi saklanır;
  otomatik modda teklif/kampanya fiyatı değişince stopaj tekrar hesaplanır.
- Yeni Trendyol API ürünleri varsayılan hizmet bedeli ve otomatik stopajla
  oluşturulur. Bunlar tahmindir; eski kayıtların giderleri otomatik değiştirilmez.
- Ürün kartındaki maliyet, kargo, hizmet, ek gider ve manuel stopaj **adet başına**
  kabul edilir. Siparişin toplam tutarı satır fiyatı×adet ağırlıklarıyla dağıtılır;
  her satır kendi KDV oranıyla hesaplanıp toplanır. Bu bir ürün gideri tahminidir;
  gerçek paket kargo faturası veya farklı vergi oranlı kesintilerin mutabakatı değildir.
- Bir satırın maliyeti/girdisi eksikse siparişin kârı bilinmiyor olarak gösterilir.
  Ürün/kategori dağılımında o siparişin satırları da eksik işaretlenir. Kâr özetleri
  yalnızca maliyeti tam siparişleri kapsar; bilinmeyen tutarlar sıfır sayılmaz.
- İptal/iade/teslim edilemeyen paket durumları tahmini satış toplamlarından çıkarılır.
  Kısmi satır iadeleri ve geçmiş maliyet anlık görüntüleri henüz kapsamda değildir.
- Hedef fiyat yalnızca ulaşılabilir hedeflerde hesaplanır ve hedefi sağlayan
  kuruşa yukarı yuvarlanır. Hatalı/aşırı hedefler ürün fiyatına yazılmaz.
- Dashboard ve ana kâr raporları reklam/ceza/erken ödeme/diğer finans giderlerini
  içermez; bu giderler sıfırmış gibi gösterilmez. Reklam atıf verisi olmadan reklam
  kârı, iade kaydı tutarından da doğrudan net iade zararı üretilmez.

Para hesapları ara adımlarda float hassasiyetiyle sürer, ekranda kuruşa yuvarlanır.
Vergi, stopaj ve iade varsayımları mali müşavir doğrulaması gerektirir. Geçmiş
raporlar hâlâ güncel ürün maliyetleriyle hesaplanır; resmî finans mutabakatı ayrı iştir.

### Kârlılık testleri

Yukarıdaki izole HTTP test veritabanını kullanarak, akış testlerinden sonra:

```bash
php scripts/test-profit.php
node scripts/test-profit-parity.js
php scripts/test-profit-http.php
CHROME_PATH=/usr/bin/chromium node scripts/test-profit-browser.js
```

`test-profit-http.php` yalnızca test için `profit@example.test` hesabı ve örnek
siparişler oluşturur; yeni test veritabanında çalıştırın. Tarayıcı testi bu hesabı
kullanır. `PHP_BIN` ile CLI yolunu belirtebilirsiniz. Kısıtlı test ortamlarında
`KARFIYAT_TEST_NO_SANDBOX=1` yalnızca tarayıcı testinin başlatma seçeneğidir.

## Tekrar güvenli senkronizasyon

- Ürünler kullanıcı+pazaryeri+barkod üzerinden eşleştirilir. Fiyat/stok ve gelen
  katalog bilgileri güncellenir; maliyet, KDV, kargo/ek/hizmet gideri, stopaj ve
  iade ayarları korunur. Barkodu olmayan veya stok/fiyatı bozuk kayıt tüm işi
  başarısız yapar. Eski mükerrer barkodlar otomatik silinmez; hata ile bildirilir.
- Son başarılı ürün senkronunun başlangıcı kaydedilir. Her çekimde tam V2 katalog ve stok görünümü alınır. Katalog değişiklik tarihi
  stok değişikliğini garanti etmediği için artımlı stok varsayılmaz. Eksik/hatalı sayfada başarı zamanı ilerlemez.
- Bir mağazanın ürün/sipariş/finans işleri ortak, 30 dakikalık kiralı kilitle
  korunur. Ağ çağrıları DB transaction'ı dışında yapılır. Kayıt anında kilit ve
  bağlantı revizyonu tekrar kontrol edilir; süresi dolan veya eski bağlantıya
  ait sonuçlar kaydedilmez. Bağlantı bilgileri güncellendiğinde imleçler sıfırlanır.
- Finans yanıtının `id` alanı `settlements.provider_id` olarak saklanır; aynı
  kullanıcı/pazaryeri/işlem ID'si tekrar alınırsa tutarlar çoğaltılmadan güncellenir.
  Kimliksiz finans yanıtı tahmini parmak iziyle birleştirilmez; iş başarısız olur.
- Eski kimliksiz kayıtlar yalnızca ilgili dönem ve türler eksiksiz tekrar alınınca
  `settlement_legacy_archive` tablosuna özgün satırın JSON'u ve ID'siyle taşınır;
  sonra aktif tablodaki yerlerini resmî ID'li kayıtlar alır. Yeniden alınan dönem
  dışındaki geçmişe dokunulmaz. Dönem boş dönse bile eski aktif kayıtlar arşive
  gider; dağıtım öncesi canlı API'nin filtre ve kimlik alanlarını doğrulayın.
- Bütün finans tür/dilimleri tamamlanmadan arşiv veya komisyon değişikliği
  yapılmaz. %0 dahil en güncel geçerli satış komisyonu ürün kartına işlenir.
- Sayfalama metadatası, kayıt sayısı, tekrar sayfa ve limit aşımı kontrol edilir.
  Ürün çekimi en çok 500, sipariş/finans çekimi pencere başına en çok 1000 sayfadır;
  limit aşılırsa kısmi başarı yerine hata döner. Siparişler 14 günlük pencerelere,
  finans işleri tür başına 14 günlük pencerelere bölünür.
- Bağlı Mağaza ekranı son eksiksiz sipariş/finans zamanlarını ve son hatayı gösterir.
  Başka bir işin başarısı, farklı işteki son hatayı silmez.

Cron CLI içindir; HTTP üzerinden çalıştırılamaz. Varsayılan kapsam eski ürün-only
çalışmaya göre daha çok API isteği yapar. Büyük kataloglarda web düğmesi yerine
cron kullanın; sıklığı hesap sayısı ve Trendyol limitlerine göre ayarlayın. Bu paket
genel senkron iş kuyruğu, 429 backoff, gerçek kargo/hakediş mutabakatı veya geçmiş maliyet
anlık görüntülerini tamamlamaz. Finans türleri ve `id` alanı gerçek satıcı hesabıyla
kontrol edilmeden üretim doğrulaması tamamlanmış sayılmaz.

### Senkron testleri

```bash
php scripts/test-sync.php
php scripts/test-trendyol-pages.php
php scripts/test-sync-concurrency.php
# İzole HTTP akış testlerinden sonra mağaza durumu:
php scripts/test-sync-ui.php
```

Bu testler geçici veritabanı ve kontrollü API yanıtları kullanır; gerçek API'ye
ve üretim verisine bağlanmaz. Ayrı bir PHP çalıştırıcısı için eşzamanlılık testinde
`PHP_BIN` ayarlayın. Canlı API kimlik bilgilerini testlere eklemeyin.


## Trendyol fiyat işlemlerinin takibi

Fiyat isteği yalnızca barkod ve fiyatları gönderir; yerel stok tekrar gönderilmez.
`price_updates` tablosuna gönderim niyeti ağ çağrısından önce kaydedilir. Batch
kimliği kabul anlamına gelir; yerel fiyat sadece eşleşen barkodun `SUCCESS` sonucu
alındığında değiştirilir. Mağaza ekranındaki sonuç kontrolü veya normal cron bu
sonucu sorgular. Aynı içerik 15 dakika içinde tekrar gönderilmez; belirsiz/bekleyen
işlem varken aynı barkoda yeni fiyat gönderilmez. Batch sonuçları 4 saat saklandığı
için cron aralığını buna göre belirleyin. Sonucu belirsiz işlemde tam ürün senkronu
mağazadaki güncel fiyatı okuyarak engeli kaldırır; bu durum istenen fiyatın başarıyla
uygulandığı anlamına gelmez. Bağlantı revizyonu değişmiş eski sonuç uygulanmaz.

Ek testler: `php scripts/test-trendyol-pages.php` ve
`php scripts/test-price-updates.php`. Bunlar izole fixture testleridir; gerçek
satıcı hesabında kabul testi yerine geçmez.
