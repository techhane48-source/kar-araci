# Kâr&Fiyat Chrome eklentisi

Trendyol Satıcı Paneli'nde **Ürün Komisyon Tarifeleri, Plus Komisyon Tarifeleri, Avantajlı Ürün Etiketleri ve Flaş Ürünler**
sayfalarında her fiyat seçeneğinin altına net kârı yazar. Hesap Kâr&Fiyat sunucusunda, siparişlerle aynı modelle yapılır
(maliyet, KDV, kargo/barem, hizmet bedeli, stopaj). Eklenti sayfada hiçbir şeyi değiştirmez; yalnızca okur ve rozet ekler.

## Kurulum
1. Kâr&Fiyat → **Ayarlar → Chrome eklentisi** → *Yeni anahtar oluştur*. Sunucu adresini ve anahtarı kopyala.
2. Chrome → `chrome://extensions` → *Geliştirici modu* açık → *Paketlenmemiş öğe yükle* → bu klasör.
3. Eklentinin *Seçenekler* sayfasına adresi ve anahtarı yapıştır → *Kaydet ve bağlantıyı dene* (Chrome sunucu alan adı için izin ister).
4. Trendyol panelinde Promosyon & Fiyat sayfalarını aç.

Rozet renkleri: yeşil kârlı, sarı Ayarlar'daki en düşük marjın altında, kırmızı zarar. Yeşil çerçeve o satırdaki en kârlı seçenek.
Flaş sayfasında barkod görünmediği için ürün adıyla eşleştirilir; ad birden fazla ürüne uyuyorsa rozet çıkmaz.

## Test
`python3 scripts/eklenti/test-eklenti.py` — eklentiyi Chromium'a yükler, gerçek panelden çıkarılmış DOM yapısındaki
test sayfasında rozetleri ve açılır pencere hesabını doğrular.
