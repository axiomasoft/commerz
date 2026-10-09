<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Attributes\Api;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Quotes\Enums\AdjustmentTarget;

#[Api(since: '0.1')]
final readonly class QuoteResult
{
    /** Same as `$totals->total`. */
    public Money $total;

    /**
     * @param  list<QuotedLine>  $lines  in input order
     * @param  list<Adjustment>  $orderAdjustments  order-level adjustments as declared (their shares are on the lines)
     * @param  list<Adjustment>  $deliveryAdjustments
     * @param  list<TaxLine>  $taxLines  all taxes, line and delivery
     * @param  list<Rejection>  $rejections
     * @param  list<string>  $codes  the presented codes
     * @param  string  $fingerprint  sha256 of the input and the keys and versions of the stages that ran
     */
    public function __construct(
        public CommerceContext $context,
        public array $lines,
        public array $orderAdjustments,
        public ?DeliveryCharge $delivery,
        public array $deliveryAdjustments,
        public array $taxLines,
        public QuoteTotals $totals,
        public array $rejections,
        public array $codes,
        public string $fingerprint,
    ) {
        $this->total = $totals->total;
    }

    /**
     * What each promotion took off this quote, for PromotionUsage::consume when the order is placed: one entry per
     * promotion id, in order of first appearance — own line adjustments of the lines (lines in order), then the
     * order-level adjustments as declared, then the delivery adjustments. The per-line shares of order-level
     * adjustments are not counted again. Adjustments without a promotion id (host stages) are ignored.
     *
     * @return list<AppliedPromotion>
     *
     * @throws InvalidCommand quotes.inconsistent_promotion when one promotion id carries two different coupon codes
     */
    public function appliedPromotions(): array
    {
        $ids = [];
        $coupons = [];
        $discounts = [];

        foreach ($this->declaredAdjustments() as $adjustment) {
            if ($adjustment->promotionId === null) {
                continue;
            }

            // Parallel lists, not an id-keyed array: PHP turns an id like '1' into an int key.
            $index = array_search($adjustment->promotionId, $ids, true);

            if ($index === false) {
                $index = count($ids);
                $ids[] = $adjustment->promotionId;
                $coupons[] = $adjustment->couponCode;
                $discounts[] = Money::zero($this->context->currency);
            } elseif ($coupons[$index] !== $adjustment->couponCode) {
                throw new InvalidCommand('adjustments', "promotion '{$adjustment->promotionId}' carries two coupon codes", 'quotes.inconsistent_promotion');
            }

            $discounts[$index] = $discounts[$index]->minus($adjustment->amount);
        }

        return array_map(
            static fn (string $id, ?string $coupon, Money $discount): AppliedPromotion => new AppliedPromotion($id, $coupon, $discount),
            $ids,
            $coupons,
            $discounts,
        );
    }

    /** @return iterable<Adjustment> every adjustment once: line-own, order-level as declared, delivery. */
    private function declaredAdjustments(): iterable
    {
        foreach ($this->lines as $line) {
            foreach ($line->adjustments as $adjustment) {
                if ($adjustment->target === AdjustmentTarget::Line) {
                    yield $adjustment;
                }
            }
        }

        yield from $this->orderAdjustments;
        yield from $this->deliveryAdjustments;
    }
}
