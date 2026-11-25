# Read-only Trendyol returns

Source: https://developers.trendyol.com/reference/getclaims and https://developers.trendyol.com/docs/i%CC%87adesi-olu%C5%9Fturulan-sipari%C5%9Fleri-%C3%A7ekme-getclaims

- GET claims only. Store each claim item separately; never treat a financial transaction count as item quantity.
- Distinguish outbound package ID from return package ID. Match exact order, outbound package, line and barcode; no guessed barcode-only allocation.
- Date filtering semantics in documentation versions differ (created vs modified). Query coverage is stored as claims_query, not a guarantee of complete modified-date history. Verify against stage.
- Keep latest status, do not overwrite with older responses. No personal customer names, addresses or notes stored by this module.
- Return state does not prove money refunded or restock condition. Observed cost is not a loss. Order-level cargo and financial movements remain separate until allocation is supported by actual invoice and claim data.
- Manual sync or CLI php scripts/sync.php claims. 7/30/89-day lookback is application policy; live tests and real multi-item/split-package fixtures remain outstanding. No accept/reject action or marketplace write.
