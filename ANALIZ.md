# Kâr&Fiyat — abonelik ürünü için teknik ve ürün incelemesi

Tarih: 7 Kasım 2026. İncelenen durum, bu çalışma alanındaki önceki ödeme/CSRF düzeltmelerini de içerir. Bu turda uygulama kodu değiştirilmedi.

## Düzeltme durumu — ilk paket

1, 2, 7 ve 34 numaralı bulgular için düzeltmeler uygulandı: otomatik yönetici
kaldırıldı, eski bilinen şifreli hesaplar kapatılıyor; 2FA kurulum/yeniden kurulum
korunuyor; takip URL/IP politikası ve crawler çıkış proxy'si eklendi; XLSX sayıları
korunuyor. CLI yönetici sıfırlaması ve 2FA değişikliklerinde oturum iptali de eklendi.
Aşağıdaki bulgular ilk inceleme anını belgeler; kalan maddeler açık durumdadır.
Gerçek pazaryeri sayfalarının yeni ağ politikasıyla uyumluluğu dağıtımda doğrulanmalıdır.

## Düzeltme durumu — kârlılık paketi

10, 11, 13, 14, 16, 17, 19, 20 ve 43 için ortak ürün/sipariş hesabı, rapor dağılımı,
Türkiye gün sınırı, hedef doğrulaması ve kampanya kaydı düzeltildi. 15 için paket
seviyesinde iptal/iade durumları dışlanır; kısmi satır iadeleri henüz tamamlanmadı.
12'deki eksik finans giderleri sıfır gösterilmek yerine açıkça hesap dışında
belirtilir; resmî gider entegrasyonu hâlâ açık. 23'te İngilizce/Türkçe iade ve
kampanya tür adları raporlarda karşılanır. 24'te Buybox iddiası kaldırılıp ekran
kategori içi karşılaştırma olarak adlandırıldı; uydurma mikro iade zararı da kaldırıldı.
42'nin sayısal API doğrulaması iyileştirildi; dosya/arşiv sınırları açık. 44'teki
Chrome eklentisi bu pakete dahil değildir; yeni ortak web hesabına ayrıca taşınmalıdır.

Sipariş giderleri adet başına tahmin edilir; gerçek kargo/hizmet faturası dağılımı,
vergi doğrulaması, finans kayıtlarının tekrar güvenli senkronu ve geçmiş maliyetler
bir sonraki aşamalardadır. Aşağıdaki numaralı bulgular ilk incelemenin kaydıdır.

## Düzeltme durumu — senkron paketi

26, 27 ve 28 için fiyat/stok güncelleme, örtüşmeli imleç, eksik yanıtı reddetme,
sağlayıcı işlem ID'siyle finans upsert'i ve dönem bazlı eski kayıt arşivi eklendi.
29'da limit aşımı artık sessiz kesme değildir; hata olarak bildirilir. 30 için cron
ürünlere ek olarak sipariş/finans geçmişini de çeker. 31'in mağaza bazlı çakışma
kontrolü ve kısa DB yazma transaction'ı tamamlandı; kalıcı iş kuyruğu ve backoff
hâlâ açık. Bağlantı revizyonu değiştiğinde çalışan eski iş veri kaydedemez.

Eski kimliksiz finans kayıtları yalnızca eksiksiz yeniden alınan dönemde arşivlenir;
dönem dışı geçmiş otomatik temizlenmez. Gerçek Trendyol dokümanına erişim ortamın
ağ politikası tarafından engellendi. Kontrollü yanıt testleri canlı API sözleşmesinin
veya gerçek satıcı hesabındaki sonuçların doğrulandığı anlamına gelmez. Özellikle
finans `id` alanı, işlem türleri ve tarih filtreleri dağıtım öncesi kontrol edilmelidir.

## Kapsam ve sınırlar

PHP sayfaları, ortak hesaplama/kimlik doğrulama/veritabanı katmanı, pazaryeri istemcileri, Node tarayıcı servisi, Chrome eklentisi, kurulum belgeleri ve mevcut testler incelendi. Bazı hesaplama hataları ayrı geçici veritabanıyla yerelde yeniden üretildi. Gerçek Trendyol ve iyzico hesaplarıyla uçtan uca doğrulama, üretim sunucusu incelemesi, tarayıcıda tüm ekranları kapsayan görsel test ve yük testi yapılmadı. Kodda bulunmayan işletim kontrolleri, sunucuda da bulunmadığı kesinmiş gibi değerlendirilmemelidir.

