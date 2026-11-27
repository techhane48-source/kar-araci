# Buybox decision support

Read-only. A matching-price scenario is shown only for a fresh observation (age 0–3600 seconds), rank greater than one, multiple sellers, matching barcode, valid costs, positive price in cents, and an explicit profit rule. The current price must exceed the observed Buybox price and the modeled profit must satisfy the rule. No claim that price alone wins Buybox. Already winning products do not get a cut recommendation. No live or local price writes.

The one-hour threshold is an application decision, not a platform guarantee. Stale observations remain visible with their timestamp; the comparison is withheld. A complete manual page refresh (up to 50 products, chunks of 10) is committed atomically under the seller lock. A failed request leaves prior observations unchanged. The CSV contains only the selected page and is labeled accordingly.

Synthetic tests cover stale data, target shortfalls, winning rank, missing costs, mismatched barcodes, and failed partial refresh. Verify actual Buybox payloads and commercial rules using read-only stage data before launch. No second/third price ranking assumptions.
