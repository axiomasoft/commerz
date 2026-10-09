<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Rules\Data\RuleSpec;

/**
 * Changes a promotion; a null field stays as it is. Rules are replaced as a whole list. `new UsageLimits()` lifts the
 * limits and `clearBudget` the budget; `new PromotionWindow()` opens the period. The code, the stage, the activator and
 * the currency cannot be changed.
 */
#[Api(since: '0.1')]
final readonly class UpdatePromotion
{
    /**
     * @param  list<RuleSpec>|null  $conditions
     * @param  list<RuleSpec>|null  $effects
     */
    public function __construct(
        public PromotionId $promotion,
        public ?string $name = null,
        public ?int $priority = null,
        public ?Stacking $stacking = null,
        public ?bool $stopFurther = null,
        public ?array $conditions = null,
        public ?array $effects = null,
        public ?UsageLimits $limits = null,
        public ?Money $budget = null,
        public bool $clearBudget = false,
        public ?PromotionWindow $window = null,
    ) {
        if ($name !== null) {
            CreatePromotion::assertName($name);
        }

        if ($priority !== null) {
            CreatePromotion::assertPriority($priority);
        }

        CreatePromotion::assertBudget($budget);

        if ($clearBudget && $budget instanceof Money) {
            throw new InvalidCommand('budget', 'give a new budget or clear it, not both', 'promotions.invalid_update');
        }
    }
}
