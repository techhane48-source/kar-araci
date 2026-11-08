# Trendyol Satıcı Bilgi Merkezi: iş kuralları

İnceleme: 8 Kasım 2026, Türkiye. Giriş gerektirmeden açılan 11 resmi metin içeriği incelendi. [Kaynak adresleri ve içerik özetleri](trendyol-akademi-sources.json) kayıtlıdır. Özetler ilgili içeriğin tarayıcıda dönen JSON nesnesinden hesaplanmıştır; ham HTTP gövdesinin özeti değildir. Videoların ve görsellerin tamamı bu incelemenin kapsamı değildir.

Melontik incelemesi ekran/veri akışını; bu çalışma özelliklerin Trendyol açısından amacını ve iş kurallarını açıklıyor. Resmi metin, örnek Excel şeması, Melontik'te gözlenen davranış ve uygulama kararı farklı kanıtlardır. Ticari koşullar değişebilir; satıcının güncel panelindeki dönem ve teklifler esas alınmalıdır.

## Kaynaklı kurallar ve uygulama karşılıkları

| Konu | Resmi kaynakta açıklanan kural | Projedeki karşılığı |
| --- | --- | --- |
| Avantajlı ürün etiketi | Önerilen ürün için belirlenen fiyat aralığında fiyat güncellemesi yapılır; üç avantaj seviyesi bulunur. Aralıklar periyodik, SSS'ye göre haftalık yenilenir. Aralık dışında kalan fiyat etiketi kaldırabilir. | Evrensel bir yüzde indirimi hesaplamak yerine kaynaktaki üç üst fiyatı ve geçerlilik dönemini kullan. Etiket satış artışı garantisi değildir. |
| Etikete etki eden fiyat | Fiyat, yalnızca etiket ekranından değil ürün listesi, toplu işlem, otomatik fiyatlandırma, komisyon tarifesi ve ilgili promosyon ekranlarından da değişebilir. | Excel seçimi aday fiyattır; güncel efektif fiyatın ve diğer promosyonların ayrıca değerlendirilmesi gerekir. |
| Flaş ürün | Algoritmanın önerdiği ürün ve zaman dilimine fiyat verilir. Resmi metin 09.00–00.00 arasındaki üç saatlik dilimleri anlatır. Katılım iptal edilebilir; kapsamda olmayan ürün için ekran pasif olabilir. | Satırdaki başlangıç/bitiş tarihlerini koru. Aynı barkodu farklı günlerde tek kayda indirme. |
| 24 saatlik flaş | İncelenen resmi metin üç saatlik akışı anlatırken kullanıcının gerçek dosyası 24 saat fiyat/tarih sütunlarını da içeriyor. | Yalnızca üç saati sabitleme; dosyada fiyatı ve tarihleri bulunan 24 veya 3 saatlik teklifi kullan. Bu fark, bütün sürümlerin tek şemada olduğu varsayımını geçersiz kılar. |
| Ürün uygunluğu | Etiket/flaş metinlerinde 500'ü aşan satıcı/varyant satıcı toplamları ve barkod sayılarıyla ilgili teknik kısıtlar anlatılıyor. | Yalnızca fiyatın tutmasına dayanarak ürünün kesin etiket alacağını söyleme. Uygunluk bilgisini panel/sağlayıcıdan doğrula; bu sayılar uygulamada yerel stok sayısı gibi yorumlanmamalı. |
| Plus fiyatı | Plus tarifesi yalnızca Plus müşteri siparişlerinde geçerlidir. Girilecek fiyat normal satış fiyatını ve ilgili Plus tarife fiyatını aşamaz. | Plus senaryosunu tüm müşterilere uygulanmış gibi hesaplama. Üst sınır, iki fiyatın küçüğüdür. Bu dal henüz uygulanmadı. |
| Plus komisyon matrahı | Satıcının karşıladığı indirimlerle oluşan komisyona esas fiyat, müşterinin gördüğü son fiyattan farklı olabilir. Plus tarifesine uymayan durumda ürün tarifesi veya temel komisyon devreye girer. | Müşteri fiyatı, satıcı gelirine esas tutar ve komisyon matrahı ayrı alanlar olmalı; tek fiyata indirgenemez. Tam promosyon/matrah motoru henüz uygulanmadı. |
| Plus'ın diğer etkileri | Plus fiyatları Buybox'ı, fiyat geçmişini ve son 30 gün en düşük fiyat geçmişini etkilemez; Plus fiyatı yıldız aralığına gelse de avantajlı etiket gösterilmez. | Plus fiyatını normal satış fiyatı/etiket adayı olarak dışa aktarma. Dosya türü doğrulanmadan Plus ekranını genel CSV motoruna bağlama. |
| İndirim katmanları | Fiyat değişimi, net indirim, koşullu promosyonlar, Plus, birlikte al, kod ve kupon farklı katmanlardır. Aynı katmanda birden fazla indirim varsa en yüksek indirimi sağlayan değerlendirilir. | Oranları doğrudan toplayan bir indirim hesabı kullanma. Mevcut uygulama yalnızca teklif fiyatı senaryosu verir; birleşik promosyon hesabı vermez. |
| Flaş üzerine indirim | Net indirim flaş fiyatına doğrudan eklenmez; bazı koşullu indirimler ise oluşturulduğu ekrana/katmana göre birleşir veya karşılaştırılır. | Her flaş fiyatının bütün indirimlerle üst üste uygulanacağı varsayımını kullanma. Birleşim bilgisi olmadan kârı kesinleştirme. |
| Kupon maliyeti | Kupon metnine göre maliyet kullanılan kupon adedine bağlıdır. Örnekte satıcının 500 TL ürününde 100 TL kupon, 400 TL ödeme/komisyon matrahına yol açar. | Tanımlanan kupon bütçesini gerçekleşmiş gider sayma. Bu örnek, Trendyol tarafından finanse edilen bütün promosyonlar için genel matrah kuralı olarak genişletilemez. |
| Kampanya finansmanı | İndirimler metni satıcının planladığı indirimin satıcıya ait olduğunu; Plus kampanyaları metni karşılama oranı olmayan kampanyanın tamamının satıcıya ait olduğunu belirtir. | Satıcı ve pazaryeri katkılarını ayrı kaydet. Katkı oranı bilinmeyen kampanyada finansman uydurma. |
| Buybox | Fiyatın yanında satıcı puanı, teslimat, kargo, kampanyalar ve operasyon statüsü etkilidir. En düşük fiyat birincilik garantisi değildir. | Fiyat kırma önerisi Buybox garantisi olarak sunulmamalı. Satıcının fiyat/kâr sınırı korunmalı. Mevcut Buybox modülünün sağlayıcı doğrulaması ayrı iştir. |

