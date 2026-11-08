# Melontik ile sayfa ve işlev karşılaştırması

İnceleme tarihi: 8 Kasım 2026 (Türkiye). İncelenen proje: `3176128`.
Referans: https://melontik.com — herkese açık site ve sitenin sunduğu demo hesabı.

**Sonuç: proje Melontik'in aynısı olmamış.** Ekran isimlerinin önemli bir kısmı
benzer olsa da görünüm, veri kapsamı ve iş akışları belirgin biçimde farklı.
Çalışan hesaplama, kayıt, dosya ve senkron altyapısı var; bu, referanstaki ürünün
bütün işlevlerinin mevcut olduğu anlamına gelmiyor. Yüzdesel benzerlik puanı verilmedi;
böyle bir puan bu incelemeden güvenilir biçimde çıkarılamaz.

## Yöntem ve sınırlar

- Kapsam: ana taramada 48 adres, ayrıca 2 blog kategorisi ve kayıt sekmesi; 19 yardım + 11 blog yazısı. Ayarlar içinde 12 sekme ve Hakediş/Desi görünümü de kontrol edildi. Yönetici ekranları, uygulama mağazaları ve kurulu eklentinin iç ekranları bu müşteri arayüzü incelemesinin dışında.
- **7 adres demo hesabında Dashboard’a yönlendi:** altı Pazar Zekası adresi ve partner paneli. Bu ekranların iç işlevleri test edilemedi; yalnızca herkese açık tanıtım ve bizim kaynak kodumuz üzerinden kapsam farkı kaydedildi.
- Referans ekranları Chromium'da açıldı; menü, görünen alanlar, düğmeler ve metinler incelendi. Sayfa haritası sitemap ve demo menüsüyle karşılaştırıldı.
- Referansın demo verileri ile bizim izole SQLite test verimiz farklıdır. Hesaplar arası toplam ciro/kâr eşitliği testi yapılmadı.
- Referansın yardım belgeleri okundu. Belgelerde anlatılan davranış ile demo ekranında gözlenen davranış raporda ayrı kanıt olarak değerlendirildi.
- Bizim sayfalar 1440 px masaüstünde, seçili sayfalar 390 px mobil genişlikte açıldı. Boş demo ve ayrıca üretilmiş sipariş verisiyle denendi.
- Gerçek satıcı bağlantısı, gerçek abonelik tahsilatı ve referansın kayıt/değişiklik/Excel yükleme işlemleri gerçekleştirilmedi. Referansın arka uç doğruluğuna erişimimiz yok.
- İletişim formu, PIN gönderimi ve bülten kaydı gönderilmedi. İnceleme gerçek kişilere bildirim üretmedi.
- Bu teslimat inceleme raporudur; uygulama davranışında düzeltme yapılmadı.

## Öncelikli bulgular