Öncelikler: **P0** müşteri verisi/finansal karar güvenilirliği nedeniyle yayın öncesi giderilmeli; **P1** ilk ücretli sürüm için tamamlanmalı; **P2** iyileştirme veya ürün kararı. “Doğrulama” işareti dış servis, mali müşavir, hukuk veya üretim ortamında kontrol gerektirir.

## Güvenlik ve hesaplar

1. **P0 — İlk kurulum yöneticisinin şifresi sabit ve biliniyor.** `includes/config.php:17`, `includes/db.php:250`. Şifreyi değiştirme zorunluluğu ilk kurulumda başka birinin bu bilgilerle giriş yapıp yönetici şifresini değiştirmesini engellemez. Ortama özel güvenli kurulum/CLI ile yönetici oluşturma gerekir.

2. **P0 — 2FA yeniden kurma eylemi mevcut 2FA'yı kod istemeden kapatıyor.** `public_html/hesabim.php:29`. `2fa_setup`, oturum ve CSRF ile `totp_enabled=0` yazar; mevcut TOTP veya şifreyi doğrulamaz. Aktif 2FA yeniden kurulmadan önce ek kimlik doğrulama gerekir.

3. **P1 — Giriş, TOTP ve kayıt için uygulama düzeyinde deneme sınırı yok.** `public_html/giris.php`, `public_html/kayit.php`. TOTP bekleme adımının açık bir zaman aşımı ve kullanılan kodu yeniden kullanmayı engelleme kaydı da yok. IP ve hesap bazlı sınırlandırma, bekleme süresi ve 2FA oturum süresi eklenmeli. Sunucu tarafındaki olası korumalar ayrıca doğrulanmalı.

4. **P1 — E-posta doğrulama ve şifre kurtarma bulunmuyor.** Kayıt sonrası adres doğrulanmıyor; şifresini unutan kişi hesabını kurtaramıyor. Süreli, tek kullanımlık token ve e-posta teslim altyapısı gerekli.

5. **P1 — Oturum yaşam döngüsü eksik.** `includes/auth.php:9`, `public_html/kayit.php`, `public_html/hesabim.php`. Secure cookie ve strict mode açıkça zorlanmıyor; kayıt/demo girişinde oturum ID yenilenmiyor; şifre değişiminde diğer oturumlar iptal edilmiyor. Bunların bir kısmı sunucu ayarıyla sağlanabilir, ancak projede güvence altında değil.

6. **P1 — Giriş sonrası yönlendirme harici adres kabul edebiliyor.** `public_html/giris.php:27` ve `:64`. Yalnızca `/` ile başlama kontrolü, `//example.com` gibi protokole göre dış adrese giden yolları kabul ediyor. Yalnızca yerel izinli yollar kabul edilmeli.

7. **P0 — Rakip takip URL'leri için sunucu ağına erişim riski var.** `public_html/pazar-zekasi.php:17`, `:67`; `crawler/crawl.js:121`. Kullanıcı URL'si host/IP izin listesi olmadan sunucudan ziyaret ediliyor; PHP yönlendirmeleri takip ediyor; crawler alt kaynakları da açıyor. Özel/yerel IP, yeniden yönlendirme ve DNS değişimi kontrolleri ile ağ izolasyonu gerekli. Gerçek erişilebilirlik dağıtımın ağ politikasına bağlıdır.

8. **P1 — Ödeme anahtarları ve TOTP sırları veritabanında açık saklanıyor.** `includes/db.php`, `public_html/admin.php:236`. Pazaryeri anahtarlarının aksine iyzico secret şifrelenmiyor ve yönetici formunda açık metin gösteriliyor. Gizli değerlerin korunması, maskelenmesi ve değiştirme/rotasyon akışı gerekli.

9. **P1 — Şifreleme anahtarı yönetimi kırılgan.** `includes/crypto.php`. Anahtar yoksa/bozuksa sessizce yenisi üretiliyor; yazma başarısı kontrol edilmiyor; eşzamanlı ilk oluşturma kilitli değil; CBC şifrelemesi bütünlük etiketi taşımıyor. Atomik anahtar oluşturma, izinler, hata halinde durma ve doğrulanmış şifreleme gerekir.

## Kârlılık ve raporların doğruluğu

10. **P0 — Aynı ürünün kârı ekranlar arasında değişiyor.** `public_html/urunler.php:260`, `public_html/kar-marji.php:93`, `public_html/uyarilar.php:43`. Bazı sayfalar kayıtlı sıfır hizmet bedeli/stopajı kullanırken bazıları otomatik bedel atıyor. Yerelde maliyet=200, fiyat=400, kargo=50, komisyon=%21,36, KDV=%20 için iki yol **53,80 TL** ve **39,475 TL** verdi. Ortak, açık bir ücret politikası gerekli.

