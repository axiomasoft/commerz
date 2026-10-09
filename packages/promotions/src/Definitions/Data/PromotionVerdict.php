<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Enums\VerdictKind;
use Commerz\Promotions\Rules\Enums\PromotionStage;

/**
 * The outcome for one switched-on promotion in one quote. An applied promotion carries the discount it gave (a
 * positive amount) and no reason; a rejected or skipped one carries the stable snake_case reason and no discount.
 */
#[Api(since: '0.1')]
final readonly class PromotionVerdict
{
    /**
     * @param  string|null  $couponCode  canonical code of the coupon that switched it on
     * @param  string|null  $reason  e.g. `minimum_not_reached`, `stopped_by_promotion`
     */
    public function __construct(
        public PromotionId $promotion,
        public string $code,
        public string $name,
        public PromotionStage $stage,
        public ?string $couponCode,
        public VerdictKind $kind,
        public ?string $reason,
        public ?Money $discount,
    ) {
        if ($kind === VerdictKind::Applied && ($reason !== null || ! $discount instanceof Money || ! $discount->isPositive())) {
            throw new InvalidCommand('kind', 'an applied verdict has a positive discount and no reason', 'promotions.invalid_verdict');
        }

        if ($kind !== VerdictKind::Applied && ($reason === null || preg_match('/\A[a-z][a-z0-9_]*\z/', $reason) !== 1 || $discount instanceof Money)) {
            throw new InvalidCommand('reason', 'a rejected or skipped verdict has a snake_case reason and no discount', 'promotions.invalid_verdict');
        }
    }
}
