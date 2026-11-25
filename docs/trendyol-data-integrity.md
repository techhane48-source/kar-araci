# Trendyol veri doğruluğu — geliştirme 03

Kaynaklar: Trendyol Developers / Sipariş Paketlerini Çekme V2; Cari Hesap Ekstresi Entegrasyonu; Kargo Faturası Detayları.

- Sipariş V2: packageTotalPrice, barcode, lineUnitPrice, lineGrossAmount, lineSellerDiscount, lineTyDiscount, lineId, commission ve vatRate.
- Settlements: işlem kimliği ve ödeme emri kimliği saklanır. Aynı kullanıcı/pazaryeri/işlem kimliği tekrar geldiğinde güncellenir.
- Eski kimliksiz kayıtlar silinmez; doğrulanmış finans raporlarına dahil edilmez. Tekrar senkron gerekir.
- Tarih dilimleri eksik sayfa halinde başarılı gösterilmez. Kısmi senkron tamamlanmış değildir.
- Eski sipariş komisyon oranı mevcut ürün fiyatlandırma ayarını ezmez.
- Bu paket gerçek net kârı tamamlamaz: otherfinancials, kargo faturası, iade/iptal, maliyet geçmişi, ödeme mutabakatı, cursor stream ve gerçek API testleri gereklidir.
- Bu değişiklikler canlı mağazaya gönderilmez; master dalına merge edilmeden test edilmelidir.