## Asıl kaynaklar

- [Avantajlı Ürün Etiketleri](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/avantajli-urun-etiketleri)
- [Flaş Ürünler](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/flas-urunler)
- [Plus Komisyon Tarifeleri](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/plus-komisyon-tarifeleri)
- [İndirimlerin Uygulanma Sırası](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/indirimlerin-uygulanma-sirasi)
- [Kuponlar](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/kuponlar), [İndirimler](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/indirim), [Kampanyalar](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/kampanyalara-nasil-katilirim)
- [Trendyol Plus Kampanyaları](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/trendyol-plus-kampanyalari)
- [Buybox](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/buybox-nedir), [Buybox kuralları](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/otomatik-fiyatlandirma-buybox-kurallari)
- [Promosyon & Fiyat ana sayfa](https://akademi.trendyol.com/satici-bilgi-merkezi/detay/promosyon-fiyat-ana-sayfa)

## İlk uygulama adımı ve sınırları

[Promosyon XLSX değişikliği](PROMOSYON-XLSX.md) ürün komisyonu, avantajlı etiket ve flaş dosyalarını ele alıyor. Kademenin kendi komisyonu, teklif satırı ve tam zaman aralığı korunuyor. Saat bakımından kısmi tarife çakışması tek oranla hesaplanmıyor. Eksik maliyet, geçersiz teklif ve eksik zorunlu tarife seçime açılmıyor.

Mevcut kupon/kampanya birleşimleri, satıcı/pazaryeri katkısı, tam Plus fiyat/matrah hesabı, gerçekleşmiş hakediş mutabakatı ve giderlerin farklı KDV oranları bu değişiklikle çözülmüş sayılmaz. Sonuçlar kayıtlı ürün giderleriyle hesaplanan **teklif bazlı tahmini kâr** olarak gösterilir. Trendyol Akademi'ye erişilmiş olması tek başına vergi hesabını onaylamaz.