11. **P0 — Ürün ve kategori raporlarında kesintiler kârdan düşülmüyor.** `public_html/raporlar.php:73`, `:94`. Kâr `ciro - maliyet` olarak sunuluyor; komisyon, kargo, vergi ve hizmet bedeli hesaba katılmıyor. Reklam raporundaki “net kâr” da yalnızca satıştan reklamı çıkarıyor. Tüm raporlar ortak hesaplama üzerinden üretilmeli.

12. **P0 — Dashboard giderlerinin bir bölümü sürekli sıfır.** `public_html/dashboard.php:42`. Reklam, ceza, erken ödeme ve diğer giderler başlatılıyor ama finans kayıtlarından okunup toplanmıyor. Bu nedenle gösterilen net kâr eksik giderlerle hesaplanıyor.

13. **P1 — Haftalık performansın kâr sütunu sürekli sıfır kalıyor.** `public_html/canli-performans.php:87`. Haftalık satış ve sipariş sayısı artırılıyor, `profit` artırılmıyor; ekranda bu alan gösteriliyor.

14. **P1 — Gün sınırı Türkiye saatine göre hesaplanmıyor.** `public_html/canli-performans.php:85`. UTC temelli 86400 bölmesi, İstanbul'un yerel gece yarısıyla karşılaştırılıyor. 00:00–02:59 siparişleri önceki güne kayabilir. Yerelde gün kovası 03:00 çıktı; yerel takvim günü kullanılmalı.

15. **P0 — İptal/iade durumları satış toplamlarından ayrılmıyor.** `includes/trendyol.php:153`, `public_html/dashboard.php:44`, `public_html/raporlar.php`. Sipariş durumu kaydediliyor ama toplamlarda durum filtresi veya ters kayıt mantığı yok. İptal edilmiş paketler ciro/kâr içinde kalabilir.

16. **P1 — Karma KDV'li siparişler hatalı hesaplanıyor.** `includes/functions.php:409`. Döngüde son ürünün KDV oranı tüm sipariş için kullanılıyor. Komisyon, kargo ve hizmetin vergi oranları da tek ürün oranıyla ele alınıyor. Satır bazlı vergi ve gider dağıtımı gerekli.

17. **P1 — Sipariş kârı bazı kayıtlı maliyetleri kullanmıyor.** `includes/functions.php:445`. Ek maliyet ve iade oranı sıfır gönderiliyor; hizmet bedeli sabit; ürün/sipariş başına kargo ve paket gideri dağıtım kuralı açık değil. Çok adetli ve çok ürünlü paket testleri gerekir.

18. **P1 — Geçmiş dönem maliyetleri ve komisyonları korunmuyor.** Siparişler güncel ürün maliyetine/oranına bağlanıyor. Bugün maliyet değiştirildiğinde geçmiş ay kârı da değişiyor. Sipariş anındaki maliyet ve gerçek kesinti kayıtları ayrı saklanmalı.

19. **P0 — Ulaşılamayan hedef marj için aşırı büyük fiyat üretiliyor.** `includes/functions.php:144`, `public_html/assets/app.js:83`. Arama üst sınırında çözüm bulunamadığı kontrol edilmiyor. Yerelde %90 hedef marj için yaklaşık **2,37 × 10²¹ TL** döndü; gerçekleşen marj %64,7'de kaldı. Geçersiz hedef açıkça reddedilmeli.

20. **P1 — Sıfır değerler bazı hesaplarda varsayılana dönüşüyor.** `public_html/kar-marji.php:57`, `public_html/uyarilar.php`. `?: 20` ve `?: trendyol_service_fee()` kullanımı %0 KDV veya sıfır hizmet bedeli gibi açık değerleri korumuyor. “Eksik” ile “sıfır” ayrılmalı.

21. **P1 / doğrulama — Komisyon ve ücret tarifeleri güncellik yönetimine sahip değil.** `includes/functions.php:13`, `:129`, `public_html/assets/app.js:16`. Genel kategori tablosu ve 13,19 TL sabiti var; kaynak/tarih/sürüm ve satıcıya özel sözleşme modeli yok. Finans API'siyle barkod bazlı oran güncellenebilse de tarihsel tarife takibi sağlanmıyor.

