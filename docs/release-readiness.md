# Release readiness — Trendyol first

This is a draft development branch, not a production release. The eight workstreams are tracked below; a green synthetic CI does not establish actual marketplace accuracy.

| Workstream | Implemented in development | Required acceptance evidence |
|---|---|---|
| Trendyol integration | V2 product read, order stream, settlement/documents, claims, Buybox, status and coverage tracking | Actual stage/production read-only payloads, pagination, currency and split packages; current commercial rates |
| Cargo/desi/payout | Invoice detail audit, explicit seller expectations, payout statement candidate, manual bank receipts | Contract tariff and gross/net fee basis, actual carrier invoice, actual bank deposit |
| Real profit/returns | Cost snapshots and dated cost, order/claim/finance evidence and estimates | Verified settlement signs, VAT invoices, return inventory condition, service/ads/account fees; no definitive net profit until reconciled |
| Promotion | Exact barcode and tier parsing, target filters | Real panel XLSX/CSV samples for each campaign type; seller/platform subsidy and eligibility |
| Melontik parity | Public reference examples, 288 model cases and browser/PHP parity | Authenticated comparison with same settings; documented fee and rounding differences |
| Actual data test | Isolated populated synthetic database, no external credentials | Read-only store connection and anonymized official extracts; no live writes |
| Performance/security | CSRF, locks, tenant scoping, encryption, safe uploads, crawler restrictions, batched evidence | Production-like load test (20k products), audit, egress restriction, backup restore and migration, secret recovery |
| UI | Existing functional screens retained | Final premium desktop/mobile redesign after functional acceptance |

## Acceptance process

1. Backup SQLite using its backup API and preserve encryption key securely, separately. Restore to an isolated environment; never test migrations against live data.
2. Connect a dedicated read-only Trendyol stage store or use explicitly authorized read-only production data. Do not disclose keys in issues/chat/logs. Keep seller/environment bindings.
3. Record exact source payloads with personal fields removed; compare amounts, IDs, counts, dates and currency against official panel exports, including zero/missing values, split packages, returned units, subsidies and multi-parcel invoices.
4. Reconcile a completed payout from statement through bank receipt. Verify gross vs net and VAT separately. An amount match is not sufficient proof of full reconciliation.
5. Compare matching inputs against authenticated Melontik if access is available. Record differences; public examples alone do not prove equivalence.
6. Run full regression suite and populated HTTP tests. Benchmark large catalogs; review access controls and SSRF restrictions, deploy crawler in restricted isolated network or leave disabled.
7. Design after these gates. Review mobile layout and accessibility with actual populated screens.
8. Obtain explicit approval before merging, production deployment, cron activation or marketplace writes.

No production deployment, bank integration or actual seller-data verification is represented as complete.


## Scope freeze and final gates
No new marketplaces or feature expansion. The development branch now has automated payout history and three-way statement checks, estimated-to-observed commission, three-stage cargo lifecycle with dated local tariff repricing, and store-level deduction evidence. Synthetic regressions are not proof of current marketplace tariffs or realized net profit. Closing gate: all financial reports require matching domestic seller identity and reject malformed/duplicate/fractional order lines; old records without seller evidence need a successful refresh. Remaining hard acceptance blockers: complete official current tariff and service-fee basis; verified real store payloads and invoice VAT; actual returns/campaign exports and physical inventory evidence; payout/bank reconciliation; authenticated Melontik comparison; load/security/migration checks; final interface. No estimate of completion date until real-data access is established. Do not merge or deploy without explicit approval.

## Read-only acceptance runner (adapted from work branch)
Run on an authorized private server with the existing matching database and encryption key: `php scripts/verify-trendyol.php --days=7`. If multiple connected non-demo accounts exist, use `--user-id=<local id>`. An existing DB elsewhere can be selected with `--db=/private/app.sqlite`. No migration, sync, key generation or marketplace write is performed. The SQLite connection uses read-only flags and query_only. In a private shell, `--environment=stage` or `--environment=production` can use TRENDYOL_SELLER_ID, TRENDYOL_API_KEY and TRENDYOL_API_SECRET without a local DB. Never commit or paste these credentials; no environment fallback. Report is aggregate JSON only, no customer, barcode, seller or key values. Exit 0 means selected read checks and any local comparison succeeded; 2 means checks/differences; 1 means setup failed. This is not release approval, independent bank evidence, tariff confirmation or complete financial reconciliation. The selected services may return empty data; all scenario coverage still needs real examples. The tool is not run automatically in CI against live accounts. CI uses synthetic credentials and fake client only. No server access or actual store data was available when adding the runner.

API-only verification also rejects invalid normalized catalog price/stock and malformed order lines, totals or statuses. Failure reports retain aggregate counts only. Valid empty responses still do not demonstrate scenario coverage.

## Cargo invoice item acceptance
The read-only runner discovers domestic cargo documents from DeductionInvoices in the selected date window, fetches item details and compares the parcel/type key, order number, amount and desi to the selected user's stored rows. Duplicate, invalid, missing, extra or mismatching item data fails the check. Only aggregate counts leave the runner; an empty response cannot establish evidence. This is not a comparison of invoice VAT, invoice header totals, tariff correctness, or final realized profit. Local-only invoices absent from the queried document set are outside this item comparison.

Live API acceptance now also attempts one most recent eligible past-day PAID payment from the first payment-order page, using the existing production three-way candidate calculation. It queries all supported settlement and financial-document types by payment ID. Only fixed status labels are published; no payout amounts or identifiers. No eligible payment means not verified, not a successful arithmetic match. Independent bank and final profit verification remain separate.

Live acceptance separately checks domestic Sale and Return statement arithmetic over the selected date window using production sign conventions and 1 kuruş tolerance. It reports only fixed outcome labels; empty or out-of-scope records are not evidence. This verifies the API's internal arithmetic, not order-level profit, VAT invoices or bank receipts.
