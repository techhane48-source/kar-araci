# Promosyon XLSX okuma, hesaplama ve dışa aktarım

8 Kasım 2026. Önceki genel CSV okuyucusu, [gerçek dosya incelemesinde](MELONTIK-EXCEL-DOGRULAMA.md) doğrulanan üç dosya türüne özel XLSX akışıyla değiştirildi. [Resmi iş kuralları](TRENDYOL-IS-KURALLARI.md) ile sistem gözlemleri ayrı tutuldu.

## Davranış

- Ürün komisyon tarifesi dört fiyat aralığını ve her aralığın kendi komisyonunu kullanır. Güncel fiyat/müşteri fiyatı sütunları yeni teklifler sayılmaz.
- Avantajlı etikette üç üst fiyat teklif olarak değerlendirilir. `KOMİSYON TARİFESİ` bir Var/Yok alanıdır. Tarife gerekiyorsa ayrı yüklenen ürün komisyon dosyasıyla barkod, fiyat ve tarih bakımından eşleştirilir.
- Flaş teklifleri kaynak Excel satırıyla tutulur. Aynı barkod farklı tarihlerde tekrar edebilir. 24 ve 3 saat seçenekleri, dosyada fiyat ve saat bulunduğunda açılır. Çıktıda `Güncellenecek Fiyat` sütununa ilgili seçim etiketi yazılır.
- Başlangıç/bitişler Türkiye saatindedir. Komisyon ve yıldız yüklemesinde tarih/saat kullanıcıdan alınır; tanınan 7 günlük komisyon metnindeki gün/saatle uyuşması kontrol edilir. Yıl bilgisi bu metinde yoksa kullanıcıdan alınır. Flaş tarihleri satırdan okunur.
- Tarife teklifin bütün zaman aralığını kapsıyorsa kademe uygulanır. Dönem tamamen dışarıdaysa dosyanın birinci kademe temel komisyonuna dönülür. Kısmi kesişimde tek oranla kâr üretilmez; aralığın ayrılarak kontrol edilmesi istenir.
- Eksik maliyet, bulunamayan/çoklu eşleşen ürün, eksik zorunlu tarife, hatalı aralık, formül içeren temel hesap alanı ve süresi geçmiş teklif seçilemez. Sıfır komisyon ve sıfır KDV varsayılana dönüştürülmez.
- Her satır için bir teklif seçilir. 100 satırlık sayfalarda seçimler saklanabilir. Başka sayfaya geçmeden saklamak gerekir. İndirmede yeni sayfa seçimleri önceki sayfalardaki seçimlerle birleştirilir.
- Çıktı özgün ZIP çalışma kitabının kopyasıdır. Yalnızca çalışma sayfası akış halinde yeniden yazılır, seçilen hücrelerin değerleri değiştirilir. Diğer satır/hücre değerleri ve arşiv bileşenleri korunur. XML'in sözdizimsel biçimi/boşlukları aynı kalmak zorunda değildir.
- Yükleme veya indirme, ürün tablosundaki fiyatı ya da Trendyol'u değiştirmez. Eski dosya/tarife/maliyet bağlamından gelen POST seçimleri kabul edilmez.

Plus, indirim ve kampanya modüllerinde özgün dosya sözleşmesi henüz uygulanmadığı için genel okuyucuyla sahte bir analiz yapılmaz; kapsam bilgisi gösterilir. Eski serbest CSV promosyon formatı kaldırılmıştır. Genel CSV analizi `toplu-analiz.php` üzerinde devam eder.

## Hesabın kapsamı

Hesap, teklif fiyatını satış ve komisyon matrahı kabul eden basit senaryodur. Kayıtlı ürünün maliyet, kargo, hizmet bedeli, stopaj ve KDV ayarları kullanılır. Mevcut kupon/kampanya zinciri, pazaryeri katkısı veya ayrı komisyon matrahı çözülmez. Bu nedenle arayüz sonuçları tahmin olarak adlandırır ve birleşimlerin kapsam dışında olduğunu açıklar. Muhasebe veya gerçekleşmiş hakediş doğrulaması değildir.