22. **P1 / doğrulama — “Net kâr” ile nakit kesintileri ayrımı netleşmeli.** `includes/functions.php:93`. Stopaj doğrudan kârdan düşülüyor, negatif net KDV sıfırlanıyor, iade kaybı satışın yüzdesi kabul ediliyor. Varsayımlar ve gelir/kurumlar vergisi kapsamı mali müşavirle doğrulanıp tahmini kâr ve nakit akışı ayrı adlandırılmalı.

23. **P1 — İade ve kampanya raporları senkron verinin tür adlarıyla uyuşmuyor.** `includes/functions.php:499`, `public_html/raporlar.php:110`, `:198`. Senkron `Return`, `Discount`, `Coupon` alıp tür adını olduğu gibi saklıyor; rapor `İade`, `İndirim`, `Kupon` arıyor. Resmî yanıt İngilizce türleri kullandığında raporlar boş kalır. Tek bir normalizasyon katmanı gerekli.

24. **P1 — Buybox raporu gerçek Buybox verisi kullanmıyor.** `public_html/raporlar.php:169`. Kendi ürünlerinin kategori içi en düşük fiyatı ile karşılaştırma yapıyor; rakip teklif veya Buybox sahipliği sorgulanmıyor. Özellik yeniden adlandırılmalı veya gerçek veriyle tamamlanmalı. İade zararı raporundaki %2 “mikro iade” varsayımı da gerçek veri gibi sunulmamalı.

25. **P1 — Hakediş kontrolü gerçek mutabakat değil.** `public_html/hakedis-desi.php:48`. Kullanıcının girdiği tutar son 30 gün sipariş toplamıyla karşılaştırılıyor; ödeme dönemi, iadeler, kesintiler ve vade eşleştirilmiyor. Sipariş verisi sıfırken bile “eksik bulunmadı” sonucu üretilebiliyor. Yetersiz veri durumunda doğrulama yapılamadığı belirtilmeli.

## Entegrasyonlar ve senkronizasyon

26. **P0 — Mevcut ürünlerin fiyat ve stokları güncellenmiyor.** `includes/functions.php:340`. Barkod zaten varsa kayıt atlanıyor. “Ürünleri Çek” sonrasında mevcut ürün fiyatı/stoku eski kalıyor. Maliyetleri koruyan bir upsert gerekir.

27. **P0 — Kısmi API hatalarında senkron başarılı sayılıp veri atlanabiliyor.** `includes/trendyol.php:111`, `includes/functions.php:353`. Başarısız paralel ürün sayfaları atlanıyor; son senkron zamanı yine ilerliyor. Ayrıca imleç işlem sonuna yazıldığından işlem sırasında değişen ürünler de kaçabilir. Güvenli imleç, hata görünürlüğü ve yeniden deneme gerekli.

28. **P0 — Cari hesap senkronu aynı işlemi tekrar tekrar ekliyor.** `includes/functions.php:489`, `includes/db.php:167`. Sağlayıcı işlem ID'si ve benzersiz kısıt/upsert yok. Aynı dönem tekrar çekildiğinde gider ve iade toplamları şişer. Sağlayıcı ID'si saklanmalı; tekrar çekim etkisiz olmalı.

29. **P1 — Sipariş ve finans sayfa limitleri sessiz veri kaybına neden olabilir.** `includes/trendyol.php:153`, `:244`. Varsayılan sipariş limiti pencere başına 2.000, finans limiti tür/pencere başına 5.000 kayıt; limite ulaşma durumu eksiklik olarak raporlanmıyor. Kapsama ve sayfalama tamlığı izlenmeli.

30. **P1 — Otomatik iş yalnızca ürünleri çekiyor.** `scripts/sync.php`. Siparişler çoğunlukla Canlı Performans sayfası açılınca son 7 gün için geliyor; finans elle tetikleniyor. 30/90 günlük raporların tam geçmişe sahip olduğu garanti edilmiyor. İlk geçmiş aktarımı ve zamanlanmış sipariş/finans işleri gerekli.

31. **P1 — Senkron istekleri web sürecini ve oturumu uzun süre tutuyor.** `includes/functions.php:294`, `public_html/canli-performans.php:60`. Sınırsız süre/512 MB, oturum kilidi ve arka plan iş kuyruğu eksikliği var. İş kaydı, kilit, ilerleme, tekrar deneme ve hata yönetimi gerekir.

