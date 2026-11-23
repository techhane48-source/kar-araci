# Trendyol sipariş muhasebesi ve resmî belgeler

Bu inceleme, sipariş muhasebesi ekranının kullandığı servisleri kapsar. Katalog,
sayfalama ve fiyat güncellemesi için [önceki doğrulamaya](TRENDYOL-DOGRULAMA.md)
bakın. Servis ve kod karşılıkları [kapsam matrisinde](TRENDYOL-API-KAPSAM-MATRISI.md).

## Sipariş ve indirimler

Sipariş akışındaki satır kimliği ile satıcı/Trendyol indirim alanları, geldikleri
biçimde siparişin satır JSON'una yazılır. Alan yoksa ekranda bilinmiyor görünür.
Önceden kaydedilmiş siparişlerde alanları doldurmak için ilgili değişiklik
penceresi tekrar senkronize edilmelidir. Birim ve toplam indirim alanları mevcut
kâr formülüne ayrıca düşülmez. Aynı indirimi iki kez düşmemek için örnek siparişte
satır ve paket net tutarları finans kayıtlarıyla karşılaştırılmalıdır.

## İadeler, cari hareketler ve kargo

İade listesi sipariş numarasıyla sorgulanır. Her iade kalemi kendi kimliği,
sipariş satırı ve durumuyla gösterilir. İade talebi ve finansal ters kayıt ayrı
olaylardır. Ekran iade durumundan para hareketi türetmez.

Cari hareketler kullanıcının senkronize verisinden okunur. Bir ödeme emri seçilirse
finans satırları sağlayıcıdan yeniden alınır, ödeme emri kimliği kontrol edilir
ve sipariş kimliğine göre filtrelenir. Borç, alacak ve komisyon ayrı gösterilir.
Ham hareketler, yerel cari kayıtla ikinci kez toplanmaz.

