# The quote pipeline

A **quote** answers "what would this cost right now?". It is a pure function:
no writes, the same input gives the same output.

```php
$quote = Sales::carts()->quote($cartId);   // QuoteResult
$quote->lines;  $quote->orderAdjustments;  $quote->taxLines;  $quote->delivery;
$quote->totals; $quote->total;  $quote->fingerprint;
```

## Anchors and stages

`QuotePipeline` (core) turns a `QuoteInput` — context, lines with snapshots,
presented codes, optional delivery selection — into a `QuoteResult` by running
stages in fixed anchors:

```text
Base → CatalogPromotions → CartPromotions → Delivery → DeliveryPromotions → Tax → Totals
```

| Anchor | Key | Owner | Does |
|---|---|---|---|
| Base | `base` | core | unit prices through `PriceResolver::resolveMany` |
| CatalogPromotions | `promotions.catalog` | promotions | line-level discounts |
| CartPromotions | `promotions.cart` | promotions | order-level discounts |
| Delivery | `delivery` | shipping | the selected method's price |
| DeliveryPromotions | `promotions.delivery` | promotions | delivery discounts |
| Tax | `tax` | pricing | one `TaxLine` per line and tax code |
| Totals | `totals` | core | sums, the "total ≥ 0" check, allocation of order discounts over lines |

An anchor without a contributing package is skipped, so a shop without
promotions simply has no discount stages. Only the anchor's owner takes the
`At` position; a host stage goes `Before` or `After` an anchor
([a custom quote stage](../how-to/custom-quote-stage)).

## Rules the pipeline holds

- A stage is a pure `QuoteState → QuoteState` function. An exception becomes
  `QuoteFailed`.
- `QuoteState` is immutable. Every adjustment carries its target (line, order,
  delivery), a negative amount, its source key and an explanation; rejected
  promotions and codes are recorded with a reason.
- Order-level discounts are shared over the line nets by the largest-remainder
  method, so the lines sum to the whole.
- Tax is computed per line on the base **after all discounts**, rounded per line
  with the configured mode. A tax-exempt customer gets no tax lines. A missing
  rate fails the quote (`pricing.tax_rate_not_found`).
- The `fingerprint` is the sha256 of the whole input — context time included —
  and the key and plugin version of every stage that ran.

## Quote, then place

A quote is what the buyer sees; `PlaceOrder` carries the total the buyer
accepted. Checkout re-runs the quote under the cart lock, and if the total
differs it throws `QuoteChanged`. The order keeps the quote it ran under the
lock, so what was accepted is what is stored.

Worked numbers: the shop scenario in `tests/EndToEnd/ShopScenarioTest.php`
walks a cart of 2500.00 through a 10 % promotion, a 100.00 coupon, 300.00
delivery and 20 % included VAT to a total of 2450.00.