| Öncelik | Bulgu | Tekrarlanabilir kanıt / etkisi |
| --- | --- | --- |
| P0 | Geçmiş siparişin kârı güncel ürün maliyetiyle değişiyor | İzole veride aynı eski siparişin kârı, ürün maliyeti 50 TL artırılınca 32,13 TL'den -9,53 TL'ye değişti. Test değişikliği geri alındı. Referansın Ürün Ayarları rehberi eski siparişlerde eski maliyetin korunduğunu söylüyor. Sipariş anı maliyet/gider görüntüsü gerekli. |
| P0 | Farklı KDV oranlarında fiyat motorları ayrışıyor | Aynı maliyet/kargo/komisyon/hedef ile canlı referans %0 KDV için 1.721,80 TL, %10 için 1.724,70 TL verdi. Bizde 1.725,56 TL ve 1.757,21 TL çıktı. Ürün KDV oranının gider KDV oranlarından ayrılması, negatif net KDV politikası ve yuvarlama bağımsız doğrulanmalı. |
| P0 | Hakediş ekranı gerçek hakediş mutabakatı yapmıyor | `hakedis-desi.php` girilen tutarı son 30 günlük `SUM(total_price)` ile karşılaştırıyor. Vade, ödeme emri, iade, komisyon faturası ve kargo faturası eşleştirmesi yok. Aynı isimle sunulan işlevin kapsamı referanstan farklı. |
| P0 | Tam finans net kârı eksik | Bizim raporlar ürün kartı giderlerinden tahmin üretiyor. Referans Dashboard reklam, ceza, erken ödeme, diğer faturalar ve uluslararası kesintileri ayrı gösteriyor. Bu veri modeli tamamlanmadan eşit net kâr beklenemez. |
| P1 | Giriş sonrası menü ekran dışına taşıyor | Dashboard'da 1440 px görünüm için belge genişliği 2209 px; 390 px için 1661 px ölçüldü. Menü öğeleri ve hesap kontrolleri görüntü dışında kalıyor. Masaüstünde 26 ekran/sekme, mobilde denenen 4 ekran bu taşmadan etkilendi. |
| P1 | Yeniden fiyatlama önizlemesinin CSV indirmesi bozuk | Hedef %20 ile 2 ürün için önizleme oluştu. İndirme bağlantısına GET isteği `200 text/html; charset=UTF-8` döndürdü; Content-Disposition yok. `kar-marji.php` önizlemeyi yalnız POST'ta oluşturuyor, indirme GET'inde `$rep` boş kalıyor. |
| P1 | Demo ana sayfadan başlatılamıyor | Ana sayfada demo tanıtım kartı var fakat `demo.php` bağlantısı/formu yok. Demoya ancak testte giriş sayfasından CSRF alıp doğrudan uç noktayı çağırarak girildi. |
| P1 | Demo finans/sipariş deneyimi boş | Demo ürün oluşturuyor, sipariş/finans oluşturmuyor. Dashboard ve kârlılık raporları boş. Referans demosu veriyle dolu ve ürünün değerini gösteriyor. |
| P1 | Promosyon modülleri aynı genel akışın varyasyonları | Altı modülde ortak CSV motoru ve farklı kolon adları kullanılıyor. Kampanya türü, min sepet, karşılama oranı, dönem ve komisyon kademesi kuralları için referanstaki tam iş akışı yok. Gerçek Trendyol dosyasıyla gidiş-dönüş kabul testi gerekli. |
| P1 | Gerçek Buybox karşılığı yok | `r=buybox` kendi ürünlerimizi kategori içi karşılaştırıyor. Rakip satıcı/sıra/sahiplik bilgisi sağlamıyor. Sayfa açıklaması bunu dürüstçe belirtiyor; yine de referansın Buybox işlevi tamamlanmış sayılmaz. |
| P1 | Hepsiburada bağlantısı yok | Referansın demo platform seçimi ve yardım belgeleri Trendyol + Hepsiburada kapsamı sunuyor. Bizim mağaza bağlantısında yalnız Trendyol aktif. |
| P2 | Müşteri ekranında rakibin adı kalmış | Promosyon girişinde “Melontik'in killer özelliği” metni görünüyor. Kendi ürün metniyle değiştirilmesi gerekiyor. |
| P2 | Müşteriye sunucu komutları gösteriliyor | Pazar Zekası ekranı `cd crawler && npm install && node crawl.js` gibi işletim komutları gösteriyor. Abonelik müşterisi bu servisi kendisi başlatmak zorunda olmamalı. |
| P2 | Pazar istihbaratı kapsamı dar | Bizde mağaza/ürün URL takibi var. Reklam radarı, influencer radarı, pazar bildirimleri ve ayrı özet paneli yok. |
| P2 | Yardım, e-posta raporu ve ürün içi rehber eksik | Referansta 19 yardım makalesi ve ekran içi yardım/tur akışı var. Bizde eşdeğer rehber deneyimi yok. |

## Görsel karşılaştırma

Referans turuncu/kahverengi palet, sol ikon menüsü, üst araç çubuğu ve yoğun veri
tabloları kullanıyor. Bizde mavi/beyaz palet ve yatay metin menüsü var. Yerleşim,
yazı tipi, kartlar, filtreler, grafikler ve gezinme deneyimi aynı değil.

- [Referans Dashboard](melontik-gorseller/referans-dashboard.png)
- [Bizim Dashboard — test verisiyle](melontik-gorseller/bizim-dashboard.png)
- [Bizim mobil Dashboard — menü taşması](melontik-gorseller/bizim-mobil.png)
- [Referans ana sayfa](melontik-gorseller/referans-ana-sayfa.png)
- [Bizim ana sayfa](melontik-gorseller/bizim-ana-sayfa.png)

## Hesaplama örnekleri

