# Melontik işlev doğrulaması — 8 Kasım 2026

Bu çalışma [üyelikli incelemeyi](MELONTIK-UYELIKLI-INCELEME.md) tamamlayan sonuç odaklı test aşamasıdır. Düğmenin açılması, kalıcı kayıt doğrulaması ve bağımsız hesap kontrolü ayrı değerlendirilmiştir. **Tüm özellikler tamamen doğrulanmış değildir.**

## Kalıcı işlemler ve filtreler

Kullanıcının belirlediği ürün ailesindeki stoksuz tek varyant üzerinde çalışıldı. Kullanıcının bildirdiği gerçek alış maliyeti kaydedildi; yeni ve yalnızca kimlik doğrulama bilgisini taşıyan bağımsız tarayıcı bağlamında tekrar okundu. Ürün önbelleği bu bağlama kopyalanmadı. Diğer varyantların maliyetleri değişmedi.

Desi küçük bir miktar değiştirilip yeniden okunarak doğrulandı, ardından ilk değerine döndürüldü. Tek varyant arşivlendi ve arşiv listesinden geri alındı. Her iki normal arayüz işleminin yanıtı başarılıydı ve güncellenen kayıt sayısı bir olarak doğrulandı. Son bağımsız kontrolde hedef normal listede, maliyet kayıtlı ve desi ilk değerindeydi.

Yedi sonuç kontrolü geçti: barkod aramasında hedefin bulunması; eşit alt/üst maliyet sınırı; hedefin üstündeki alt sınır; hedefin altındaki üst sınır; sıfır stok üst sınırı; pozitif stok alt sınırı; eşleşmeyen barkod. Barkod araması alt dize eşleşmesi yapabildiği için tam olarak tek sonuç varsayılmadı.

## Finansal dışa aktarım

İndirilen XLSX dosyası gerçek çalışma kitabı yapısı ve satırları üzerinden okundu. Sayısal alanları eksiksiz satırların tamamında şu eşitlik, 0,02 TL toleransla sağlandı:

`kâr = satış − maliyet − komisyon − kargo − net KDV − hizmet bedeli − stopaj − ek maliyet`

Hesaplanabilen kâr/maliyet ve kâr/satış yüzdeleri de aynı toleransla tutarlıydı. Kârı bilinmeyen satırlar sıfır kabul edilmedi ve başarı sayılmadı. Bazı bilinmeyen satırlarda toplam maliyet bulunmasına rağmen kârın neden hesaplanamadığı satır ayrıntılarıyla kesinleştirilmedi. Kargo faturası yansıması sıfırdan farklı bir örnekte doğrulanmadı. Bu kontrol banka mutabakatı veya farklı dönemlerdeki Dashboard ile dosya toplamlarının eşitliği anlamına gelmez.

## Bağımsız fiyat hesaplayıcısında tutarsızlık

Tamamen sentetik girdi: maliyet 1.000 TL, kargo 100 TL, komisyon %20, hedef kâr 250 TL; oran modunda maliyet üzerinden %25. Her iki modda da kararlı sonuçlar tekrar okundu.

| KDV | Gösterilen satış fiyatı | Gösterilen kâr | Dökümden hesaplanan kâr | Sonuç |
| --- | ---: | ---: | ---: | --- |
| %20 | 1.788,85 | 250,00 | 250,01 | Kuruş yuvarlaması içinde tutarlı |
| %10 | 1.724,70 | 250,00 | 261,37 | Uyuşmuyor |
| %0 | 1.721,80 | 250,00 | 323,29 | Uyuşmuyor |

Dökümden hesaplanan kâr, gösterilen net KDV işaretini korur. Negatif net KDV sıfıra sabitlense bile düşük KDV örnekleri sırasıyla 250,89 ve 247,03 TL verir; gösterilen hedefle yine uyuşmaz. Bu gözlem görünür hesap dökümünün iç tutarsızlığını kanıtlar; kapalı sunucu kodunun nedenini veya hukuki vergi doğruluğunu kanıtlamaz. Sipariş XLSX kontrolünün sonucu ayrı değerlendirilmelidir.

Sınır denemelerinde negatif maliyet, negatif kargo ve negatif KDV kabul edildi. %101 manuel komisyon, negatif satış fiyatı üretti. Sıfır hedef kâr, sıfır kargo ve sıfır manuel komisyon hesaplanabildi; boş maliyette hesap dökümü verilmedi. İlk yükleme sırasındaki geçici geçersiz değer kalıcı hata olarak sayılmadı; hazır ekranla tekrar kontrol edildi.

Bizim hesap motorumuz negatif maliyet/kargo/KDV ve %101 komisyon girdilerini zaten reddediyor. Düşük KDV için referans sonucu doğrudan kopyalamak doğru bir kabul testi değildir; giderlerin KDV oranları ve net KDV politikası ayrıca tanımlanmalıdır.

## Kalan doğrulama sınırları

- **Pazar Zekası / Influencer Radarı:** hesap kotası veya paket erişimi yok; kullanıcı başka erişim sağlayamadığını belirtti. Erişim dışındaki işlevler denenmedi.
- **Promosyon dosyası:** gerçek Trendyol kaynak dosyasıyla yükleme, kademe seçimi ve tekrar dışa aktarım zinciri tamamlanmadı. Demo görünümü ve dosya seçici incelemesi bu zincirin yerine geçmez.
- **Ödeme / abonelik:** gerçek satın alma veya tahsilat yapılmadı; ödeme uçtan uca doğrulanmadı.
- **Pazaryerine fiyat yazımı:** canlı satış fiyatı değiştirilmedi. Yerel maliyet/arşiv kaydının başarılı olması Trendyol fiyat güncellemesini kanıtlamaz.
- **Kapalı sunucu işlemleri:** kaynak koduna erişim olmadan tetiklenmeyen arka plan işleri ve tüm istisna yolları kesin olarak açıklanamaz.

Önceki rapordaki bizim promosyon modülünün kademe komisyonunu dikkate almaması ve kaynak dosya sütunlarını korumaması bulguları geçerlidir. Bu aşamada uygulama kodu değiştirilmedi; doğrulama kanıtı eklendi.

## Kanıt ve gizlilik

[Seçilmiş test sonuçları](melontik-dogrulama-sonuclari.json) yalnızca sentetik hesap girdilerinin çıktıları, kontrol adları ve doğrulama durumlarını içerir. Gerçek ürün kimlikleri, hesap verileri, sipariş tutarları, oturum bilgileri, indirilen Excel/PDF ve ekran görüntüleri depoya eklenmedi. Özel ham kanıtlar geçici çalışma alanındadır; depodaki özet bağımsız bir tekrar oynatma paketi değildir.


## 8 Kasım 2026 — Gerçek promosyon Excel dosyaları sağlandı

Kullanıcının sağladığı üç kaynak dosyayla komisyon tarifesi, yıldız etiketi ve flaş tekliflerinde yükleme–seçim–XLSX indirme zinciri doğrulandı. Bu üç akış için kaynak dosya engeli giderildi. Sonuçlar, tarih/komisyon eşleştirmeleri ve kalan sınırlar [Excel doğrulama raporunda](MELONTIK-EXCEL-DOGRULAMA.md).
