# Calculator comparison — 2026-11-25

Public reference: https://melontik.com/trendyol-komisyon-ve-kar-hesaplama and https://melontik.com/blog/rehberler/trendyol-komisyon-ve-kar-hesaplama . Public example only; no paid panel or authenticated product data was tested.

| Case | Published value | Safe-price expected |
| --- | ---: | ---: |
| Cost 400, price 717.74, cargo 65, fee 13.19, commission 19%, VAT 20%, withholding 1% | profit 80.00 | 80.00 displayed |
| Same, break-even | 597.74 | 597.74 |
| Cost 1000, cargo 100, fee 13.19, commission 20%, target profit 250 | 1788.85 | 1788.85 |
| Same, 23.5% commission | 1871.77 | 1871.78 |

The last case intentionally rounds upward to the first cent meeting the unrounded target. Discount calculations round the actual sale price to cents before evaluation; displayed profit can differ by a kuruş from an unrounded percentage simulation.

The price solver uses integer cents within 0.01–100,000,000 TRY (application ceiling, not marketplace limit). The objective is piecewise linear at the VAT floor, so it searches both segments. This handles a feasible low-price interval with a negative high-price slope. A positive margin input takes precedence over target TRY, as documented in the UI. Negative-VAT credit remains a scenario, not a guaranteed cash refund. 13.19 TRY is the public example fee, not a guaranteed current tariff. The modeled return reserve is not realized return loss.

Automated matrix: 288 hypothetical combinations of sale VAT, purchase VAT, commission, negative VAT and margin. Verifies safe cent and preceding cent, and PHP vs browser logic. Paid competitor panel, real tariffs, invoice rounding, refunds and actual order-level settlement verification remain release gates.