Kaynak: [Melontik'in yayımladığı hesaplama örneği](https://melontik.com/blog/rehberler/trendyol-komisyon-ve-kar-hesaplama).
Maliyet 1.000 TL, kargo 100 TL, KDV %20, hizmet 13,19 TL, stopaj %1, hedef kâr 250 TL.
Karşılaştırma bizim `calc_price` motorunda aynı girdilerle yapıldı.
%20 örneği ayrıca canlı demo Ürün Fiyatlandırma ekranında forma girilip
“Fiyat Oluştur” ile doğrulandı. Aynı formda %0 ve %10 KDV de denendi.

| Örnek | Referansta yayımlanan satış fiyatı | Bizim sonuç | Değerlendirme |
| --- | --- | --- | --- |
| Komisyon %20, KDV %20 — canlı demo + yazı | 1.788,85 TL | 1.788,85 TL | Bu örnekte satış fiyatı eşleşiyor. |
| Komisyon %20, KDV %0 — canlı demo | 1.721,80 TL | 1.725,56 TL | 3,76 TL fark; eşdeğer değil. |
| Komisyon %20, KDV %10 — canlı demo | 1.724,70 TL | 1.757,21 TL | 32,51 TL fark; eşdeğer değil. |
| Komisyon %23,5 — yayımlanmış örnek | 1.871,77 TL | 1.871,78 TL | Bizim motor hedef kârın altına düşmemek için üst kuruşa çıkıyor. Referansın yayımlanan değeriyle 1 kuruş farklı. |

Net KDV örneğinde de kalem kalem yuvarlama ile toplu yuvarlama arasında kuruş
farkı var. Canlı referans düşük KDV oranlarında negatif net KDV de gösterdi; bizim motor
net KDV tutarını sıfırın altına indirmiyor ve giderler için ürün KDV oranını kullanıyor.
Bu farkı referansın doğruluğuna kanıt sayıp doğrudan kopyalamak doğru olmaz.

Bu test, karma siparişleri, iadeleri, mikro ihracatı
ve gerçek faturaları kapsamaz. Sayısal eşdeğerlik için ortak girdili daha geniş bir
kabul veri kümesi ve açık yuvarlama kuralları gerekir.

## Denenen yerel işlemler

| İşlem | Sonuç |
| --- | --- |
| 37 sayfa/görünüm açılışı | HTTP 200; yakalanan sayfa JavaScript hatası yok. HTTP 200 işlevin tamamlandığı anlamına gelmez. |
| Ürün arama, eşleşmeyen arama | “Aramanızla eşleşen ürün yok.” sonucu geldi. |
| Blog liste ve geçerli yazı detayı | İzole test yazısı eklenince iki sayfa da doğru içeriği verdi. Başlangıçta hazır içerik yoktu. |
| %20 hedef marjla yeniden fiyatlama önizlemesi | 2 ürün için yeni fiyat ve tahmini kâr listesi oluştu. Uygula düğmesine basılmadı. |
| Önizleme CSV indirmesi | Başarısız: CSV yerine HTML geldi. |
| Geçmiş maliyet değişikliği | Aynı geçmiş siparişin kârı değişti; fark doğrulandı ve test değişikliği geri alındı. |
| Masaüstü/mobil gezinme | Yatay taşma doğrulandı. |

## Önerilen düzeltme sırası

1. Menü/mobil taşması, çalışan demo başlangıcı ve veriyle dolu demo; müşteriye görünen rakip adı ve işletim komutlarını temizleme.
2. Bozuk yeniden fiyatlama CSV indirmesini düzeltme; fiyat önizleme/uygulama/indirme akışını birleştirme.
3. Sipariş anı maliyet/gider görüntüsü ve geçmişe etkili düzeltme politikasını tasarlama.
4. Gerçek finans giderleri, kargo/iade ve hakediş mutabakatını tamamlama; tahmini kâr ile finansal net kârı ayırma.
5. Gerçek Trendyol Excel dosyalarıyla altı promosyon modülü için ayrı kabul testleri ve eksik kurallar.
6. İhtiyaca göre gerçek Buybox, Hepsiburada, gelişmiş ürün ayarları ve kampanya ekranları.
7. Abonelik tahsilatı, yardım merkezi, rapor mailleri ve pazar istihbaratı modülleri.

Mobil uygulama, Chrome eklentisi ve partner hakedişi ayrı ürün kapsamlarıdır;
web uygulamasını tamamlamanın ön koşulu olarak otomatik eklenmemelidir.


## Referansta denenen salt okunur etkileşimler

- Ana sayfanın Demo Hesap düğmesi: `/login?mode=demo` üzerinden veriyle dolu Dashboard açıldı.
- Fiyatlandırma Aylık/Yıllık değişimi: Starter yıllık ekranda aylık karşılığı 599 TL, aylık planda 799 TL olarak değişti. Ödeme başlatılmadı.
- SSS açılır sorusu: “nasıl kullanabilirim” seçilince görünür cevap değişti.
- Ürün Fiyatlandırma: aynı maliyet/kargo/hedef ile %20, %0, %10 KDV hesaplandı; sonuçlar yukarıda.
- Ayarlar: 12 sekmede içerik değişimi doğrulandı; hiçbir Kaydet/Öde düğmesine basılmadı. Desi görünümü de açıldı.
- Referans mobil ana sayfa 390 px genişlikte 390 px belge genişliği verdi. Bizim giriş sonrası mobil Dashboard 1661 px belge genişliği verdi. Bunlar farklı ekranlardır; ölçüm kendi ekranındaki taşmayı gösterir, doğrudan aynı sayfanın piksel karşılaştırması değildir.

## Sayfa sayfa karşılık ve eksikler

“Açıldı”, ekranın erişilebilir olduğu anlamındadır; her işlemin uçtan uca kabul edildiği anlamına gelmez.

| # | Referans sayfa | Erişim | Bizdeki karşılık | Durum | Fark / eksik |
| --- | --- | --- | --- | --- | --- |
| 1 | [Ana sayfa](https://melontik.com/) | Ekran açıldı | index.php | Farklı | Referans pazaryeri finans ürünü sunuyor; biz hesap makinesi odaklıyız. Renk, font, navigasyon, bölüm yerleşimi ve etkileşimler farklı. |
| 2 | [Fiyatlandırma](https://melontik.com/pricing) | Ekran açıldı | index.php#pricing / odeme.php | Farklı | Referansta hacme göre Starter/Business/Enterprise ve aylık/yıllık plan var. Bizde ücretsiz + sabit 149 TL Pro modeli var. |
| 3 | [Referanslar](https://melontik.com/references) | Ekran açıldı | — | Eksik | Bağımsız müşteri referansları sayfası yok. |
| 4 | [SSS](https://melontik.com/faq) | Ekran açıldı | index.php içi | Kısmi | Ana sayfada sorular var; referanstaki bağımsız SSS düzeniyle aynı değil. |
| 5 | [İletişim](https://melontik.com/contact-us) | Ekran açıldı | — | Eksik | Referanstaki ad/soyad/telefon/konu/mesaj formu ve destek sunumu yok. |
| 6 | [Gizlilik](https://melontik.com/privacy-policy) | Ekran açıldı | kvkk.php | Farklı | Sayfa var; metin, şirket bilgileri ve veri süreçleri kendi ürünümüze göre ayrıca tamamlanmalı. Metin benzerliği hukuki yeterlilik değildir. |
| 7 | [Kullanım koşulları](https://melontik.com/terms-of-use) | Ekran açıldı | kosullar.php | Farklı | Sayfa var; kapsam ve abonelik koşulları referanstan farklı. |
| 8 | [QNB Dijital Köprü](https://melontik.com/dijital-kopru) | Ekran açıldı | — | Eksik | Banka iş ortaklığı akışı yok; aynı ortaklığa sahip olduğumuz varsayılamaz. |
| 9 | [Blog](https://melontik.com/blog) | Ekran açıldı | blog.php / blog-detay.php | Kısmi | Bizim test kurulumunda blog listesi başlangıçta boştu. İzole test yazısı eklendiğinde liste ve geçerli detay açıldı; referanstaki içerik/kategori düzeni yok. Referansta listelenmiş 11 yazı var. |
| 10 | [Yardım merkezi](https://melontik.com/yardim) | Ekran açıldı | — | Eksik | Referanstaki 19 ekran rehberi, anlatımlı ekran görüntüleri ve kullanım turları yok. |
| 11 | [Mobil uygulama](https://melontik.com/mobil-uygulama) | Ekran açıldı | — | Eksik | Referans iOS/Android uygulaması ve bildirimlerini tanıtıyor. Bizde web arayüzü var; mobil uygulama yok. |
| 12 | [Önerilen fiyatlar](https://melontik.com/onerilen-fiyatlar) | Ekran açıldı | kar-marji.php | Kısmi | Hedef kâra göre fiyat önizlemesi var. Pazar ortalaması, kategori hedefleri ve kampanya öneri sistemi yok. |
| 13 | [Partner programı](https://melontik.com/partner-programi) | Ekran açıldı | hesabim.php yönlendirme alanı | Kısmi | Basit yönlendirme yapısı var; referansın sürekli yüzde 15 hakediş/partner paneliyle eşdeğer değil. |
| 14 | [Chrome eklentisi](https://melontik.com/chrome-eklentisi) | Ekran açıldı | — | Eksik | Trendyol paneli içinde çalışan bir tarayıcı eklentisi yok. |
| 15 | [Pazar Zekası tanıtımı](https://melontik.com/pazar-zekasi) | Ekran açıldı | pazar-zekasi.php | Kısmi | Bizde doğrudan basit takip ekranı var. Referanstaki ayrı ürün tanıtımı ve modül seti yok. |
| 16 | [Giriş / kayıt](https://melontik.com/login) | Ekran açıldı | giris.php / kayit.php | Farklı | Referans e-posta PIN koduyla giriş sunuyor. Biz e-posta/şifre kullanıyoruz; ayrıca TOTP var. PIN/e-posta teslimatı eşdeğerliği yok. |
| 17 | [Dashboard](https://melontik.com/dashboard) | Ekran açıldı | dashboard.php | Kısmi | Referansta brüt/net kâr ayrımı, reklam/ceza/erken ödeme/diğer fatura kırılımı ve ülke filtresi var. Bizde ürün giderlerine dayalı tahmini özet var; tam finans mutabakatı yok. |
| 18 | [Basit Dashboard](https://melontik.com/old-dashboard) | Ekran açıldı | dashboard.php | Kısmi | Referanstaki ayrı /old-dashboard görünümünün bağımsız karşılığı yok. |
| 19 | [Canlı Performans](https://melontik.com/live-performance) | Ekran açıldı | canli-performans.php | Kısmi | Saatlik grafik, günlük özet ve maliyet girişi bulunuyor. Sipariş detay kırılımı, gelişmiş filtreler ve dosya üretim deneyimi aynı değil. |
| 20 | [Ürün Komisyon Tarifesi](https://melontik.com/promotion-profitability-analysis/product-commission-tariff-profitability-analysis) | Ekran açıldı | promosyon-analiz.php?mod=komisyon | Kısmi | Referans varyantlı, filtreli ve fiyat kademeli tablo sunuyor. Biz CSV yükleme üzerinden ortak analiz akışı kullanıyoruz; gerçek platform dosyasıyla tam gidiş-dönüş henüz doğrulanmadı. |
| 21 | [Plus Komisyon Tarifesi](https://melontik.com/promotion-profitability-analysis/product-plus-commission-tariff-profitability-analysis) | Ekran açıldı | promosyon-analiz.php?mod=plus | Kısmi | Modül adı ve kolon seçimi var; Plus fiyat/komisyon kurallarının ayrı kapsamı ve arayüzü referansla eşdeğer değil. |
| 22 | [Avantajlı Ürün Etiketi](https://melontik.com/promotion-profitability-analysis/star-product-labels-profitability-analysis) | Ekran açıldı | promosyon-analiz.php?mod=etiket | Kısmi | Basit CSV kademe hesabı var; referanstaki öneri, gelişmiş toplu seçim ve varyant deneyimi yok. |
| 23 | [Flaş Ürünler](https://melontik.com/promotion-profitability-analysis/flash-offer) | Ekran açıldı | promosyon-analiz.php?mod=flash | Kısmi | Aynı ortak CSV motoru kullanılıyor; referanstaki dönem/saat teklif akışı ve ürün tablosuyla aynı değil. |
| 24 | [İndirimler](https://melontik.com/promotion-profitability-analysis/discounts) | Ekran açıldı | promosyon-analiz.php?mod=indirim | Kısmi | Referans manuel teklif hesabı ve seçimli ürün tablosu sunuyor. Bizde ortak CSV hesabı var; kampanya koşullarının tümü modellenmemiş. |
| 25 | [Kampanyalar](https://melontik.com/promotion-profitability-analysis/campaign-profitability-analysis) | Ekran açıldı | promosyon-analiz.php?mod=kampanya | Kısmi | Karşılama oranı, min sepet ve kampanya türlerinin ayrı hesaplama davranışları tamamlanmış değil. |
| 26 | [Kendi Kampanyanı Oluştur](https://melontik.com/campaign/build-your-campaign) | Ekran açıldı | — | Eksik | Referansta ayrı kampanya ekranı var; aynı işlev bizde yok. |
| 27 | [Avantajlı Teklifler](https://melontik.com/campaign/offers) | Ekran açıldı | — | Eksik | Referansta ayrı teklif kademeleri ekranı var; birebir karşılık yok. |
| 28 | [Sepet Kampanyaları](https://melontik.com/campaign/basket-campaigns) | Ekran açıldı | — | Eksik | Hepsiburada sepet kampanyası, hedefe uygunluk ve manuel teklif hesabı karşılığı yok. |
| 29 | [Sipariş Kârlılık Analizi](https://melontik.com/reports/order-profitability-analysis) | Ekran açıldı | raporlar.php?r=order | Kısmi | Bizde paket/satır hesabı ve CSV var. Referanstaki sipariş detay finans kırılımı, özel tarih aralığı ve üretilmiş rapor akışı eksik. |
| 30 | [Ürün Kârlılık Analizi](https://melontik.com/reports/product-profitability-analysis) | Ekran açıldı | raporlar.php?r=product | Kısmi | Ürün toplamları var; sipariş anı maliyetleri, gelişmiş kolonlar ve aynı rapor dosyası akışı yok. |
| 31 | [Kategori Kârlılık Analizi](https://melontik.com/reports/category-profitability-analysis) | Ekran açıldı | raporlar.php?r=category | Kısmi | Kategori bazlı toplam var. Gerçek finans giderleri ve tarihsel maliyetlerden aynı sonuç üretildiği doğrulanmış değil. |
| 32 | [İade Zarar Analizi](https://melontik.com/reports/return-loss-analysis) | Ekran açıldı | raporlar.php?r=return | Kısmi | Biz iade finans kayıtlarını gösteriyoruz. Gidiş/dönüş kargosu ve ürün durumuna göre gerçek iade zararı hesaplanmıyor. |
| 33 | [Reklam Kârlılık Analizi](https://melontik.com/reports/ads-profitability-analysis) | Ekran açıldı | raporlar.php?r=ads | Kısmi | Bizde kayıtlı reklam giderleri görünümü var; reklam/influencer veri toplama ve siparişe atıf kapsamı tamamlanmış değil. |
| 34 | [Kampanya Kârlılık Analizi](https://melontik.com/reports/offer-profitability-analysis) | Ekran açıldı | raporlar.php?r=campaign | Kısmi | Kupon/indirim netleri var. Referanstaki kampanya/tür bazlı performans ayrıntılarıyla aynı değil. |
| 35 | [Kâr Marjı Listesi](https://melontik.com/profit-margin-list) | Ekran açıldı | kar-marji.php | Hatalı/Kısmi | Liste ve hedefe göre fiyat önizlemesi çalışıyor. Önizleme CSV indirmesi HTML döndürüyor. Pazar ortalaması ve önerilen fiyat kapsamı eksik. |
| 36 | [Buybox](https://melontik.com/buybox) | Ekran açıldı | raporlar.php?r=buybox | Eksik | Bizim görünüm yalnız kendi ürünlerinin kategori içi fiyat karşılaştırması. Rakip satıcı, gerçek Buybox sırası/sahipliği ve kazanma oranı yok. |
| 37 | [Ürün Fiyatlandırma](https://melontik.com/product-pricing) | Ekran açıldı | index.php hesap makinesi | Kısmi | Temel net kâr/hedef fiyat hesabı var. Teslimat tipi, mikro ihracat ve ayrı KDV bileşenlerinin tamamı modellenmiyor. |
| 38 | [Ürün Ayarları](https://melontik.com/product-settings) | Ekran açıldı | urunler.php | Kısmi | Maliyet/ürün içe aktarma var. Model-renk-beden yönetimi, pazaryerleri arası ürün eşleştirme ve sipariş anı maliyet saklama eksik. |
| 39 | [Uyarı Sayfası](https://melontik.com/warning-page) | Ekran açıldı | uyarilar.php | Kısmi | Maliyetsiz/zarar/stok/desi uyarıları var; kurallar, bildirim kanalları ve finans denetimleri aynı değil. |
| 40 | [Hakediş & Desi Kontrolü](https://melontik.com/entitlement) | Ekran açıldı | hakedis-desi.php | Hatalı/Kısmi | Biz manuel tutarı son 30 gün satış toplamıyla karşılaştırıyoruz. Sipariş bazlı fazla kargo, eksik iade komisyonu ve hakediş mutabakatı yok; aynı adlı işlevin kapsamı farklı. |
| 41 | [Pazar Zekası Dashboard](https://melontik.com/market-intelligence/dashboard) | Demo Dashboard yönlendirmesi; iç işlev test edilmedi | pazar-zekasi.php | Eksik | Basit takip listesi dışında bağımsız pazar istihbaratı özet paneli yok. |
| 42 | [Mağaza Takibi](https://melontik.com/market-intelligence/store-tracking) | Demo Dashboard yönlendirmesi; iç işlev test edilmedi | pazar-zekasi.php type=store | Kısmi | URL bazlı takip ve tarayıcı servisi var; referanstaki tarihsel mağaza analitiğiyle eşdeğerliği doğrulanmadı. |
| 43 | [Ürün Takibi](https://melontik.com/market-intelligence/product-tracking) | Demo Dashboard yönlendirmesi; iç işlev test edilmedi | pazar-zekasi.php type=product | Kısmi | URL/fiyat takibi var; favori, yorum, puan, satıcı/Buybox ve kampanya metriklerinin tamamı yok. |
| 44 | [Pazar Zekası Bildirimleri](https://melontik.com/market-intelligence/alerts) | Demo Dashboard yönlendirmesi; iç işlev test edilmedi | — | Eksik | Pazar değişikliği bildirim merkezi ve kuralları için ayrı ekran yok. |
| 45 | [Reklam Radarı](https://melontik.com/market-intelligence/ad-intelligence) | Demo Dashboard yönlendirmesi; iç işlev test edilmedi | — | Eksik | Arama kelimesi/rakip reklam ve TBM analizi için modül yok. |
| 46 | [Influencer Radarı](https://melontik.com/market-intelligence/influencer-radar) | Demo Dashboard yönlendirmesi; iç işlev test edilmedi | — | Eksik | Influencer ürün/kampanya takip modülü yok. |
| 47 | [Partner paneli](https://melontik.com/partner) | Demo Dashboard yönlendirmesi; iç işlev test edilmedi | — | Eksik | Ayrı partner hakediş paneli yok; referanstaki ekranın demo erişim sınırı ayrıca kaydedilir. |
| 48 | [Ayarlar](https://melontik.com/settings) | Ekran açıldı | hesabim.php / magaza.php / odeme.php | Kısmi | Bizde hesap, TOTP, bağlantı ve ödeme ekranları ayrı. Referanstaki rapor mailleri, pazaryeri ayarları ve gelişmiş tercihler aynı kapsamda değil. |


### Ek adresler ve içerik sayfaları

Blog yazıları ve yardım belgelerinde sayfa erişimi ve içerik okuması yapıldı;
her belgedeki anlatılan işlemin tamamı referansta yürütülmedi. Bizde yardım
merkezi karşılığı yok; blog liste/detay altyapısı mevcut, içerik üretimi ayrı iş.

| Adres | Kontrol | Bizdeki karşılık |
| --- | --- | --- |
| [Referans](https://melontik.com/blog/pazarlama) | HTTP 200, açıldı | blog.php; kategori sayfası yok |
| [Referans](https://melontik.com/blog/rehberler) | HTTP 200, açıldı | blog.php; kategori sayfası yok |
| [Referans](https://melontik.com/login?tab=2&page=kayit-ol) | HTTP 200, açıldı | kayit.php; PIN yerine şifreli kayıt |
| [699313593e5b6b309f47ead5](https://melontik.com/blog/pazarlama/2026-trendyol-komisyon-oranlari-ne-kadar/699313593e5b6b309f47ead5) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [6996d731302d765edc01aa7d](https://melontik.com/blog/pazarlama/hepsiburada-saticlar-icin-sats-artrma-onerileri-2026/6996d731302d765edc01aa7d) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [6912e9701031a64acd43e91e](https://melontik.com/blog/pazarlama/kasim-kampanyalar-e-ticaretin-en-kritik-donemi/6912e9701031a64acd43e91e) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [6996d5d0302d765edc01a926](https://melontik.com/blog/pazarlama/trendyol-satislarn-artrmak-icin-8-etkili-yontem/6996d5d0302d765edc01a926) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [6996d6ea302d765edc01aa44](https://melontik.com/blog/rehberler/hepsiburadada-nasil-sats-yaplr-2026-rehberi/6996d6ea302d765edc01aa44) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [6996d64d302d765edc01a99e](https://melontik.com/blog/rehberler/trendyol-buybox-nedir/6996d64d302d765edc01a99e) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [efsane-cuma-kar-hesabi](https://melontik.com/blog/rehberler/trendyol-efsane-cumaya-girmeden-once-kar-hesabi-adm-adm-kontrol-listesi/efsane-cuma-kar-hesabi) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [trendyol-komisyon-ve-kar-hesaplama](https://melontik.com/blog/rehberler/trendyol-komisyon-ve-kar-hesaplama) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [6996d60b302d765edc01a95a](https://melontik.com/blog/rehberler/trendyol-saticlarnn-en-cok-yaptg-7-hata/6996d60b302d765edc01a95a) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [6996d69f302d765edc01a9ea](https://melontik.com/blog/rehberler/trendyolda-urun-yukleme-nasil-yaplr/6996d69f302d765edc01a9ea) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [6996d576302d765edc01a8ef](https://melontik.com/blog/rehberler/urun-satislarnz-artran-trendyol-seo-nedir/6996d576302d765edc01a8ef) | İçerik okundu | blog-detay.php; aynı içerik hazır değil |
| [avantajli-urun-etiketi](https://melontik.com/yardim/avantajli-urun-etiketi) | İçerik okundu | Yardım merkezi yok |
| [ayarlar](https://melontik.com/yardim/ayarlar) | İçerik okundu | Yardım merkezi yok |
| [canli-performans](https://melontik.com/yardim/canli-performans) | İçerik okundu | Yardım merkezi yok |
| [dashboard](https://melontik.com/yardim/dashboard) | İçerik okundu | Yardım merkezi yok |
| [flas-urunler](https://melontik.com/yardim/flas-urunler) | İçerik okundu | Yardım merkezi yok |
| [hakedis-desi-kontrolu](https://melontik.com/yardim/hakedis-desi-kontrolu) | İçerik okundu | Yardım merkezi yok |
| [iade-zarar-analizi](https://melontik.com/yardim/iade-zarar-analizi) | İçerik okundu | Yardım merkezi yok |
| [indirimler](https://melontik.com/yardim/indirimler) | İçerik okundu | Yardım merkezi yok |
| [kampanya](https://melontik.com/yardim/kampanya) | İçerik okundu | Yardım merkezi yok |
| [kampanya-karlilik-analizi](https://melontik.com/yardim/kampanya-karlilik-analizi) | İçerik okundu | Yardım merkezi yok |
| [kar-marji-listesi](https://melontik.com/yardim/kar-marji-listesi) | İçerik okundu | Yardım merkezi yok |
| [kategori-karlilik-analizi](https://melontik.com/yardim/kategori-karlilik-analizi) | İçerik okundu | Yardım merkezi yok |
| [plus-komisyon-tarifesi](https://melontik.com/yardim/plus-komisyon-tarifesi) | İçerik okundu | Yardım merkezi yok |
| [reklam-karlilik-analizi](https://melontik.com/yardim/reklam-karlilik-analizi) | İçerik okundu | Yardım merkezi yok |
| [siparis-karlilik-analizi](https://melontik.com/yardim/siparis-karlilik-analizi) | İçerik okundu | Yardım merkezi yok |
| [urun-ayarlari](https://melontik.com/yardim/urun-ayarlari) | İçerik okundu | Yardım merkezi yok |
| [urun-fiyatlandirma](https://melontik.com/yardim/urun-fiyatlandirma) | İçerik okundu | Yardım merkezi yok |
| [urun-karlilik-analizi](https://melontik.com/yardim/urun-karlilik-analizi) | İçerik okundu | Yardım merkezi yok |
| [urun-komisyon-tarifeleri](https://melontik.com/yardim/urun-komisyon-tarifeleri) | İçerik okundu | Yardım merkezi yok |


### İnceleme kanıtları

- [Adres ve test sonucu dökümü](melontik-inceleme.json) — oturum çerezi veya API anahtarı içermez.
- [Referans genel görünüm 1](melontik-gorseller/referans-genel-0.jpg), [2](melontik-gorseller/referans-genel-16.jpg), [3](melontik-gorseller/referans-genel-32.jpg).
- [Bizim genel görünüm 1](melontik-gorseller/bizim-genel-0.jpg), [2](melontik-gorseller/bizim-genel-16.jpg), [3](melontik-gorseller/bizim-genel-32.jpg).
- [Filtrelenebilir görsel karşılaştırma](melontik-karsilastirma.html).


## Giriş yapılmış hesapla ek inceleme

8 Kasım 2026'da kullanıcı hesabıyla erişim sağlandı. Yukarıdaki ilk raporun demo
kapsamına ek olarak [üyelikli inceleme ve buton envanteri](MELONTIK-UYELIKLI-INCELEME.md)
hazırlandı. Pazar Zekası sayfaları görüldü; bazı işlevlerde lisans/kota engeli
var. İlk rapordaki demo yönlendirmeleri tarihsel test sonucudur.