32. **P1 — Fiyat güncellemesinin nihai sonucu izlenmiyor.** `includes/trendyol.php:407`, `public_html/magaza.php:68`. Başlangıç HTTP yanıtı sonrası ürün bazlı sonuç/batch durumu sorgulanmıyor; yerel fiyat/stok doğrulanıp güncellenmiyor. Geçerli fiyat/stok aralıkları ve yeniden deneme güvenliği de eksik.

33. **P2 / doğrulama — Diğer altı pazaryerinin entegrasyonu tamamlanmış değil.** `public_html/magaza.php:14`, diğer istemciler. Amazon için refresh token veri modeli eksik; bazı istemcilerin imza/yanıtları güncel belgelerle doğrulanmalı. Pazarlamada hesap makinesi desteği ile mağaza entegrasyonu açık ayrılmalı. Trendyol endpoint sürümü de güncel resmî belgeler ve satıcı hesabıyla doğrulanmalı; DEPLOY.md içinde sürüm geçiş notu var.

## Ürün yönetimi, Excel ve CSV

34. **P0 — XLSX dışa aktarımı sayıları bozuyor.** `includes/xlsx.php:199`. `sprintf('%.10g', $v)` sonucundaki sıfırlar koşulsuz kırpılıyor. Yerelde float `200 → 2`, `400 → 4`, `20 → 2` yeniden üretildi. Maliyet ve satış fiyatları Excel'de hatalı hale gelebilir.

35. **P1 — Türkçe sayı biçimleri güvenilir okunmuyor.** `public_html/toplu-analiz.php:22`, `public_html/promosyon-analiz.php:21`, `crawler/crawl.js:58`. `1.234,56` gibi değerler doğru normalize edilmiyor; bazı CSV yolları doğrudan float dönüşümü yapıyor. Ortak yerel sayı ayrıştırıcısı, BOM/kodlama ve ayırıcı desteği gerekir.

36. **P1 — Maliyet şablonu içe/dışa aktarmada kendisiyle tam uyumlu değil.** `public_html/urunler.php:25`, `:117`. Dışarıdaki `Ürün Desisi`/`Ekstra Maliyet (TL)` başlıkları içe aktarıcının beklediği başlıklarla örtüşmüyor. USD ve yüzde ek gider alanları dışarıda sunuluyor ama dönüşte işlenmiyor. Desteklenmeyen alanlar reddedilmeli; tam round-trip testi gerekli.

37. **P1 — Aynı barkod birden çok pazaryerinde yanlış güncellenebilir.** `public_html/urunler.php:158`. Maliyet aktarımının UPDATE filtresi kullanıcı+barkod; pazaryeri filtresi yok. Şablon da pazaryeri kimliği taşımıyor. Kasıtlı ortak maliyet modeli tanımlanmadığı sürece veri karışması riski var.

38. **P1 — Maliyet aktarımı sayaçları başlatılmıyor.** `public_html/urunler.php:160`. `$updated` ve `$notFound` tanımlanmadan artırılıyor/kullanılıyor. Sonuç bildirimi güvenilir değil; hata kaydını kirletiyor. Satır bazlı sonuç ve atomik aktarım da eksik.

39. **P1 — Promosyon modülleri tarifeye özel analiz yapmıyor.** `public_html/promosyon-analiz.php:11`. Modüller büyük ölçüde başlık/etiket değiştiriyor; fiyat içeren sütunları tarayıp kayıtlı sabit komisyonla hesaplıyor. Kademeye göre komisyon, kampanya karşılama oranı, minimum sepet ve özgün dosya yapısının geri korunması tamamlanmalı. Çıktının Trendyol'a kabul edileceği gerçek şablonla doğrulanmalı.

40. **P1 — Toplu fiyatlama CSV indirme bağlantısı çalışır bir önizlemeyi taşımıyor.** `public_html/kar-marji.php:39`, `:81`, `:138`. Önizleme yalnızca POST isteğinde var; GET indirme isteğinde `$rep` yeniden null oluyor. İndirme için süreli iş/önizleme kaydı gerekli.

41. **P1 — Toplu fiyatlama uygulaması önizleme ve mağaza davranışı bakımından eksik.** `public_html/kar-marji.php:52`. Ekran filtreleri hedef sorgusuna taşınmıyor; onayda önizleme korunmayıp yeniden hesaplanıyor; güncelleme yalnızca yerel DB'ye yapılıyor; işlem bütünü ve geri alma/audit kaydı yok. Kullanıcı kaç ürünün nerede değişeceğini açık görmeli.

