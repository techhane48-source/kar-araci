# Hermes devir notu

OpenCode'da `techhane48-source/kar-araci` deposunun `work` dalını aç.
Bu paket `0bb5de4` üzerinden, kayıp yerel taslağın sohbet özeti ve resmî
Trendyol API belgeleri kullanılarak yeniden uygulandı.

## İlk kontrol

- Git dalını, commit'i ve çalışma ağacını doğrula. Yerel değişiklik varsa koru.
- [Kurulum belgesini](../DEPLOY.md), [API kapsamını](TRENDYOL-API-KAPSAM-MATRISI.md)
  ve [muhasebe incelemesini](TRENDYOL-RESMI-BELGELER-DETAYLI-INCELEME.md) oku.
- PHP CLI'de PDO SQLite ve cURL dahil proje uzantılarının yüklü olduğunu kontrol et.
  `php scripts/test-order-accounting.php`, `php scripts/test-trendyol-pages.php`,
  `php scripts/test-sync.php` ve `php scripts/test-profit.php` çalıştır.
  Bu testler örnek API verisi kullanır. Canlı mağazada değişiklik yapmadan çalıştırılabilir.
- `public_html/siparis-muhasebe.php` sayfasının Pro yetkisi, CSRF ve kullanıcı
  izolasyonunu doğrula. Testlerde geçici veritabanı kullan.

## Sonraki görev

Çok paketli ve kısmi iadeli örnekler üzerinden sipariş, iade kalemi, kargo faturası,
komisyon ve hizmet bedelinin hangi kimliklerle eşleştiğini belgeleyip test et.
Tamlığı kanıtlanmayan giderler bilinmiyor kalmalı. Banka mutabakatı ve kesin net
kâr henüz tamamlanmadı. Birden fazla sunucuya yayılan kota yönetimi de açık.

Gerçek mağaza verisiyle kabul testi, satıcı izniyle salt okunur yürütülmeli.
Kimlik bilgilerini, müşteri verilerini ve yerel SQLite dosyalarını depoya ekleme.
