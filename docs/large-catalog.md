# Large catalog safeguards

Cost file import is a transaction, capped at 20,000 data rows and 128 columns, with exact barcode lookup scoped to the current Trendyol seller account. Duplicate file barcodes or ambiguous matched catalog barcodes reject the import. Empty cost leaves an existing cost untouched; explicit zero means unknown cost. Unmatched rows are counted, not inserted. Optional VAT/rate fields are validated. The import intentionally ignores stock, sale prices, invoice desi, cargo type and unverified percentage extras: catalog templates must not overwrite observed marketplace data. Existing historical costs are not rewritten.

Indexes support user/marketplace/barcode, category and ID queries. Migration uses SQLite user_version=1 and BEGIN IMMEDIATE, so normal requests skip schema writes; future migrations must increment the version. Product alert previews are capped at 50 per type; complete filtered CSV remains available. The 20k synthetic regression reports elapsed time and peak PHP memory; it is not a production concurrency benchmark.

No live calls, price writes or deployment. Test on a backup of a legacy database before migration.
