# Trendyol sipariş muhasebesi API kapsamı

| Servis | Kod | Ekranda kullanılan veri | Kalan doğrulama |
|---|---|---|---|
| [Sipariş akışı](https://developers.trendyol.com/reference/getshipmentpackagesstream.md) | `TrendyolClient::getOrders`, `sync_orders` | Paket, satır ve mevcut indirim alanları | Satıcı ve platform indirimlerinin gerçek fatura karşılığı |
| [İade listesi](https://developers.trendyol.com/reference/getclaims.md) | `TrendyolClient::getOrderClaims` | Sipariş satırı ve kalem durumu | Kısmi iadenin ters finans hareketiyle eşleştirilmesi |
| [Cari hesap hareketleri](https://developers.trendyol.com/docs/cari-hesap-ekstresi-entegrasyonu.md) | `sync_settlements`, `order_accounting_settlements` | Siparişe bağlı borç, alacak ve hareket türü | Tüm giderlerin dönem ve paket bazında kapanışı |
| [Ödeme emirleri](https://developers.trendyol.com/reference/getpaymentorder.md) | `getPaymentOrders`, `getPaymentOrderLedger` | Ödeme emrine bağlı ve siparişle eşleşen hareketler | Banka ekstresi eşleştirmesi |
| [Diğer finans hareketleri](https://developers.trendyol.com/reference/getotherfinancials.md) | `getPaymentOrderLedger` | Kargo faturası adayları ve siparişe bağlı diğer hareketler | Hizmet faturalarının siparişe dağıtımı |
| [Kargo faturası kalemleri](https://developers.trendyol.com/reference/getcargoinvoiceitems.md) | `TrendyolClient::getCargoInvoiceItems` | Sipariş numarası, gönderi kimliği, gider türü ve tutarı | Çok paketli siparişlerde gider dağıtımı |

Ürün, stok, fiyat değişikliği ve batch kapsamı [Trendyol doğrulama belgesinde](TRENDYOL-DOGRULAMA.md).
