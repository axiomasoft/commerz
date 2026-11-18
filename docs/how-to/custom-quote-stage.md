# A custom quote stage

Insert your own pure step into the [quote pipeline](../concepts/quotes-pipeline) —
a loyalty fee, a handling charge, a minimum-order rule.

```php
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;

final class StaffDiscount implements QuoteStage
{
    public function key(): string { return 'acme.staff_discount'; }

    public function anchor(): QuoteAnchor { return QuoteAnchor::CartPromotions; }

    public function position(): StagePosition { return StagePosition::After; }

    public function apply(QuoteState $state): QuoteState
    {
        if (! in_array('staff', $state->input->context->customerGroups, true)) {
            return $state;
        }

        $discount = $state->itemsNet()->percentage(new Rate(500), RoundingMode::HalfUp)->negate();

        return $state->withAdjustment(new Adjustment(
            AdjustmentTarget::Order, null, $discount, 'acme.staff_discount', 'Staff 5 %',
        ));
    }
}
```

Register it in a plugin: `$registrar->contribute(QuoteStage::KIND, StaffDiscount::class)`.

## Rules

- **Pure.** No database writes, no network; the same input gives the same
  output. Take time from `$state->context()->at`, never the clock.
- **Position.** Only the anchor's owner takes `At`; plugins go `Before` or
  `After` an anchor. Stages run by anchor, then `Before → At → After`, then key.
- **Immutable state.** `QuoteState` has `withLine`, `withAdjustment`,
  `withDelivery`, `withTaxLine`, `withRejection`; each returns a new state.
- **Adjustments are negative** and carry a target (line, order or delivery), the
  source key and an explanation. A line adjustment needs its line id.
- **Order of money.** A change to money after the `totals` stage drops the totals
  and fails the quote; put your stage before `Totals`.
- **Failure.** Any exception becomes `QuoteFailed` with your stage's key.
- **Fingerprint.** The quote fingerprint includes the key and plugin version of
  every stage that ran, so changing a stage changes it.

Prefer promotions for discounts that business users manage as data: a rule
contribution (condition, effect) is stored, versioned, channel-assigned and
limit-aware. Write a stage when the behavior is policy of the host.
