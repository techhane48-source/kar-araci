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
alınır. `Kargo Faturası` ve `Kargo Fatura` hareketleri adaydır. Fatura kalemleri
sipariş numarasıyla eşleştirilir. `parcelUniqueId` gönderi kimliği olarak
gösterilir. Sipariş birden fazla pakete bölündüğünde fatura gideri otomatik
olarak paketlere paylaştırılmaz.

## Sıklık ve doğrulama

Web ve cron aynı veri dizinini kullanıyorsa istek aralığı satıcı, API ortamı ve
servis grubu bazında dosya kilidiyle uygulanır. HTTP 429 sonrası `Retry-After`
kullanılır. Başlık eksik veya geçersizse 30 saniye bekleme kaydedilir. Yazma
isteği otomatik olarak yeniden gönderilmez. Birden fazla sunucu arasında ortak
kota ve kesintiden sonra cursor'la devam etme henüz tamamlanmadı.

Yerel testler örnek API yanıtlarını kullanır. Canlı kabul için satıcı izniyle
çok paketli ve kısmi iadeli sipariş, komisyon, kargo faturası ve platform hizmet
bedeli örnekleri doğrulanmalı. Banka tahsilatı için ayrıca banka kaydı gerekir.