Kargo fatura kimliği, Other Financials içindeki `DeductionInvoices` yanıtından
alınır (`transactionType` = "Kargo Faturası" ya da "Kargo Fatura" olan kaydın
`id` değeri `invoiceSerialNumber`'dır). Fatura kalemleri sipariş numarasıyla
eşleştirilir. Kargo Faturası Detayları belgesinde (`getcargoinvoiceitems.md`)
kalem alanları yalnızca `shipmentPackageType` (gönderi/iade türü),
`parcelUniqueId` ("Kargo paketi benzersiz kimliği" — kargo gönderisi, sipariş
paketi DEĞİL), `orderNumber`, `amount` ve `desi`'dir. Kalemi bir sipariş
`shipmentPackageId`'sine veya `cargoTrackingNumber`'a bağlayan alan YOKTUR.
Bu yüzden kargo gideri sipariş düzeyinde gösterilir ve paket eşleşmesi ekranda
"belirsiz" olarak işaretlenir; gider paketlere paylaştırılmaz. Gönderi ve iade
kargosu `shipmentPackageType` ile ayrı gösterilir. Eksik/geçersiz tutar sıfıra
çevrilmez, "Bilinmiyor" kalır.

## Platform hizmet bedeli

Gerçek platform hizmet bedeli Other Financials (`getotherfinancials.md`) içinde
`transactionType = "DeductionInvoices"` + `transactionSubType = "PlatformServiceFee"`
ile bildirilir. Ortak `FinancialTransaction` şemasında `orderNumber` ve
`shipmentPackageId` **nullable**'dır ve belgede bu bedelin bir siparişe/pakete
bağlandığı örnek yoktur; dolayısıyla sipariş düzeyinde tekil bir eşleşme
**belgelenmemiştir**. `OtherFinancialTransactionType` enum'ında bu kalem için
iptal/ters kayıt tipi de yoktur.

Kâr hesabında kullanılan 13,19₺ (`trendyol_service_fee()`) Melontik modelinden
alınan bir **tahmindir**, gerçek bedel değildir. Bu tahmin, settlements `Sale`
komisyonundan ayrı bir kalemdir ve kârda yalnızca bir kez düşülür. Gerçek bedel
siparişe güvenle bağlanamadığı için tahmin korunur; gerçek bedel siparişe
dağıtılmadan dönem düzeyinde ayrıca gösterilir. Bir bedelin doğrulanması sipariş
kârının kesinleştiği anlamına gelmez.

**Senkronizasyon ve gösterim:** `sync_platform_service_fees()` Other Financials'ın
`otherfinancials` ucunu `transactionType=DeductionInvoices` +
`transactionSubType=PlatformServiceFee` filtreleriyle ve 14 günlük (belgedeki ≤15 gün
sınırı) tarih pencereleriyle sorgular; `page`/`size` sayfalama 500'lük sayfalar
kullanır. Kayıtlar `platform_service_fees` tablosuna **bağlantı kapsamı**
(`user_id + marketplace + seller_id + stage`) + `provider_id` (`id` işlem kimliği)
tekil anahtarıyla yazılır; aynı kullanıcı mağaza/test-c canlı ortam değiştirdiğinde
eski kayıtlar yeni bağlantı altında görünmez ve çakışan `id`'ler birbirini ezmez.
`debt`/`credit` borç/alacak olarak ayrı, `receiptId` (Dekont No) ve
`commissionInvoiceSerialNumber` (fatura seri no) referans olarak saklanır. Tur 7'nin
(seller_id/stage içermeyen) eski kayıtlarının mağaza ve ortamı güvenle belirlenemediği
için bunlar aktif bağlantıda gösterilmez; migrasyonda veri kaybı olmadan
`platform_service_fees_legacy` tablosuna arşivlenir ve yeni sorgular yalnızca kapsamı
doğrulanmış (`seller_id`+`stage`) kayıtları okur.

**Para birimi:** belgede `FinancialTransaction` şemasında para birimi alanı yoktur ve
tutarların daima TRY olduğuna dair açık bir kural yoktur (yalnızca `country` alanı
\"Ülke\" verir). Bu yüzden tutarlara ₺ etiketi konmaz, ekranda \"para birimi sağlayıcı
tarafından açıkça belirtilmiyor\" notu gösterilir ve doğrulanmış bir dönem toplamı
üretilmez. `debt`/`credit` şemada zorunludur (null değil): eksik/null veya sayısal
olmayan tutar bozuk yanıt sayılır ve senkron tamamen reddedilir (önceki başarılı dönem
korunur); geçerli `0` gerçek sıfır olarak gösterilir. Sipariş/paket/fatura referansı
gibi nullable alanlar eksikse \"bilinmiyor\" (—) kalır, sıfıra dönüştürülmez.

Sonuç `magaza.php` sayfasında dönem düzeyinde gösterilir; borç ve alacak ayrı
gösterilir, tahminle değiştirilmez ve sipariş kârına girmez. Başarısız çekimde son
başarı zamanı ilerlemez ve ekran, gösterilen kayıtların önceki başarılı çekime ait
olduğunu belirtir. Cron (`scripts/sync.php`) bu sorguyu ayrı bir görev olarak
çalıştırır; başarısızlığı cari hesap işinin sonucunu etkilemez.

## Sıklık ve doğrulama

Web ve cron aynı veri dizinini kullanıyorsa istek aralığı satıcı, API ortamı ve
servis grubu bazında dosya kilidiyle uygulanır. HTTP 429 sonrası `Retry-After`
kullanılır. Başlık eksik veya geçersizse 30 saniye bekleme kaydedilir. Yazma
isteği otomatik olarak yeniden gönderilmez. Birden fazla sunucu arasında ortak
kota ve kesintiden sonra cursor'la devam etme henüz tamamlanmadı.

Yerel testler örnek API yanıtlarını kullanır. Canlı kabul için satıcı izniyle
çok paketli ve kısmi iadeli sipariş, komisyon, kargo faturası ve platform hizmet
bedeli örnekleri doğrulanmalı. Banka tahsilatı için ayrıca banka kaydı gerekir.
