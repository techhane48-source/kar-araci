# Gerçek Trendyol Excel dosyalarıyla doğrulama — 8 Kasım 2026

Kullanıcı ürün komisyon tarifesi, avantajlı ürün etiketleri ve flaş ürünler için üç gerçek XLSX dosyası sağladı. Önceki rapordaki **bu üç dosyaya bağlı yükleme–seçim–dışa aktarma engeli giderildi**. Çalışma Melontik'in normal üyelikli arayüzünde yapıldı. Uygulamamızın kodu bu aşamada değiştirilmedi.

## Uçtan uca dosya akışı

Her modülde dosya yüklendi, tek varyant/teklif seçildi, seçim kaydı başarılı yanıt verdi ve indirme düğmesinden gerçek XLSX alındı. ZIP/XML çalışma kitabı açılıp bütün satırlar ve hücre değerleri kaynak dosyayla karşılaştırıldı. Biçimlendirme ve çalışma kitabının ikili olarak aynı kalması bu kontrolün kapsamında değildir.

| Dosya | Veri satırı | Çıktıda değişen alan | Sonuç |
| --- | ---: | --- | --- |
| Ürün komisyon tarifesi | 1.895 | Tek satırın `YENİ TSF (FİYAT GÜNCELLE)` hücresi | Satır sayısı ve kaynak sütunlar korundu; seçilen fiyatla eşleşti |
| Avantajlı ürün etiketi | 4.787 | Tek satırın `YENİ TSF (FİYAT GÜNCELLE)` hücresi; eklenen iki kâr sütunu | Kaynak sütunlar korundu; yeni fiyat seçilen fiyatla eşleşti |
| Flaş ürünler | 13.851 | Tek satırın `Güncellenecek Fiyat` hücresi | Satır sayısı ve kaynak sütunlar korundu; hücreye `24 Saat` seçimi yazıldı |

Yıldız çıktısına `Kar Tutarı` ve `Kar/Satış Fiyatı (%)` başlıkları eklendi; yalnızca seçilen satırda bu alanlar dolduruldu. Dolayısıyla bu dosyanın başlık satırı bütünüyle aynı değildir; özgün sütunlar korunmuş ve sonuna iki sütun eklenmiştir.

Flaş dosyasında `Güncellenecek Fiyat` sayısal bir fiyat alanı gibi kullanılamaz: bu denemede `24 Saat` etiketini taşır, sayısal teklif `24 Saat Fiyat` sütunundadır. Aynı barkod birden fazla tarihte tekrar eder; satır/teklif kimliği korunmalıdır.

## Komisyon tarifesi

İşlenmiş 1.504 varyantın dört kademesi incelendi. Toplam 6.016 komisyon oranı kaynak dosyanın `1.KOMİSYON`–`4.KOMİSYON` sütunlarıyla; 6.016 baz fiyat ise ilgili fiyat sınırıyla eşleşti. Aynı sayıda kâr/satış yüzdesi yeniden hesaplanıp doğrulandı.

Birinci kademede baz fiyat alt sınırdır (`1.Fiyat Alt Limit`); sonraki kademelerde ilgili üst sınır kullanılır. Alt ve üst limitleri bağımsız teklifler gibi değerlendirmek doğru değildir. Eksik maliyet, bulunamayan ürün ve teklif girilmemesi uyarı listesinde ayrı tutuldu; bu satırlar başarılı hesap olarak sayılmadı.

## Yıldız etiketi ve komisyon çakışması

İşlenmiş 1.603 varyant için üç yıldız fiyatı ve kâr/satış yüzdesi, toplam 4.809 noktada kaynak dosya ile eşleşti. Aynı dönemde komisyon tarifesi bulunan 4.512 fiyat noktasında uygulanan komisyon, yüklenmiş komisyon dosyasındaki uygun kademeyle eşleşti.

Yıldız dosyasındaki `KOMİSYON TARİFESİ` sütunu sayısal oran değildir; `Var`/`Yok` bilgisidir. Kâr hesabı için ürünün komisyonu ve varsa tarih/fiyat bakımından geçerli tarife ayrıca gerekir. Kalan 297 fiyat noktasının komisyonu yıldız dosyasından tek başına doğrulanamaz; bu noktalar 4.512 çakışma kontrolünün içinde değildir.

## Flaş teklifleri ve tarih

Dosya 8–18 Kasım 2026 için 24 saatlik teklifler içerir. İlk sınırlı dönem denemesinde dönem dışı teklifler uyarı listesine ayrıldı. Ardından dosya tüm tarih aralığıyla yeniden yüklendi: 11.799 teklif hesaplandı, 2.052 satır eksik maliyet uyarısıyla ayrıldı. Bu iki sayı dosyanın 13.851 veri satırını kapsar.

Hesaplanan 11.799 teklifin fiyatı, İstanbul saatinden UTC'ye çevrilen başlangıç/bitiş tarihleri ve kâr/satış yüzdesi kaynakla eşleşti. Gün bazında tarifenin geçerli olduğu dönemde fiyat kademesi komisyonu; sonraki dönemde birinci kademedeki temel komisyon kullanılması, bütün bu tekliflerde gözlenen sonuçla eşleşti. Tarife dışındaki 6.640 teklifin düşük komisyon kademesinde kalmadığı ayrıca kontrol edildi.