## Saklama ve sunucu gereksinimleri

- PHP 8.1+; uygulamanın SQLite/mbstring gereksinimlerine ek olarak ZipArchive, DOM, XMLReader ve XMLWriter gerekir (genellikle PHP zip ve xml paketleri).
- En fazla 10 MB dosya; sunucu `upload_max_filesize` en az 10M, `post_max_size` en az 12M olmalı. Eksik uzantı veya yükleme hatası kullanıcıya bildirilir.
- XLSX arşivi diske açılmaz. En fazla 2.000 bileşen, bileşen başına 64 MB, toplam 128 MB açılmış boyut ve 50.000 veri satırı sınırı uygulanır. DTD/entity, dış sayfa bağlantısı ve güvensiz ZIP yolları reddedilir.
- Yüklemeler web kökü dışındaki veritabanı dizininin `promotion-imports/<user_id>` alanına rastgele adla, dosya 0600/dizin 0700 izinleriyle saklanır. Oturumda yalnızca sahiplik, sürüm, dönem, dosya metaverisi ve seçimler tutulur.
- Oturumdaki kaynaklar 24 saat kullanılabilir. Eski dosyalar sonraki yüklemede temizlenir; zamanlanmış disk temizleme işi bu değişiklikte yoktur. Kullanıcı başına geçici alan 50 MB ile sınırlıdır. Aynı modülün dosyası değişince eski dosya ve seçimler kaldırılır.
- Çıktı için kullanılan geçici XML ve XLSX dosyaları işlem sonunda silinir. Büyük çalışma sayfası satır parçalarıyla işlenir.

## Doğrulama

`php scripts/test-promotion.php`: dört komisyon, yıldız/tarife eşleşmesi, sıfır oran, maliyet eksikliği, saatlik kısmi çakışma, tekrarlı barkod, 3 saat ve 24 saat teklifleri, süresi geçmiş teklif, değiştirilmiş seçim anahtarı, Türkçe sayı, XML/ZIP sınırları ve kaynak yapısını koruyan çıktı.

`php scripts/test-promotion-http.php`: ayrı test veritabanı ve çalışan yerel sunucu ister. Giriş/Pro/CSRF, gerçek multipart yükleme, XLSX indirme, kullanıcı izolasyonu, maliyet/tarife değişince eski seçimin reddi ve sayfalar arası aynı barkod seçimlerini kontrol eder.

Örnek, iki terminalde aynı test veritabanıyla:

```sh
mkdir -p /tmp/kf-promo-test
KARFIYAT_DB=/tmp/kf-promo-test/app.sqlite php -S 127.0.0.1:8093 -t public_html
```

```sh
KARFIYAT_DB=/tmp/kf-promo-test/app.sqlite KARFIYAT_TEST_BASE=http://127.0.0.1:8093 php scripts/test-promotion-http.php
```

Mevcut `test-profit.php`, `test-xlsx.php` ve yeni XLSX sözleşmesine uyarlanmış `test-profit-http.php` regresyonları geçti. Tarayıcıda masaüstü ve dar ekranla yükleme/seçim/indirme denendi; JavaScript hatası olmadı. Önceden raporlanan genel üst menü taşması bu değişiklikle giderilmedi.

Kullanıcının sağladığı üç gerçek dosya özel alanda yeniden okundu. Test maliyetleri sentetikti; gerçek maliyet/kâr doğrulaması yapılmadı. Bağımsız Python ZIP/XML karşılaştırmasında üç çıktının da satır sayısı ve başlıkları korundu; yalnızca seçilen tek hücre değişti. 13.851 satırlık flaş dosyası okuma/analiz/çıktı/tekrar okuma denemesi 128 MB PHP bellek sınırında tamamlandı (ölçülen PHP zirvesi yaklaşık 70 MB; bu değer işletim sistemindeki tüm süreç belleği değildir). Dosyaların Trendyol paneline yeniden yüklenerek kabulü ve canlı fiyat yazımı denenmedi.
