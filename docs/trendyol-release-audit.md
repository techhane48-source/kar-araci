# Trendyol-first release audit — 25 November 2026

This is a draft release candidate, not a production approval. Sources reviewed:
- https://developers.trendyol.com/docs/cari-hesap-ekstresi-entegrasyonu
- https://developers.trendyol.com/docs/ürün-filtreleme-onaylı-ürün-v2
- https://developers.trendyol.com/docs/sipariş-paketlerini-akış-ile-çekme
- https://developers.trendyol.com/docs/kargo-faturası-detayları
- https://developers.trendyol.com/docs/1-servis-limitleri

## Findings and changes

| Area | Risk | This commit | Verification still needed |
| --- | --- | --- | --- |
| Read requests | Transient failures silently interrupt sync | Bounded GET retry for 429/5xx/network; Retry-After respected; write never replayed | Stage API rate limits and failures |
| Product catalog | Duplicated or moving pages masquerade as complete | Count, barcode and content ID validation | Live 10k+ pagination and rate limits |
| Finance | Missing pages or unrelated seller records corrupt totals | Validate IDs, seller, date, payment ID and expected count | Real settlement fixtures for every type |
| Finance | Foreign scope mixed into TRY | Persist raw rows and explicit domestic scope; old rows require re-sync | Validate country and affiliate semantics |
| Payout | Gross credit and commission double-counted | Four-decimal candidate computation using documented signs; separate payment statement comparison | Commission adjustment, coupon and otherfinance sign cases against real payouts |
| Cargo | Repeated invoice pages inflate cost | Check count and duplicate parcel/type within invoice | Actual invoice corrections and multiple parcels |
| Report | User/API text may execute spreadsheet formulas | Escape formula-like CSV cells | Verify other legacy exports |
| Analysis | One huge HTML page and session state | SQLite storage, atomic replace, per-user/module isolation, 100 rows per page, 20k row ceiling | Upload actual Trendyol templates, verify hosting memory and speed |
| Promotions | Guessing missing commission or ranges | Explicit columns, percentage styles, no missing-rate profit, bounded-range scenario only | Original files for all six modules, campaign subsidies and eligibility |
| Returns | Financial records do not prove returned item condition | Existing report separates finance records from loss | Claim/return matching, re-sale condition and actual costs |
| Security | Credential and tenant mixups | Previous GCM, seller binding, lock; this commit checks finance response seller | Payment checkout binding, admin initial credential, login throttling and browser/CSRF audit |
| History | First observed cost not actual purchase invoice | Previous immutable observations; no retroactive exact claim | Effective-dated cost import and correction workflow |

## Calculation definitions

- Estimate: sale less modeled cost, commission, cargo, fee, VAT and cash withholding. Withholding is not necessarily a final accounting expense; the interface must not be marketed as statutory accounting.
- Statement candidate: signed seller revenue plus commission-only adjustments and separate financial document credits/debits, excluding payment transfers themselves. This is a diagnostic, not authoritative cash or profit.
- Payment statement: compare the payment order's official amount to the recorded PaymentOrder movement. Bank reconciliation requires bank evidence.
- Returns: actual financial movement and item-level return condition must be considered separately; no assumed percentage loss.
- Imported ranges: upper bound is a scenario, not a guaranteed eligible price. No automated publication to Trendyol.

## Release gates

1. Run PHP regression, API fixture tests, SQLite and HTTP smoke tests in CI. Review latest run, not older SHA.
2. Install isolated stage account and test read-only endpoints for product, orders, finance, Buybox, cargo and payment order. Never put credentials in GitHub or chat.
3. Reconcile real anonymized settlement and payout samples, including all adjustment types, cancellations, split packages, discounts, withheld tax and rounding.
4. Compare a controlled matrix of Melontik calculator outputs (tax rates, cargo, returns, commissions, price targets). Paid Melontik screens and actual templates have not been tested.
5. Test original Excel files and large catalog import/export, including leading-zero barcodes, style percentages and duplicate rows. No promise of direct upload compatibility.
6. Review checkout, default admin password, rate limiting, user data export and deletion, database backup, concurrency, and deployment settings before any public sale.
7. Keep master untouched until the above gates pass and a human approves deployment.

## Operational decisions

Only Trendyol in the shared analysis screen. No write to a live marketplace, no automatic price change, no deployment. Imported data and payout summaries remain provisional. Historical costs use first observation, not invoice-backed history. Test code uses fixtures and synthetic accounts only.
