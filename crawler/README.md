# Kâr&Fiyat — Tarayıcı Servisi (Pazar Zekası)

Rakip mağaza/ürün fiyatlarını **headless Chrome** ile düzenli tarayan bağımsız bir servis.
`kar-araci` uygulamasının `trackers` tablosunu okur, her takibi ziyaret eder, fiyat + ürün
sayısını çıkarır ve sonucu `trackers` (son fiyat) + `tracker_snapshots` (geçmiş) tablolarına
yazar. PHP uygulaması (`pazar-zekasi.php`) sonuçları okur.

## Kurulum

```bash
cd crawler
npm install        # puppeteer-core (küçük; Chrome'u sistemden kullanır, ayrı indirmez)
```

Chrome veya Edge sistemde kurulu olmalı. Otomatik bulunur (Chrome → Edge sırasıyla);
farklı bir yol için `CHROME_PATH` ortam değişkeni ver.

## Çalıştırma

```bash
# 1) Sürekli döngü (servis olarak): her 15 dakikada bir tarar
node crawl.js

# 2) Tek tur (Windows Görev Zamanlayıcı / cron için önerilir)
node crawl.js --once
```

### Windows'ta servis olarak (önerilen)

Görev Zamanlayıcı'da yeni bir görev oluştur, tetikleyiciyi "günlük, 15 dakikada bir tekrarla",
eylemi şu komut yap:

```
Program:  C:\Users\berke\AppData\Local\hermes\tools\node-26.7.0-win32-x64\node.exe
Argüman:  C:\Users\berke\kar-araci\crawler\crawl.js --once
Başlangıç: C:\Users\berke\kar-araci\crawler
```

Böylece Node sürekli açık tutulmaz; Windows her turda tek seferlik çalıştırır.

## Ortam değişkenleri (hepsi opsiyonel)

| Değişken       | Açıklama                                      | Varsayılan                   |
|----------------|-----------------------------------------------|------------------------------|
| `KARFIYAT_DB`  | `app.sqlite` yolu                             | `../data/app.sqlite`         |
| `CHROME_PATH`  | Chrome/Edge çalıştırılabilir yolu             | otomatik tespit              |
| `INTERVAL_MS`  | Tur aralığı (ms)                              | `900000` (15 dk)             |
| `HEADLESS=0`   | Görünür tarayıcı (debug)                      | headless                     |

## Fiyat çıkarma mantığı

1. **JSON-LD** (`Product`/`ProductModel`/`ItemList` şeması) — ürün sayfalarında en güvenilir.
2. **DOM** (`.sale-price`, `.discounted-price`, `[data-price]`) — mağaza/arama sayfaları.
   `0,01 TL` gibi "sepette %X indirim" gürültüleri `< 1 TL` olduğundan elenir.
3. Mağaza tipinde **medyan** fiyat (ucuz/teaser ürünlerden etkilenmez); ürün tipinde tek fiyat.

## Notlar

- **Bot koruması:** Trendyol bazen captcha/403 dönebilir. Servis bunu algılar ve o turu atlar
  (`[skip]`). Sık tarama rate-limit'e takılabilir — `INTERVAL_MS`'i büyük tut (≥ 15 dk).
- **Concurrent DB erişimi:** Servis SQLite'ı WAL modunda açar; PHP ile aynı dosyayı güvenle
  paylaşır. Kısa yazma süreleri nedeniyle kilit çakışması nadirdir.
- **DOM değişimi:** Trendyol sınıf adlarını değiştirirse `extract()` içindeki seçicileri
  güncellemen gerekir. `debug-trendyol.js` sayfadaki gerçek yapıyı döker.