Bu, saat sınırındaki bütün kesişim durumlarının doğrulanması anlamına gelmez. Komisyon dosyasının metninde 08.00–07.59 saatleri bulunurken yükleme arayüzü gün aralığı seçtirir. Saat sınırındaki teklif çakışması için ayrı kabul kuralı gerekir. Dosyadaki 3 saatlik teklif alanları boş olduğundan **3 saatlik teklif seçimi denenmedi**.

## Kâr dökümünün bağımsız kontrolü

Üç modülden birer örnekte “Nasıl hesapladık” dökümü normal arayüzden açıldı. Gösterilen komisyon tutarı, satış × komisyon oranıyla; net kâr ise maliyet, komisyon, kargo, net KDV, hizmet bedeli, stopaj, ek maliyet ve varsa uluslararası hizmet bedeli düşülerek yeniden hesaplandı. Üç örnek de 0,02 TL toleransla tutarlıydı ve tablodaki kârla eşleşti.

Bu üç örnek bütün ürünlerin vergi/kargo varsayımlarının hukuken veya muhasebe bakımından doğru olduğunu kanıtlamaz. Önceki bağımsız fiyat hesaplayıcısının düşük KDV tutarsızlığı da bu sonuçla ortadan kalkmaz; bunlar farklı hesap akışlarıdır.

## Bizim projede kesinleşen eksikler

1. `public_html/promosyon-analiz.php` doğrudan bu XLSX dosyalarını kabul etmiyor; CSV istiyor. Kullanıcıya dönüştürme yükü bırakılıyor.
2. Her başlığında “fiyat” geçen sütun ayrı kademe sayılıyor. Alt/üst sınır, güncel fiyat, müşterinin gördüğü fiyat ve seçim alanı birbirine karışıyor. Modüle özel şema ayrımı gerekiyor.
3. Komisyon dosyasındaki dört oran yerine ürünün tek komisyonu kullanılıyor. Yıldız ve flaş teklifleri için tarih/fiyat bakımından geçerli komisyon tarifesi eşleştirmesi bulunmuyor.
4. Flaş seçiminde teklif tarihi/satırı yerine yalnızca barkod üzerinden seçim, aynı ürünün farklı tarihlerdeki tekliflerini ayıramıyor. `24 Saat` gibi seçim değerleri de sayısal fiyat gibi okunamaz.
5. Dışa aktarım kaynak dosyayı korumuyor: yeni, daraltılmış CSV oluşturuluyor. Trendyol'a geri yüklenebilir bir çıktı olduğunu varsayamayız.

Düzeltmenin sırası: üç dosya tipine özel XLSX okuyucu ve şema doğrulama → fiyat/komisyon/tarih eşleştirmesi → satır bazında seçim → kaynak sütunları koruyan XLSX çıktısı. Kişisel veriler yerine sentetik satırlar içeren örnekler bu şemalarla hazırlanıp kabul testi yapılmalıdır.

## Son durum ve kalan sınırlar

Testlerden sonra üç özgün dosya yeniden yüklenerek deneme seçimleri temizlendi; son okumalarda seçili kademe ve bekleyen seçim yoktu. Kullanıcının daha önce verdiği gerçek ürün maliyeti kaydı korundu. Canlı pazaryeri fiyatı değiştirilmedi ve üretilen dosyalar Trendyol'a gönderilmedi.

Bu üç modülde yükleme–seçim–indirme zinciri doğrulandı. Plus komisyon, indirim ve kampanya dosyaları bu üç dosya değildir; onların özgün dosya şemaları bu çalışmada doğrulanmadı. Paket erişimi olmayan modüller, gerçek tahsilat ve canlı pazaryeri fiyat yazımı için önceki sınırlar geçerlidir.

[Seçilmiş sonuçlar](melontik-excel-dogrulama-sonuclari.json) ve [yalnızca başlıklardan oluşan dosya şemaları](trendyol-promosyon-dosya-semalari.json) depodadır. Gerçek dosyalar, barkodlar, ürün adları, hesap/oturum bilgileri ve gerçek kâr tutarları eklenmedi. Ham kanıtlar özel geçici çalışma alanında tutulur.


## 8 Kasım 2026 — Resmi iş kuralları ve ilk XLSX düzeltmesi

Trendyol Satıcı Bilgi Merkezi kaynaklarıyla iş kuralları belgelendi. Ürün komisyonu, avantajlı etiket ve flaş dosyalarının genel CSV akışı yerine şemaya özel XLSX okuma, kademe/dönem eşleştirme, satır bazında seçim ve kaynağı koruyan çıktı eklendi. Önceki eksik listesi bu düzeltmeden önceki sürümü anlatır. Uygulanan kapsam, testler ve kalan sınırlamalar [değişiklik notunda](PROMOSYON-XLSX.md). Canlı pazaryeri yazımı yapılmadı.