42. **P1 — Dosya ve sayısal girdi sınırları eksik.** `includes/xlsx.php:11`, `public_html/api-save.php`, dosya yükleme sayfaları. Uygulama düzeyinde satır, arşiv açılmış boyutu, XML boyutu ve alan aralığı kontrolleri yok; XLSX tümüyle açılıyor. Sistem upload limitleri tek başına açılmış arşivi sınırlamaz. Negatif maliyet, aşırı oran, hatalı tür ve kısmi aktarım senaryoları kontrol edilmeli. CSV dışa aktarımında formül olarak yorumlanabilecek hücreler de güvenli yazılmalı.

43. **P1 — Kampanya hesabını kaydetme normal fiyatı kaydediyor.** `public_html/index.php:357`. `priceVal`, yalnızca ters fiyat modunu ayırıyor; kampanya modunda indirimli sonuç yerine normal fiyat alınıyor. Sonuçta görülen kampanya kârı ile kaydedilen ürün farklı oluyor.

44. **P1 — Chrome eklentisi tamamlanmamış.** `chrome-eklenti/manifest.json`, `popup.html:59`. Manifest V3 varsayılan güvenlik politikası satır içi scripti çalıştırmaz; JavaScript ayrı dosyaya taşınmalı. Ayrıca eklentinin formülü web uygulamasından farklı, KDV/stopaj/hizmet bedeli eksik. Satıcı panelinden veri okuma ve abonelik bağlantısı da yok.

## Abonelik, demo ve ticari akış

45. **P1 — Ücretsiz ürün sınırı tüm giriş yollarında uygulanmıyor.** `public_html/api-save.php:42`, `public_html/magaza.php`, `includes/functions.php:287`. Manuel kayıt limiti var; ücretsiz kullanıcı mağaza senkronuyla çok sayıda ürün alabiliyor. Hangi özelliğin hangi pakete ait olduğu merkezi politika olarak uygulanmalı. Rapor ve toplu yeniden fiyatlamanın paket kapsamı da net değil.

46. **P1 — Demo sınırı ve izolasyonu eksik.** `public_html/demo.php:17`. Süresiz Pro hesap üretiliyor; `is_demo` işlemleri kısıtlamada kullanılmıyor; temizlik ve kota yok. Saniyelik e-posta kimliği çakışabilir. Ana sayfada demo tanıtımı var ama demo girişine bağlı akış yok; demo yalnızca ürün üretirken Dashboard sipariş bekliyor. Süreli ve örnek siparişli demo gerekli.

47. **P1 — Pro kullanıcı aktifken yenileme formu gösterilmiyor.** `public_html/odeme.php:143`. Ödeme kartı `if (!$pro)` içinde; üye bitiş gelmeden arayüzden yenileyemiyor. Alttaki aktivasyon kodu süre eklemeyi desteklese de kullanıcı akışı bunu sunmuyor.

48. **P2 / ürün kararı — Otomatik abonelik yenilemesi yok.** `public_html/kosullar.php`. Mevcut model manuel aylık erişim satışı. Bu ilk sürüm için bilinçli seçim olabilir; tekrar eden tahsilat vaat edilecekse otomatik yenileme, iptal, ödeme hatası ve yeniden deneme akışı ayrıca geliştirilmelidir.

49. **P1 — Ödeme yaşam döngüsü eksik.** `public_html/iyzico-callback.php`, `includes/db.php:83`. Başarılı aktivasyon iyileştirildi, ancak başarısız/iptal/sona ermiş incelemeler için kalıcı durum akışı, bildirim kaçarsa mutabakat işi, iade/chargeback ve üyelik geri alma yok. Callback hataları operatöre izlenebilir olay olarak kaydedilmiyor.

50. **P1 / doğrulama — Sandbox ve canlı ortam bağlamı ödeme kaydında tutulmuyor.** Checkout sorgusu güncel global ayarı kullanıyor; işlem açılırken kullanılan ortam saklanmıyor. Ortam/anahtar değiştirilirse bekleyen ödeme doğrulanamayabilir. Gerçek iyzico sandbox başlangıç→dönüş testi ve üretim öncesi kontrol gerekli. Callback/referral URL'leri için `HTTP_HOST` yerine sabit uygulama adresi tercih edilmeli.

51. **P1 — Havale bildirim ve yönetim süreci eksik.** `public_html/odeme.php:17`, `public_html/admin.php`. Tekrarlı POST yeni bekleyen kayıtlar açabiliyor; referans gün bazlı ve benzersiz değil; ödeme bildirimi için yapılandırılmış IBAN kontrolü yok. Onaylayan, banka işlem eşleştirmesi ve düzeltme gerekçesi kaydı tutulmuyor. Bu durum ilk aktivasyonun tekrarına karşı önceki düzeltmeden ayrıdır: farklı bekleyen kayıtlar ayrı ayrı onaylanabilir.

