# Order evidence report (Trendyol only)

Sources: https://developers.trendyol.com/docs/cari-hesap-ekstresi-entegrasyonu and https://developers.trendyol.com/docs/kargo-faturas%C4%B1-detaylar%C4%B1

- Group by user and order number; include all known packages, even if the selected date window includes only one.
- Match statement rows using both orderNumber and shipmentPackageId, validate raw identity and domestic scope.
- Sale credit, commissionAmount and sellerRevenue are shown only when their arithmetic agrees within one kuruş. These are observed Sale components, not net commission after adjustment.
- Preserve other settlement types as debit/credit rather than assume refund count or signs from localized labels.
- Cargo invoice parcelUniqueId is not the shipment package ID. Group invoice lines at order level; never distribute them across packages. Validate domestic invoice document before including.
- Missing purchase cost or cargo invoice stays null. Cost is first observed, not actual accounting invoice. Claims are shown separately and do not prove refunds.
- Date selection is order-created date; existing later financial records are included. Incomplete sync, old unidentified finance records and unknown order links remain release blockers.
- No final profit is computed in this report, even when base evidence matches. Service invoices, VAT, withholding, non-Trendyol shipping, return inventory and late adjustments need evidence.
- Test fixtures are synthetic. Real API matching, split orders and reconciliation still require stage/live read-only verification.
