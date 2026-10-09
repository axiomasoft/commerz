<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class PromotionView
{
    /**
     * @param  list<ChannelId>  $channels  sorted by id
     * @param  list<RuleSpec>  $conditions  in order, with canonical parameters
     * @param  list<RuleSpec>  $effects  in order, with canonical parameters
     */
    public function __construct(
        public PromotionId $id,
        public string $code,
        public string $name,
        public PromotionStage $stage,
        public string $activator,
        public Stacking $stacking,
        public bool $stopFurther,
        public int $priority,
        public ?Currency $currency,
        public PromotionStatus $status,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public array $channels,
        public array $conditions,
        public array $effects,
        public UsageLimits $limits,
        public ?Money $budget,
        public int $revision,
        public PromotionUsageSummary $usage,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