52. **P1 — Partner hakedişi ticari tanımla uyuşmuyor ve kapanış akışı eksik.** `includes/billing.php:20`, `public_html/hesabim.php:147`. Ekran KDV hariç %15 derken hesap brüt tutarın %15'i. Hakedişi ödenmiş işaretleme, ödeme referansı, iade mahsuplaşması ve uygunluk kontrolleri tamamlanmalı.

53. **P1 — Fatura, destek ve abonelik bildirimleri yok.** Ödeme/yenileme makbuzu ve fatura süreci, yaklaşan bitiş e-postası, işlem hata bildirimi ve izlenebilir destek kanalı projede bulunmuyor. Fatura dış sistemde kesilecekse süreç ve sorumluluk belgelenmeli. “Öncelikli destek” vaadi için görünür iletişim kanalı gerekir.

## İşletim, veri altyapısı ve kullanıcı deneyimi

54. **P1 — Yayın/izleme/geri yükleme süreçleri eksik.** Depoda otomatik CI, health/readiness kontrolü, iş başarısızlığı alarmı, yapılandırılmış hata kaydı ve otomatik yedek/geri yükleme doğrulaması bulunmuyor. SQLite WAL ile tutarlı yedek ve şifreleme anahtarı yedeği birlikte tasarlanmalı. Üretimde haricî çözümler varsa doğrulanmalı.

55. **P1 — Veritabanı bütünlüğü ve migration yönetimi güçlendirilmeli.** `includes/db.php`. Çoğu ilişkide foreign key yok; kullanıcı+pazaryeri+barkod tekilliği yok; tracker silinince geçmişler kalıyor. Her web isteğinde DDL/seed kontrolü çalışıyor; migration sürümü ve yarış koşulu yönetimi yok. Birden çok örnek/worker kullanımında SQLite'ın paylaşımı ve yazma kapasitesi planlanmalı; SQLite kullanımı tek başına hata değildir.

56. **P1 — Büyük veri için kaynak sınırları ve rapor performansı eksik.** Birçok ekran bütün ürün/siparişleri `fetchAll()` ile belleğe alıyor; analiz çıktıları oturumda tutuluyor; takip listesi kota ve sayfalama taşımıyor; maliyet aktarımı/raporlarda sorgu yükü artıyor. Kullanıcı başına iş kotaları, sorgu indeksleri, sayfalama ve yük testi gerekir. “Sınırsız ürün” vaadi kapasiteyle ilişkilendirilmeli.

57. **P1 — Crawler'ın Linux dağıtımı ve kullanıcı yaşam döngüsü eksik.** `crawler/crawl.js:32`, `:159`, `:174`, `crawler/package.json`. Otomatik Chrome keşfi yalnızca Windows yollarına bakıyor; Node `node:sqlite` gereksinimi engines alanında tanımlı değil; servis gözetimi ve başarısız çıkış kodları eksik. Bütün tracker'lar, kullanıcı Pro süresi bitse bile taranıyor; Chrome sandbox kapatılıyor; geçmiş saklama ve iş sınırları yok. PHP paylaşımlı hosting ile ayrı crawler arasında aynı DB'ye erişim modeli belgelenmeli.

58. **P1 — Test kapsamı temel ürün risklerini yakalamıyor.** Mevcut ödeme/akış testleri yararlı ama rapor mutabakatı, iptal/iade, karma KDV, Excel round-trip, senkron tekrarları/kısmi hatalar, eşzamanlı ödeme istekleri ve tarayıcı ekranları için kapsama yok. Önceki testlerin geçmesi, tüm ürünün doğru olduğunu kanıtlamıyor. PHP zip/XML/curl gereksinimleri de kurulumda açık listelenmeli.

59. **P1 — Ürün gezinmesi ve ilk kullanım akışı tamamlanmamış.** `includes/header.php`, `public_html/assets/style.css:43`, `includes/footer.php`. Çok sayıda bağlantı tek satırda; mobil için giriş yapılmış uygulama menüsü yok. Mağaza→ürün→maliyet→ilk doğru rapor yönlendirmesi eksik; hesap/ürün düzenleme akışları dağınık. Footer “Ürünlerim” Dashboard'a gidiyor; geliştirici komutları müşteri Pazar Zekası ekranında gösteriliyor. Mobil taşma/erişilebilirlik/ekran okuyucu ve hata durumları tarayıcıda ayrıca doğrulanmalı. Depodaki cookie/debug HTML dosyaları dağıtım kapsamından çıkarılmalı ve hassas içerik bakımından incelenmeli.

