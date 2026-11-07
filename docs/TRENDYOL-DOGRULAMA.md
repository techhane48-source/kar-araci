# Trendyol resmî belge doğrulaması

İnceleme: 8 Kasım 2026 (Türkiye). Kaynak: doğrudan developers.trendyol.com
üzerinden indirilen resmî Markdown belgeleri. Belge adresleri, kaynak güncelleme
tarihleri ve indirilen içeriğin SHA-256 özetleri [kaynak listesinde](trendyol-sources.json).

Bu çalışma API sözleşmesini ve fixture yanıtlarıyla uygulama davranışını doğrular.
Gerçek satıcı hesabıyla uçtan uca kabul testi henüz yapılmadı.

| Konu | Belgede doğrulanan davranış | Uygulama |
| --- | --- | --- |
| Ürün V2 | V1, 15 Ekim 2026'da kaldırıldı. Onaylı ürünler içerik içinde varyantlar halinde döner; sayfa büyüklüğü en çok 100 içeriktir. | `/products/approved`, içerik/varyant ayrımı ve sıfır KDV/komisyon korunur. |
| Stok/fiyat | Ayrı `/products/approved/inventory-and-price` servisi vardır. Katalog değişikliği stok değişikliğini garanti etmez. | Her senkronda iki tam görünüm barkodla birleşir; eksik/eşleşmeyen görünüm kaydedilmez. Mevcut kullanıcı maliyet/vergi ayarları korunur. |
| Büyük katalog | 10.000 içerik sınırından sonra `nextPageToken` gerekir. | Sayısal sayfadan opaque tokena geçilir; eksik/tekrar token ve toplam uyuşmazlığı hata verir. |
| Sipariş | Stream servisi son 3 aylık veri, en çok 14 günlük değişiklik pencereleri, `hasMore/nextCursor` ve sabit filtreler kullanır. En az 5 saniye aralık önerilir. | `/orders/stream`, 200 kayıt, pencereler ve cursor; yarım sonuç başarı sayılmaz. Çekilen değişiklik aralığı mağaza ekranında gösterilir. |
| Sipariş tutarı | `shipmentPackageId`, `packageTotalPrice`, `lineUnitPrice` ve `barcode/stockCode` belgelenmiştir. | Paket kimliği ve net birim fiyat kullanılır; eksik net fiyat için belgelenmiş brüt birim fiyat eksi toplam birim indirim alınır. Awaiting/Verified/UnPacked satış toplamına girmez. |
| Sipariş zamanı | Sipariş belgesi `orderDate` için GMT+3, `createdDate` için GMT belirtir. | `orderDate` UTC epoch'a dönüştürülür. Finans işlem tarihine bu düzeltme uygulanmaz. |
| Finans | Settlements yolu ve `id` doğrudur. İngilizce istek türüne karşı örnekte Türkçe `Satış` döner. `DeliveryFee` ve iptal türleri mevcuttur. | Sağlayıcı ID ile tekrar güvenli kayıt; istek türü kanonik, ham yanıt türü ayrı saklanır. Sale, Return, DeliveryFee/Cancel, Discount/Cancel, Coupon/Cancel çekilir. Kampanya iptalleri borç-alacak netiyle giderden düşer. |
| Fiyat/stok yazma | En çok 1000 ürün, stok 0–20.000. Fiyat tek başına gönderilebilir. Aynı içerik 15 dakika tekrarlanamaz. | Fiyat değişikliğinde stok gönderilmez; sınırlar ve tekrar engeli uygulanır. Ağ çağrısından önce kalıcı niyet kaydı açılır. |
| Batch | Kabul yanıtı `batchRequestId` içerir. Stok/fiyat sonucu üst düzey status içermez; barkodun item status değeri esas alınır. Sonuç 4 saat saklanır. | Yalnız eşleşen ürünün SUCCESS sonucu yerel fiyatı değiştirir. Bekleyen/ret/belirsiz/bağlantısı değişmiş durumlar ayrıdır. Sonucu belirsiz yazma otomatik tekrarlanmaz. |
| Kimlik/ortam | Basic auth ve satıcı ID + entegratör adı User-Agent gerekir. Canlı/test bilgileri farklıdır; test ortamı IP yetkilendirmesi gerektirebilir. | `sellerId - KarFiyat`; seçilen ortamda doğrulama, otomatik diğer ortama kimlik gönderimi yok. |
| İstek sıklığı | Limitler servis gruplarına göre farklıdır. | İstemci içinde ürün 0,21 sn, finans 0,65 sn, sipariş stream 5 sn aralık uygulanır. Aynı mağaza senkronları ortak kilit kullanır. Süreçler arası genel kota/backoff henüz yoktur. |

## Test ve kalan kabul koşulları

`test-trendyol-pages.php` V2 sayfalama, 10.001 içerik, ayrı stok verisi, stream
cursor, tarih pencereleri, Türkçe finans türü ve batch sonuçlarını doğrular.
`test-price-updates.php` kalıcı bekleyen işlem, kullanıcı izolasyonu, ret, ağ hatası,
tekrar engeli, 4 saat süresi ve bağlantı değişikliğinde fiyat uygulanmamasını doğrular.
`test-sync.php` atomik yazma, sıfır değerler, kimlikli finans ve maliyetlerin korunmasını kontrol eder.
HTTP/tarayıcı regresyonları hesaplama, rapor, ödeme ve güvenlik akışlarını kapsar.

Canlıya kabul için gerçek satıcı hesabında salt okunur katalog/stok/sipariş/finans
karşılaştırması; ardından satıcının seçtiği bir üründe kontrollü fiyat değişikliği ve
batch sonucu kontrolü gerekir. Anahtarlar sohbet veya GitHub'a yazılmamalıdır.

Otherfinancials, ödeme emirleri, bütün kesinti türleri ve kargo/hakediş mutabakatı
henüz tamamlanmış değildir. Kategori varsayımları, hizmet bedeli ve vergi/stopaj
hesabı bu API belgeleriyle mali doğruluk onayı almış sayılmaz. Mevcut kâr raporu
ürün maliyetlerine dayalı tahmindir; banka tahsilat mutabakatı değildir.
