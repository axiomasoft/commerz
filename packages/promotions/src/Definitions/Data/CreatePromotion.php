<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Common\Data\PromotionCodes;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;

/**
 * A promotion. The code, the stage, the activator and the currency never change afterwards. The currency is `$currency`
 * or, when it is not given, the one of the budget and of the amounts in the rules; two different currencies are refused.
 * A promotion with no currency has no budget and no amounts and works in every currency.
 */
#[Api(since: '0.1')]
final readonly class CreatePromotion
{
    /**
     * @param  list<ChannelId>  $channels  at least one
     * @param  list<RuleSpec>  $effects  from 1 to 10
     * @param  list<RuleSpec>  $conditions  up to 20
     * @param  PromotionStatus  $status  `Active` or `Paused`
     */
    public function __construct(
        public string $code,
        public string $name,
        public PromotionStage $stage,
        public string $activator,
        public array $channels,
        public array $effects,
        public array $conditions = [],
        public Stacking $stacking = Stacking::Stackable,
        public bool $stopFurther = false,
        public int $priority = 0,
        public ?Currency $currency = null,
        public UsageLimits $limits = new UsageLimits,
        public ?Money $budget = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public PromotionStatus $status = PromotionStatus::Active,
    ) {
        PromotionCodes::code('code', $code);
        self::assertName($name);
        self::assertPriority($priority);
        self::assertBudget($budget);
        new PromotionWindow($startsAt, $endsAt);

        if ($channels === []) {
            throw new InvalidCommand('channels', 'must not be empty', 'promotions.invalid_channels');
        }

        if ($status === PromotionStatus::Archived) {
            throw new InvalidCommand('status', 'a promotion is created active or paused', 'promotions.invalid_status');
        }

        if ($budget instanceof Money && $currency instanceof Currency && ! $budget->currency->equals($currency)) {
            throw new InvalidCommand('budget', "must be in {$currency->code}", 'promotions.currency_mismatch');
        }
    }

    /** @internal */
    public static function assertName(string $name): void
    {
        if (trim($name) === '' || strlen($name) > 255) {
            throw new InvalidCommand('name', 'must be 1 to 255 bytes', 'promotions.invalid_name');
        }
    }

    /** @internal the column is a 32-bit integer */
    public static function assertPriority(int $priority): void
    {
        if ($priority < -2_147_483_648 || $priority > 2_147_483_647) {
            throw new InvalidCommand('priority', 'must fit a 32-bit integer', 'promotions.invalid_priority');
        }
    }

    /** @internal */
    public static function assertBudget(?Money $budget): void
    {
        if ($budget instanceof Money && ! $budget->isPositive()) {
            throw new InvalidCommand('budget', 'must be greater than zero', 'promotions.invalid_budget');
        }
    }
}
