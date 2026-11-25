# Order-level financial evidence subtotal
Reference: https://developers.trendyol.com/docs/cari-hesap-ekstresi-entegrasyonu and https://developers.trendyol.com/docs/kargo-faturas%C4%B1-detaylar%C4%B1.

Sale's credit, commissionAmount and sellerRevenue are checked against each other (one kuruş tolerance), then active package and barcode-level amounts are matched (five kuruş tolerance). A matching package total alone is insufficient. No automatic allocation to individual order lines sharing a barcode. Negative/zero sale amounts and nonzero Sale debt invalidate the subtotal. Cargo invoice identity and domestic scope must match; return-only freight does not imply outbound freight is zero. Missing shipment rows, conflicting records, returns/claims/adjustments or missing costs block the subtotal.

Known subtotal = observed Sale sellerRevenue minus stored historical/first-observed product cost minus observed outbound cargo amount. No commission double deduction. This is a gross component difference, not accounting or realized profit. VAT basis, actual service fee, withholding, advertising, other fees, future returns and completeness of invoices remain unresolved. Absence of observed claim is not proof no future return. UI and CSV report coverage and explicitly withhold net profit. A duplicate product barcode can no longer seed a first-observed cost snapshot; already persisted historic snapshots are not rewritten.

Synthetic fixtures only; actual seller data and authenticated Melontik comparison remain acceptance gates.