60. **P1 / doğrulama — Hukuki metinler ve veri yaşam döngüsü gerçek ürünle eşleşmiyor.** `public_html/kvkk.php:11`, `public_html/kosullar.php`. Metin “üçüncü kişilerle paylaşılmaz” derken iyzico'ya alıcı bilgileri gönderiliyor; sipariş müşteri adı ve API bilgileri de veri envanterinde yeterince anlatılmıyor. Veri sorumlusu/iletişim, saklama süreleri, silme/dışa aktarma başvurusu ve ticari abonelik/iade şartları netleşmeli. KVKK madde atıfları hukuk uzmanıyla doğrulanmalı; “KVKK uyumlu” iddiası bu incelemeyle doğrulanmış değildir.

## Yerelde yeniden üretilen örnekler

- Ortak test girdileri: maliyet 200 TL, satış 400 TL, kargo 50 TL, komisyon %21,36, KDV %20. Kayıtlı sıfır hizmet/stopaj ile `calc_profit`: **53,80 TL**. Kâr Marjı ekranındaki 13,19 TL hizmet+otomatik stopaj seçimi: **39,475 TL**.
- %90 hedef marj, aynı girdiler ve varsayılan hizmet/stopaj ile `calc_price`: fiyat **2.3666711477967515e+21**, gerçekleşen marj **%64,7**. Ulaşılamayan hedef reddedilmiyor.
- XLSX sayı yazım ifadesi: float **200→2**, **400→4**, **20→2**, **50,5→50,5**. Bu, dosyanın sayı üretim ifadesinin yeniden üretimidir; Excel masaüstü uygulamasında görsel doğrulama yapılmadı.
- Gün kovası: İstanbul'da 7 Kasım 2026 yerel gün başlangıcı 00:00 iken kullanılan UTC bölmesi gündüz örneği için 03:00 oluşturuyor. Gece ilk üç saat önceki güne sınıflanabilir.

## Önceden giderilmiş, bu listedeki açıklarla karıştırılmaması gerekenler

Ödeme sahibi/tutar/para birimi/sepet eşleştirmesi; aynı ödeme token/ID'siyle ikinci aktivasyonun önlenmesi; ödeme+üyelik+hakedişin aynı transaction'da kaydı; manuel ürün kaydı ve toplu fiyatlamanın CSRF kontrolü; boş CSRF tokenının reddi; varsayılan şifre değiştirilmeden yönetici işlemi yapılmasının engellenmesi önceki turda düzeltildi. Buna rağmen ilk kurulumdaki bilinen şifre riski ve bu rapordaki ödeme yaşam döngüsü eksikleri sürüyor.

## Önerilen uygulama sırası

1. P0 güvenlik açıkları, XLSX veri bozulması ve ortak kârlılık motoru.
2. Sipariş/finans veri modeli, geçmiş maliyetler, tekrar güvenli senkron ve doğru raporlar.
3. Merkezi paket politikası, demo sınırları, yenileme ve ödeme mutabakatı.
4. Gerçek şablonlarla dosya akışları, mağaza fiyat güncelleme sonuçları ve onboarding.
5. Sandbox/satıcı hesabı doğrulamaları, operasyonel izleme/yedek, hukuk metinleri ve sınırlı ücretli pilot.

## 8 Kasım 2026 — Resmî Trendyol belgeleriyle uyum düzeltmeleri

Resmî geliştirici belgelerine erişildi. Kaldırılmış V1 ürün/sipariş yolları yerine
V2 içerik/varyant kataloğu, ayrı stok/fiyat görünümü ve sipariş stream servisi
uygulandı. Finansın Türkçe yanıt türleri korunuyor, iptal türleri alınıyor.
Fiyat kabulü artık başarı sayılmıyor: kalıcı batch takibi ve ürün SUCCESS kontrolü
var; eski stok fiyat isteğiyle geri gönderilmiyor. Kaynaklar, doğrulanan sınırlar
ve gerçek satıcı hesabıyla yapılması gereken kabul koşulları
[Trendyol doğrulamasında](docs/TRENDYOL-DOGRULAMA.md) listelidir.
Önceki tarihli V1/artımlı stok notları tarihsel durumdur; güncel davranış bu rapordadır.
